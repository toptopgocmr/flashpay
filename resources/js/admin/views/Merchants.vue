<template>
  <div>
    <div class="page-header">
      <div>
        <h1>Marchands</h1>
        <p>Commerces qui encaissent avec FlashPay : QR, code client, sans contact (NFC / TPE), USSD.</p>
      </div>
      <div class="actions">
        <ExportButton filename="marchands" :columns="EXP_COLS" :fetch="expFetch" />
        <button class="btn-normal" @click="load">Actualiser</button>
        <button class="btn accent" @click="creating = true">+ Créer un marchand</button>
      </div>
    </div>

    <div class="grid grid-4 mb">
      <button v-for="t in TABS" :key="t.key" class="kpi" :class="{ on: status === t.key }" @click="setStatus(t.key)">
        <span class="k">{{ t.label }}</span>
        <span class="v">{{ t.key ? (counts[t.key] || 0) : total }}</span>
      </button>
    </div>

    <section class="container">
      <div class="tabs">
        <button v-for="t in TABS" :key="t.key" :class="{ on: status === t.key }" @click="setStatus(t.key)">
          {{ t.label }} <span class="n">{{ t.key ? (counts[t.key] || 0) : total }}</span>
        </button>
      </div>
      <div class="toolbar">
        <div class="search">
          <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2"><circle cx="7" cy="7" r="5"/><path d="M11 11l4 4"/></svg>
          <input v-model="q" type="search" placeholder="Nom du commerce, propriétaire, téléphone…" @keyup.enter="load" />
        </div>
        <select v-model="country" @change="city = ''; load()" aria-label="Pays">
          <option value="">Tous les pays</option>
          <option v-for="c in geo" :key="c.country" :value="c.country">{{ c.name }}{{ byCountry[c.country] ? ' (' + byCountry[c.country] + ')' : '' }}</option>
        </select>
        <select v-model="city" :disabled="!country" @change="load" aria-label="Ville">
          <option value="">Toutes les villes</option>
          <option v-for="v in citiesOf(country)" :key="v" :value="v">{{ v }}</option>
        </select>
        <span class="grow"></span>
        <span class="hint" style="margin:0;">{{ rows.length }} résultat(s)</span>
      </div>
      <div class="container-body flush" style="overflow-x:auto;">
        <table>
          <thead>
            <tr><th>Commerce</th><th>Propriétaire</th><th>Code marchand</th><th>Localisation</th><th>Modes de retrait</th><th class="num">Solde</th><th>Statut</th><th>Inscrit le</th><th></th></tr>
          </thead>
          <tbody>
            <tr v-for="m in rows" :key="m.id">
              <td>
                <div class="who">
                  <span class="ava">{{ initials(m.business_name) }}</span>
                  <div><strong>{{ m.business_name }}</strong><small>{{ m.category || 'Commerce' }}<template v-if="m.cashiers || m.outlets"> · {{ m.cashiers || 0 }} caissier(s) · {{ m.outlets || 0 }} PV</template></small></div>
                </div>
              </td>
              <td>{{ m.owner }}<br /><small class="mono" style="color:var(--text-2)">{{ $phone(m.phone) }}</small></td>
              <td class="mono">{{ m.code }}</td>
              <td><Flag :iso="m.country" /> <strong>{{ m.city || '—' }}</strong><br /><small style="color:var(--text-2)">{{ m.address || countryName(m.country) }}</small></td>
              <td>
                <span v-for="c in m.channels || []" :key="c.type" class="chan" :class="{ def: c.is_default }" :title="c.is_default ? 'Par défaut' : ''">{{ CH[c.type] || c.type }}</span>
                <button class="btn-link" style="padding:0 4px;" @click="accountsFor = m">Gérer</button>
              </td>
              <td class="num">{{ money(m.balance, m.currency) }}</td>
              <td><span class="status" :class="cls(m.status)">{{ LABEL[m.status] || m.status }}</span></td>
              <td>{{ date(m.created_at) }}</td>
              <td class="actions-cell">
                <IconAction icon="key" label="Réinitialiser le mot de passe" @click="resetFor = m" />
                <IconAction v-if="m.status !== 'approved'" icon="check" tone="ok" label="Valider le marchand" :disabled="busy === m.id" @click="decide(m, 'approved')" />
                <IconAction v-if="m.status !== 'rejected'" icon="ban" tone="danger" :label="m.status === 'approved' ? 'Suspendre le marchand' : 'Rejeter'" :disabled="busy === m.id" @click="decide(m, 'rejected')" />
              </td>
            </tr>
          </tbody>
        </table>
        <div v-if="!loading && !rows.length" class="empty">
          <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 9l1.5-5h15L21 9"/><path d="M3 9h18v2a3 3 0 0 1-6 0 3 3 0 0 1-6 0 3 3 0 0 1-6 0z"/><path d="M5 13v7h14v-7"/></svg>
          <strong>Aucun marchand {{ status ? LABEL[status].toLowerCase() : '' }}</strong>
          Créez-le avec « + Créer un marchand », ou le commerçant s'inscrit depuis l'application (profil « Marchand »).
        </div>
      </div>
    </section>

    <AccountForm v-if="creating" kind="merchant" :country="country" :city="city" @close="creating = false" @created="load" />
    <MerchantAccounts v-if="accountsFor" :merchant="accountsFor" @close="accountsFor = null" @changed="load" />
    <PasswordReset v-if="resetFor" :user-id="resetFor.user_id" :name="resetFor.business_name" :phone="resetFor.phone" @close="resetFor = null" />
  </div>
