<template>
  <div>
    <div class="page-header">
      <div>
        <h1>Agents</h1><p>Réseau d'agents : super-agents, agents et sous-agents, float, agrément et commissions.</p>
      </div>
      <div class="actions">
        <ExportButton filename="agents" :columns="EXP_COLS" :fetch="expFetch" />
        <button class="btn-normal" @click="load">Actualiser</button>
        <button class="btn accent" @click="creating = true">+ Créer un agent</button>
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
          <input v-model="q" type="search" placeholder="Nom ou téléphone de l'agent…" @keyup.enter="load" />
        </div>
        <select v-model="country" @change="city = ''; load()" aria-label="Pays">
          <option value="">Tous les pays</option>
          <option v-for="c in geo" :key="c.country" :value="c.country">{{ c.name }}{{ byCountry[c.country] ? ' (' + byCountry[c.country] + ')' : '' }}</option>
        </select>
        <select v-model="level" @change="load" aria-label="Niveau">
          <option value="">Tous les niveaux</option>
          <option value="super">Super-agents ({{ levels.super || 0 }})</option>
          <option value="sub">Sous-agents ({{ levels.sub || 0 }})</option>
          <option value="simple">Agents ({{ levels.simple || 0 }})</option>
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
            <tr><th>Agent</th><th>Localisation</th><th class="num">Float (wallet)</th><th>Agrément</th><th>Compte</th><th>Créé le</th><th></th></tr>
          </thead>
          <tbody>
            <tr v-for="m in rows" :key="m.id" class="clickable" :class="{ off: !m.active }" @click="$router.push('/agents/' + m.id)">
              <td>
                <div class="who">
                  <span class="ava">{{ initials(m.name) }}</span>
                  <div>
                    <strong>{{ m.name }}</strong>
                    <small class="mono">{{ m.agent_code || '—' }} · {{ $phone(m.phone) }}</small>
                    <span v-if="m.level === 'super'" class="role-chip red" style="margin-top:4px;">Super-agent · {{ m.sub_agents }} sous-agent(s)</span>
                    <span v-else-if="m.level === 'sub'" class="role-chip" style="margin-top:4px;">Sous-agent de {{ m.parent_name || '—' }}</span>
                  </div>
                </div>
              </td>
              <td><Flag :iso="m.country" /> <strong>{{ m.city || '—' }}</strong><br /><small style="color:var(--text-2)">{{ m.zone || countryName(m.country) }}</small></td>
              <td class="num">{{ money(m.float, m.currency) }}<div v-if="m.wallet_status === 'frozen'" style="font-size:11px;color:var(--err);font-weight:600;">Float gelé</div></td>
              <td><span class="status" :class="cls(m.status)">{{ LABEL[m.status] || m.status }}</span></td>
              <td><span class="status" :class="m.active ? 'ok' : 'err'">{{ m.active ? 'Actif' : 'Désactivé' }}</span></td>
              <td>{{ date(m.created_at) }}</td>
              <td class="actions-cell" @click.stop>
                <IconAction icon="eye" label="Fiche de l'agent" :to="'/agents/' + m.id" />
                <IconAction v-if="m.status === 'approved'" icon="fund" tone="accent" label="Approvisionner le float" @click="fundFor = m; fundAmount = null" />
                <IconAction icon="key" label="Réinitialiser le mot de passe" @click="resetFor = m" />
                <IconAction v-if="m.status !== 'approved'" icon="check" tone="ok" label="Valider l'agrément" :disabled="busy === m.id" @click="decide(m, 'approved')" />
                <IconAction v-if="m.status !== 'rejected'" icon="ban" tone="danger" :label="m.status === 'approved' ? 'Suspendre l\'agrément' : 'Rejeter'" :disabled="busy === m.id" @click="decide(m, 'rejected')" />
                <IconAction icon="power" :tone="m.active ? 'danger' : 'ok'" :label="m.active ? 'Désactiver le compte' : 'Activer le compte'" @click="toggleFor = m" />
              </td>
            </tr>
          </tbody>
        </table>
        <div v-if="!loading && !rows.length" class="empty">
          <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>
          <strong>Aucun agent {{ status ? LABEL[status].toLowerCase() : '' }}</strong>
          Créez votre premier agent avec « + Créer un agent » : il se connecte ensuite dans l'application FlashPay (profil « Agent »).
        </div>
      </div>
    </section>

    <AccountForm v-if="creating" kind="agent" :country="country" :city="city" @close="creating = false" @created="load" />
    <Modal v-if="fundFor" title="Approvisionner le float" :subtitle="fundFor.name + ' · solde actuel ' + money(fundFor.float, fundFor.currency)" @close="fundFor = null">
      <div><label class="field">Montant</label><input v-model.number="fundAmount" type="number" min="100" step="100" placeholder="Ex. 500000" /></div>
      <div><label class="field">Référence du versement (facultatif)</label><input v-model.trim="fundNote" placeholder="Ex. reçu n° 1234 / virement BGFI" /></div>
      <template #foot>
        <button class="btn-normal" @click="fundFor = null">Annuler</button>
        <button class="btn" :disabled="!fundAmount || fundAmount < 100 || busy" @click="fund">Créditer {{ fundAmount ? money(fundAmount, fundFor.currency) : '' }}</button>
      </template>
    </Modal>
    <AccountStatusModal v-if="toggleFor" :user-id="toggleFor.user_id" :name="toggleFor.name" :phone="toggleFor.phone" :active="!toggleFor.active" @close="toggleFor = null" @done="load" />
    <PasswordReset v-if="resetFor" :user-id="resetFor.user_id" :name="resetFor.name" :phone="resetFor.phone" @close="resetFor = null" />
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
import Modal from '../components/Modal.vue'
import Flag from '../components/Flag.vue'
import AccountStatusModal from '../components/AccountStatusModal.vue'

