<template>
  <div>
    <div class="page-header">
      <div><h1>Approvisionnements agents</h1><p>Demandes de float soumises par les agents (dépôt cash, virement). Une fois validée, le wallet de l'agent est crédité depuis la trésorerie FlashPay (§3.1.2, §3.4.3). Les demandes adressées à un super-agent sont traitées par celui-ci.</p></div>
      <div class="actions">
        <ExportButton filename="approvisionnements" :columns="EXP_COLS" :fetch="expFetch" /><button class="btn-normal" @click="load">Actualiser</button></div>
    </div>
    <div class="tabs mb">
      <button v-for="t in ['pending', 'approved', 'rejected']" :key="t" :class="{ on: status === t }" @click="status = t; load()">{{ LBL[t] }}</button>
    </div>
    <div v-if="msg" class="flash info"><div>{{ msg }}</div></div>
    <section class="container">
      <div class="container-body flush" style="overflow-x:auto;">
        <table>
          <thead><tr><th>Agent</th><th>Mode</th><th>Justificatif</th><th class="num">Montant</th><th>Demandé</th><th>Traitement</th><th></th></tr></thead>
          <tbody>
            <tr v-for="r in d?.data || []" :key="r.id">
              <td><strong>{{ r.agent?.user?.full_name }}</strong><br /><small class="mono">{{ r.agent?.agent_code }} · {{ $phone(r.agent?.user?.phone) }}</small></td>
              <td>{{ METHOD[r.method] }}<span v-if="r.super_agent"><br /><small>via {{ r.super_agent?.user?.full_name }}</small></span></td>
              <td class="mono">{{ r.proof_reference || '—' }}<br /><small>{{ r.note }}</small></td>
              <td class="num"><strong>{{ money(r.amount, r.currency) }}</strong></td>
              <td>{{ date(r.created_at) }}</td>
              <td><span v-if="r.reviewer">{{ r.reviewer.full_name }}<br /><small>{{ date(r.reviewed_at) }}</small></span><small v-if="r.rejection_reason" style="color:var(--err)">{{ r.rejection_reason }}</small></td>
              <td class="actions-cell" v-if="r.status === 'pending'">
                <IconAction icon="check" tone="ok" label="Valider et créditer le float" @click="act = { r, decision: 'approve', amount: r.amount }" />
                <IconAction icon="ban" tone="danger" label="Rejeter la demande" @click="act = { r, decision: 'reject', reason: '' }" />
              </td><td v-else></td>
            </tr>
          </tbody>
        </table>
        <div v-if="d && !d.data.length" class="empty"><strong>Aucune demande</strong></div>
      </div>
    </section>
    <Modal v-if="act" :title="act.decision === 'approve' ? 'Valider l\'approvisionnement' : 'Rejeter la demande'" :subtitle="act.r.agent?.user?.full_name" @close="act = null">
      <div v-if="error" class="flash err" style="margin:0"><div>{{ error }}</div></div>
      <div v-if="act.decision === 'approve'"><label class="field">Montant crédité (après contrôle des fonds reçus)</label><input v-model.number="act.amount" type="number" /></div>
      <div v-else><label class="field">Motif</label><input v-model.trim="act.reason" placeholder="Ex. dépôt non reçu sur le compte FlashPay" /></div>
      <template #foot><button class="btn-normal" @click="act = null">Annuler</button><button class="btn" :disabled="busy" @click="submit">Confirmer</button></template>
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
import { money, date, errMsg } from '../utils/format'

const LBL = { pending: 'En attente', approved: 'Validées', rejected: 'Rejetées' }
const METHOD = { cash_deposit: 'Dépôt cash', bank_transfer: 'Virement bancaire', super_agent: 'Super-agent' }
const d = ref(null)
const status = ref('pending')
const act = ref(null)
const busy = ref(false)
const error = ref('')
const msg = ref('')

async function load() {
  const { data } = await api.get('/admin/float-requests', { params: { status: status.value } })
  d.value = data
}
async function submit() {
  busy.value = true
  error.value = ''
  try {
    const { r, decision, amount, reason } = act.value
    await api.post(`/admin/float-requests/${r.id}/review`, { decision, amount, reason })
    msg.value = decision === 'approve' ? `Float de ${r.agent?.user?.full_name} crédité de ${money(amount, r.currency)}.` : 'Demande rejetée, agent notifié.'
    act.value = null
    load()
  } catch (e) {
    error.value = errMsg(e)
  } finally {
    busy.value = false
  }
}
onMounted(load)

// --- Export de la liste (tous les résultats filtrés)
const EXP_COLS = [
  { label: 'Agent', value: (r) => r.agent?.user?.full_name },
  { label: 'Identifiant agent', value: (r) => r.agent?.agent_code },
  { label: 'Téléphone', value: (r) => fmtPhone(r.agent?.user?.phone) },
  { label: 'Mode', value: (r) => METHOD[r.method] || r.method },
  { label: 'Super-agent', value: (r) => r.super_agent?.user?.full_name },
  { label: 'Justificatif', value: (r) => r.proof_reference },
  { label: 'Montant', value: (r) => r.amount },
  { label: 'Devise', value: (r) => r.currency },
  { label: 'Statut', value: (r) => LBL[r.status] || r.status },
  { label: 'Motif de rejet', value: (r) => r.rejection_reason },
  { label: 'Demandé le', value: (r) => fmtDate(r.created_at) },
  { label: 'Traité par', value: (r) => r.reviewer?.full_name },
  { label: 'Traité le', value: (r) => fmtDate(r.reviewed_at) },
]
const expFetch = (onP) => fetchAllPages('/admin/float-requests', { status: status.value }, onP)
</script>
