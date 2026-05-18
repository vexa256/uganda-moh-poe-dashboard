// Stub. Original (untracked) tenantSnapshot.js was lost. The real module
// captured a snapshot of the current tenant (country/POE/user) for
// cross-tenant residue detection. This stub returns the country code from
// window.COUNTRY_CODE so reconcileTenant() has something to compare against.
export function getTenantSnapshot() {
  const cc = (typeof window !== 'undefined' && window.COUNTRY_CODE) || 'UG';
  return {
    country_code: String(cc).toUpperCase(),
    captured_at: new Date().toISOString(),
  };
}
