// Run the plugin's audit on the server over SSH. Read-only: the audit only reads settings and makes GET requests.
// If the plugin isn't installed yet (a "before" report), the audit code is sent over STDIN to `wp eval-file -`
// and nothing is written to the server.

import { spawn } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const pluginDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');

function run(host, command, input = null) {
  return new Promise((resolve) => {
    const p = spawn('ssh', ['-o', 'BatchMode=yes', '-o', 'ConnectTimeout=15', host, command]);
    let out = '';
    let err = '';
    p.stdout.on('data', (d) => (out += d));
    p.stderr.on('data', (d) => (err += d));
    p.on('close', (code) => resolve({ code, out, err }));
    if (input !== null) p.stdin.end(input);
    else p.stdin.end();
  });
}

const q = (s) => `'${String(s).replace(/'/g, `'\\''`)}'`;

function firstJson(text) {
  const start = text.indexOf('{"audit_version"');
  if (start < 0) return null;
  try {
    return JSON.parse(text.slice(start).trim().split('\n')[0]);
  } catch {
    return null;
  }
}

export async function serverAudit(host, wpPath, { wp = 'wp', log = () => {} } = {}) {
  // Keep ~ working: quote everything after it.
  const dir = wpPath === '~' ? '"$HOME"' : wpPath.startsWith('~/') ? `"$HOME"/${q(wpPath.slice(2))}` : q(wpPath);
  const cd = `cd ${dir} && `;
  log(`SSH ${host}: wp ai-ready audit`);
  let r = await run(host, `${cd}${wp} ai-ready audit --format=json 2>/dev/null`);
  let audit = firstJson(r.out);
  if (audit) return { audit, via: 'plugin' };

  log('Plugin not active there: sending the audit code over STDIN (nothing is written to the server)');
  const code =
    readFileSync(path.join(pluginDir, 'inc/bots.php'), 'utf8') +
    readFileSync(path.join(pluginDir, 'inc/audit.php'), 'utf8').replace(/^<\?php/, '') +
    "\necho wp_json_encode( mjsair_audit_run( true ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );\n";
  r = await run(host, `${cd}${wp} eval-file - 2>/dev/null`, code);
  audit = firstJson(r.out);
  if (audit) return { audit, via: 'eval-file' };
  throw new Error(`Could not run the audit on ${host}:${wpPath}\n${(r.err || r.out).slice(0, 800)}`);
}
