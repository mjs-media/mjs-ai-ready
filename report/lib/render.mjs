// The branded report: one self-contained HTML file (fonts embedded) that Chrome prints to an A4 PDF.
// MJS Websites tokens: ink #111, cyan #00CFFF, Anton (headings) and Inter (text). No phone numbers anywhere.

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { SECTIONS, COPY } from './checks.mjs';
import { BOTS } from './robots.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const font = (f) => readFileSync(path.join(here, '../assets/fonts', f)).toString('base64');

const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch]);

// Just enough markdown for quoted AI answers: paragraphs, bullet lists, bold. Everything is escaped first.
function mdLite(text) {
  const inline = (t) => esc(t.replace(/^#+\s*/, '')).replace(/\*\*(.+?)\*\*/g, '<b>$1</b>').replace(/\*\*/g, '');
  let html = '';
  let list = false;
  for (const raw of String(text).split(/\n/)) {
    const line = raw.trim();
    const bullet = line.match(/^[-*•]\s+(.*)$/);
    if (bullet) {
      if (!list) html += '<ul>';
      list = true;
      html += `<li>${inline(bullet[1])}</li>`;
      continue;
    }
    if (list) html += '</ul>';
    list = false;
    if (line) html += `<p>${inline(line)}</p>`;
  }
  return html + (list ? '</ul>' : '');
}
const LABEL = { pass: 'Good', warn: 'Worth fixing', fail: 'Needs fixing', info: 'Note' };
const pill = (s) => `<span class="pill is-${s}">${LABEL[s] || '—'}</span>`;
const date = (iso) => new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });

const MODES = {
  before: { kicker: 'AI readiness check', title: 'How AI search sees your website' },
  after: { kicker: 'AI Ready Upgrade: done', title: 'Your website is ready for AI search' },
  recheck: { kicker: 'Follow-up check', title: 'AI search, a few weeks on' },
};

function intro(mode, d) {
  const name = esc(d.business.name);
  if (mode === 'before')
    return `<p>People increasingly ask ChatGPT, Claude, Perplexity, Copilot and Google's AI answers for recommendations instead of scrolling through search results. Those tools can only describe ${name} accurately if they can reach your website and understand it.</p><p>We checked three things: whether search engines and AI tools are allowed in and can load your pages, whether your site explains the business in a form they can read, and what AI search says about you today.</p>`;
  if (mode === 'after')
    return `<p>We've made the changes from your AI readiness check. This report checks the same things again so you can see what's different. Search engines and AI tools now find clear, consistent facts about ${name} on your website.</p><p>The changes on your site are instant, but AI tools refresh what they know on their own timetable, usually over several weeks. We'll check again in 4–6 weeks.</p>`;
  return `<p>It's been a few weeks since we made ${name}'s website AI ready. We've run the same checks again and asked AI search the same questions, so you can compare.</p>`;
}

function aiSection(ai, before) {
  if (!ai || ai.skipped || !ai.results?.length) return '';
  const prev = (q) => before?.ai?.results?.find((r) => r.text === q.text);
  const rows = ai.results
    .map((r) => {
      const p = prev(r);
      const yesno = (v) => (v ? '<b class="yes">Yes</b>' : '<b class="no">No</b>');
      const answer = r.answer ? mdLite(r.answer.length > 1600 ? r.answer.slice(0, 1580).replace(/\s+\S*$/, '') + '…' : r.answer) : `<em>${esc(r.error || 'No answer.')}</em>`;
      return `<div class="qa">
        <div class="q">“${esc(r.text)}”</div>
        <div class="a">${answer}</div>
        <div class="signals"><span>Mentions you: ${yesno(r.mentioned)}${p ? ` <i>(before: ${p.mentioned ? 'yes' : 'no'})</i>` : ''}</span><span>Points to your website: ${yesno(r.citedSite)}${p ? ` <i>(before: ${p.citedSite ? 'yes' : 'no'})</i>` : ''}</span><span>Your website came up in its search: ${yesno(r.foundSite)}${p ? ` <i>(before: ${p.foundSite ? 'yes' : 'no'})</i>` : ''}</span></div>
      </div>`;
    })
    .join('');
  return `<section class="block">
    <h2>What AI search says about you${before ? ' now' : ' today'}</h2>
    <p class="lead">We asked Claude, with live web search, the kind of questions a customer might ask. Other AI tools work in a similar way but each has its own sources, and answers change from day to day, so treat this as a snapshot.</p>
    ${rows}
    <p class="small">Asked ${date(ai.asked)}. Phone numbers are removed from quoted answers.</p>
  </section>`;
}

