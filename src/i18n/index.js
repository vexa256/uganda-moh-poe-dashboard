/**
 * src/i18n/index.js — single source of truth for the entire app's display
 * translation. Every view imports `useI18n()` and gets the same reactive
 * `currentLang` and the same `t(key, fallback)` lookup.
 *
 * Design rules
 *   • Display-only. Stored values, payloads, exposure / symptom / disease
 *     codes are NEVER translated.
 *   • English is the canonical key. Every dictionary maps the English source
 *     label to the localised string. Missing keys fall through to English.
 *   • Reactive: a single global `currentLang` ref drives every view at once.
 *     Switching language in one screen instantly updates every other screen.
 *   • Persistent: choice is written to localStorage under SC_LANG_KEY and
 *     read on the next boot.
 *   • Tree-shake-friendly: dictionaries are static imports so Vite can dead-
 *     code-eliminate when a language is removed from SUPPORTED_LANGS.
 *
 * Existing per-view t() functions (notably in SecondaryScreening.vue) keep
 * working — they delegate to this module so behaviour is identical.
 */

import { ref } from 'vue'
import FR from './fr.js'
import PT from './pt.js'
import { tSync, isOnline as _i18nOnline, pendingCount as _i18nPending } from './translateService.js'

// Bump this whenever async translations land, so any computed/template
// that reads t() re-evaluates and shows the just-fetched string.
const _translationBump = ref(0)
if (typeof window !== 'undefined') {
  window.addEventListener('i18n:translation-ready', () => { _translationBump.value++ })
}

export const SUPPORTED_LANGS = ['en', 'fr', 'pt']
export const LANG_LABELS = Object.freeze({
  en: 'EN', fr: 'FR', pt: 'PT',
})
export const LANG_NAMES = Object.freeze({
  en: 'English',
  fr: 'Français',
  pt: 'Português',
})

const STORAGE_KEY = 'sc_display_lang'   // shared with the legacy per-view key

const DICTS = Object.freeze({
  en: null,           // English is the canonical key set; null means "no lookup, return key"
  fr: FR || {},
  pt: PT || {},
})

// One global ref shared across every importer.
const _initial = (() => {
  try {
    const v = (typeof localStorage !== 'undefined') ? localStorage.getItem(STORAGE_KEY) : null
    return SUPPORTED_LANGS.includes(v) ? v : 'en'
  } catch { return 'en' }
})()
export const currentLang = ref(_initial)

/**
 * Translate a key using the current language.
 *
 * @param {string} key       English source label (canonical key).
 * @param {string} [fallback] Optional fallback if the key isn't in any dict.
 * @returns {string}
 */
export function t(key, fallback) {
  if (!key && key !== '') return fallback ?? ''
  // Tracking the bump makes Vue re-evaluate this on async translation arrivals.
  void _translationBump.value
  const lang = currentLang.value
  if (lang === 'en') return fallback ?? key
  // Static dict first (instant)
  const dict = DICTS[lang]
  if (dict && Object.prototype.hasOwnProperty.call(dict, key)) {
    return dict[key]
  }
  // Then translate-service: cache hit → cached translation; miss → English
  // + async fetch queued for next render.
  const dynamic = tSync(key, lang)
  if (dynamic && dynamic !== key) return dynamic
  return fallback || key
}

// Re-export translate-service reactive flags for views that want to show
// the offline / pending UI affordances.
export const translateOnline  = _i18nOnline
export const translatePending = _i18nPending

export function setLang(lang) {
  if (!SUPPORTED_LANGS.includes(lang)) return
  if (currentLang.value === lang) return
  currentLang.value = lang
  try { localStorage.setItem(STORAGE_KEY, lang) } catch { /* private mode → ignore */ }
  if (typeof window !== 'undefined') {
    try { window.dispatchEvent(new CustomEvent('app:lang-changed', { detail: { lang } })) } catch {}
  }
}

/**
 * Composable for use inside `<script setup>` blocks. Returns a stable
 * reference to the same global ref + helpers so every view stays in sync.
 *
 * Vue auto-tracks the ref read inside `t()` because every t() call reads
 * currentLang.value, so any computed/template that uses t() re-evaluates
 * when the language changes.
 *
 * @returns {{ t: typeof t, setLang: typeof setLang, currentLang: typeof currentLang,
 *            SUPPORTED_LANGS: string[], LANG_LABELS: Record<string,string>,
 *            LANG_NAMES: Record<string,string> }}
 */
export function useI18n() {
  return { t, setLang, currentLang, SUPPORTED_LANGS, LANG_LABELS, LANG_NAMES }
}

// Default export so `import i18n from '@/i18n'` works for callers that
// don't use the composable form.
export default { t, setLang, currentLang, useI18n, SUPPORTED_LANGS, LANG_LABELS, LANG_NAMES }
