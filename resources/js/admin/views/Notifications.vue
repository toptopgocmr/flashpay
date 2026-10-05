<template>
  <div>
    <div class="page-header">
      <div><h1>Centre de notifications</h1></div>
      <div class="actions">
        <ExportButton filename="notifications" :columns="EXP_COLS" :fetch="expFetch" />
        <button class="btn-normal" :disabled="loading" @click="load">{{ loading ? 'Actualisation…' : 'Actualiser' }}</button>
        <button class="btn" :disabled="!d?.unread || marking" @click="readAll">Tout marquer comme lu</button>
      </div>
    </div>
    <div v-if="error" class="flash err mb"><div>{{ error }}</div></div>
    <p v-if="updatedAt" class="upd">Mis à jour à {{ updatedAt }} · {{ d?.unread || 0 }} non lue(s)</p>
    <div class="tabs mb">
      <button v-for="t in tabs" :key="t.k" :class="{ on: filter === t.k }" @click="filter = t.k; load()">{{ t.l }}<span v-if="t.k === 'critical' && d?.critical_unread" class="n">{{ d.critical_unread }}</span></button>
    </div>
    <section class="container">
      <div class="container-body flush">
        <table>
          <thead><tr><th>Gravité</th><th>Événement</th><th>Détail</th><th>Date</th><th></th></tr></thead>
          <tbody>
            <tr v-for="n in d?.data || []" :key="n.id" :style="n.read_at ? 'opacity:.6' : ''" :class="{ unread: !n.read_at }">
              <td><span class="status" :class="cls(n.severity)">{{ SEV[n.severity] || n.severity }}</span></td>
              <td><strong>{{ n.title }}</strong><br /><small class="mono" style="color:var(--text-2)">{{ n.type }}</small></td>
              <td>{{ n.body }}</td>
              <td>{{ date(n.created_at) }}</td>
              <td class="actions-cell">
                <IconAction v-if="!n.read_at" icon="check" tone="ok" label="Marquer comme lu" @click="markRead(n)" />
                <IconAction v-if="link(n)" icon="open" label="Ouvrir" :to="link(n)" @click="markRead(n)" />
              </td>
            </tr>
          </tbody>
        </table>
        <div v-if="d && !d.data.length" class="empty"><strong>Aucune notification</strong></div>
      </div>
    </section>
  </div>
</template>

<script setup>
import ExportButton from '../components/ExportButton.vue'
import { fetchAllPages, fmtDate, fmtPhone } from '../utils/export'
import IconAction from '../components/IconAction.vue'
import { onMounted, ref } from 'vue'
import api from '../services/api'
import { date } from '../utils/format'

const d = ref(null)
const filter = ref('unread')
const tabs = [{ k: 'unread', l: 'Non lues' }, { k: 'payments', l: 'Paiements' }, { k: 'critical', l: 'Critiques' }, { k: 'all', l: 'Toutes' }]
const paramsFor = (f) => (f === 'unread' ? { unread: 1 } : f === 'critical' ? { severity: 'critical' } : f === 'payments' ? { kind: 'payments' } : {})
const SEV = { info: 'Info', success: 'OK', warning: 'Attention', critical: 'Critique' }
const cls = (s) => ({ critical: 'err', warning: 'warn', success: 'ok' })[s] || 'pending'
const link = (n) => (n.type?.startsWith('payment_') && n.data?.transaction_id ? `/transactions/${n.data.transaction_id}` : null) || ({
  kyc_pending: '/kyc', float_request: '/float-requests', fraud_alert: '/fraud', security_report: '/support',
  reconciliation_anomaly: n.data?.report_id ? `/reconciliation?report=${n.data.report_id}` : '/reconciliation', dispute_opened: '/support', support_ticket: '/support',
  manual_intervention: n.data?.transaction_id ? `/transactions/${n.data.transaction_id}` : '/audit', account_lost: '/fraud',
})[n.type]

const loading = ref(false)
const marking = ref(false)
const error = ref('')
const updatedAt = ref('')
// Prévient l'en-tête (cloche + menu) de recompter tout de suite les non lues.
const refreshBadges = () => window.dispatchEvent(new Event('fp:badges'))

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/admin/notifications', { params: { ...paramsFor(filter.value), _: Date.now() } })
    d.value = data
    updatedAt.value = new Date().toLocaleTimeString('fr-FR')
  } catch (e) {
    error.value = 'Actualisation impossible : ' + (e.response?.data?.message || e.message)
  } finally {
    loading.value = false
  }
  refreshBadges()
}
async function readAll() {
  marking.value = true
  try {
    await api.post('/admin/notifications/read')
  } catch (e) {
    error.value = e.response?.data?.message || e.message
  } finally {
    marking.value = false
  }
  await load()
}
async function markRead(n) {
  if (n.read_at) return
  n.read_at = new Date().toISOString() // retour visuel immédiat
  try {
    await api.post('/admin/notifications/read', { ids: [n.id] })
  } catch (_) {}
  refreshBadges()
  if (filter.value === 'unread') d.value.data = d.value.data.filter((x) => x.id !== n.id)
}
onMounted(load)

// --- Export de la liste (tous les résultats filtrés)
const EXP_COLS = [
  { label: 'Gravité', value: (n) => SEV[n.severity] || n.severity },
  { label: 'Type', value: (n) => n.type },
  { label: 'Événement', value: (n) => n.title },
  { label: 'Détail', value: (n) => n.body },
  { label: 'Lue', value: (n) => (n.read_at ? 'Oui' : 'Non') },
  { label: 'Date', value: (n) => fmtDate(n.created_at) },
]
const expFetch = (onP) => fetchAllPages('/admin/notifications', paramsFor(filter.value), onP)
</script>

<style scoped>
.upd { font-size: 12.5px; color: var(--text-2); margin: -6px 0 10px; }
tr.unread td:first-child { box-shadow: inset 3px 0 0 var(--link, #1e3a8a); }
</style>
