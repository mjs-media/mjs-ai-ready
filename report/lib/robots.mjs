// Crawler list and robots.txt reader. Keep in step with inc/bots.php.

export const BOTS = {
  Googlebot: { owner: 'Google', kind: 'search', label: 'Google Search' },
  Bingbot: { owner: 'Microsoft', kind: 'search', label: 'Bing, Copilot and ChatGPT search results' },
  'OAI-SearchBot': { owner: 'OpenAI', kind: 'search', label: 'ChatGPT search' },
  'ChatGPT-User': { owner: 'OpenAI', kind: 'search', label: 'ChatGPT visiting a page for someone' },
  GPTBot: { owner: 'OpenAI', kind: 'training', label: 'OpenAI model training' },
  PerplexityBot: { owner: 'Perplexity', kind: 'search', label: 'Perplexity search' },
  'Perplexity-User': { owner: 'Perplexity', kind: 'search', label: 'Perplexity visiting a page for someone' },
  ClaudeBot: { owner: 'Anthropic', kind: 'training', label: 'Anthropic model training' },
  'Claude-SearchBot': { owner: 'Anthropic', kind: 'search', label: 'Claude search' },
  'Claude-User': { owner: 'Anthropic', kind: 'search', label: 'Claude visiting a page for someone' },
  'Google-Extended': { owner: 'Google', kind: 'training', label: 'Gemini model training (not Google Search)' },
  Applebot: { owner: 'Apple', kind: 'search', label: 'Siri and Spotlight' },
  'Applebot-Extended': { owner: 'Apple', kind: 'training', label: 'Apple model training' },
  DuckDuckBot: { owner: 'DuckDuckGo', kind: 'search', label: 'DuckDuckGo' },
};

export function parseRobots(txt) {
  const groups = [];
  let current = null;
  let inUa = false;
  for (let line of String(txt || '').split(/\r\n|\r|\n/)) {
    line = line.replace(/#.*$/, '').trim();
    const i = line.indexOf(':');
    if (!line || i < 0) continue;
    const field = line.slice(0, i).trim().toLowerCase();
    const value = line.slice(i + 1).trim();
    if (field === 'user-agent') {
      if (!inUa) {
        current = { agents: [], rules: [] };
        groups.push(current);
      }
      current.agents.push(value.toLowerCase());
      inUa = true;
    } else if (field === 'allow' || field === 'disallow') {
      inUa = false;
      if (current) current.rules.push([field, value]);
    } else {
      inUa = false;
    }
  }
  return groups;
}

// Google's rules: most specific group, then longest matching path (Allow wins a tie).
export function robotsAllows(txt, bot, path = '/') {
  const groups = parseRobots(txt);
  const lc = bot.toLowerCase();
  let rules = [];
  let group = null;
  for (const g of groups) if (g.agents.includes(lc)) { rules = rules.concat(g.rules); group = bot; }
  if (!group) for (const g of groups) if (g.agents.includes('*')) { rules = rules.concat(g.rules); group = '*'; }
  let best = -1;
  let allowed = true;
  for (const [type, p] of rules) {
    if (!p) continue;
    const re = new RegExp('^' + p.replace(/[.+?^${}()|[\]\\]/g, (m) => (m === '$' ? '$' : '\\' + m)).replace(/\*/g, '.*'));
    if (!re.test(path)) continue;
    if (p.length > best || (p.length === best && type === 'allow')) {
      best = p.length;
      allowed = type === 'allow';
    }
  }
  return { allowed, group };
}

export function sitemapsIn(txt) {
  return [...String(txt || '').matchAll(/^\s*sitemap:\s*(\S+)/gim)].map((m) => m[1]);
}
