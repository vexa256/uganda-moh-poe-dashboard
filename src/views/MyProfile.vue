<template>
  <IonPage>
    <IonHeader class="mp-hdr" translucent>
      <div class="mp-hdr-bg">
        <div class="mp-hdr-top">
          <IonButtons slot="start"><IonMenuButton menu="app-menu" class="mp-menu"/></IonButtons>
          <div class="mp-hdr-title">
            <span class="mp-eye">My Account</span>
            <span class="mp-h1">Profile</span>
          </div>
        </div>

        <!-- Hero -->
        <div class="mp-hero">
          <div class="mp-avatar">{{ initials }}</div>
          <div class="mp-hero-r">
            <div class="mp-name">{{ auth.full_name||auth.name||'User' }}</div>
            <div class="mp-username">@{{ auth.username||'unknown' }}</div>
            <div class="mp-role-pill">{{ auth.role_key||'Officer' }}</div>
          </div>
        </div>
      </div>
    </IonHeader>

    <IonContent class="mp-content" :fullscreen="true">
      <div class="mp-body">

        <!-- CONTACT INFO -->
        <div class="mp-card">
          <div class="mp-card-h"><span class="mp-card-t">Contact</span></div>
          <div class="mp-row"><span class="mp-k">Email</span><span class="mp-v">{{ auth.email||'—' }}</span></div>
          <div class="mp-row"><span class="mp-k">Phone</span><span class="mp-v">{{ auth.phone||'—' }}</span></div>
          <div class="mp-row"><span class="mp-k">User ID</span><span class="mp-v mp-v--mono">{{ auth.id||'—' }}</span></div>
          <div class="mp-row"><span class="mp-k">Last Login</span><span class="mp-v">{{ fmtDate(auth.last_login_at) }}</span></div>
        </div>

        <!-- ASSIGNMENT -->
        <div class="mp-card">
          <div class="mp-card-h"><span class="mp-card-t">Geographic Assignment</span></div>
          <div class="mp-row"><span class="mp-k">POE</span><span class="mp-v">{{ auth.poe_code||'—' }}</span></div>
          <div class="mp-row"><span class="mp-k">District</span><span class="mp-v">{{ auth.district_code||'—' }}</span></div>
          <div class="mp-row"><span class="mp-k">PHEOC</span><span class="mp-v">{{ auth.pheoc_code||'—' }}</span></div>
          <div class="mp-row"><span class="mp-k">Region</span><span class="mp-v">{{ auth.province_code||'—' }}</span></div>
          <div class="mp-row"><span class="mp-k">Country</span><span class="mp-v">{{ auth.country_code||'—' }}</span></div>
        </div>

        <!-- ROLE PERMISSIONS -->
        <div class="mp-card" v-if="permissions.length">
          <div class="mp-card-h"><span class="mp-card-t">Permissions</span></div>
          <div class="mp-perms">
            <div v-for="p in permissions" :key="p.k" :class="['mp-perm', p.v?'mp-perm--on':'mp-perm--off']">
              <span class="mp-perm-ico">{{ p.v?'✓':'✗' }}</span>
              <span class="mp-perm-l">{{ p.l }}</span>
            </div>
          </div>
        </div>

        <!-- MY ACTIVITY -->
        <div class="mp-card" v-if="myStats">
          <div class="mp-card-h"><span class="mp-card-t">My Activity</span></div>
          <div class="mp-stats">
            <div class="mp-stat"><span class="mp-stat-n">{{ myStats.screenings_total }}</span><span class="mp-stat-l">Screenings</span></div>
            <div class="mp-stat"><span class="mp-stat-n">{{ myStats.symptomatic }}</span><span class="mp-stat-l">Symptomatic</span></div>
            <div class="mp-stat"><span class="mp-stat-n">{{ myStats.referrals }}</span><span class="mp-stat-l">Referrals</span></div>
            <div class="mp-stat"><span class="mp-stat-n">{{ myStats.active_days }}</span><span class="mp-stat-l">Active Days</span></div>
          </div>
        </div>

        <!-- ACTIONS -->
        <div class="mp-card">
          <div class="mp-card-h"><span class="mp-card-t">Quick Actions</span></div>
          <button class="mp-action" @click="goSettings">
            <span class="mp-action-ico">&#x2699;</span>
            <span class="mp-action-l">App Settings</span>
            <span class="mp-action-arr">›</span>
          </button>
          <button class="mp-action" @click="goSync">
            <span class="mp-action-ico">&#x21BB;</span>
            <span class="mp-action-l">Sync Manager</span>
            <span class="mp-action-arr">›</span>
          </button>
          <button class="mp-action mp-action--danger" @click="signOut">
            <span class="mp-action-ico">&#x21AA;</span>
            <span class="mp-action-l">Sign Out</span>
            <span class="mp-action-arr">›</span>
          </button>
        </div>

        <div style="height:48px"/>
      </div>
    </IonContent>
  </IonPage>
