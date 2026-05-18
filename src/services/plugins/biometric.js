/**
 * services/plugins/biometric.js — biometric / PIN app-lock.
 *
 * Reference: https://github.com/EpicShaggy/capacitor-native-biometric
 *
 * CLIENT-ONLY design (no backend change):
 *   - PIN is stored as SHA-256(PIN + salt) in localStorage ("app.lock.pin_hash"
 *     + "app.lock.pin_salt"). Never sent to server.
 *   - Biometric enrolment state persisted in localStorage ("app.lock.bio_ok").
 *   - "Identity reference" for the biometric plugin is the logged-in user id
 *     so the OS can invalidate if user re-enrols their fingerprint.
 *
 * API:
 *   enrollPin(pin) → bool
 *   verifyPin(pin) → bool
 *   clearPin()     → void
 *   hasPin()       → bool
 *   setBiometricEnabled(on) → bool
 *   isBiometricEnabled()     → bool
 *   biometricAvailable()     → { available, type } — HW probe
 *   verifyBiometric({ reason }) → bool
 */

import { makePluginSandbox } from './_base'
import { CAPABILITY_KEYS } from '../capabilities'

const sandbox = makePluginSandbox({
  name: 'biometric',
  importer: () => import('capacitor-native-biometric'),
  settingsKey: CAPABILITY_KEYS.APP_LOCK,
})

const PIN_HASH_KEY = 'app.lock.pin_hash'
const PIN_SALT_KEY = 'app.lock.pin_salt'
const BIO_OK_KEY   = 'app.lock.bio_ok'

function safeGet(k) { try { return localStorage.getItem(k) } catch { return null } }
function safeSet(k, v) { try { localStorage.setItem(k, v) } catch {} }
function safeRemove(k) { try { localStorage.removeItem(k) } catch {} }

function randomSalt(len = 16) {
  try {
    const arr = new Uint8Array(len)
    crypto.getRandomValues(arr)
    return Array.from(arr, b => b.toString(16).padStart(2, '0')).join('')
  } catch {
    return String(Date.now()) + Math.random().toString(16).slice(2)
  }
}

async function sha256Hex(str) {
  try {
    const enc = new TextEncoder().encode(str)
    const buf = await crypto.subtle.digest('SHA-256', enc)
    return Array.from(new Uint8Array(buf), b => b.toString(16).padStart(2, '0')).join('')
  } catch {
    // Very-low-end fallback — not cryptographic, but better than plaintext.
    let h = 0
    for (let i = 0; i < str.length; i++) { h = ((h << 5) - h) + str.charCodeAt(i); h |= 0 }
    return String(h)
  }
}

export function hasPin() { return !!(safeGet(PIN_HASH_KEY) && safeGet(PIN_SALT_KEY)) }

export async function enrollPin(pin) {
  const clean = String(pin || '').replace(/\D/g, '').slice(0, 10)
  if (clean.length < 4) return false
  const salt = randomSalt()
  const hash = await sha256Hex(salt + ':' + clean)
  safeSet(PIN_SALT_KEY, salt)
  safeSet(PIN_HASH_KEY, hash)
  return true
}

export async function verifyPin(pin) {
  const clean = String(pin || '').replace(/\D/g, '')
  const salt = safeGet(PIN_SALT_KEY)
  const hash = safeGet(PIN_HASH_KEY)
  if (!salt || !hash) return false
  const actual = await sha256Hex(salt + ':' + clean)
  return actual === hash
}

export function clearPin() {
  safeRemove(PIN_HASH_KEY); safeRemove(PIN_SALT_KEY); safeRemove(BIO_OK_KEY)
}

export function setBiometricEnabled(on) {
  if (on) safeSet(BIO_OK_KEY, '1')
  else safeRemove(BIO_OK_KEY)
}

export function isBiometricEnabled() { return safeGet(BIO_OK_KEY) === '1' }

export async function biometricAvailable() {
  return sandbox.run(async ({ NativeBiometric }) => {
    const r = await NativeBiometric.isAvailable()
    // Plugin returns { isAvailable, biometryType } on v4
    return {
      available: !!(r?.isAvailable ?? r?.available),
      type: r?.biometryType || 'UNKNOWN',
    }
  }, { fallback: { available: false, type: 'NONE' } })
}

export async function verifyBiometric({ reason = 'Unlock POE Screening', title = 'Unlock', subtitle = '' } = {}) {
  return sandbox.run(async ({ NativeBiometric }) => {
    await NativeBiometric.verifyIdentity({
      reason,
      title,
      subtitle,
      description: reason,
      negativeButtonText: 'Use PIN',
      useFallback: false,
    })
    return true
  }, { fallback: false, bypassGate: true })
}

export async function isAvailable() { return sandbox.isAvailable() }
