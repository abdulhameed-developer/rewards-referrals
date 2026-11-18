import { readdirSync, readFileSync } from 'node:fs';
import { join, relative, resolve } from 'node:path';
import { createHash } from 'node:crypto';
import { spawnSync } from 'node:child_process';

// Static inspection only. Never import/require/bootstrap plugin or WordPress files.
const root = process.cwd();
function fail(message) { throw new Error(message); }
function assert(condition, message) { if (!condition) fail(message); }
const branch = process.env.RR_BRANCH_NAME;
assert(!branch || branch === 'main' || (/^(fix|hotfix|maintenance|test|docs|release|security)\/[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/.test(branch) && !branch.includes('..')), 'Use the documented descriptive branch naming policy.');
const paths = [];
const privateParts = new Set(['engineering', 'outputs', 'node_modules', '__pycache__', '__tests__']);
function walk(dir) {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    if (dir === root && entry.name === '.git') continue;
    const absolute = join(dir, entry.name);
    const name = relative(root, absolute).split('\\').join('/');
    assert(!entry.isSymbolicLink(), `Symlink is outside the public source policy: ${name}`);
    assert(!privateParts.has(entry.name), `Private/generated path in the public tree: ${name}`);
    assert(!/(?:^|\/)(?:\.env(?:\..*)?|wp-config\.php)$/.test(name), `Private configuration path: ${name}`);
    assert(!/\.(?:sql|sqlite|sqlite3|pem|key|p12|pfx)$/i.test(name), `Private dump/key file type: ${name}`);
    assert(!name.includes('__premium_only'), `Premium implementation path in Free source: ${name}`);
    if (entry.isDirectory()) walk(absolute);
    else { assert(entry.isFile(), `Unsupported file type: ${name}`); paths.push(name); }
  }
}
walk(root);
const text = name => readFileSync(join(root, name), 'utf8');
const main = text('rewards-referrals.php');
const readme = text('readme.txt');
const manifest = JSON.parse(text('RELEASE-MANIFEST.json'));
const version = main.match(/^ \* Version:\s*(\S+)\s*$/m)?.[1];
assert(version && manifest.version === version, 'Plugin header and release manifest versions differ.');
assert(main.includes(`define( 'REWARDS_REFERRALS_VERSION', '${version}' );`), 'Internal version differs from the plugin header.');
assert(readme.match(/^Stable tag:\s*(\S+)\s*$/m)?.[1] === version, 'WordPress Stable tag differs from the plugin header.');
assert(manifest.edition === 'Free' && manifest.plugin_basename === 'rewards-referrals/rewards-referrals.php' && manifest.text_domain === 'rewards-referrals', 'Free technical identity changed.');
assert(/'is_premium'\s*=>\s*false\s*,/.test(main), 'Public bootstrap must select the Free edition.');
assert(!/'is_premium'\s*=>\s*true\s*,/.test(main), 'Premium bootstrap is forbidden in the Free tree.');
assert(/^ \* Text Domain: rewards-referrals\s*$/m.test(main), 'Text domain differs from installed identity.');
assert(/^ \* License: GPL-2\.0-or-later\s*$/m.test(main), 'Plugin license header changed.');
for (const required of ['LICENSE', 'THIRD-PARTY-NOTICES.txt', 'SECURITY.md']) assert(paths.includes(required), `Required redistribution/documentation file missing: ${required}`);
const hashes = manifest.source_file_sha256;
const requiredFrozenPaths = [
  'README.md',
  'includes/class-br-admin.php',
  'includes/class-br-frontend.php',
  'includes/class-br-ledger.php',
  'includes/class-br-woocommerce.php',
  'includes/class-rr-recovery.php',
  'languages/rewards-referrals.pot',
  'readme.txt',
  'rewards-referrals.php',
];
assert(hashes && typeof hashes === 'object' && Object.getPrototypeOf(hashes) === Object.prototype && Object.keys(hashes).length > 0 && requiredFrozenPaths.every(name => Object.prototype.hasOwnProperty.call(hashes, name)), 'Declared frozen source hashes must be a plain nonempty object containing every required critical path.');
for (const [name, expected] of Object.entries(hashes)) {
  assert(typeof expected === 'string' && /^[a-f0-9]{64}$/.test(expected), `Invalid declared SHA-256: ${name}`);
  assert(!name.startsWith('/') && !name.split('/').includes('..'), 'Manifest path must stay inside the repository.');
  const actual = createHash('sha256').update(readFileSync(join(root, name))).digest('hex');
  assert(actual === expected, `Declared frozen source hash differs: ${name}`);
}
// Strong-token indicators only; report file names, never matched credential values.
const indicators = [
  /\bgh[pousr]_[A-Za-z0-9]{25,}\b/,
  /\bgithub_pat_[A-Za-z0-9_]{35,}\b/,
  /-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/,
  /\b(?:sk_live|rk_live)_[A-Za-z0-9]{16,}\b/,
  /\b(?:AKIA|ASIA)[A-Z0-9]{16}\b/,
];
for (const name of paths) {
  const data = readFileSync(join(root, name));
  if (data.includes(0)) continue; // Image/binary assets are not a text-secret scan.
  const dataText = data.toString('utf8');
  assert(!indicators.some(pattern => pattern.test(dataText)), `Strong credential indicator in public text: ${name}`);
}
const php = paths.filter(name => name.endsWith('.php') && !name.startsWith('vendor/'));
const js = paths.filter(name => name.endsWith('.js') && !name.startsWith('vendor/'));
function syntax(command, args, name) {
  const result = spawnSync(command, [...args, resolve(root, name)], { encoding: 'utf8', timeout: 20000 });
  assert(!result.error && result.status === 0, `Syntax validation failed: ${name}`);
}
for (const name of php) syntax('php', ['-n', '-l'], name);
for (const name of js) syntax(process.execPath, ['--check'], name);
console.log(JSON.stringify({ status: 'PASS', branch: branch || '(local unspecified)', version, edition: manifest.edition, first_party_php_syntax_files: php.length, first_party_js_syntax_files: js.length, declared_frozen_hashes: Object.keys(manifest.source_file_sha256).length, public_files: paths.length, PHP_syntax_only: true, plugin_executed: false, scope: 'Static boundaries, selected frozen hashes and first-party syntax; not runtime/accounting/WordPress.org acceptance or complete secret detection.' }, null, 2));
