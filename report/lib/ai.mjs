// What AI search says about the business: a few customer-style questions to Claude with web search.
// The API key stays on this Mac (env var or macOS keychain); nothing goes to client servers.

import Anthropic from '@anthropic-ai/sdk';
import { execFileSync } from 'node:child_process';

export const KEYCHAIN_SERVICE = 'mjs-ai-ready-anthropic';
const MODEL = 'claude-opus-5-5';

export function apiKey() {
  if (process.env.MJS_ANTHROPIC_API_KEY) return process.env.MJS_ANTHROPIC_API_KEY;
  if (process.env.ANTHROPIC_API_KEY) return process.env.ANTHROPIC_API_KEY;
  try {
    return execFileSync('security', ['find-generic-password', '-s', KEYCHAIN_SERVICE, '-w'], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();
  } catch {
    return '';
  }
}

const SYSTEM = `You are answering as an AI search assistant would for a member of the public in the UK.
Search the web, then answer the question directly and naturally in under 150 words, the way you would for a customer.
Only state what you found. If you can't find the business, or aren't sure it's the right one, say so plainly rather than guessing.
Do not include phone numbers in your answer.`;

// UK-style phone numbers out of quoted answers: MJS reports never show phone numbers.
export function redactPhones(text) {
  return String(text).replace(/(?:\+44\s?\(?0?\)?|\(?0)[\d\s\-().]{8,16}\d/g, (m) => {
    const digits = m.replace(/\D/g, '');
    return digits.length >= 10 && digits.length <= 13 ? '[phone number]' : m;
  });
}

export function buildQuestions({ business, town, services }) {
  const where = town ? ` in ${town}` : '';
  const qs = [{ id: 'what', text: `What does ${business}${where} do?` }];
  for (const s of services.slice(0, 2)) {
    qs.push({ id: 'recommend', text: `Who would you recommend for ${s.toLowerCase()}${where}?` });
  }
  return qs;
}

function norm(s) {
  return String(s)
    .toLowerCase()
    .replace(/&/g, 'and')
    .replace(/\b(ltd|limited|llp|plc|the)\b/g, '')
    .replace(/[^a-z0-9]+/g, ' ')
    .trim();
}

async function ask(client, question, town) {
  const messages = [{ role: 'user', content: question }];
  const tools = [{ type: 'web_search_20260209', name: 'web_search', max_uses: 5, user_location: { type: 'approximate', country: 'GB', ...(town ? { city: town } : {}) } }];
  let response;
  const blocks = [];
  for (let turn = 0; turn < 4; turn++) {
    response = await client.beta.messages.create({
      model: MODEL,
      max_tokens: 8000,
      system: SYSTEM,
      output_config: { effort: 'medium' },
      betas: ['server-side-fallback-2026-07-01'],
      fallbacks: 'default',
      tools,
      messages,
    });
    blocks.push(...response.content);
    if (process.env.MJSAIR_DEBUG) console.error('turn', turn, response.stop_reason, response.content.map((b) => b.type + (b.citations ? `(${b.citations.length})` : '')).join(' '));
    if (response.stop_reason !== 'pause_turn') break;
    messages.push({ role: 'assistant', content: response.content });
  }
  if (response.stop_reason === 'refusal') {
    return { answer: '', cited: [], found: [], error: 'The model declined to answer this question.' };
  }
  const answer = response.content.filter((b) => b.type === 'text').map((b) => b.text).join('').trim();
  const cited = new Set();
  const found = new Set();
  for (const b of blocks) {
    if (b.type === 'text') for (const c of b.citations || []) if (c.url) cited.add(c.url);
    if (b.type === 'web_search_tool_result' && Array.isArray(b.content)) for (const r of b.content) if (r.url) found.add(r.url);
  }
  return { answer, cited: [...cited], found: [...found], model: response.model, usage: response.usage };
}

export async function aiChecks({ business, town, services, site }, log = () => {}) {
  const key = apiKey();
  if (!key) {
    return { skipped: `No Anthropic API key. Store one with: security add-generic-password -a mjs -s ${KEYCHAIN_SERVICE} -w` };
  }
  // Talk to the real API even if this shell points ANTHROPIC_BASE_URL somewhere else.
  const client = new Anthropic({ apiKey: key, baseURL: process.env.MJS_ANTHROPIC_BASE_URL || 'https://api.anthropic.com' });
  const host = new URL(site).hostname.replace(/^www\./, '');
  const name = norm(business);
  const results = [];
  for (const q of buildQuestions({ business, town, services })) {
    log(`Asking: ${q.text}`);
    try {
      const r = await ask(client, q.text, town);
      const onSite = (u) => {
        try {
          return new URL(u).hostname.replace(/^www\./, '') === host;
        } catch {
          return false;
        }
      };
      results.push({
        ...q,
        answer: redactPhones(r.answer),
        mentioned: !!name && norm(r.answer).includes(name),
        // With dynamic filtering the model reads results through code execution, so text often has no
        // citations: also count the website as linked when the answer names its address.
        citedSite: r.cited.some(onSite) || r.answer.toLowerCase().includes(host),
        foundSite: r.found.some(onSite),
        sources: (r.cited.length ? r.cited : r.found).slice(0, 8),
        error: r.error || '',
        model: r.model,
      });
    } catch (e) {
      results.push({ ...q, answer: '', mentioned: false, citedSite: false, foundSite: false, sources: [], error: e.message });
    }
  }
  return { model: MODEL, asked: new Date().toISOString(), results };
}
