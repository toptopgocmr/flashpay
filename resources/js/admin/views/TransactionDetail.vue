<template>
  <div v-if="t">
    <div class="page-header">
      <div><h1 class="mono" style="font-size:22px;">{{ t.reference }}</h1><p>Détail de la transaction, écritures comptables et suivi support.</p></div>
      <div class="actions"><router-link class="btn-normal" to="/transactions">‹ Transactions</router-link></div>
    </div>
    <div class="grid grid-4 mb">
      <div class="card" v-go="'#tx-ledger'" title="Voir les écritures"><div class="stat-value">{{ formatXaf(t.amount) }}</div><div class="stat-label">Montant</div></div>
      <div class="card" v-go="{ path: '/transactions', query: { status: t.status } }" title="Transactions avec ce statut"><div class="stat-value">{{ t.status }}</div><div class="stat-label">Statut</div></div>
      <div class="card" v-go="t.source_wallet?.user ? { path: '/transactions', query: { user: t.source_wallet.user.id } } : '#tx-ledger'" title="Opérations de l'expéditeur"><div class="stat-label">Expéditeur</div><div style="font-weight:700;font-size:17px">{{ sender.name || '—' }}</div><div class="mono stat-label">{{ sender.account || '' }} · {{ t.source_rail }}</div></div>
      <div class="card" v-go="t.destination_wallet?.user ? { path: '/transactions', query: { user: t.destination_wallet.user.id } } : '#tx-ledger'" title="Opérations du bénéficiaire"><div class="stat-label">Bénéficiaire</div><div style="font-weight:700;font-size:17px">{{ beneficiary.name || '—' }}</div><div class="mono stat-label">{{ beneficiary.account || '' }} · {{ t.destination_rail }}</div></div>
    </div>

    <div id="tx-ledger" class="card" style="margin-bottom:24px;">
      <h3>Écritures du grand livre</h3>
      <table>
        <thead><tr><th>Compte</th><th>Type</th><th>Montant</th></tr></thead>
        <tbody>
          <tr v-for="e in t.ledger_entries" :key="e.id">
            <td>{{ e.account }}</td>
            <td><span class="badge" :class="e.type === 'debit' ? 'badge-failed' : 'badge-success'">{{ e.type }}</span></td>
            <td>{{ formatXaf(e.amount) }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="card" style="margin-bottom:24px;">
      <h3>Notes support</h3>
      <div v-for="n in t.notes" :key="n.id" style="padding:8px 0; border-bottom:1px solid #eee;">
        <strong>{{ n.author?.full_name }}</strong> — {{ new Date(n.created_at).toLocaleString('fr-FR') }}
        <p style="margin:4px 0 0;">{{ n.note }}</p>
      </div>
      <form @submit.prevent="addNote" style="margin-top:12px; display:flex; gap:8px;">
        <input v-model="noteText" placeholder="Ajouter une note..." style="flex:1; padding:8px; border-radius:8px; border:1px solid #ddd;" />
        <button class="btn" type="submit">Ajouter</button>
      </form>
    </div>

    <div class="card">
      <h3>Escalader vers un partenaire</h3>
      <form @submit.prevent="escalate" style="display:flex; gap:8px; flex-wrap:wrap;">
        <select v-model="escalatePartner" style="padding:8px; border-radius:8px;">
          <option value="peex">PEEX</option>
        </select>
        <input v-model="escalateMessage" placeholder="Message pour le partenaire" style="flex:1; min-width:200px; padding:8px; border-radius:8px; border:1px solid #ddd;" />
        <button class="btn" type="submit">Escalader</button>
      </form>
    </div>
  </div>
</template>

<script setup>
import { computed as _computed } from 'vue'
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import api from '../services/api'

const route = useRoute()
const t = ref(null)
const noteText = ref('')
const escalatePartner = ref('peex')
const escalateMessage = ref('')

async function load() {
  const { data } = await api.get(`/support/transactions/${route.params.id}`)
  t.value = data
}

async function addNote() {
  if (!noteText.value.trim()) return
  await api.post(`/support/transactions/${route.params.id}/notes`, { note: noteText.value })
  noteText.value = ''
  await load()
}

async function escalate() {
  await api.post(`/support/transactions/${route.params.id}/escalate`, {
    partner: escalatePartner.value,
    message: escalateMessage.value,
  })
  escalateMessage.value = ''
  alert('Incident escaladé.')
}

function formatXaf(n) {
  return new Intl.NumberFormat('fr-FR').format(n || 0) + ' XAF'
}

onMounted(load)

// Expéditeur / bénéficiaire lisibles (compte FlashPay, sinon informations saisies sur l'opération)
const sender = _computed(() => {
  const x = t.value || {}, m = x.meta || {}, u = x.source_wallet?.user
  return { name: u?.full_name || m.sender_name || m.payer_name || (x.source_rail === 'treasury' ? 'FlashPay (trésorerie)' : null), account: x.source_account || u?.phone }
})
const beneficiary = _computed(() => {
  const x = t.value || {}, m = x.meta || {}, u = x.destination_wallet?.user
  return { name: m.merchant_name || u?.full_name || m.beneficiary_name || m.client_name || (x.destination_rail === 'treasury' ? 'FlashPay (trésorerie)' : null), account: x.destination_account || u?.phone || m.bank_name }
})
</script>