function checksSection(checks, before) {
  const prev = (id) => before?.checks?.find((x) => x.id === id);
  return Object.entries(SECTIONS)
    .map(([key, heading]) => {
      const rows = checks.filter((x) => x.section === key);
      if (!rows.length) return '';
      return `<section class="block"><h2>${esc(heading)}</h2>
      <table class="checks">${before ? '<thead><tr><th>Before</th><th>Now</th><th></th></tr></thead>' : ''}<tbody>
      ${rows
        .map((x) => {
          const p = prev(x.id);
          return `<tr>${before ? `<td class="st">${p ? pill(p.status) : '<span class="pill">—</span>'}</td>` : ''}<td class="st">${pill(x.status)}</td>
          <td><div class="t">${esc(x.title)}</div><div class="d">${esc(x.detail)}</div><div class="w">${esc(x.why)}</div></td></tr>`;
        })
        .join('')}
      </tbody></table></section>`;
    })
    .join('');
}

function crawlerTable(ext) {
  const rows = Object.entries(BOTS)
    .map(([bot, info]) => {
      const r = ext.robots.bots[bot];
      const v = ext.botAccess[bot];
      const robots = !ext.robots.content ? 'No file (allowed)' : r.allowed ? 'Allowed' : '<b class="no">Asked to stay out</b>';
      const visit = !v ? '<span class="muted">Not tested</span>' : v.blocked ? `<b class="no">May be blocked</b> (${v.challenged ? 'security check' : 'code ' + v.code})` : 'Loaded normally';
      return `<tr><td><b>${esc(bot)}</b><br><span class="muted">${esc(info.owner)}: ${esc(info.label)}</span></td><td>${info.kind === 'search' ? 'Search / answers' : 'Model training'}</td><td>${robots}</td><td>${visit}</td></tr>`;
    })
    .join('');
  return `<section class="block avoid"><h2>Crawler by crawler</h2>
  <p class="lead">“Search / answers” crawlers fetch pages to show them in search results and AI answers. “Model training” crawlers collect pages to train future AI models; turning those away is a business choice and doesn't stop you appearing in AI answers.</p>
  <table class="bots"><thead><tr><th>Crawler</th><th>Used for</th><th>Your robots.txt</th><th>Test visit</th></tr></thead><tbody>${rows}</tbody></table></section>`;
}

function nextSteps(mode, checks, before) {
  if (mode === 'before') {
    const todo = checks.filter((x) => x.status === 'fail' || x.status === 'warn');
    return `<section class="block avoid"><h2>What the AI Ready Upgrade does</h2>
    <p class="lead">A one-off upgrade we carry out on your existing WordPress site. Nothing about its look changes.</p>
    <ul class="ticks">
      <li><b>Welcomes search engines and AI search tools</b> with clear crawler rules, and you choose whether AI training crawlers are allowed.</li>
      <li><b>Spells out your business</b> in structured data on every page: what you do, where, the areas you cover, opening hours and how to get in touch.</li>
      <li><b>Lists your services and answers common questions</b> in a way search engines and AI tools can read.</li>
      <li><b>Adds a plain-text summary (llms.txt)</b> for the AI tools that read it.</li>
      <li><b>Sets up Google Search Console and Bing Webmaster Tools</b>, submits your sitemap, and tells Bing about updates as you make them.</li>
      <li><b>Checks for anything blocking AI tools</b>, such as a firewall or Cloudflare setting, and explains what to change.</li>
      <li><b>Re-checks in 4–6 weeks</b> with a follow-up report like this one.</li>
    </ul>
    ${todo.length ? `<h3>On your site specifically</h3><ul class="dots">${todo.map((x) => `<li><b>${esc(x.title)}:</b> ${esc(x.detail)}</li>`).join('')}</ul>` : ''}
    </section>`;
  }
  const improved = before ? checks.filter((x) => x.status === 'pass' && ['warn', 'fail'].includes(before.checks.find((b) => b.id === x.id)?.status)) : [];
  const still = checks.filter((x) => x.status === 'fail' || x.status === 'warn');
  return `<section class="block avoid"><h2>${mode === 'after' ? 'What we changed' : 'What has changed'}</h2>
    ${improved.length ? `<ul class="ticks">${improved.map((x) => `<li><b>${esc(x.title)}</b></li>`).join('')}</ul>` : before ? '<p>No changes in the checks since last time.</p>' : '<p>No earlier report to compare with.</p>'}
    ${still.length ? `<h3>Still worth attention</h3><ul class="dots">${still.map((x) => `<li><b>${esc(x.title)}:</b> ${esc(x.detail)}</li>`).join('')}</ul>` : ''}
    ${mode === 'after' ? '<h3>What happens next</h3><p>Google, Bing and the AI tools revisit your site on their own schedules. New details usually start to show in AI answers over several weeks. We\'ll run this check again in 4–6 weeks and send you the comparison.</p>' : ''}
  </section>`;
}

