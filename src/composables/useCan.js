/**
 * useCan.js — action-on-target permission composable (B5).
 *
 * Wraps services/rbac.js + useAuth. Exposes:
 *   const { can, canForTarget, isAdmin, isSupervisor } = useCan()
 *
 * Usage in templates:
 *   <ion-button v-if="can('alerts.acknowledge', alert)" ...>Acknowledge</ion-button>
 *
 * The second arg (target) is optional. When provided AND it has a
 * `routed_to_level` field, the action-on-target ladder is enforced.
 */
import { computed } from 'vue'
import { can as rbacCan, canForTarget as rbacCanForTarget, isAdmin, isSupervisor, isPoeStaff, scopeOf } from '@/services/rbac'
import { useAuth } from './useAuth'

export function useCan() {
  const auth = useAuth()

  function can(perm, target) {
    if (target !== undefined && target !== null) {
      return rbacCanForTarget(perm, auth.value, target)
    }
    return rbacCan(perm, auth.value)
  }

  function canForTarget(perm, target) {
    return rbacCanForTarget(perm, auth.value, target)
  }

  return {
    auth,
    can,
    canForTarget,
    isAdmin: computed(() => isAdmin(auth.value)),
    isSupervisor: computed(() => isSupervisor(auth.value)),
    isPoeStaff: computed(() => isPoeStaff(auth.value)),
    scope: computed(() => scopeOf(auth.value)),
    role: computed(() => auth.value?.role_key || null),
  }
}