</template>

<script setup>
import { IonPage, IonHeader, IonButtons, IonMenuButton, IonContent } from '@ionic/vue'
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { APP, dbGetByIndex, STORE } from '@/services/poeDB'

const router = useRouter()
function getAuth() { return JSON.parse(sessionStorage.getItem('AUTH_DATA') ?? 'null') ?? {} }
const auth = ref(getAuth())
const myStats = ref(null)

const initials = computed(() => {
  const name = auth.value?.full_name || auth.value?.name || auth.value?.username || 'U'
  return name.split(' ').filter(Boolean).slice(0, 2).map(w => w[0]?.toUpperCase() || '').join('') || 'U'
})

const permissions = computed(() => {
  const p = auth.value?._permissions || {}
  return [
    { k: 'screen_p', l: 'Primary Screening',   v: !!p.can_do_primary_screening },
    { k: 'screen_s', l: 'Secondary Screening', v: !!p.can_do_secondary_screening },
    { k: 'aggregate', l: 'Submit Aggregated',  v: !!p.can_submit_aggregated },
    { k: 'manage_u', l: 'Manage Users',         v: !!p.can_manage_users },
    { k: 'view_poe', l: 'View All POE Data',    v: !!p.can_view_all_poe_data },
    { k: 'manage_p', l: 'Manage POEs',          v: !!p.can_manage_poes },
    { k: 'ack_alert', l: 'Acknowledge Alerts',  v: !!p.can_acknowledge_alerts },
    { k: 'close_n', l: 'Close Notifications',   v: !!p.can_close_notifications },
  ]
})

