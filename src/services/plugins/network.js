/**
 * services/plugins/network.js — accurate connectivity via @capacitor/network.
 *
 * Replaces navigator.onLine (which lies on Android WebView + captive portal).
 * Exports:
 *   - getStatus(): { connected, connectionType }
 *   - subscribe(cb): unsubscribe fn; cb(status) on every change
 *   - start()/stop(): idempotent global listener management
 *   - web fallback: uses online/offline events when plugin unavailable
 *
 * Reference: https://capacitorjs.com/docs/apis/network
 */

import { makePluginSandbox } from './_base'
import { CAPABILITY_KEYS } from '../capabilities'

const sandbox = makePluginSandbox({
  name: 'network',
  importer: () => import('@capacitor/network'),
  settingsKey: CAPABILITY_KEYS.NETWORK,
  requiresNative: false, // plugin has a web fallback implementation too
})

const listeners = new Set()
let started = false
let removeHandle = null
let lastStatus = { connected: typeof navigator !== 'undefined' ? !!navigator.onLine : true, connectionType: 'unknown' }

function emit(status) {
  lastStatus = status
  for (const cb of listeners) { try { cb(status) } catch (e) { console.debug('[network] listener threw:', e?.message) } }
  try { window.dispatchEvent(new CustomEvent('network-changed', { detail: status })) } catch {}
}

export async function getStatus() {
  const s = await sandbox.run(async ({ Network }) => Network.getStatus(), { fallback: null })
  if (s && typeof s.connected === 'boolean') {
    return { connected: s.connected, connectionType: s.connectionType || 'unknown' }
  }
  // Web fallback
  return {
    connected: typeof navigator !== 'undefined' ? !!navigator.onLine : true,
    connectionType: 'unknown',
  }
}

export function subscribe(cb) {
  listeners.add(cb)
  // Fire once with current known status so callers can sync UI immediately.
  try { cb(lastStatus) } catch {}
  return () => listeners.delete(cb)
}

export async function start() {
  if (started) return
  started = true
  // Try native plugin listener first.
  const handle = await sandbox.run(async ({ Network }) => {
    const h = await Network.addListener('networkStatusChange', (status) => {
      emit({ connected: !!status?.connected, connectionType: status?.connectionType || 'unknown' })
    })
    const initial = await Network.getStatus()
    emit({ connected: !!initial?.connected, connectionType: initial?.connectionType || 'unknown' })
    return h
  }, { fallback: null })

  if (handle && typeof handle.remove === 'function') {
    removeHandle = () => { try { handle.remove() } catch {} }
    return
  }

  // Web fallback — attach navigator events.
  const onOnline = () => emit({ connected: true, connectionType: 'unknown' })
  const onOffline = () => emit({ connected: false, connectionType: 'none' })
  try {
    window.addEventListener('online', onOnline)
    window.addEventListener('offline', onOffline)
    removeHandle = () => {
      window.removeEventListener('online', onOnline)
      window.removeEventListener('offline', onOffline)
    }
    emit(lastStatus)
  } catch (err) {
    console.debug('[network] web fallback failed:', err?.message)
  }
}

export function stop() {
  if (!started) return
  started = false
  try { removeHandle && removeHandle() } catch {}
  removeHandle = null
}

export function lastKnown() { return lastStatus }
