<template>
  <div>
    <div class="page-header">
      <div><h1>Litiges & support</h1></div>
      <div class="actions">
        <ExportButton :filename="tab === 'disputes' ? 'contestations' : 'tickets-support'" :columns="tab === 'disputes' ? EXP_DISPUTES : EXP_TICKETS" :fetch="expFetch" /><button class="btn-normal" @click="load">Actualiser</button></div>
    </div>
    <div class="tabs mb">
      <button :class="{ on: tab === 'disputes' }" @click="tab = 'disputes'; load()">Contestations<span class="n" v-if="disputes?.total">{{ disputes.total }}</span></button>
      <button :class="{ on: tab === 'tickets' }" @click="tab = 'tickets'; load()">Tickets<span class="n" v-if="tickets?.total">{{ tickets.total }}</span></button>
    </div>
    <div v-if="msg" class="flash info"><div>{{ msg }}</div></div>

    <section class="container" v-if="tab === 'disputes'">
      <div class="container-body flush" style="overflow-x:auto;">
        <table>
          <thead><tr><th>Référence</th><th>Client</th><th>Motif</th><th>Transaction</th><th>Échéance</th><th>Statut</th><th></th></tr></thead>
          <tbody>
            <tr v-for="x in disputes?.data || []" :key="x.id">
              <td class="mono">{{ x.reference }}</td>
              <td><strong>{{ x.user?.full_name }}</strong><br /><small class="mono">{{ $phone(x.user?.phone) }}</small></td>
              <td>{{ x.reason_label }}<br /><small>{{ x.description }}</small><div v-if="x.counterparty_response" class="hint" style="margin-top:4px">Réponse de {{ x.counterparty?.name || 'l\'autre partie' }} : « {{ x.counterparty_response }} »</div></td>
              <td><router-link :to="`/transactions/${x.transaction_id}`" class="mono">{{ x.transaction?.reference }}</router-link><br /><small>{{ x.transaction?.type }} · {{ money(x.transaction?.amount, x.transaction?.currency) }}</small></td>
              <td><span :style="x.overdue ? 'color:var(--err);font-weight:600' : ''">{{ date(x.sla_due_at) }}</span></td>
              <td><span class="status" :class="x.status === 'investigating' ? 'warn' : 'pending'">{{ x.status === 'investigating' ? 'En instruction' : 'Ouvert' }}</span></td>
              <td class="actions-cell">
                <IconAction v-if="x.status === 'open'" icon="search" label="Instruire le litige" @click="investigate(x)" />
                <IconAction icon="scale" tone="accent" label="Arbitrer (rembourser / rejeter)" @click="resolving = { x, action: 'refund', amount: x.transaction?.amount, resolution: '', source: x.counterparty ? 'counterparty' : 'auto' }" />
              </td>
            </tr>
          </tbody>
        </table>
        <div v-if="disputes && !disputes.data.length" class="empty"><strong>Aucune contestation ouverte</strong></div>
      </div>
    </section>

    <section class="container" v-else>
      <div class="container-body flush">
        <table>
          <thead><tr><th>Ticket</th><th>Utilisateur</th><th>Catégorie</th><th>Dernier message</th><th>Échéance</th><th></th></tr></thead>
          <tbody>
            <tr v-for="t in tickets?.data || []" :key="t.id">
              <td><strong>{{ t.subject }}</strong><br /><small class="mono">{{ t.reference }}</small> <span v-if="t.priority !== 'normal'" class="status err">{{ t.priority }}</span></td>
              <td>{{ t.user?.full_name }}<br /><small class="mono">{{ $phone(t.user?.phone) }}</small></td>
              <td>{{ CAT[t.category] || t.category }}</td>
              <td style="max-width:320px">{{ t.messages?.[t.messages.length - 1]?.body }}</td>
              <td><span :style="t.overdue ? 'color:var(--err);font-weight:600' : ''">{{ date(t.sla_due_at) }}</span></td>
              <td class="actions-cell"><IconAction icon="reply" label="Répondre" @click="replying = { t, message: '', status: 'pending_user' }" /></td>
            </tr>
          </tbody>
        </table>
        <div v-if="tickets && !tickets.data.length" class="empty"><strong>Aucun ticket ouvert</strong></div>
      </div>
    </section>

    <Modal v-if="resolving" title="Arbitrage du litige" :subtitle="resolving.x.reference + ' · ' + resolving.x.reason_label" @close="resolving = null">
      <div v-if="error" class="flash err" style="margin:0"><div>{{ error }}</div></div>
      <div class="row2">
        <div><label class="field">Décision</label><select v-model="resolving.action"><option value="refund">Rembourser le client</option><option value="reject">Rejeter la contestation</option></select></div>
        <div v-if="resolving.action === 'refund'"><label class="field">Montant remboursé</label><input v-model.number="resolving.amount" type="number" /></div>
      </div>
      <div v-if="resolving.action === 'refund'">
        <label class="field">Débiter</label>
        <select v-model="resolving.source">
          <option v-if="resolving.x.counterparty" value="counterparty">Le wallet de {{ resolving.x.counterparty.name }} ({{ ROLE[resolving.x.counterparty.role] || resolving.x.counterparty.role }} · solde {{ money(resolving.x.counterparty.balance, resolving.x.counterparty.currency) }})</option>
          <option value="flashpay">FlashPay (correction exceptionnelle tracée)</option>
          <option value="auto">Automatique (marchand si paiement marchand, sinon FlashPay)</option>
        </select>
        <div class="hint">Le montant est crédité sur le wallet de {{ resolving.x.user?.full_name }} (auteur de la contestation).</div>
      </div>
      <div v-if="resolving.action === 'refund' && resolving.x.transaction?.status === 'failed'" class="flash warn" style="margin:0"><div>Attention : cette opération est <strong>échouée</strong>. En principe rien n'a été débité ; vérifiez chez l'opérateur avant de rembourser.</div></div>
      <div><label class="field">Décision motivée (envoyée au client)</label><input v-model.trim="resolving.resolution" placeholder="Ex. Débit confirmé par MTN, remboursement effectué." /></div>
      <template #foot><button class="btn-normal" @click="resolving = null">Annuler</button><button class="btn" :disabled="!resolving.resolution || busy" @click="resolve">Valider</button></template>
    </Modal>
    <Modal v-if="replying" :title="replying.t.subject" :subtitle="replying.t.reference" @close="replying = null">
      <div v-for="m in replying.t.messages" :key="m.id" class="card" :style="m.from_staff ? 'background:var(--surface-2)' : ''"><small>{{ m.author?.full_name }} · {{ date(m.created_at) }}</small><div>{{ m.body }}</div></div>
      <textarea v-model="replying.message" rows="4" placeholder="Votre réponse"></textarea>
      <select v-model="replying.status"><option value="pending_user">En attente de l'utilisateur</option><option value="resolved">Résolu</option><option value="closed">Clôturé</option></select>
      <template #foot><button class="btn-normal" @click="replying = null">Fermer</button><button class="btn" :disabled="!replying.message" @click="reply">Envoyer</button></template>
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

