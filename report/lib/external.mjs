// Checks made from outside, as the public (and AI crawlers) see the site. Read-only: GET requests only.

import { BOTS, robotsAllows, sitemapsIn } from './robots.mjs';

const BROWSER_UA =
  'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

// User agents as the crawlers announce themselves. Only the name matters to most firewalls.
const BOT_UAS = {
  'OAI-SearchBot': 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; OAI-SearchBot/1.0; +https://openai.com/searchbot',
  'ChatGPT-User': 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ChatGPT-User/1.0; +https://openai.com/bot',
  GPTBot: 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.2; +https://openai.com/gptbot',
  'Claude-SearchBot': 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Claude-SearchBot/1.0; +https://www.anthropic.com)',
  'Claude-User': 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Claude-User/1.0; +https://www.anthropic.com)',
  ClaudeBot: 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)',
  PerplexityBot: 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)',
  'Perplexity-User': 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Perplexity-User/1.0; +https://perplexity.ai/perplexity-user)',
};

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function get(url, ua = BROWSER_UA) {
  const started = Date.now();
  try {
    const res = await fetch(url, {
      headers: { 'User-Agent': ua, Accept: 'text/html,application/xhtml+xml,text/plain;q=0.9,*/*;q=0.8', 'Accept-Language': 'en-GB,en;q=0.9' },
      redirect: 'follow',
      signal: AbortSignal.timeout(25000),
    });
    const body = await res.text();
    return {
      code: res.status,
      url: res.url,
      type: res.headers.get('content-type') || '',
      server: res.headers.get('server') || '',
      cfRay: res.headers.get('cf-ray') || '',
      cfMitigated: res.headers.get('cf-mitigated') || '',
      body,
      ms: Date.now() - started,
      error: '',
    };
  } catch (e) {
    return { code: 0, url, type: '', server: '', cfRay: '', cfMitigated: '', body: '', ms: Date.now() - started, error: e.message };
  }
}

function looksLikeChallenge(r) {
  return (
    r.cfMitigated === 'challenge' ||
    /<title>\s*(Just a moment|Attention Required|Access denied|Checking your browser)/i.test(r.body) ||
    /cf-chl-|challenge-platform|captcha-delivery\.com|_Incapsula_Resource|sucuri_cloudproxy/i.test(r.body)
  );
}

