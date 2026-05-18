/**
 * services/sentinelFormWrite.js — the ONE helper every Sentinel feature
 * uses to push scanner/voice/BLE results into a view's form ref.
 *
 * See docs/sentinel-plan/ARCHITECTURE.md §5.
 *
 * Rules (enforced here, once, so feature authors cannot forget):
 *   1. Never overwrite a value the officer has already typed.
 *   2. Never introduce a new field to the form that did not already exist.
 *      If a feature needs a new field, the feature's own PR extends the form
 *      shape — not this helper.
 *   3. Always return the list of fields that were actually filled, so the
 *      caller can surface a success summary.
 *   4. Never throw. A corrupt partial returns an empty filled list.
 */

/**
 * Apply a partial into a form ref. `formRef` is a Vue `ref` (object ref or
 * shallowRef) whose `.value` is the form object. `partial` is a plain object
 * with the field → value pairs the feature wants to push.
 *
 * @param  {import('vue').Ref<Object>}  formRef   Vue ref wrapping the form
 * @param  {Record<string, any>}        partial   fields to apply (nulls ignored)
 * @returns {string[]} names of the keys actually written
 */
export function applySentinelFields(formRef, partial) {
  if (!formRef || !partial || typeof partial !== 'object') return []
  const form = formRef.value
  if (!form || typeof form !== 'object') return []

  const filled = []
  for (const [k, v] of Object.entries(partial)) {
    if (v === undefined || v === null || v === '') continue
    if (!(k in form)) continue                       // rule 2: never introduce a new field
    const current = form[k]
    if (current !== undefined && current !== null && current !== '' && current !== 0 && current !== false) {
      // rule 1: respect existing user input. A truthy-ish existing value is
      // treated as "officer already set this". 0/false are intentionally
      // skipped from the "already set" check because they are also the
      // default for temp/symptoms toggles.
      continue
    }
    try {
      form[k] = v
      filled.push(k)
    } catch (err) {
      console.debug('[sentinel] form write failed for', k, err?.message)
    }
  }
  return filled
}