function fmtDate(d) { if(!d)return'—'; try{return new Date(d.replace(' ','T')).toLocaleString([],{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'})}catch{return d} }

async function loadMyStats() {
  try {
    const uid = auth.value?.id; if (!uid) return
    const all = await dbGetByIndex(STORE.PRIMARY_SCREENINGS, 'captured_by_user_id', uid).catch(() => [])
    // Count every non-voided primary screening this user captured. Filtering
    // by record_status==='COMPLETED' under-counts: most primaries stay at
    // SUBMITTED/REFERRED until secondary closes them, sometimes never.
    const live = all.filter(r => !r.deleted_at && r.record_status !== 'VOIDED')
    const days = new Set(live.map(r => (r.captured_at || '').slice(0, 10)).filter(Boolean))
    myStats.value = {
      screenings_total: live.length,
      symptomatic: live.filter(r => r.symptoms_present).length,
      referrals: live.filter(r => r.referral_created).length,
      active_days: days.size,
    }
  } catch {}
}

function goSettings() { router.push('/settings') }
function goSync() { router.push('/sync') }
function signOut() {
  if (!confirm('Sign out of this session?')) return
  sessionStorage.removeItem('AUTH_DATA')
  router.replace('/')
  setTimeout(() => location.reload(), 100)
}

onMounted(() => { auth.value = getAuth(); loadMyStats() })
</script>

<style scoped>
*{box-sizing:border-box}
.mp-hdr{--background:transparent;border:none}
.mp-hdr-bg{background:linear-gradient(135deg,#001D3D,#003566,#003F88);padding:0 0 10px}
.mp-hdr-top{display:flex;align-items:center;gap:4px;padding:6px 8px 0}
.mp-menu{--color:rgba(255,255,255,.7)}
.mp-hdr-title{flex:1;display:flex;flex-direction:column;min-width:0}
.mp-eye{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:1.2px;color:rgba(255,255,255,.4)}
.mp-h1{font-size:17px;font-weight:800;color:#fff}

.mp-hero{display:flex;align-items:center;gap:14px;padding:12px 16px 0}
.mp-avatar{width:64px;height:64px;border-radius:50%;background:linear-gradient(135deg,#0066CC,#003F88);display:flex;align-items:center;justify-content:center;font-size:24px;font-weight:900;color:#fff;flex-shrink:0;border:2px solid rgba(255,255,255,.15)}
.mp-hero-r{flex:1;min-width:0}
.mp-name{font-size:18px;font-weight:800;color:#fff;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mp-username{font-size:11px;color:rgba(255,255,255,.55);font-family:monospace;margin-top:2px}
.mp-role-pill{display:inline-block;font-size:9px;font-weight:800;padding:3px 8px;border-radius:99px;background:rgba(255,255,255,.15);color:#90EE90;margin-top:6px;letter-spacing:.4px}

.mp-content{--background:#F0F4FA}
.mp-body{padding:10px 12px 0;max-width:480px;margin:0 auto}

.mp-card{background:#fff;border-radius:10px;border:1px solid #E8EDF5;margin-bottom:10px;overflow:hidden;box-shadow:0 1px 2px rgba(0,0,0,.03)}
.mp-card-h{padding:12px 14px;border-bottom:1px solid #F0F4FA}
.mp-card-t{font-size:13px;font-weight:800;color:#1A3A5C}

.mp-row{display:flex;justify-content:space-between;align-items:center;padding:10px 14px;border-top:1px solid #F0F4FA}
.mp-row:first-of-type{border-top:none}
.mp-k{font-size:12px;color:#64748B;font-weight:600}
.mp-v{font-size:13px;font-weight:700;color:#1A3A5C;text-align:right;max-width:65%;word-break:break-word}
.mp-v--mono{font-family:monospace;font-size:11px}

.mp-perms{display:grid;grid-template-columns:1fr 1fr;gap:0}
.mp-perm{display:flex;align-items:center;gap:8px;padding:9px 14px;border-top:1px solid #F0F4FA;font-size:11px}
.mp-perm:nth-child(odd){border-right:1px solid #F0F4FA}
.mp-perm--on{color:#047857}
.mp-perm--off{color:#94A3B8}
.mp-perm-ico{font-weight:900;font-size:13px;width:14px;text-align:center}
.mp-perm-l{flex:1;font-weight:600}

.mp-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:1px;background:#F1F5F9}
.mp-stat{display:flex;flex-direction:column;align-items:center;padding:12px 4px;background:#fff;gap:2px}
.mp-stat-n{font-size:18px;font-weight:900;color:#1A3A5C;line-height:1}
.mp-stat-l{font-size:9px;font-weight:700;color:#94A3B8;text-transform:uppercase;text-align:center}

.mp-action{width:100%;display:flex;align-items:center;gap:12px;padding:13px 14px;border:none;border-top:1px solid #F0F4FA;background:transparent;cursor:pointer;text-align:left}
.mp-action:first-of-type{border-top:none}
.mp-action--danger .mp-action-l{color:#DC2626}
.mp-action--danger .mp-action-ico{background:#FEE2E2}
.mp-action-ico{font-size:18px;width:32px;height:32px;display:flex;align-items:center;justify-content:center;background:#F1F5F9;border-radius:8px;flex-shrink:0}
.mp-action-l{flex:1;font-size:13px;font-weight:700;color:#1A3A5C}
.mp-action-arr{font-size:18px;color:#94A3B8;font-weight:600}
</style>
