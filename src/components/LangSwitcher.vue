<template>
  <div class="lang-switcher" role="group" aria-label="Display language">
    <button
      v-for="lang in SUPPORTED_LANGS"
      :key="lang"
      type="button"
      class="lang-switcher__btn"
      :class="{ 'lang-switcher__btn--active': currentLang === lang }"
      :aria-pressed="currentLang === lang"
      :aria-label="LANG_NAMES[lang]"
      :title="LANG_NAMES[lang]"
      @click="pickLang(lang)"
    >{{ LANG_LABELS[lang] }}</button>
  </div>
</template>

<script setup>
/**
 * LangSwitcher (2026-05-06 v3) — drives BOTH layers of translation:
 *
 *   1. vue-i18n setLang(lang) flips the global currentLang ref. Every
 *      string already wrapped in t() across the app re-evaluates against
 *      the bundled rw/fr/pt catalogs immediately, no reload.
 *
 *   2. window.__POE_TRANSLATE_TO__(lang) (defined in index.html) sets
 *      Google's googtrans cookie + reloads. After the reload, Google
 *      Translate scans the freshly-rendered Vue DOM and translates
 *      every untranslated string (the long tail not covered by t()).
 *      An aggressive re-trigger loop in index.html keeps catching new
 *      content as Vue re-renders.
 *
 * Net effect: clicking a language pill translates BOTH the chrome
 * (instant, offline-safe via bundled catalogs) AND the long-tail body
 * text (page reload + Google).
 */
import { useI18n } from '@/i18n'
const { setLang, currentLang, SUPPORTED_LANGS, LANG_LABELS, LANG_NAMES } = useI18n()

function pickLang(lang) {
  // Step 1: vue-i18n — happens synchronously, NO reload, instant for
  // strings wrapped in t().
  setLang(lang)
  // Step 2: Google Translate cookie + reload. window.__POE_TRANSLATE_TO__
  // handles 'en' as reset. Wrapped in setTimeout(0) so the setLang state
  // commits to localStorage before the reload kicks in.
  setTimeout(() => {
    try {
      const fn = (typeof window !== 'undefined') && window.__POE_TRANSLATE_TO__
      if (typeof fn === 'function') fn(lang)
    } catch (e) { /* widget not loaded yet — silent */ }
  }, 0)
}
</script>

<style scoped>
.lang-switcher {
  display: inline-flex;
  gap: 4px;
  padding: 3px;
  background: rgba(15, 23, 42, .04);
  border-radius: 999px;
}
.lang-switcher__btn {
  border: none;
  background: transparent;
  color: #475569;
  font-family: inherit;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: .4px;
  padding: 5px 10px;
  border-radius: 999px;
  cursor: pointer;
  -webkit-tap-highlight-color: transparent;
  transition: background .15s ease, color .15s ease;
  min-width: 32px;
}
.lang-switcher__btn:hover { color: #0F172A; background: rgba(15, 23, 42, .04); }
.lang-switcher__btn:focus-visible {
  outline: 2px solid #2563EB;
  outline-offset: 2px;
}
.lang-switcher__btn--active {
  background: #FFFFFF;
  color: #0F172A;
  box-shadow: 0 1px 2px rgba(0, 0, 0, .08);
}
</style>
