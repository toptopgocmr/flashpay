<template>
  <div v-if="t">
    <div class="page-header">
      <div><h1 class="mono" style="font-size:22px;">{{ t.reference }}</h1></div>
      <div class="actions"><button class="btn-normal" @click="showRefund = true">Rembourser via PEEX</button><router-link class="btn-normal" to="/transactions">‹ Transactions</router-link></div>
      <PeexRefund v-if="showRefund" :transaction-id="Number(route.params.id)" @close="showRefund = false" />
    </div>
    <div class="grid grid-4 mb">
      <div class="card" v-go="'#tx-ledger'" title="Voir les écritures"><div class="stat-value">{{ formatXaf(t.amount) }}</div><div class="stat-label">Montant</div></div>
      <div class="card" v-go="{ path: '/transactions', query: { status: t.status } }" title="Transactions avec ce statut"><div class="stat-value">{{ t.status }}</div><div class="stat-label">Statut</div></div>
      <div class="card" v-go="t.source_wallet?.user ? { path: '/transactions', query: { user: t.source_wallet.user.id } } : '#tx-ledger'" title="Opérations de l'expéditeur"><div class="stat-label">Expéditeur</div><div style="font-weight:700;font-size:17px">{{ sender.name || '—' }}</div><div class="mono stat-label">{{ sender.account || '' }} · {{ t.source_rail }}</div></div>
      <div class="card" v-go="t.destination_wallet?.user ? { path: '/transactions', query: { user: t.destination_wallet.user.id } } : '#tx-ledger'" title="Opérations du bénéficiaire"><div class="stat-label">Bénéficiaire</div><div style="font-weight:700;font-size:17px">{{ beneficiary.name || '—' }}</div><div class="mono stat-label">{{ beneficiary.account || '' }} · {{ t.destination_rail }}</div></div>
    </div>

    <div v-if="t.flow" class="card" style="margin-bottom:24px;">
      <h3>Parcours des fonds</h3>
      <p class="stat-label" style="margin-top:-4px">Débit du compte de l'expéditeur (collecte) → compte principal FlashPay → crédit du compte du bénéficiaire (décaissement).</p>
      <div class="flow">
        <template v-for="(s, i) in t.flow" :key="s.step">
          <div class="step" :class="'st-' + (s.status || 'none')">
            <div class="st-l">{{ i + 1 }}. {{ s.label }}</div>
            <div class="st-a">{{ s.account }}</div>
            <div class="st-p">{{ s.partner }} · {{ ST[s.status] || '—' }}</div>
          </div>
          <div v-if="i < t.flow.length - 1" class="arrow">→</div>
        </template>
      </div>
    </div>

    <div v-if="t.costs" class="card" style="margin-bottom:24px;">
      <h3>Frais &amp; marge</h3>
      <table>
        <thead><tr><th>Ligne</th><th>Partenaire</th><th>Référence</th><th>Statut</th><th class="num">Montant</th><th class="num">Frais</th></tr></thead>
        <tbody>
          <tr>
            <td><b>Facturé par FlashPay</b><div class="stat-label">frais client{{ t.costs.merchant_fee ? ' + commission marchand' : '' }}</div></td>
            <td>FlashPay</td><td class="mono">{{ t.reference }}</td><td>{{ ST[t.status] || t.status }}</td><td></td>
            <td class="num pos"><b>+{{ nf(t.costs.billed) }} {{ t.costs.currency }}</b></td>
          </tr>
          <tr v-for="l in t.costs.legs" :key="l.kind + l.reference">
            <td>{{ l.label }}</td><td>{{ l.partner }}</td><td class="mono">{{ l.reference || '—' }}</td><td>{{ ST[l.status] || '—' }}</td>
            <td class="num">{{ nf(l.amount) }} {{ l.currency }}</td>
            <td class="num neg">
              <template v-if="l.fee !== null">−{{ nf(l.fee) }} {{ l.currency }} <small v-if="l.fee_source === 'estimate'" title="Tarif saisi dans Transactions › Tarifs partenaires">(estimé)</small><small v-else>(partenaire)</small></template>
              <small v-else>non communiqués</small>
            </td>
          </tr>
          <tr class="tot">
            <td colspan="5"><b>Marge FlashPay</b> <small class="stat-label">= facturé − frais partenaires</small></td>
            <td class="num" :class="t.costs.margin < 0 ? 'neg' : 'pos'"><b>{{ nf(t.costs.margin) }} {{ t.costs.currency }}</b></td>
          </tr>
        </tbody>
      </table>
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
import PeexRefund from '../components/PeexRefund.vue'
const showRefund = ref(false)

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

const ST = { successful: 'Réussi', pending: 'En cours', failed: 'Échoué', processing: 'En cours', reversed: 'Remboursé' }
const nf = (n) => new Intl.NumberFormat('fr-FR').format(n || 0)

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

<style scoped>
.flow { display: flex; align-items: stretch; gap: 8px; flex-wrap: wrap; }
.flow .step { flex: 1; min-width: 180px; border: 1px solid var(--border, #e5e7eb); border-radius: 10px; padding: 10px 12px; border-left-width: 4px; }
.flow .arrow { align-self: center; font-size: 20px; color: var(--text-2, #6b7280); }
.st-successful { border-left-color: #16a34a !important; } .st-pending { border-left-color: #d97706 !important; } .st-failed { border-left-color: #dc2626 !important; } .st-none { border-left-color: #9ca3af !important; }
.st-l { font-weight: 700; } .st-a { font-size: 13px; margin: 2px 0; word-break: break-all; } .st-p { font-size: 12px; color: var(--text-2, #6b7280); }
.num { text-align: right; white-space: nowrap; } .pos { color: #15803d; } .neg { color: #b91c1c; } tr.tot td { border-top: 2px solid var(--border, #e5e7eb); }
</style>
