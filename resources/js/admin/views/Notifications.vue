<template>
  <div>
    <div class="page-header">
      <div><h1>Centre de notifications</h1><p>KYC en attente, approvisionnements, alertes anti-fraude, anomalies de rapprochement, interventions manuelles et incidents techniques (§11.4).</p></div>
      <div class="actions">
        <ExportButton filename="notifications" :columns="EXP_COLS" :fetch="expFetch" />
        <button class="btn-normal" @click="load">Actualiser</button>
        <button class="btn" @click="readAll">Tout marquer comme lu</button>
      </div>
    </div>
    <div class="tabs mb">
      <button v-for="t in tabs" :key="t.k" :class="{ on: filter === t.k }" @click="filter = t.k; load()">{{ t.l }}<span v-if="t.k === 'critical' && d?.critical_unread" class="n">{{ d.critical_unread }}</span></button>
    </div>
    <section class="container">
      <div class="container-body flush">
        <table>
          <thead><tr><th>Gravité</th><th>Événement</th><th>Détail</th><th>Date</th><th></th></tr></thead>
          <tbody>
            <tr v-for="n in d?.data || []" :key="n.id" :style="n.read_at ? 'opacity:.6' : ''">
              <td><span class="status" :class="cls(n.severity)">{{ SEV[n.severity] || n.severity }}</span></td>
              <td><strong>{{ n.title }}</strong><br /><small class="mono" style="color:var(--text-2)">{{ n.type }}</small></td>
              <td>{{ n.body }}</td>
              <td>{{ date(n.created_at) }}</td>
              <td class="actions-cell"><IconAction v-if="link(n)" icon="open" label="Ouvrir" :to="link(n)" /></td>
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
const tabs = [{ k: 'unread', l: 'Non lues' }, { k: 'critical', l: 'Critiques' }, { k: 'all', l: 'Toutes' }]
const SEV = { info: 'Info', success: 'OK', warning: 'Attention', critical: 'Critique' }
const cls = (s) => ({ critical: 'err', warning: 'warn', success: 'ok' })[s] || 'pending'
const link = (n) => ({
  kyc_pending: '/kyc', float_request: '/float-requests', fraud_alert: '/fraud', security_report: '/support',
  reconciliation_anomaly: '/reconciliation', dispute_opened: '/support', support_ticket: '/support',
  manual_intervention: n.data?.transaction_id ? `/transactions/${n.data.transaction_id}` : '/audit', account_lost: '/fraud',
})[n.type]

async function load() {
  const params = filter.value === 'unread' ? { unread: 1 } : filter.value === 'critical' ? { severity: 'critical' } : {}
  const { data } = await api.get('/admin/notifications', { params })
  d.value = data
}
async function readAll() {
  await api.post('/admin/notifications/read')
  load()
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
const expFetch = (onP) => fetchAllPages('/admin/notifications', filter.value === 'unread' ? { unread: 1 } : filter.value === 'critical' ? { severity: 'critical' } : {}, onP)
</script>
