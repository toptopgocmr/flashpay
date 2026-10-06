<template>
  <div>
    <div class="page-header">
      <div>
        <h1>Règlements marchands</h1><p>Virements bancaires et versements mobile money demandés par les marchands, à exécuter et confirmer.</p>
      </div>
      <div class="actions">
        <ExportButton filename="reglements-bancaires" :columns="EXP_COLS" :fetch="expFetch" /><button class="btn-normal" @click="load">Actualiser</button></div>
    </div>

    <div class="grid grid-3 mb">
      <div class="card" v-go="'#stl-pending'"><div class="stat-label">Virements à exécuter</div><div class="stat-value">{{ d?.pending.length ?? '—' }}</div></div>
      <div class="card" v-go="'#stl-pending'"><div class="stat-label">Montant à virer</div><div class="stat-value">{{ money(d?.pending_total) }}</div></div>
      <div class="card" v-go="'#stl-history'"><div class="stat-label">Traités récemment</div><div class="stat-value">{{ d?.recent.length ?? '—' }}</div></div>
    </div>

    <section id="stl-pending" class="container mb">
      <div class="container-head"><h3>À exécuter <span class="counter">({{ d?.pending.length ?? 0 }})</span></h3></div>
      <div class="container-body flush" style="overflow-x:auto;">
        <table>
          <thead><tr><th>Marchand</th><th>Banque / bénéficiaire</th><th>RIB / IBAN</th><th class="num">Montant</th><th>Demandé le</th><th></th></tr></thead>
          <tbody>
            <tr v-for="t in d?.pending || []" :key="t.id">
              <td><strong>{{ t.merchant || '—' }}</strong><br /><small class="mono" style="color:var(--text-2)">{{ t.reference }}</small> <span v-if="t.auto" class="status muted">auto</span></td>
              <td>{{ t.bank_name }}<br /><small style="color:var(--text-2)">{{ t.account_holder }}</small></td>
              <td class="mono">{{ t.account_number }}<br /><small v-if="t.swift">SWIFT {{ t.swift }}</small></td>
              <td class="num"><strong>{{ money(t.amount, t.currency) }}</strong></td>
              <td>{{ date(t.created_at) }}</td>
              <td class="actions-cell">
                <IconAction icon="check" tone="ok" label="Virement effectué" @click="open(t, 'complete')" />
                <IconAction icon="ban" tone="danger" label="Rejeter et rembourser" @click="open(t, 'reject')" />
              </td>
            </tr>
          </tbody>
        </table>
        <div v-if="d && !d.pending.length" class="empty"><strong>Aucun virement en attente</strong>Les demandes des marchands apparaîtront ici.</div>
      </div>
    </section>

    <section id="stl-history" class="container">
      <div class="container-head"><h3>Historique</h3></div>
      <div class="container-body flush" style="overflow-x:auto;">
        <table>
          <thead><tr><th>Marchand</th><th>Banque</th><th class="num">Montant</th><th>Statut</th><th>Référence bancaire / motif</th><th>Date</th></tr></thead>
          <tbody>
            <tr v-for="t in d?.recent || []" :key="t.id">
              <td>{{ t.merchant }}</td>
              <td>{{ t.bank_name }} · <span class="mono">{{ t.account_number }}</span></td>
              <td class="num">{{ money(t.amount, t.currency) }}</td>
              <td><span class="status" :class="t.status === 'successful' ? 'ok' : 'err'">{{ t.status === 'successful' ? 'Viré' : 'Rejeté (remboursé)' }}</span></td>
              <td>{{ t.bank_ref || t.failure_reason }}</td>
              <td>{{ date(t.completed_at || t.created_at) }}</td>
            </tr>
            <tr v-if="d && !d.recent.length"><td colspan="6" class="stat-label">Aucun virement traité.</td></tr>
          </tbody>
        </table>
      </div>
    </section>

    <Modal v-if="action" :title="action.kind === 'complete' ? 'Confirmer le virement' : 'Rejeter le virement'" :subtitle="action.t.merchant + ' · ' + money(action.t.amount, action.t.currency)" @close="action = null">
      <div v-if="error" class="flash err" style="margin:0;"><div>{{ error }}</div></div>
      <div v-if="action.kind === 'complete'">
        <label class="field">Référence du virement bancaire</label>
        <input v-model.trim="value" placeholder="Ex. VIR-2026-000123" />
        <div class="hint">{{ action.t.bank_name }} · {{ action.t.account_holder }} · {{ action.t.account_number }}</div>
      </div>
      <div v-else>
        <label class="field">Motif (communiqué au marchand)</label>
        <input v-model.trim="value" placeholder="Ex. RIB erroné, compte clôturé" />
        <div class="hint">Le montant et les frais sont remboursés immédiatement sur le wallet du marchand.</div>
      </div>
      <template #foot>
        <button class="btn-normal" @click="action = null">Annuler</button>
        <button class="btn" :disabled="!value || busy" @click="submit">{{ action.kind === 'complete' ? 'Confirmer' : 'Rejeter et rembourser' }}</button>
      </template>
    </Modal>
  </div>
</template>

<script setup>
import ExportButton from '../components/ExportButton.vue'
import { fetchAllPages, fmtDate, fmtPhone } from '../utils/export'
import IconAction from '../components/IconAction.vue'
import { onMounted, ref } from 'vue'
import api from '../services/api'
import Modal from '../components/Modal.vue'

const d = ref(null)
const action = ref(null)
const value = ref('')
const busy = ref(false)
const error = ref('')

async function load() {
  const { data } = await api.get('/admin/settlements/bank')
  d.value = data
}
function open(t, kind) {
  action.value = { t, kind }
  value.value = ''
  error.value = ''
}
async function submit() {
  busy.value = true
  error.value = ''
  try {
    const { t, kind } = action.value
    await api.post(`/admin/settlements/${t.id}/${kind}`, kind === 'complete' ? { bank_ref: value.value } : { reason: value.value })
    action.value = null
    await load()
  } catch (e) {
    error.value = e.response?.data?.message || e.message
  } finally {
    busy.value = false
  }
}
const nf = new Intl.NumberFormat('fr-FR')
const money = (n, c) => (n == null ? '—' : nf.format(n) + ' ' + (c || 'XAF'))
const date = (s) => (s ? new Date(s).toLocaleString('fr-FR', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—')
onMounted(load)

// --- Export de la liste (tous les résultats filtrés)
const EXP_COLS = [
  { label: 'Référence', value: (t) => t.reference },
  { label: 'Marchand / client', value: (t) => t.merchant },
  { label: 'Banque', value: (t) => t.bank_name },
  { label: 'Titulaire', value: (t) => t.account_holder },
  { label: 'RIB / IBAN', value: (t) => t.account_number },
  { label: 'SWIFT', value: (t) => t.swift },
  { label: 'Montant', value: (t) => t.amount },
  { label: 'Frais', value: (t) => t.fee },
  { label: 'Devise', value: (t) => t.currency },
  { label: 'Statut', value: (t) => ({ processing: 'À exécuter', successful: 'Viré', reversed: 'Rejeté (remboursé)' })[t.status] || t.status },
  { label: 'Référence bancaire / motif', value: (t) => t.bank_ref || t.failure_reason },
  { label: 'Demandé le', value: (t) => fmtDate(t.created_at) },
  { label: 'Traité le', value: (t) => fmtDate(t.completed_at) },
]
const expFetch = async () => { const { data } = await api.get('/admin/settlements/bank'); return [...data.pending, ...data.recent] }
</script>
