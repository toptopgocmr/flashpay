<template>
  <div>
    <div class="page-header">
      <div><h1>Réconciliation</h1><p>Contrôle automatique toutes les heures (§13.1) : équilibre du ledger, cohérence solde wallet ↔ écritures, opérations bloquées chez l'opérateur (« en cours de vérification »), écritures orphelines PEEX.</p></div>
      <div class="actions"><button class="btn" :disabled="busy" @click="run">Lancer maintenant</button></div>
    </div>
    <div ref="detailTop" class="detail-head mb" v-if="last">
      <h2>Rapport n° {{ last.id }} · {{ date(last.created_at) }}</h2>
      <span class="status" :class="last.anomalies ? 'err' : 'ok'">{{ last.anomalies ? last.anomalies + ' anomalie(s)' : 'Aucune anomalie' }}</span>
    </div>
    <div class="grid grid-4 mb" v-if="last">
      <div class="card" v-for="(v, k) in last.results" :key="k"><div class="stat-label">{{ LBL[k] }}</div><div class="stat-value" :style="v.length ? 'color:var(--err)' : ''">{{ v.length }}</div></div>
    </div>
    <section class="container mb" v-for="(rows, k) in last?.results || {}" :key="k" v-show="rows.length">
      <div class="container-head"><h3>{{ LBL[k] }}</h3></div>
      <div class="container-body flush" style="overflow-x:auto;">
        <table>
          <thead><tr><th v-for="c in Object.keys(rows[0] || {})" :key="c">{{ COL[c] || c }}</th></tr></thead>
          <tbody><tr v-for="(r, i) in rows" :key="i"><td v-for="c in Object.keys(r)" :key="c" class="mono"><router-link v-if="c === 'transaction_id' && r[c]" :to="'/transactions/' + r[c]">{{ r[c] }}</router-link><template v-else>{{ r[c] }}</template></td></tr></tbody>
        </table>
      </div>
    </section>
    <section class="container">
      <div class="container-head"><h3>Historique des rapports</h3></div>
      <div class="container-body flush">
        <table>
          <thead><tr><th>#</th><th>Date</th><th>Anomalies</th><th></th></tr></thead>
          <tbody><tr v-for="r in reports" :key="r.id" :class="{ sel: last?.id === r.id }" style="cursor:pointer" @click="show(r)"><td>{{ r.id }}</td><td>{{ date(r.created_at) }}</td><td><span class="status" :class="r.anomalies ? 'err' : 'ok'">{{ r.anomalies }}</span></td><td class="actions-cell"><IconAction icon="eye" label="Voir le détail" @click.stop="show(r)" /></td></tr></tbody>
        </table>
      </div>
    </section>
  </div>
</template>

<script setup>
import IconAction from '../components/IconAction.vue'
import { nextTick, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import api from '../services/api'
import { date } from '../utils/format'

const LBL = { unbalanced_transactions: 'Transactions déséquilibrées', wallet_mismatches: 'Écarts solde / ledger', stuck_transactions: 'En cours de vérification', peex_orphans: 'Orphelines PEEX' }
const COL = { gap: 'Écart', user_id: 'Utilisateur', currency: 'Devise', since: 'Depuis', stage: 'Étape', reference: 'Référence', transaction_id: 'Transaction', wallet_id: 'Wallet', user: 'Titulaire', balance: 'Solde wallet', ledger: 'Solde ledger', ledger_balance: 'Solde ledger', diff: 'Écart', difference: 'Écart', track_id: 'Track ID', status: 'Statut', amount: 'Montant' }
const route = useRoute()
const detailTop = ref(null)
// Affiche le rapport choisi et remonte en haut de page pour voir son détail
async function show(r) {
  last.value = r
  await nextTick()
  detailTop.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}
const reports = ref([])
const last = ref(null)
const busy = ref(false)
async function load() {
  reports.value = (await api.get('/admin/reconciliation')).data
  const wanted = Number(route.query.report || 0)
  last.value = reports.value.find((r) => r.id === wanted) || reports.value[0] || null
}
async function run() {
  busy.value = true
  try { await api.post('/admin/reconciliation/run'); await load() } finally { busy.value = false }
}
onMounted(load)
</script>

<style scoped>
.detail-head { display: flex; align-items: center; gap: 12px; }
.detail-head h2 { margin: 0; font-size: 18px; }
tr.sel td { background: var(--surface-2); font-weight: 600; }
</style>
