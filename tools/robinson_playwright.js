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

function mapBlockedReason(signal) {
  if (!signal) {
    return null;
  }
  if (signal.includes('captcha') || signal.includes('verify you are human')) {
    return 'captcha';
  }
  if (signal.includes('access denied') || signal.includes('attention required') || signal.includes('unusual traffic')) {
    return 'access_denied';
  }
  if (signal.includes('enable javascript')) {
    return 'js_required';
  }
  if (signal.includes('bot detection')) {
    return 'access_denied';
  }
  return 'access_denied';
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
      return {
        clicked: true,
        selector: 'button,[role="button"],input[type="button"],input[type="submit"]',
        text,
      };
    }
  }
  return {
    clicked: false,
    selector: null,
    text: null,
  };
}

async function main() {
  const [url] = process.argv.slice(2);
  if (!url) {
    throw new Error('Usage: node tools/robinson_playwright.js <url>');
  }

  console.error('[DEBUG] runner=playwright');

  const xhrHits = [];
  const priceResponses = [];
  const xhrDumps = [];

  const browser = await chromium.launch({
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox'],
  });
  const context = await browser.newContext({
    locale: 'de-DE',
    timezoneId: 'Europe/Berlin',
    viewport: { width: 1280, height: 720 },
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
    xhrHits.push(response);
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
    if (xhrDumps.length < 3) {
      xhrDumps.push({
        url: response.url(),
        status: response.status(),
        contentType,
        bodyText,
        decoded,
      });
    }
    priceResponses.push(decoded);
  });

  let navigationError = null;
  let navigationResponse = null;
  let consentClicked = false;
  let consentMeta = { clicked: false, selector: null, text: null };
  let networkIdleMs = null;
  try {
    console.error(`[DEBUG] step=goto url=${url}`);
    navigationResponse = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });
    consentMeta = await handleConsentOverlays(page);
    consentClicked = consentMeta.clicked;
    const waitStart = Date.now();
    await page.waitForLoadState('networkidle', { timeout: 60000 });
    networkIdleMs = Date.now() - waitStart;
    console.error(
      `[DEBUG] step=consent clicked=${consentClicked} selector=${JSON.stringify(consentMeta.selector)} text=${JSON.stringify(consentMeta.text)}`
    );
    console.error(`[DEBUG] step=wait networkidle ok=true ms=${networkIdleMs}`);
  } catch (error) {
    navigationError = error instanceof Error ? error.message : String(error);
    console.error(`[DEBUG] step=wait networkidle ok=false ms=${networkIdleMs ?? 0}`);
  }

  const renderedHtml = await page.content();
  const renderedHtmlSize = Buffer.byteLength(renderedHtml, 'utf8');
  console.error(`[DEBUG] step=dom rendered_html_size=${renderedHtmlSize}`);

  try {
    await page.waitForFunction(() => /€/.test(document.body ? document.body.innerText : ''), { timeout: 30000 });
  } catch (error) {
    // Best effort; continue even if timeout.
  }

  const bodyText = await page.innerText('body');
  const bodyTextSize = Buffer.byteLength(bodyText, 'utf8');
  console.error(`[DEBUG] step=dom body_text_size=${bodyTextSize}`);

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
  let blockedReason = mapBlockedReason(blockedSignal);
  let blocked = Boolean(blockedSignal);
  if (!blocked && renderedHtmlSize <= 50000) {
    blocked = true;
    blockedReason = 'empty';
  }

  console.error(`[DEBUG] step=xhr hits=${xhrHits.length} price_candidates=${priceResponses.length}`);
  if (chosenPrice) {
    console.error(
      `[DEBUG] step=extract price_text=${JSON.stringify(chosenPrice.priceText)} price_value=${chosenPrice.priceValue} currency=${JSON.stringify(chosenPrice.currency)}`
    );
  }
  if (blocked) {
    console.error(`[DEBUG] step=blocked reason="${blockedReason || 'unknown'}"`);
  }

  const urlEffective = page.url();
  const httpStatus = navigationResponse ? navigationResponse.status() : null;
  let error = navigationError;

  if (!chosenPrice || blocked) {
    const bodyPreview = bodyText.slice(0, 1500);
    const htmlPreview = renderedHtml.slice(0, 1500);
    const title = await page.title();
    console.error(`[DUMP] title=${JSON.stringify(title)}`);
    console.error(`[DUMP] bodyTextPreview=${JSON.stringify(bodyPreview)}`);
    console.error(`[DUMP] htmlPreview=${JSON.stringify(htmlPreview)}`);
    const screenshotBuffer = await page.screenshot({ type: 'png', fullPage: false });
    console.error(`[DUMP] screenshot_png_base64=${screenshotBuffer.toString('base64')}`);
    xhrDumps.forEach((dump) => {
      const preview = dump.bodyText.slice(0, 1000);
      const candidates = extractCandidatesFromJson(dump.decoded).slice(0, 5);
      const candidateSummary = candidates.map((candidate) => ({
        path: candidate.path,
        value: candidate.value,
        currency: normalizeCurrency(candidate.currency),
      }));
      console.error(
        `[DUMP] xhr url=${dump.url} status=${dump.status} ct=${JSON.stringify(dump.contentType)} body_preview=${JSON.stringify(preview)} price_candidates=${JSON.stringify(candidateSummary)}`
      );
    });
  }

  if (!chosenPrice && !blocked) {
    error = 'did_not_render';
  }

  const bodyTextPreview = !chosenPrice ? bodyText.slice(0, 1500) : null;
  const output = {
    runner: 'playwright',
    url_requested: url,
    url_effective: urlEffective,
    http_status: httpStatus,
    blocked,
    consent_clicked: consentClicked,
    rendered_html_size: renderedHtmlSize,
    body_text_size: bodyTextSize,
    body_text_preview: bodyTextPreview,
    xhr_hits: xhrHits.length,
    price_text: chosenPrice ? chosenPrice.priceText : null,
    price_value: chosenPrice ? chosenPrice.priceValue : null,
    currency: chosenPrice ? chosenPrice.currency : null,
    error,
  };

  await browser.close();

  process.stdout.write(`${JSON.stringify(output)}\n`);
}

main().catch((error) => {
  const payload = {
    runner: 'playwright',
    url_requested: null,
    url_effective: null,
    http_status: null,
    blocked: false,
    consent_clicked: false,
    rendered_html_size: 0,
    body_text_size: 0,
    body_text_preview: null,
    xhr_hits: 0,
    price_text: null,
    price_value: null,
    currency: null,
    error: error instanceof Error ? error.message : String(error),
  };
  console.error('[DEBUG] runner=playwright');
  console.error('[DEBUG] step=dom rendered_html_size=0');
  console.error('[DEBUG] step=xhr hits=0 price_candidates=0');
  process.stdout.write(`${JSON.stringify(payload)}\n`);
  process.exit(1);
});
