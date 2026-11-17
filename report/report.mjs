#!/usr/bin/env node
// MJS AI Ready report. Runs on Matt's Mac; read-only towards the client's site.
//
//   bin/report <ssh-host|-> <wp-path|-> <public-url> [options]
//
// See README.md → "The report".

import { mkdirSync, writeFileSync, readFileSync, readdirSync, existsSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { serverAudit } from './lib/ssh.mjs';
import { externalChecks } from './lib/external.mjs';
import { aiChecks } from './lib/ai.mjs';
import { buildChecks, summarise } from './lib/checks.mjs';
import { renderHtml } from './lib/render.mjs';

const HELP = `Usage: bin/report <ssh-host|-> <wp-path|-> <public-url> [options]

  ssh-host / wp-path   where WordPress lives, e.g. mjswebsites ~/public_html.
                       Use - - to make outside checks only (no SSH).
  public-url           e.g. https://www.example.co.uk

Options:
  --mode=before|after|recheck   default before
  --compare=<file.json>         earlier report to compare with (after/recheck default: the latest "before" for this site)
  --business="Name"             business name for the AI questions (default: from the site)
  --town="Town"                 town for the AI questions (default: from the site)
  --service="House removals"    a service to ask about (repeat for two)
  --no-ai                       skip the AI questions
  --no-pdf                      HTML and JSON only
  --wp="php ~/wp-cli.phar"      how to run WP-CLI on the server (default: wp)
  --out=<dir>                   output folder (default: mjs-ai-ready/reports/<domain>/)
`;

function parseArgs(argv) {
  const pos = [];
  const opt = { service: [] };
  for (const a of argv) {
    const m = a.match(/^--([a-z-]+)(?:=(.*))?$/);
    if (!m) pos.push(a);
    else if (m[1] === 'service') opt.service.push(m[2]);
    else opt[m[1]] = m[2] ?? true;
  }
  return { pos, opt };
}

const log = (s) => process.stderr.write(`  · ${s}\n`);

function chrome() {
  for (const p of ['/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/Applications/Chromium.app/Contents/MacOS/Chromium', '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge']) {
    if (existsSync(p)) return p;
  }
  return null;
}

async function main() {
  const { pos, opt } = parseArgs(process.argv.slice(2));
  if (opt.help || pos.length < 3) {
    process.stdout.write(HELP);
    process.exit(pos.length < 3 && !opt.help ? 1 : 0);
  }
  const [host, wpPath, rawUrl] = pos;
  const mode = opt.mode || 'before';
  if (!['before', 'after', 'recheck'].includes(mode)) throw new Error('--mode must be before, after or recheck');
  const site = new URL(/^https?:\/\//.test(rawUrl) ? rawUrl : `https://${rawUrl}`).origin + '/';
  const domain = new URL(site).hostname.replace(/^www\./, '');
  const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
  const outDir = path.resolve(opt.out || path.join(root, 'reports', domain));
  mkdirSync(outDir, { recursive: true });

  process.stderr.write(`MJS AI Ready report: ${site} (${mode})\n`);

  let audit = null;
  let via = 'none';
  if (host !== '-' && wpPath !== '-') {
    ({ audit, via } = await serverAudit(host, wpPath, { wp: opt.wp || 'wp', log }));
  } else {
    log('Outside checks only (no SSH)');
  }

  const ext = await externalChecks(site, log);

  const fromSite = audit?.facts?.business || {};
  const schemaBiz = ext.homepage.schema_business || {};
  const business = {
    name: opt.business || fromSite.name || schemaBiz.name || ext.homepage.og_site_name || ext.homepage.title.split(/\s[|–-]\s/)[0] || domain,
    town: opt.town || fromSite.locality || schemaBiz.locality || '',
    services: opt.service.length ? opt.service : fromSite.services || [],
  };
  if (!business.town) log('No town known: pass --town="…" for better AI questions');
  if (!business.services.length) log('No services known: pass --service="…" to ask who AI recommends');

  const ai = opt['no-ai'] ? { skipped: 'Skipped (--no-ai)' } : await aiChecks({ business: business.name, town: business.town, services: business.services, site }, log);
  if (ai.skipped) log(`AI questions: ${ai.skipped}`);

  let before = null;
  if (mode !== 'before') {
    let file = opt.compare;
    if (!file) {
      const prior = readdirSync(outDir).filter((f) => f.endsWith('-before.json')).sort();
      file = prior.length ? path.join(outDir, prior[prior.length - 1]) : null;
    }
    if (file) {
      before = JSON.parse(readFileSync(file, 'utf8'));
      log(`Comparing with ${path.basename(file)}`);
    } else {
      log('No earlier "before" report found to compare with');
    }
  }

  const checks = buildChecks({ ext, audit });
  const data = {
    tool: 'mjs-ai-ready-report',
    version: 1,
    mode,
    generated: new Date().toISOString(),
    site,
    business,
    auditVia: via,
    summary: summarise(checks),
    checks,
    ai,
    external: ext,
    audit,
    before: before ? { generated: before.generated, checks: before.checks, ai: before.ai } : null,
  };

  const stamp = data.generated.slice(0, 10);
  const base = path.join(outDir, `${stamp}-${mode}`);
  writeFileSync(`${base}.json`, JSON.stringify(data, null, 2));
  writeFileSync(`${base}.html`, renderHtml(data));
  log(`Saved ${base}.json and .html`);

  if (!opt['no-pdf']) {
    const bin = chrome();
    if (!bin) log('Chrome not found: no PDF (open the HTML and print to PDF)');
    else {
      execFileSync(bin, ['--headless=new', '--disable-gpu', '--no-pdf-header-footer', '--hide-scrollbars', `--print-to-pdf=${base}.pdf`, `file://${base}.html`], { stdio: 'ignore' });
      log(`Saved ${base}.pdf`);
    }
  }
  const s = data.summary;
  process.stderr.write(`Done: ${s.pass} good, ${s.warn} worth fixing, ${s.fail} need fixing.\n`);
  process.stdout.write(`${base}.pdf\n`);
}

main().catch((e) => {
  process.stderr.write(`Error: ${e.message}\n`);
  process.exit(1);
});