const decode = (s) =>
  String(s)
    .replace(/<[^>]+>/g, ' ')
    .replace(/&nbsp;/g, ' ')
    .replace(/&amp;/g, '&')
    .replace(/&#0?39;|&#x27;|&rsquo;|&lsquo;/g, "'")
    .replace(/&quot;|&ldquo;|&rdquo;/g, '"')
    .replace(/&ndash;|&mdash;/g, '–')
    .replace(/&#(\d+);/g, (_, n) => String.fromCharCode(+n))
    .replace(/\s+/g, ' ')
    .trim();

function metaContent(html, attr, name) {
  for (const tag of html.match(/<meta\b[^>]*>/gi) || []) {
    const n = tag.match(new RegExp(`${attr}\\s*=\\s*["']([^"']+)["']`, 'i'));
    if (n && n[1].toLowerCase() === name) {
      const c = tag.match(/content\s*=\s*"([^"]*)"|content\s*=\s*'([^']*)'/i);
      return c ? decode(c[1] ?? c[2]) : '';
    }
  }
  return null;
}

export function parseHtml(html) {
  const types = new Set();
  const business = [];
  let blocks = 0;
  let errors = 0;
  for (const m of html.matchAll(/<script[^>]+application\/ld\+json[^>]*>([\s\S]*?)<\/script>/gi)) {
    blocks++;
    let data;
    try {
      data = JSON.parse(m[1].trim());
    } catch {
      errors++;
      continue;
    }
    const nodes = Array.isArray(data) ? data : data['@graph'] ? data['@graph'] : [data];
    for (const node of nodes.flatMap((n) => (n && n['@graph'] ? n['@graph'] : [n]))) {
      for (const t of [].concat(node?.['@type'] || [])) types.add(String(t));
      if (node?.address || node?.areaServed) business.push(node);
    }
  }
  const h1 = [...html.matchAll(/<h1\b[^>]*>([\s\S]*?)<\/h1>/gi)].map((m) => ({ text: decode(m[1]), image: /<(img|svg)\b/i.test(m[1]) }));
  const title = (html.match(/<title[^>]*>([\s\S]*?)<\/title>/i) || [])[1];
  const robots = metaContent(html, 'name', 'robots') || '';
  const b = business[0] || {};
  const addr = Array.isArray(b.address) ? b.address[0] : b.address || {};
  return {
    schema_types: [...types],
    schema_blocks: blocks,
    schema_errors: errors,
    meta_description: metaContent(html, 'name', 'description') || '',
    og_site_name: metaContent(html, 'property', 'og:site_name') || '',
    title: title ? decode(title) : '',
    noindex: /noindex/i.test(robots),
    h1,
    google_verify: metaContent(html, 'name', 'google-site-verification') !== null,
    bing_verify: metaContent(html, 'name', 'msvalidate.01') !== null,
    schema_business: b.name ? { name: b.name, locality: addr.addressLocality || '', type: b['@type'] } : null,
  };
}

export async function externalChecks(base, log = () => {}) {
  const site = base.replace(/\/+$/, '') + '/';
  const out = { site, checked: new Date().toISOString() };

  log('Fetching homepage');
  const home = await get(site);
  out.homepage = { code: home.code, url: home.url, ms: home.ms, error: home.error, server: home.server, cloudflare: !!home.cfRay || /cloudflare/i.test(home.server), challenge: looksLikeChallenge(home), bytes: home.body.length, ...parseHtml(home.body) };

  log('Fetching robots.txt');
  const robots = await get(site + 'robots.txt');
  const robotsOk = robots.code === 200 && !/<html/i.test(robots.body.slice(0, 500));
  const content = robotsOk ? robots.body : '';
  out.robots = {
    code: robots.code,
    content,
    cloudflareManaged: /BEGIN Cloudflare Managed|Content-Signal:/i.test(content),
    sitemaps: sitemapsIn(content),
    bots: Object.fromEntries(Object.keys(BOTS).map((b) => [b, robotsAllows(content, b, '/')])),
  };

  log('Fetching llms.txt');
  const llms = await get(site + 'llms.txt');
  const llmsOk = llms.code === 200 && !/html/i.test(llms.type) && llms.body.trimStart().startsWith('#');
  out.llms = { code: llms.code, ok: llmsOk, type: llms.type, lines: llmsOk ? llms.body.split('\n').length : 0, preview: llmsOk ? llms.body.slice(0, 1500) : '' };

  log('Checking sitemap');
  const candidates = [...out.robots.sitemaps, site + 'wp-sitemap.xml', site + 'sitemap_index.xml', site + 'sitemap.xml'];
  out.sitemap = { url: '', code: 0 };
  for (const u of [...new Set(candidates)]) {
    const r = await get(u);
    if (r.code === 200 && /<(sitemapindex|urlset)/i.test(r.body)) {
      out.sitemap = { url: r.url, code: 200 };
      break;
    }
  }

  // Same homepage, announced as each AI crawler. A firewall that knows the real crawlers' addresses
  // may treat them differently from this test, so a difference means "may be blocking", not "is".
  out.botAccess = {};
  for (const [bot, ua] of Object.entries(BOT_UAS)) {
    log(`Visiting as ${bot}`);
    await sleep(700);
    const r = await get(site, ua);
    const challenged = looksLikeChallenge(r);
    const blocked = r.code === 0 || r.code === 401 || r.code === 403 || r.code === 406 || r.code === 429 || r.code >= 500 || challenged;
    out.botAccess[bot] = { code: r.code, challenged, blocked: home.code === 200 && blocked, error: r.error, bytes: r.body.length };
  }
  return out;
}
