/**
 * services/secondarySyncWorker.js — DEPRECATED (kept as a thin compatibility
 * wrapper around the unified syncEngine).
 *
 *  Why it exists: `main.ts` and a handful of older views import
 *  `startSecondarySyncWorker` and `flushSecondaryOutbox` from this path. Rather
 *  than touch every callsite at the same time as the engine rewrite, this
 *  module forwards both symbols to the new engine. Its previous, narrow
 *  Phase-1-only logic was the root cause of records never reaching the
 *  server (see syncEngine.js for the full design).
 *
 *  Do not add new logic here. Import from `@/services/syncEngine` instead.
 */

import { startSyncEngine, flushAll, kick } from '@/services/syncEngine'

/** Compat: old name. Boots the unified engine. */
export function startSecondarySyncWorker() {
  startSyncEngine()
}

/** Compat: old name. Triggers a full flush across all syncable stores. */
export async function flushSecondaryOutbox() {
  return flushAll('legacy-flushSecondaryOutbox')
}

/** Compat: nudge the engine without forcing a synchronous flush. */
export function nudgeSync(reason = 'legacy-nudge') {
  kick(reason)
}
