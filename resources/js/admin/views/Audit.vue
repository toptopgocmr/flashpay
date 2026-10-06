<template>
  <div>
    <div class="page-header">
      <div>
        <h1>Journal d'audit</h1>
        <p>Trace immuable (chaîne de hachage) de toutes les interventions de l'équipe et du système.</p>
      </div>
      <div class="actions">
        <ExportButton filename="journal-audit" :columns="EXP_COLS" :fetch="expFetch" />
        <button class="btn-normal" :disabled="verifying" @click="verify">{{ verifying ? 'Vérification…' : 'Vérifier l\'intégrité' }}</button>
      </div>
    </div>

    <div v-if="integrity" class="flash" :class="integrity.intact ? 'info' : 'err'">
      <div>
        <strong>{{ integrity.intact ? 'Chaîne intègre' : 'Altération détectée' }}</strong> —
        {{ integrity.intact ? `${n(integrity.entries)} entrées vérifiées, aucune modification.` : `l'entrée n° ${integrity.first_broken_id} ne correspond plus à son empreinte.` }}
      </div>
    </div>

    <section class="container">
      <div class="toolbar">
        <div class="search grow">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
          <input v-model="action" placeholder="Filtrer par action (ex. wallet., kyc., refund.)" @keyup.enter="load(1)" />
        </div>
        <div class="au-chips">
          <button v-for="p in PREFIXES" :key="p.v" :class="{ on: action === p.v }" @click="action = action === p.v ? '' : p.v; load(1)">{{ p.l }}</button>
        </div>
      </div>
      <div class="container-body flush">
        <table>
          <thead><tr><th style="width:70px">#</th><th style="width:140px">Date</th><th>Acteur</th><th>Action</th><th>Objet</th><th>Données</th><th style="width:120px">IP</th></tr></thead>
          <tbody>
            <tr v-if="!d"><td colspan="7"><div class="skeleton" style="height:18px"></div></td></tr>
            <tr v-for="l in d?.data || []" :key="l.id">
              <td class="mono">{{ l.id }}</td>
              <td>{{ date(l.created_at) }}</td>
              <td>
                <div class="who"><span class="ava">{{ initials(l.actor?.full_name || 'Système') }}</span><div><strong>{{ l.actor?.full_name || 'Système' }}</strong></div></div>
              </td>
              <td><span class="au-act" :class="family(l.action)">{{ l.action }}</span></td>
              <td>
                <router-link v-if="link(l)" :to="link(l)">{{ subject(l) }}</router-link>
                <span v-else>{{ subject(l) }}</span>
              </td>
              <td><DataChips :data="l.data" :max="6" /></td>
              <td class="mono">{{ l.ip || '—' }}</td>
            </tr>
            <tr v-if="d && !d.data.length"><td colspan="7"><div class="empty"><strong>Aucune entrée</strong>Aucune action ne correspond à ce filtre.</div></td></tr>
          </tbody>
        </table>
      </div>
      <Pager :meta="d" @go="load" />
    </section>
  </div>
</template>

<script setup>
import ExportButton from '../components/ExportButton.vue'
import DataChips from '../components/DataChips.vue'
import Pager from '../components/Pager.vue'
import { fetchAllPages, fmtDate } from '../utils/export'
import { onMounted, ref } from 'vue'
import api from '../services/api'
import { date } from '../utils/format'

const PREFIXES = [
  { v: 'wallet.', l: 'Wallets' }, { v: 'kyc', l: 'KYC' }, { v: 'refund', l: 'Remboursements' },
  { v: 'settings.', l: 'Paramètres' }, { v: 'user', l: 'Comptes' }, { v: 'agent', l: 'Agents' },
]
const SUBJ = { User: 'Compte', Transaction: 'Transaction', Wallet: 'Wallet', Merchant: 'Marchand', Agent: 'Agent', KycDocument: 'Pièce KYC', FloatRequest: 'Approvisionnement' }
const nf = new Intl.NumberFormat('fr-FR')
const n = (v) => nf.format(v || 0)
const d = ref(null)
const action = ref('')
const integrity = ref(null)
const verifying = ref(false)

const short = (t) => String(t || '').split('\\').pop()
const subject = (l) => (l.subject_type ? `${SUBJ[short(l.subject_type)] || short(l.subject_type)} n° ${l.subject_id}` : '—')
const link = (l) => {
  const t = short(l.subject_type)
  if (t === 'Transaction') return '/transactions/' + l.subject_id
  if (t === 'Agent') return '/agents/' + l.subject_id
  return null
}
const family = (a) => String(a || '').split('.')[0]
const initials = (s) => String(s).split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0].toUpperCase()).join('')

async function load(page = 1) {
  const { data } = await api.get('/admin/audit-logs', { params: { page, ...(action.value ? { action: action.value } : {}) } })
  d.value = data
}
async function verify() {
  verifying.value = true
  try { integrity.value = (await api.get('/admin/audit-logs/verify')).data } finally { verifying.value = false }
}
onMounted(() => load())

// --- Export de la liste (tous les résultats filtrés)
const EXP_COLS = [
  { label: '#', value: (l) => l.id },
  { label: 'Date', value: (l) => fmtDate(l.created_at) },
  { label: 'Acteur', value: (l) => l.actor?.full_name || 'Système' },
  { label: 'Action', value: (l) => l.action },
  { label: 'Objet', value: (l) => (l.subject_type ? l.subject_type + ' #' + l.subject_id : '') },
  { label: 'Données', value: (l) => (l.data ? JSON.stringify(l.data) : '') },
  { label: 'IP', value: (l) => l.ip },
  { label: 'Empreinte', value: (l) => l.hash },
]
const expFetch = (onP) => fetchAllPages('/admin/audit-logs', action.value ? { action: action.value } : {}, onP)
</script>

<style scoped>
.au-chips { display: flex; gap: 6px; flex-wrap: wrap; }
.au-chips button { border: 1px solid var(--border-strong); background: #fff; border-radius: 999px; padding: 4px 11px; font: inherit; font-size: 12.5px; cursor: pointer; color: var(--text-2); }
.au-chips button.on { background: var(--brand); border-color: var(--brand); color: #fff; }
.au-act { font-family: "JetBrains Mono", Consolas, monospace; font-size: 12px; padding: 2px 8px; border-radius: 6px; background: var(--soft); color: var(--brand); white-space: nowrap; }
.au-act.wallet, .au-act.refund { background: var(--rose); color: var(--accent); }
.au-act.settings { background: #fef3c7; color: #92400e; }
.au-act.kyc { background: #dcfce7; color: #166534; }
</style>
