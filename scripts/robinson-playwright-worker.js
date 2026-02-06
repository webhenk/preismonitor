const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const PRICE_KEY_REGEX = /(price|amount|total|rate)/i;
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

function detectContext(text) {
  const lower = text.toLowerCase();
  if (lower.includes('pro person') || lower.includes('p.p') || lower.includes('per person')) {
    return 'per_person';
  }
  if (lower.includes('pro nacht') || lower.includes('per night')) {
    return 'per_night';
  }
  if (lower.includes('gesamt') || lower.includes('total')) {
    return 'total';
  }
  return null;
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
    context: detectContext(text),
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
  const consentTexts = ['Akzeptieren', 'Alle akzeptieren', 'Zustimmen'];
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
  const [url, outputDir] = process.argv.slice(2);
  if (!url || !outputDir) {
    throw new Error('Usage: node robinson-playwright-worker.js <url> <outputDir>');
  }

  ensureDir(outputDir);
  const responseDir = path.join(outputDir, 'price-responses');
  ensureDir(responseDir);
  const baseDir = process.cwd();
  const netDir = path.join(baseDir, 'artifacts', 'debug', 'net');
  ensureDir(netDir);
  const parsedUrl = new URL(url);
  const safeHost = parsedUrl.hostname.replace(/[^a-z0-9.-]+/gi, '-') || 'unknown-host';
  const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
  const netSessionDir = path.join(netDir, `${safeHost}_${timestamp}`);
  ensureDir(netSessionDir);

  const networkLog = [];
  const priceResponses = [];
  const jsonArtifacts = [];

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
    const entry = {
      url: response.url(),
      method: request.method(),
      resourceType,
      status: response.status(),
    };
    networkLog.push(entry);

    if (!['xhr', 'fetch'].includes(resourceType)) {
      return;
    }
    const contentType = response.headers()['content-type'] || '';
    if (!contentType.includes('application/json')) {
      return;
    }
    let bodyText = null;
    try {
      bodyText = await response.text();
    } catch (error) {
      return;
    }
    if (!bodyText) {
      return;
    }
    if (!JSON_KEYWORDS_REGEX.test(bodyText)) {
      return;
    }
    const fileName = `response_${jsonArtifacts.length + 1}.json`;
    const filePath = path.join(netSessionDir, fileName);
    fs.writeFileSync(filePath, bodyText);
    jsonArtifacts.push({
      url: response.url(),
      status: response.status(),
      path: filePath,
    });
    let decoded = null;
    try {
      decoded = JSON.parse(bodyText);
    } catch (error) {
      return;
    }
    if (!containsPriceKeys(decoded)) {
      return;
    }
    priceResponses.push({
      url: response.url(),
      status: response.status(),
      body: decoded,
      path: filePath,
    });
  });

  let navigationError = null;
  try {
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await handleConsentOverlays(page);
    await page.waitForLoadState('networkidle', { timeout: 60000 });
  } catch (error) {
    navigationError = error instanceof Error ? error.message : String(error);
  }

  await handleConsentOverlays(page);

  try {
    await page.waitForFunction(() => {
      const isVisible = (element) => {
        if (!element || !(element instanceof HTMLElement)) {
          return false;
        }
        const style = window.getComputedStyle(element);
        if (!style || style.visibility === 'hidden' || style.display === 'none') {
          return false;
        }
        return element.offsetParent !== null;
      };
      const selectors = ['.price', '.total', '.total-price', '[data-testid="total-price"]'];
      for (const selector of selectors) {
        const element = document.querySelector(selector);
        if (element && isVisible(element) && /€/.test(element.innerText || '')) {
          return true;
        }
      }
      const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_ELEMENT);
      while (walker.nextNode()) {
        const el = walker.currentNode;
        if (isVisible(el) && /€/.test(el.innerText || '')) {
          return true;
        }
      }
      return false;
    }, { timeout: 30000 });
  } catch (error) {
    // Best effort; continue even if timeout.
  }

  const html = await page.content();
  const htmlPath = path.join(outputDir, 'page.html');
  fs.writeFileSync(htmlPath, html);

  const bodyText = await page.innerText('body');
  const innerTextPreview = bodyText.slice(0, 20000);
  const innerTextPath = path.join(outputDir, 'body-text.txt');
  fs.writeFileSync(innerTextPath, innerTextPreview);

  const screenshotPath = path.join(outputDir, 'screenshot.png');
  await page.screenshot({ path: screenshotPath, fullPage: true });

  const candidates = await page.evaluate(() => {
    const results = [];
    const isVisible = (element) => {
      if (!element || !(element instanceof HTMLElement)) {
        return false;
      }
      const style = window.getComputedStyle(element);
      if (!style || style.visibility === 'hidden' || style.display === 'none') {
        return false;
      }
      return element.offsetParent !== null;
    };
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_ELEMENT);
    while (walker.nextNode()) {
      const el = walker.currentNode;
      if (!isVisible(el)) {
        continue;
      }
      const text = el.innerText ? el.innerText.trim() : '';
      if (!text) {
        continue;
      }
      if (!/[€$£]|\b(?:EUR|CHF|USD|GBP)\b/i.test(text)) {
        continue;
      }
      if (text.length > 200) {
        continue;
      }
      results.push({ text, html: el.outerHTML });
      if (results.length >= 50) {
        break;
      }
    }
    return results;
  });

  let chosen = null;
  for (const candidate of candidates) {
    if (/gesamt|total/i.test(candidate.text) && parsePriceFromText(candidate.text)) {
      chosen = candidate;
      break;
    }
  }
  if (!chosen) {
    chosen = candidates.find((candidate) => parsePriceFromText(candidate.text)) || null;
  }

  let price = null;
  let priceText = null;
  let domSnippet = null;
  if (chosen) {
    price = parsePriceFromText(chosen.text);
    priceText = chosen.text;
    domSnippet = chosen.html;
  }

  let regexPrice = null;
  const regexMatch = innerTextPreview.match(/(\d{1,3}(?:\.\d{3})*,\d{2})\s?€/);
  if (regexMatch) {
    regexPrice = {
      raw: regexMatch[0],
      value: normalizeAmount(regexMatch[1]),
      currency: 'EUR',
    };
  }

  let jsonPrice = null;
  if (priceResponses.length > 0) {
    const candidatesFromJson = priceResponses.flatMap((entry) => extractCandidatesFromJson(entry.body));
    const preferred = pickPreferredJsonPrice(candidatesFromJson);
    if (preferred) {
      jsonPrice = {
        raw: preferred.value,
        value: Number(preferred.value),
        currency: normalizeCurrency(preferred.currency),
        path: preferred.path,
      };
    }
  }

  const preferredPrice = jsonPrice || price || regexPrice || null;

  const blockedSignal = findBlockedSignal(html);
  const hasEuro = /€/.test(innerTextPreview);
  const hasSuccessfulXhr = networkLog.some(
    (entry) => ['xhr', 'fetch'].includes(entry.resourceType) && entry.status >= 200 && entry.status < 400,
  );
  const blockedDueToMissingPrice = !hasEuro && jsonArtifacts.length === 0 && !hasSuccessfulXhr;

  const networkLogPath = path.join(outputDir, 'network-log.json');
  fs.writeFileSync(networkLogPath, JSON.stringify(networkLog, null, 2));

  const output = {
    state: blockedSignal || blockedDueToMissingPrice ? 'blocked' : navigationError ? 'error' : 'ok',
    blocked: Boolean(blockedSignal || blockedDueToMissingPrice),
    error: navigationError,
    price: preferredPrice,
    price_text: priceText,
    dom_snippet: domSnippet,
    context: preferredPrice ? preferredPrice.context : null,
    artifacts: {
      html: htmlPath,
      inner_text: innerTextPath,
      screenshot: screenshotPath,
      network_log: networkLogPath,
      price_responses: priceResponses.map((entry, index) => ({
        url: entry.url,
        status: entry.status,
        path: entry.path || path.join(responseDir, `response_${index + 1}.json`),
      })),
      xhr_json: jsonArtifacts,
      net_session_dir: netSessionDir,
    },
  };

  await browser.close();

  process.stdout.write(`${JSON.stringify(output)}\n`);
}

main().catch((error) => {
  const payload = {
    state: 'error',
    blocked: false,
    error: error instanceof Error ? error.message : String(error),
  };
  process.stdout.write(`${JSON.stringify(payload)}\n`);
  process.exit(1);
});
