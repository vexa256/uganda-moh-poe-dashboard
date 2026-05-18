// Loads the disease engine (Diseases.js + Diseases_intelligence.js + exposures.js)
// into a Node global `window` so `window.DISEASES` and `window.EXPOSURES` are
// available to baseline / test scripts. The engine files are
// browser-style (no exports — they mutate `window`).

import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const require = createRequire(import.meta.url);
const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(__dirname, '..', '..', '..');

// Provide the browser-shaped globals the engine touches.
globalThis.window = globalThis.window || {};

// Silence noisy banner console.log calls while loading.
const _origLog = console.log;
console.log = () => {};
try {
  require(path.join(repoRoot, 'src/Diseases.js'));
  require(path.join(repoRoot, 'src/Diseases_intelligence.js'));
  require(path.join(repoRoot, 'src/exposures.js'));
} finally {
  console.log = _origLog;
}

if (!globalThis.window.DISEASES) {
  throw new Error('bootstrap-window.mjs: window.DISEASES not loaded');
}
if (typeof globalThis.window.DISEASES.getEnhancedScoreResult !== 'function') {
  throw new Error('bootstrap-window.mjs: getEnhancedScoreResult not present — intelligence layer missing');
}

export const window = globalThis.window;