const CAT = { security_report: 'Sécurité', blocking_incident: 'Incident bloquant', account: 'Compte', kyc: 'KYC', information: 'Information' }
const tab = ref('disputes')
const disputes = ref(null)
const tickets = ref(null)
const resolving = ref(null)
const replying = ref(null)
const busy = ref(false)
const ROLE = { client: 'client', merchant: 'marchand', agent: 'agent', super_agent: 'super-agent', cashier: 'caissier' }
const error = ref('')
const msg = ref('')

async function load() {
  disputes.value = (await api.get('/support/desk/disputes')).data
  tickets.value = (await api.get('/support/desk/tickets')).data
}
async function investigate(x) {
  await api.post(`/admin/disputes/${x.id}`, { action: 'investigate' })
  load()
}
async function resolve() {
  busy.value = true
  error.value = ''
  try {
    const r = resolving.value
    await api.post(`/admin/disputes/${r.x.id}`, { action: r.action, amount: r.amount, resolution: r.resolution, source: r.source })
    msg.value = `Litige ${r.x.reference} ${r.action === 'refund' ? 'résolu avec remboursement' : 'rejeté'}.`
    resolving.value = null
    load()
  } catch (e) {
    error.value = errMsg(e)
  } finally {
    busy.value = false
  }
}
async function reply() {
  await api.post(`/support/desk/tickets/${replying.value.t.id}/reply`, { message: replying.value.message, status: replying.value.status })
  replying.value = null
  load()
}
onMounted(load)

// --- Export de la liste (tous les résultats filtrés)
const EXP_DISPUTES = [
  { label: 'Référence', value: (x) => x.reference },
  { label: 'Client', value: (x) => x.user?.full_name },
  { label: 'Téléphone', value: (x) => fmtPhone(x.user?.phone) },
  { label: 'Motif', value: (x) => x.reason_label },
  { label: 'Description', value: (x) => x.description },
  { label: 'Transaction', value: (x) => x.transaction?.reference },
  { label: 'Montant', value: (x) => x.transaction?.amount },
  { label: 'Statut', value: (x) => x.status },
  { label: 'Échéance', value: (x) => fmtDate(x.sla_due_at) },
  { label: 'En retard', value: (x) => (x.overdue ? 'Oui' : 'Non') },
  { label: 'Décision', value: (x) => x.resolution },
  { label: 'Ouvert le', value: (x) => fmtDate(x.created_at) },
]
const EXP_TICKETS = [
  { label: 'Référence', value: (t) => t.reference },
  { label: 'Utilisateur', value: (t) => t.user?.full_name },
  { label: 'Téléphone', value: (t) => fmtPhone(t.user?.phone) },
  { label: 'Catégorie', value: (t) => CAT[t.category] || t.category },
  { label: 'Objet', value: (t) => t.subject },
  { label: 'Priorité', value: (t) => t.priority },
  { label: 'Statut', value: (t) => t.status },
  { label: 'Échéance', value: (t) => fmtDate(t.sla_due_at) },
  { label: 'Messages', value: (t) => (t.messages || []).length },
  { label: 'Ouvert le', value: (t) => fmtDate(t.created_at) },
]
const expFetch = (onP) => fetchAllPages(tab.value === 'disputes' ? '/support/desk/disputes' : '/support/desk/tickets', {}, onP)
</script>
