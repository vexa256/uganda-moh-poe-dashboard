#!/usr/bin/env node
/**
 * Build guard for the Laravel-side Vite bundle.
 *
 * Why this exists:
 *   Server admins (e.g. the Uganda MoH ops team at poes.health.go.ug)
 *   who don't have Node installed kept running `npm run build` after
 *   `git pull`, getting confused by ERR_MODULE_NOT_FOUND on
 *   laravel-vite-plugin. They don't need to run it — `public/build/`
 *   is committed.
 *
 * Behaviour:
 *   • Pre-built bundle present  → print a friendly message and exit 0.
 *   • Bundle missing OR --force → delegate to `vite build`.
 *
 *   --force / FORCE_BUILD=1 lets actual developers rebuild on demand.
 *
 * This file uses ONLY Node built-ins (no node_modules required) so the
 * fast-path works on a machine that has never run npm install.
 */

import { existsSync, statSync } from 'node:fs';
import { spawn } from 'node:child_process';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const apiRoot   = resolve(__dirname, '..');
const manifest  = resolve(apiRoot, 'public/build/manifest.json');

const force =
  process.argv.includes('--force') ||
  process.env.FORCE_BUILD === '1';

const c = {
  green:  s => `\x1b[32m${s}\x1b[0m`,
  yellow: s => `\x1b[33m${s}\x1b[0m`,
  blue:   s => `\x1b[34m${s}\x1b[0m`,
};

if (!force && existsSync(manifest) && statSync(manifest).size > 10) {
  console.log(c.green('✓ Pre-built Vite bundle already present at api/public/build/'));
  console.log('  Manifest:        ' + manifest);
  console.log('  Server admins:   no action needed — Laravel will load these assets.');
  console.log(c.yellow('  To force a rebuild (dev only): npm run build -- --force'));
  console.log('                                 OR  FORCE_BUILD=1 npm run build');
  process.exit(0);
}

console.log(c.blue('→ Building Vite bundle (no committed bundle, or --force given)…'));

const child = spawn('npx', ['--no-install', 'vite', 'build'], {
  stdio: 'inherit',
  cwd: apiRoot,
  shell: process.platform === 'win32',
});

child.on('error', (err) => {
  console.error(c.yellow('\n✘ Could not invoke vite. Is node_modules installed?'));
  console.error('  Run:  npm ci    (or)    npm install');
  console.error('  Then: npm run build -- --force');
  console.error('\nUnderlying error:', err.message);
  process.exit(1);
});

child.on('exit', (code) => process.exit(code ?? 1));
