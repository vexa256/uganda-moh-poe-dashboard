// Stub. Original (untracked) tenantReconciler.js was lost. The real module
// purged cross-tenant IDB residue when a snapshot indicated the device had
// previously been bound to a different country tenant. This stub returns a
// no-op report — the IDB is not modified. Restore real module from editor
// history when convenient.
export async function reconcileTenant(_opts) {
  return {
    purged: 0,
    skipped: true,
    reason: 'tenantReconciler stub — no-op',
  };
}
