const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const PRICE_KEY_REGEX = /(price|amount|total)/i;
const JSON_KEYWORDS_REGEX = /(price|amount|total|currency)/i;
const BLOCKED_SIGNALS = [
  'captcha',
  'access denied',
  'enable javascript',
  'verify you are human',
  'unusual traffic',
  'bot detection',
  'attention required',
];

function ensureDir(dirPath) {
  if (!fs.existsSync(dirPath)) {
    fs.mkdirSync(dirPath, { recursive: true });
  }
}

function normalizeCurrency(value) {
  if (!value) {
    return null;
  }
  const currencyMap = {
    '€': 'EUR',
    EUR: 'EUR',
    CHF: 'CHF',
    '$': 'USD',
    USD: 'USD',
    '£': 'GBP',
    GBP: 'GBP',
  };
  const trimmed = value.toString().trim().toUpperCase();
  return currencyMap[trimmed] || trimmed;
}

function normalizeAmount(amount) {
  if (!amount) {
    return null;
  }
  let clean = amount.replace(/\u00A0/g, '').replace(/\s+/g, '');
  if (clean.includes(',') && clean.includes('.')) {
    clean = clean.replace(/\./g, '').replace(',', '.');
  } else if (clean.includes(',')) {
    clean = clean.replace(',', '.');
  }
  const value = Number.parseFloat(clean);
  return Number.isFinite(value) ? value : null;
}

function parsePriceFromText(text) {
  const pattern = /((€|\$|£|CHF|EUR|USD|GBP)\s*([0-9][0-9.\s\u00A0]*[0-9](?:,[0-9]{2})?))|(([0-9][0-9.\s\u00A0]*[0-9](?:,[0-9]{2})?)\s*(€|EUR|CHF|USD|GBP|\$|£))/i;
  const match = text.match(pattern);
  if (!match) {
    return null;
  }
  const currency = match[2] || match[6];
  const amount = match[3] || match[5];
  const value = normalizeAmount(amount);
  if (value === null) {
    return null;
  }
  return {
    raw: match[0],
    value,
    currency: normalizeCurrency(currency),
  };
}

function extractCandidatesFromJson(value, pathStack = []) {
  const results = [];
  if (value === null || value === undefined) {
    return results;
  }
  if (Array.isArray(value)) {
    value.forEach((item, index) => {
      results.push(...extractCandidatesFromJson(item, [...pathStack, String(index)]));
    });
    return results;
  }
  if (typeof value !== 'object') {
    return results;
  }
  for (const [key, entry] of Object.entries(value)) {
    const nextPath = [...pathStack, key];
    if (typeof entry === 'number') {
      results.push({
        value: entry,
        currency: value.currency || value.curr || null,
        path: nextPath.join('.'),
        key,
      });
    } else if (typeof entry === 'string' && /\d/.test(entry)) {
      const parsed = parsePriceFromText(entry);
      if (parsed) {
        results.push({
          value: parsed.value,
          currency: parsed.currency,
          path: nextPath.join('.'),
          key,
        });
      }
    }
    results.push(...extractCandidatesFromJson(entry, nextPath));
  }
  return results;
}

function pickPreferredJsonPrice(candidates) {
  if (candidates.length === 0) {
    return null;
  }
  const totalCandidate = candidates.find((candidate) => /total/i.test(candidate.key || ''));
  if (totalCandidate) {
    return totalCandidate;
  }
  return candidates[0];
}

function containsPriceKeys(payload) {
  if (payload === null || payload === undefined) {
    return false;
  }
  if (Array.isArray(payload)) {
    return payload.some(containsPriceKeys);
  }
  if (typeof payload !== 'object') {
    return false;
  }
  for (const [key, value] of Object.entries(payload)) {
    if (PRICE_KEY_REGEX.test(key)) {
      return true;
    }
    if (containsPriceKeys(value)) {
      return true;
    }
  }
  return false;
}

function findBlockedSignal(content) {
  const lower = content.toLowerCase();
  return BLOCKED_SIGNALS.find((signal) => lower.includes(signal));
}

async function handleConsentOverlays(page) {
  const consentTexts = ['Alle akzeptieren', 'Akzeptieren', 'Zustimmen'];
  const candidates = page.locator('button, [role="button"], input[type="button"], input[type="submit"]');
  const count = await candidates.count();
  for (let i = 0; i < count; i += 1) {
    const candidate = candidates.nth(i);
    const text = (await candidate.innerText().catch(() => '')) || '';
    if (consentTexts.some((phrase) => text.toLowerCase().includes(phrase.toLowerCase()))) {
      await candidate.click({ timeout: 2000 }).catch(() => {});
      return true;
    }
  }
  return false;
}

