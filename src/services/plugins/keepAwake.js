/**
 * services/plugins/keepAwake.js — prevents device screen lock during active screening.
 *
 * Reference: https://github.com/capacitor-community/keep-awake
 *
 * Contract:
 *   - activate() is idempotent; safe to call repeatedly.
 *   - deactivate() MUST be called on view leave; pair with onUnmounted.
 *   - A maxDurationMs safety timer (default 30 min) auto-deactivates to
 *     conserve battery even if caller forgets.
 */

import { makePluginSandbox } from './_base'
import { CAPABILITY_KEYS } from '../capabilities'

const sandbox = makePluginSandbox({
  name: 'keep-awake',
  importer: () => import('@capacitor-community/keep-awake'),
  settingsKey: CAPABILITY_KEYS.KEEPAWAKE,
})

let active = false
let safetyTimer = null

export async function activate(maxDurationMs = 30 * 60 * 1000) {
  if (active) return true
  const ok = await sandbox.run(async ({ KeepAwake }) => {
    await KeepAwake.keepAwake()
    return true
  }, { fallback: false })
  if (ok) {
    active = true
    clearTimeout(safetyTimer)
    safetyTimer = setTimeout(() => { deactivate().catch(() => {}) }, maxDurationMs)
  }
  return ok
}

export async function deactivate() {
  if (!active) return true
  clearTimeout(safetyTimer)
  safetyTimer = null
  const ok = await sandbox.run(async ({ KeepAwake }) => {
    await KeepAwake.allowSleep()
    return true
  }, { fallback: true })
  active = false
  return ok
}

export function isActive() { return active }

export async function isAvailable() { return sandbox.isAvailable() }