</template>

<script setup>
import ExportButton from '../components/ExportButton.vue'
import { fetchAllPages, fmtDate, fmtPhone } from '../utils/export'
import IconAction from '../components/IconAction.vue'
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../services/api'
import AccountForm from '../components/AccountForm.vue'
import PasswordReset from '../components/PasswordReset.vue'
import Flag from '../components/Flag.vue'
import MerchantAccounts from '../components/MerchantAccounts.vue'

const TABS = [
  { key: '', label: 'Tous' },
  { key: 'pending', label: 'En attente' },
  { key: 'approved', label: 'Validés' },
  { key: 'rejected', label: 'Rejetés / suspendus' },
]
const LABEL = { pending: 'En attente', approved: 'Validé', rejected: 'Rejeté' }
const route = useRoute()
const router = useRouter()
const rows = ref([])
const counts = ref({})
const status = ref(route.query.status || '')
const q = ref('')
const loading = ref(false)
const busy = ref(null)
const creating = ref(false)
const resetFor = ref(null)
const accountsFor = ref(null)
const CH = { mobile_money: 'Mobile money', bank: 'Banque', wallet: 'Wallet', cash_pickup: 'Cash agent' }
const geo = ref([])
const country = ref(route.query.country || '')
const city = ref(route.query.city || '')
const byCountry = ref({})
const citiesOf = (iso) => geo.value.find((c) => c.country === iso)?.cities || []
const countryName = (iso) => geo.value.find((c) => c.country === iso)?.name || iso
api.get('/admin/geo').then(({ data }) => { geo.value = data.countries }).catch(() => {})
const total = computed(() => Object.values(counts.value).reduce((a, b) => a + Number(b), 0))

async function load() {
  loading.value = true
  try {
    const { data } = await api.get('/admin/merchants', { params: { status: status.value || undefined, q: q.value || undefined, country: country.value || undefined, city: city.value || undefined } })
    rows.value = data.data
    counts.value = data.counts || {}
    byCountry.value = data.by_country || {}
  } finally {
    loading.value = false
  }
}
function setStatus(s) {
  status.value = s
  router.replace({ query: s ? { status: s } : {} })
  load()
}
async function decide(m, decision) {
  busy.value = m.id
  try {
    await api.post(`/admin/merchants/${m.id}/validate`, { decision })
    await load()
  } finally {
    busy.value = null
  }
}
const nf = new Intl.NumberFormat('fr-FR')
const money = (n, c) => nf.format(n || 0) + ' ' + (c || 'XAF')
const date = (s) => (s ? new Date(s).toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' }) : '—')
const initials = (s) => (s || '?').split(/\s+/).map((w) => w[0]).slice(0, 2).join('').toUpperCase()
const cls = (s) => ({ approved: 'ok', pending: 'warn', rejected: 'err' }[s] || 'muted')

onMounted(load)

// --- Export de la liste (tous les résultats filtrés)
const EXP_COLS = [
  { label: 'Commerce', value: (m) => m.business_name },
  { label: 'Catégorie', value: (m) => m.category },
  { label: 'Caissiers', value: (m) => m.cashiers },
  { label: 'Points de vente', value: (m) => m.outlets },
  { label: 'Propriétaire', value: (m) => m.owner },
  { label: 'Téléphone', value: (m) => fmtPhone(m.phone) },
  { label: 'Code marchand', value: (m) => m.code },
  { label: 'Pays', value: (m) => m.country },
  { label: 'Ville', value: (m) => m.city },
  { label: 'Adresse', value: (m) => m.address },
  { label: 'Modes de retrait', value: (m) => (m.channels || []).map((c) => (CH[c.type] || c.type) + (c.is_default ? ' (défaut)' : '')).join(', ') },
  { label: 'Solde', value: (m) => m.balance },
  { label: 'Devise', value: (m) => m.currency },
  { label: 'Statut', value: (m) => LABEL[m.status] || m.status },
  { label: 'Inscrit le', value: (m) => fmtDate(m.created_at) },
]
const expFetch = (onP) => fetchAllPages('/admin/merchants', { status: status.value || undefined, q: q.value || undefined, country: country.value || undefined, city: city.value || undefined }, onP)
</script>

<style scoped>
.chan { display: inline-block; font-size: 11.5px; padding: 2px 8px; margin: 0 4px 3px 0; border-radius: 99px; background: #f1f5f9; color: var(--text-2); white-space: nowrap; }
.chan.def { background: var(--ok-bg); color: var(--ok); font-weight: 600; }
.kpi { text-align: left; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 14px 16px; cursor: pointer; font: inherit; box-shadow: var(--shadow); transition: border-color .15s, transform .15s; }
.kpi:hover { border-color: var(--link); transform: translateY(-1px); }
.kpi.on { border-color: var(--brand); box-shadow: 0 0 0 3px rgba(30, 58, 138, .12); }
.kpi .k { display: block; color: var(--text-2); font-size: 12.5px; }
.kpi .v { display: block; font-size: 24px; font-weight: 750; margin-top: 2px; }
</style>
