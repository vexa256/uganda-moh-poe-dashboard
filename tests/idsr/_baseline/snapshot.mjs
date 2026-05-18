// Run the v3.x engine over the 200 baseline vignettes and persist the result.
//
// Default destination is `current.json` next to this script — but that file is
// FROZEN after Phase 0. To avoid accidentally clobbering it, the script now
// requires either:
//   --out=<path>     write to <path> (recommended for any phase ≥ 1)
//   --stdout         emit JSON to stdout
//   --force-current  explicit opt-in for the legacy behaviour (Phase 0 only).
//
// Phase 6 diffs `current.json` against a fresh snapshot written via `--out`.

import './bootstrap-window.mjs';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const vignettes = JSON.parse(fs.readFileSync(path.join(__dirname, 'vignettes.json'), 'utf8'));

const args = process.argv.slice(2);
const outArg = args.find((a) => a.startsWith('--out='));
const useStdout = args.includes('--stdout');
const forceCurrent = args.includes('--force-current');
const explicitOut = outArg ? outArg.slice('--out='.length) : null;

if (!explicitOut && !useStdout && !forceCurrent) {
  console.error(
    'snapshot.mjs: refusing to clobber the frozen `current.json`.\n' +
    'Pass --out=<path>, --stdout, or --force-current.'
  );
  process.exit(2);
}

const out = vignettes.map((v) => {
  let result;
  try {
    result = window.DISEASES.getEnhancedScoreResult(
      v.present_symptoms,
      v.absent_symptoms,
      v.exposure_engine_codes,
      v.visited_countries,
      v.vitals
    );
  } catch (err) {
    result = { __error: err.message, __stack: err.stack };
  }
  return { id: v.id, label: v.label, result };
});

const json = JSON.stringify(out, null, 2);

if (useStdout) {
  process.stdout.write(json + '\n');
} else {
  const outPath = explicitOut
    ? path.resolve(explicitOut)
    : path.join(__dirname, 'current.json');
  fs.writeFileSync(outPath, json);
  console.log('Baseline written:', out.length, 'vignettes →', outPath);
}

const errs = out.filter((x) => x.result && x.result.__error);
if (errs.length) {
  console.warn(`WARN: ${errs.length} vignette(s) errored. First:`, errs[0].id, errs[0].result.__error);
}