const TABS = [
  { key: '', label: 'Tous' },
  { key: 'pending', label: 'En attente' },
  { key: 'approved', label: 'Actifs' },
  { key: 'rejected', label: 'Rejetés / suspendus' },
]
const LABEL = { pending: 'En attente', approved: 'Actif', rejected: 'Suspendu' }
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
const toggleFor = ref(null)
const geo = ref([])
const country = ref(route.query.country || '')
const city = ref(route.query.city || '')
const byCountry = ref({})
const level = ref(route.query.level || '')
const levels = ref({})
const citiesOf = (iso) => geo.value.find((c) => c.country === iso)?.cities || []
const countryName = (iso) => geo.value.find((c) => c.country === iso)?.name || iso
api.get('/admin/geo').then(({ data }) => { geo.value = data.countries }).catch(() => {})
const fundFor = ref(null)
const fundAmount = ref(null)
const fundNote = ref('')
async function fund() {
  busy.value = fundFor.value.id
  try {
    await api.post(`/admin/agents/${fundFor.value.id}/float`, { amount: fundAmount.value, note: fundNote.value || undefined })
    fundFor.value = null
    fundNote.value = ''
    await load()
  } finally {
    busy.value = null
  }
}
const total = computed(() => Object.values(counts.value).reduce((a, b) => a + Number(b), 0))

async function load() {
  loading.value = true
  try {
    const { data } = await api.get('/admin/agents', { params: { status: status.value || undefined, q: q.value || undefined, country: country.value || undefined, city: city.value || undefined, level: level.value || undefined } })
    rows.value = data.data
    levels.value = data.levels || {}
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
    await api.post(`/admin/agents/${m.id}/validate`, { decision })
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
  { label: 'Agent', value: (m) => m.name },
  { label: 'Téléphone', value: (m) => fmtPhone(m.phone) },
  { label: 'Identifiant', value: (m) => m.agent_code },
  { label: 'Niveau', value: (m) => ({ super: 'Super-agent', sub: 'Sous-agent', simple: 'Agent' }[m.level] || '') },
  { label: 'Super-agent', value: (m) => m.parent_name },
  { label: 'Pays', value: (m) => m.country },
  { label: 'Ville', value: (m) => m.city },
  { label: 'Zone', value: (m) => m.zone },
  { label: 'Float', value: (m) => m.float },
  { label: 'Devise', value: (m) => m.currency },
  { label: 'Float gelé', value: (m) => (m.wallet_status === 'frozen' ? 'Oui' : 'Non') },
  { label: 'Agrément', value: (m) => LABEL[m.status] || m.status },
  { label: 'Compte', value: (m) => (m.active ? 'Actif' : 'Désactivé') },
  { label: 'Créé le', value: (m) => fmtDate(m.created_at) },
]
const expFetch = (onP) => fetchAllPages('/admin/agents', { status: status.value || undefined, q: q.value || undefined, country: country.value || undefined, city: city.value || undefined, level: level.value || undefined }, onP)
</script>

<style scoped>
tr.clickable { cursor: pointer; }
tr.clickable:hover td { background: var(--surface-2); }
tr.off td { background: #fff7f7; }
.kpi { text-align: left; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 14px 16px; cursor: pointer; font: inherit; box-shadow: var(--shadow); transition: border-color .15s, transform .15s; }
.kpi:hover { border-color: var(--link); transform: translateY(-1px); }
.kpi.on { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(225, 29, 42, .10); }
.kpi .k { display: block; color: var(--text-2); font-size: 12.5px; }
.kpi .v { display: block; font-size: 24px; font-weight: 750; margin-top: 2px; }
</style>
