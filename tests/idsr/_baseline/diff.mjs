#!/usr/bin/env node
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const args = process.argv.slice(2);
const flag = (k, d=null) => {
  const i = args.findIndex(a => a === k || a.startsWith(k+'='));
  if (i < 0) return d;
  if (args[i].includes('=')) return args[i].split('=').slice(1).join('=');
  return args[i+1] ?? d;
};

const preFile  = flag('--pre',  join(here, 'current.json'));
const postFile = flag('--post', join(here, 'locked.json'));
const outFile  = flag('--out',  null);

const pre  = JSON.parse(readFileSync(preFile,  'utf8'));
const post = JSON.parse(readFileSync(postFile, 'utf8'));

if (pre.length !== post.length) {
  console.error(`pre/post length mismatch: ${pre.length} vs ${post.length}`);
  process.exit(1);
}

const topDiffs = [];
const errorDelta = [];
for (let i = 0; i < pre.length; i++) {
  const p = pre[i], q = post[i];
  if (p.id !== q.id) {
    console.error(`vignette id mismatch at ${i}: ${p.id} vs ${q.id}`);
    process.exit(1);
  }
  const pErr = !!p.result?.__error, qErr = !!q.result?.__error;
  if (pErr !== qErr) errorDelta.push({ id: p.id, pre_error: pErr, post_error: qErr });
  const pTop = p.result?.top_diagnoses?.[0]?.disease_id ?? null;
  const qTop = q.result?.top_diagnoses?.[0]?.disease_id ?? null;
  if (pTop === qTop) continue;
  topDiffs.push({
    vignette_id: p.id,
    pre_top:  { id: pTop, score: p.result?.top_diagnoses?.[0]?.final_score ?? null },
    post_top: { id: qTop, score: q.result?.top_diagnoses?.[0]?.final_score ?? null },
    pre_top5:  (p.result?.top_diagnoses ?? []).slice(0, 5).map(d => `${d.disease_id}:${d.final_score}`),
    post_top5: (q.result?.top_diagnoses ?? []).slice(0, 5).map(d => `${d.disease_id}:${d.final_score}`),
    pre_risk:  p.result?.ihr_risk?.risk_level ?? null,
    post_risk: q.result?.ihr_risk?.risk_level ?? null,
    vignette_summary: q.summary || p.summary || null,
  });
}

const summary = {
  total: pre.length,
  top_diff_count: topDiffs.length,
  error_delta_count: errorDelta.length,
};

const payload = { summary, error_delta: errorDelta, top_diffs: topDiffs };
const text = JSON.stringify(payload, null, 2);

if (outFile) {
  writeFileSync(outFile, text);
  console.log(`Diff written: ${topDiffs.length} top shifts / ${pre.length} vignettes → ${outFile}`);
} else {
  console.log(text);
}
console.error(`# top-disease shifts: ${topDiffs.length} of ${pre.length}; error delta: ${errorDelta.length}`);