const CAVEATS = `<section class="block caveats avoid"><h2>What this report can and can't tell you</h2><ul class="dots">
  <li>Nobody can promise search rankings, or that an AI tool will recommend a particular business. These tools decide for themselves what to show. What we can do is make sure they can reach your site and get the facts right.</li>
  <li>AI answers change from day to day and differ between tools. Changes to your site usually take several weeks to show up in them.</li>
  <li>llms.txt is a proposed standard. Some AI tools read it and others don't yet.</li>
  <li>The test visits borrow each AI tool's name. Real crawlers come from known addresses that firewalls may treat differently, so a "may be blocked" result means it's worth checking, not that it's certain.</li>
  <li>If your site uses Cloudflare, its AI-bot blocking is changed in the Cloudflare dashboard, not in WordPress.</li>
</ul></section>`;

export function renderHtml(d) {
  const m = MODES[d.mode];
  const s = d.summary;
  const css = `
@font-face{font-family:Anton;src:url(data:font/woff2;base64,${font('anton-400.woff2')}) format('woff2');font-weight:400}
@font-face{font-family:Inter;src:url(data:font/woff2;base64,${font('inter-var.woff2')}) format('woff2');font-weight:100 900}
:root{--ink:#111;--cyan:#00CFFF;--paper:#fff;--muted:#5b6168;--line:#e3e6ea;--pass:#0a7a3e;--pass-bg:#dff6e8;--warn:#7a5600;--warn-bg:#fff1cc;--fail:#9c1c1c;--fail-bg:#fde0e0;--info:#24425f;--info-bg:#e5eef7}
@page{size:A4;margin:16mm 15mm 18mm}
*{box-sizing:border-box}
body{margin:0;background:var(--paper);color:var(--ink);font:400 10.5pt/1.5 Inter,system-ui,sans-serif;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.wrap{max-width:820px;margin:0 auto;padding:24px 16px}
@media print{.wrap{padding:0;max-width:none}}
h1,h2{font-family:Anton,Impact,sans-serif;font-weight:400;letter-spacing:.01em;text-transform:uppercase;line-height:1.05}
h2{font-size:19pt;margin:0 0 8px}
h3{font-size:11pt;margin:18px 0 6px}
.cover{background:var(--ink);color:#fff;border-radius:6px;padding:28px 30px 26px;margin-bottom:22px}
.brand{font-family:Anton,Impact,sans-serif;font-size:13pt;letter-spacing:.06em;text-transform:uppercase}
.brand span{color:var(--cyan)}
.kicker{color:var(--cyan);font-weight:700;text-transform:uppercase;letter-spacing:.08em;font-size:9pt;margin-top:34px}
.cover h1{font-size:34pt;margin:6px 0 10px;color:#fff}
.meta{color:#c9ced4;font-size:10pt}
.meta b{color:#fff;font-weight:600}
.tiles{display:flex;gap:10px;margin-top:22px}
.tile{flex:1;background:#1e1e1e;border-radius:5px;padding:10px 12px}
.tile b{display:block;font-family:Anton,Impact,sans-serif;font-size:24pt;font-weight:400;line-height:1}
.tile.pass b{color:#5fe39a}.tile.warn b{color:#ffd166}.tile.fail b{color:#ff7a7a}
.tile span{font-size:9pt;color:#c9ced4}
.intro p{font-size:11pt;margin:0 0 10px}
.block{margin:26px 0 0;break-inside:auto}
.avoid{break-inside:avoid}
.lead{color:var(--muted);margin:0 0 12px}
.small,.muted{color:var(--muted);font-size:9pt}
table{width:100%;border-collapse:collapse}
.checks td{border-top:1px solid var(--line);padding:9px 6px;vertical-align:top}
.checks tr{break-inside:avoid}
.checks th{font-size:8.5pt;color:var(--muted);text-align:left;font-weight:600;padding:0 6px 4px}
.st{width:104px}
.t{font-weight:700}
.d{margin-top:2px}
.w{margin-top:3px;color:var(--muted);font-size:9pt}
.pill{display:inline-block;padding:2px 8px;border-radius:20px;font-size:8.5pt;font-weight:700;white-space:nowrap;background:#eee;color:#555}
.pill.is-pass{background:var(--pass-bg);color:var(--pass)}.pill.is-warn{background:var(--warn-bg);color:var(--warn)}.pill.is-fail{background:var(--fail-bg);color:var(--fail)}.pill.is-info{background:var(--info-bg);color:var(--info)}
.qa{border-left:4px solid var(--cyan);background:#f5fcff;padding:10px 14px;margin:0 0 12px;break-inside:avoid;border-radius:0 5px 5px 0}
.q{font-weight:700;font-size:11pt}
.a{margin:6px 0;font-size:10pt}.a p{margin:0 0 5px}.a ul{margin:0 0 6px;padding-left:18px}.a li{margin:0 0 3px}
.signals{display:flex;gap:18px;font-size:9pt;color:var(--muted)}
.yes{color:var(--pass)}.no{color:var(--fail)}
.bots th{text-align:left;font-size:8.5pt;color:var(--muted);padding:0 6px 4px}
.bots td{border-top:1px solid var(--line);padding:6px;font-size:9.5pt;vertical-align:top}
.bots tr{break-inside:avoid}
ul.ticks,ul.dots{padding-left:0;list-style:none;margin:8px 0}
ul.ticks li,ul.dots li{position:relative;padding-left:22px;margin:0 0 7px}
ul.ticks li::before{content:"✓";position:absolute;left:2px;color:#00a8d0;font-weight:800}
ul.dots li::before{content:"";position:absolute;left:6px;top:.62em;width:6px;height:6px;border-radius:50%;background:var(--cyan)}
.caveats{background:#f4f5f7;border-radius:6px;padding:16px 18px}
.caveats h2{font-size:15pt}
footer{margin-top:28px;border-top:3px solid var(--ink);padding-top:10px;display:flex;justify-content:space-between;gap:16px;font-size:9pt;color:var(--muted)}
footer b{color:var(--ink)}
@media (max-width:600px){.tiles{flex-wrap:wrap}.tile{flex:1 1 40%}.cover h1{font-size:26pt}.st{width:auto}}
`;
  return `<!doctype html><html lang="en-GB"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>${esc(d.business.name)}: ${esc(m.title)}</title><style>${css}</style></head><body><div class="wrap">
<header class="cover">
  <div class="brand">MJS <span>Websites</span></div>
  <div class="kicker">${esc(m.kicker)}</div>
  <h1>${esc(m.title)}</h1>
  <div class="meta"><b>${esc(d.business.name)}</b> · ${esc(d.site.replace(/^https?:\/\//, '').replace(/\/$/, ''))} · ${date(d.generated)}${d.before ? ` · compared with ${date(d.before.generated)}` : ''}</div>
  <div class="tiles">
    <div class="tile pass"><b>${s.pass}</b><span>Good</span></div>
    <div class="tile warn"><b>${s.warn}</b><span>Worth fixing</span></div>
    <div class="tile fail"><b>${s.fail}</b><span>Need fixing</span></div>
  </div>
</header>
<div class="intro">${intro(d.mode, d)}</div>
${aiSection(d.ai, d.before)}
${checksSection(d.checks, d.before)}
${crawlerTable(d.external)}
${nextSteps(d.mode, d.checks, d.before)}
${CAVEATS}
<footer><div><b>MJS Websites</b> · part of MJS Media · mjswebsites.co.uk</div><div>Questions? Get in touch through the contact form at mjswebsites.co.uk/contact or on WhatsApp.</div></footer>
</div></body></html>`;
}

export { COPY };
