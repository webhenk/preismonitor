const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const PRICE_KEY_REGEX = /(price|amount|total|rate)/i;
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

async function main() {
  const [url, outputDir] = process.argv.slice(2);
  if (!url || !outputDir) {
    throw new Error('Usage: node robinson-playwright-worker.js <url> <outputDir>');
  }

  ensureDir(outputDir);
  const responseDir = path.join(outputDir, 'price-responses');
  ensureDir(responseDir);

  const networkLog = [];
  const priceResponses = [];

  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({
    locale: 'de-DE',
    userAgent: 'PreisMonitor/1.0 (Playwright; +https://example.com)',
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
    });
    const fileName = `response_${priceResponses.length}.json`;
    fs.writeFileSync(path.join(responseDir, fileName), JSON.stringify(decoded, null, 2));
  });

  let navigationError = null;
  try {
    await page.goto(url, { waitUntil: 'networkidle', timeout: 60000 });
  } catch (error) {
    navigationError = error instanceof Error ? error.message : String(error);
  }

  try {
    await page.waitForFunction(() => /€|Gesamtpreis/i.test(document.body.innerText), { timeout: 30000 });
  } catch (error) {
    // Best effort; continue even if timeout.
  }

  const html = await page.content();
  const htmlPath = path.join(outputDir, 'page.html');
  fs.writeFileSync(htmlPath, html);

  const screenshotPath = path.join(outputDir, 'screenshot.png');
  await page.screenshot({ path: screenshotPath, fullPage: true });

  const candidates = await page.evaluate(() => {
    const results = [];
    const isVisible = (el) => {
      if (!el || !(el instanceof HTMLElement)) {
        return false;
      }
      const style = window.getComputedStyle(el);
      if (!style || style.visibility === 'hidden' || style.display === 'none') {
        return false;
      }
      return el.offsetParent !== null;
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

  const blockedSignal = findBlockedSignal(html);

  const networkLogPath = path.join(outputDir, 'network-log.json');
  fs.writeFileSync(networkLogPath, JSON.stringify(networkLog, null, 2));

  const output = {
    state: blockedSignal ? 'blocked' : navigationError ? 'error' : 'ok',
    blocked: Boolean(blockedSignal),
    error: navigationError,
    price,
    price_text: priceText,
    dom_snippet: domSnippet,
    context: price ? price.context : null,
    artifacts: {
      html: htmlPath,
      screenshot: screenshotPath,
      network_log: networkLogPath,
      price_responses: priceResponses.map((entry, index) => ({
        url: entry.url,
        status: entry.status,
        path: path.join(responseDir, `response_${index + 1}.json`),
      })),
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