async function main() {
  const [url] = process.argv.slice(2);
  if (!url) {
    throw new Error('Usage: node tools/robinson_playwright.js <url>');
  }

  const baseDir = path.resolve(__dirname, '..');
  const artifactsDir = path.join(baseDir, 'artifacts', 'debug', 'robinson');
  const xhrDir = path.join(artifactsDir, 'xhr');
  ensureDir(xhrDir);

  const htmlPath = path.join(artifactsDir, 'rendered.html');
  const bodyPath = path.join(artifactsDir, 'body.txt');
  const screenshotPath = path.join(artifactsDir, 'screenshot.png');

  const xhrArtifacts = [];
  const priceResponses = [];

  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({
    locale: 'de-DE',
    timezoneId: 'Europe/Berlin',
    userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
    extraHTTPHeaders: {
      'Accept-Language': 'de-DE,de;q=0.9,en;q=0.8',
    },
  });
  const page = await context.newPage();

  page.on('response', async (response) => {
    const request = response.request();
    const resourceType = request.resourceType();
    if (!['xhr', 'fetch'].includes(resourceType)) {
      return;
    }
    const contentType = response.headers()['content-type'] || '';
    if (!contentType.includes('application/json')) {
      return;
    }
    let bodyText;
    try {
      bodyText = await response.text();
    } catch (error) {
      return;
    }
    if (!bodyText || !JSON_KEYWORDS_REGEX.test(bodyText)) {
      return;
    }
    let decoded;
    try {
      decoded = JSON.parse(bodyText);
    } catch (error) {
      return;
    }
    if (!containsPriceKeys(decoded)) {
      return;
    }
    const fileName = `response_${xhrArtifacts.length + 1}.json`;
    const filePath = path.join(xhrDir, fileName);
    fs.writeFileSync(filePath, bodyText);
    xhrArtifacts.push(filePath);
    priceResponses.push(decoded);
  });

  let navigationError = null;
  try {
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });
    const consentClicked = await handleConsentOverlays(page);
    if (consentClicked) {
      await page.waitForLoadState('networkidle', { timeout: 60000 });
    } else {
      await page.waitForLoadState('networkidle', { timeout: 60000 });
    }
  } catch (error) {
    navigationError = error instanceof Error ? error.message : String(error);
  }

  const renderedHtml = await page.content();
  fs.writeFileSync(htmlPath, renderedHtml);

  console.error('Runner: playwright');
  console.error(`Rendered HTML size: ${Buffer.byteLength(renderedHtml, 'utf8')}`);

  try {
    await page.waitForFunction(() => /€/.test(document.body ? document.body.innerText : ''), { timeout: 30000 });
  } catch (error) {
    // Best effort; continue even if timeout.
  }

  const bodyText = await page.innerText('body');
  fs.writeFileSync(bodyPath, bodyText);

  await page.screenshot({ path: screenshotPath, fullPage: true });

  let jsonPrice = null;
  if (priceResponses.length > 0) {
    const candidates = priceResponses.flatMap((entry) => extractCandidatesFromJson(entry));
    const preferred = pickPreferredJsonPrice(candidates);
    if (preferred) {
      jsonPrice = {
        priceText: String(preferred.value),
        priceValue: Number(preferred.value),
        currency: normalizeCurrency(preferred.currency),
      };
    }
  }

  let regexPrice = null;
  const regexMatch = bodyText.match(/(\d{1,3}(?:\.\d{3})*,\d{2})\s?€/);
  if (regexMatch) {
    regexPrice = {
      priceText: regexMatch[0],
      priceValue: normalizeAmount(regexMatch[1]),
      currency: 'EUR',
    };
  }

  const chosenPrice = jsonPrice || regexPrice;
  const blockedSignal = findBlockedSignal(renderedHtml || bodyText || '');

  await browser.close();

  console.error(`XHR hits: ${xhrArtifacts.length}`);

  const output = {
    priceText: chosenPrice ? chosenPrice.priceText : null,
    priceValue: chosenPrice ? chosenPrice.priceValue : null,
    currency: chosenPrice ? chosenPrice.currency : null,
    blocked: Boolean(blockedSignal),
    htmlPath: path.relative(baseDir, htmlPath),
    screenshotPath: path.relative(baseDir, screenshotPath),
    xhrHitsCount: xhrArtifacts.length,
    error: navigationError,
  };

  process.stdout.write(`${JSON.stringify(output)}\n`);
}

main().catch((error) => {
  const payload = {
    priceText: null,
    priceValue: null,
    currency: null,
    blocked: false,
    htmlPath: null,
    screenshotPath: null,
    xhrHitsCount: 0,
    error: error instanceof Error ? error.message : String(error),
  };
  console.error('Runner: playwright');
  console.error('Rendered HTML size: 0');
  console.error('XHR hits: 0');
  process.stdout.write(`${JSON.stringify(payload)}\n`);
  process.exit(1);
});
