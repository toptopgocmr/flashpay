<template>
  <div>
    <div class="page-header">
      <div>
        <h1>Clients</h1>
        <p>Tous les comptes clients FlashPay : fiche, wallet, KYC, activation, historique.</p>
      </div>
      <div class="actions">
        <ExportButton filename="clients" :columns="EXP_COLS" :fetch="expFetch" />
        <button class="btn-normal" @click="load(1)">Actualiser</button>
      </div>
    </div>

    <div v-if="msg" class="flash" :class="msg.type"><div>{{ msg.text }}</div></div>

    <div class="kpis mb">
      <button v-for="t in TILES" :key="t.key" class="kpi" :class="{ on: isOn(t) }" @click="applyTile(t)">
        <span class="k"><i v-if="t.dot" class="dot" :class="t.dot"></i>{{ t.label }}</span>
        <span class="v">{{ t.money ? short(counts[t.key]) + ' XAF' : n(counts[t.key]) }}</span>
      </button>
    </div>

    <section class="container">
      <div class="container-body filters">
        <div class="search">
          <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2"><circle cx="7" cy="7" r="5"/><path d="M11 11l4 4"/></svg>
          <input v-model="f.q" type="search" placeholder="Nom, téléphone ou e-mail…" @input="debounced" />
        </div>
        <select v-model="f.status" @change="load(1)" aria-label="Statut du compte">
          <option value="">Tous les statuts</option><option value="active">Actifs</option><option value="inactive">Désactivés</option>
        </select>
        <select v-model="f.kyc" @change="load(1)" aria-label="KYC">
          <option value="">Tous les KYC</option><option value="pending">KYC en attente</option><option value="verified">KYC validé</option><option value="rejected">KYC rejeté</option>
        </select>
        <select v-model="f.wallet" @change="load(1)" aria-label="Wallet">
          <option value="">Tous les wallets</option><option value="frozen">Wallets gelés</option>
        </select>
        <label class="date-range"><span>Inscrits du</span><input v-model="f.from" type="date" @change="load(1)" /><span>au</span><input v-model="f.to" type="date" @change="load(1)" /></label>
        <select v-model="f.sort" @change="load(1)" aria-label="Tri">
          <option value="">Plus récents</option><option value="activity">Dernière activité</option><option value="balance">Solde le plus élevé</option><option value="name">Nom (A → Z)</option>
        </select>
        <button v-if="hasFilter" class="btn-link" @click="resetFilters">Effacer</button>
      </div>

      <div v-if="selected.length" class="bulk">
        <strong>{{ selected.length }} sélectionné(s)</strong>
        <button class="btn-ok" :disabled="busy" @click="bulk(true)">Activer</button>
        <button class="btn-danger" :disabled="busy" @click="bulk(false)">Désactiver</button>
        <button class="btn-link" @click="selected = []">Annuler</button>
      </div>

      <div class="container-body flush" style="overflow-x: auto;">
        <table>
          <thead>
            <tr>
              <th style="width: 36px;"><input type="checkbox" :checked="allChecked" @change="toggleAll" aria-label="Tout sélectionner" /></th>
              <th>Client</th><th>KYC</th><th class="num">Solde</th><th class="num">Opérations</th><th>Dernière activité</th><th>Compte</th><th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="c in rows" :key="c.id" :class="{ off: !c.active }" class="clickable" @click="open(c)">
              <td @click.stop><input type="checkbox" :value="c.id" v-model="selected" /></td>
              <td>
                <div class="who">
                  <span class="ava">{{ initials(c.full_name) }}</span>
                  <div><strong>{{ c.full_name }}</strong><small class="mono">{{ $phone(c.phone) }}</small><small v-if="c.email">{{ c.email }}</small></div>
                </div>
              </td>
              <td><span class="status" :class="KYC[c.kyc_status]?.cls">{{ KYC[c.kyc_status]?.label || c.kyc_status }}</span></td>
              <td class="num">{{ money(c.balance, c.currency) }}<div v-if="c.wallet_status === 'frozen'" class="tag-frozen">Wallet gelé</div></td>
              <td class="num">{{ n(c.tx_count) }}</td>
              <td>{{ c.last_activity ? dt(c.last_activity) : '—' }}</td>
              <td>
                <span class="status" :class="c.active ? 'ok' : 'err'">{{ c.active ? 'Actif' : 'Désactivé' }}</span>
                <div v-if="!c.active && c.status_reason" class="hint">{{ c.status_reason }}</div>
              </td>
              <td class="actions-cell" @click.stop>
                <IconAction icon="eye" label="Fiche du client" :to="'/clients/' + c.id" />
                <IconAction icon="power" :tone="c.active ? 'danger' : 'ok'" :label="c.active ? 'Désactiver le compte' : 'Activer le compte'" @click="toggleFor = c" />
              </td>
            </tr>
          </tbody>
        </table>
        <div v-if="!loading && !rows.length" class="empty">
          <strong>Aucun client</strong>
          {{ hasFilter ? 'Aucun client ne correspond à ces filtres.' : 'Les clients s\'inscrivent eux-mêmes depuis l\'application FlashPay.' }}
        </div>
      </div>
      <div class="container-foot pager" v-if="meta && meta.last_page > 1">
        <button class="btn-normal" :disabled="!meta.prev_page_url" @click="load(meta.current_page - 1)">Précédent</button>
        <span style="margin: 0 12px;">Page {{ meta.current_page }} / {{ meta.last_page }} · {{ n(meta.total) }} client(s)</span>
        <button class="btn-normal" :disabled="!meta.next_page_url" @click="load(meta.current_page + 1)">Suivant</button>
      </div>
    </section>
    <AccountStatusModal v-if="toggleFor" :user-id="toggleFor.id" :name="toggleFor.full_name" :phone="toggleFor.phone" :active="!toggleFor.active"
      @close="toggleFor = null" @done="(m) => { msg = { type: 'info', text: m }; load(meta?.current_page || 1) }" />
  </div>
</template>

<script setup>
import ExportButton from '../components/ExportButton.vue'
import { fetchAllPages, fmtDate, fmtPhone } from '../utils/export'
import IconAction from '../components/IconAction.vue'
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../services/api'
import AccountStatusModal from '../components/AccountStatusModal.vue'

const router = useRouter()
const route = useRoute()
const KYC = {
  pending: { label: 'Non fourni', cls: 'muted' }, submitted: { label: 'À vérifier', cls: 'pending' },
  verified: { label: 'Validé', cls: 'ok' }, rejected: { label: 'Rejeté', cls: 'err' },
}
const TILES = [
  { key: 'total', label: 'Tous les clients', f: {} },
  { key: 'active', label: 'Actifs', dot: 'ok', f: { status: 'active' } },
  { key: 'inactive', label: 'Désactivés', dot: 'err', f: { status: 'inactive' } },
  { key: 'kyc_pending', label: 'KYC en attente', dot: 'warn', f: { kyc: 'pending' } },
  { key: 'frozen', label: 'Wallets gelés', dot: 'err', f: { wallet: 'frozen' } },
  { key: 'balance', label: 'Solde total', money: true, f: null },
]
const rows = ref([])
const meta = ref(null)
const counts = ref({})
const loading = ref(false)
const busy = ref(false)
const msg = ref(null)
const selected = ref([])
const toggleFor = ref(null)
const f = reactive({ q: '', status: '', kyc: '', wallet: '', from: '', to: '', sort: '' })
const hasFilter = computed(() => Object.entries(f).some(([k, v]) => v && k !== 'sort'))

const nf = new Intl.NumberFormat('fr-FR')
const n = (v) => (v == null ? '—' : nf.format(v))
const money = (v, c) => nf.format(v || 0) + ' ' + (c || 'XAF')
const short = (v) => (v == null ? '—' : v >= 1e6 ? (v / 1e6).toFixed(1).replace('.', ',') + ' M' : v >= 1e4 ? Math.round(v / 1e3) + ' k' : nf.format(v))
const dt = (s) => new Date(s).toLocaleString('fr-FR', { day: '2-digit', month: 'short', year: '2-digit', hour: '2-digit', minute: '2-digit' })
const initials = (s) => (s || '?').split(/\s+/).map((w) => w[0]).slice(0, 2).join('').toUpperCase()

const isOn = (t) => t.key === 'balance' ? f.sort === 'balance' : t.f && ['status', 'kyc', 'wallet'].every((k) => (t.f[k] || '') === f[k])
function applyTile(t) {
  if (t.key === 'balance') { f.sort = f.sort === 'balance' ? '' : 'balance'; load(1); return }
  if (!t.f) return
  f.status = t.f.status || ''; f.kyc = t.f.kyc || ''; f.wallet = t.f.wallet || ''
  load(1)
}
function resetFilters() { Object.assign(f, { q: '', status: '', kyc: '', wallet: '', from: '', to: '' }); load(1) }
let timer = null
function debounced() { clearTimeout(timer); timer = setTimeout(() => load(1), 300) }
const allChecked = computed(() => rows.value.length > 0 && rows.value.every((c) => selected.value.includes(c.id)))
function toggleAll() { selected.value = allChecked.value ? [] : rows.value.map((c) => c.id) }
function open(c) { router.push('/clients/' + c.id) }

async function load(page = 1) {
  loading.value = true
  try {
    const params = Object.fromEntries(Object.entries(f).filter(([, v]) => v))
    const { data } = await api.get('/admin/clients', { params: { ...params, page } })
    rows.value = data.data
    meta.value = data
    counts.value = data.counts || {}
  } catch (e) {
    msg.value = { type: 'err', text: e.response?.data?.message || e.message }
  } finally {
    loading.value = false
  }
}

async function bulk(active) {
  if (!confirm(`${active ? 'Activer' : 'Désactiver'} ${selected.value.length} client(s) ?`)) return
  busy.value = true
  try {
    const { data } = await api.post('/admin/accounts/bulk-status', { active, ids: selected.value })
    msg.value = { type: 'info', text: data.message }
    selected.value = []
    await load(meta.value?.current_page || 1)
  } catch (e) {
    msg.value = { type: 'err', text: e.response?.data?.message || e.message }
  } finally {
    busy.value = false
  }
}

onMounted(() => { if (route.query.sort) f.sort = String(route.query.sort); load() })

// --- Export de la liste (tous les résultats filtrés)
const EXP_COLS = [
  { label: 'Nom', value: (c) => c.full_name },
  { label: 'Téléphone', value: (c) => fmtPhone(c.phone) },
  { label: 'E-mail', value: (c) => c.email },
  { label: 'KYC', value: (c) => KYC[c.kyc_status]?.label || c.kyc_status },
  { label: 'Solde', value: (c) => c.balance },
  { label: 'Devise', value: (c) => c.currency },
  { label: 'Wallet', value: (c) => (c.wallet_status === 'frozen' ? 'Gelé' : 'Actif') },
  { label: 'Opérations', value: (c) => c.tx_count },
  { label: 'Dernière activité', value: (c) => fmtDate(c.last_activity) },
  { label: 'Compte', value: (c) => (c.active ? 'Actif' : 'Désactivé') },
  { label: 'Motif', value: (c) => c.status_reason },
  { label: 'Inscrit le', value: (c) => fmtDate(c.created_at) },
]
const expFetch = (onP) => fetchAllPages('/admin/clients', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), onP)
</script>

<style scoped>
.kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; }
.kpi { text-align: left; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 12px 16px; cursor: pointer; font: inherit; box-shadow: var(--shadow); }
.kpi:hover { border-color: var(--link); }
.kpi.on { border-color: var(--brand); box-shadow: 0 0 0 3px rgba(30, 58, 138, .12); }
.kpi .k { display: block; color: var(--text-2); font-size: 12.5px; }
.kpi .v { display: block; font-size: 22px; font-weight: 750; margin-top: 2px; }
.dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 6px; }
.dot.ok { background: var(--ok); } .dot.err { background: var(--err); } .dot.warn { background: var(--warn); }
.filters { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
.filters select, .filters input { padding: 8px 10px; border: 1px solid var(--border-strong); border-radius: 8px; font: inherit; }
.filters .search input { padding-left: 32px; }
.date-range { display: inline-flex; align-items: center; gap: 6px; }
.date-range span { color: var(--text-2); font-size: 13px; }
.bulk { display: flex; gap: 10px; align-items: center; padding: 10px 16px; background: var(--info-bg); border-top: 1px solid var(--border); }
.who { display: flex; gap: 10px; align-items: center; }
.who small { display: block; color: var(--text-2); font-size: 12px; }
.ava { width: 34px; height: 34px; border-radius: 50%; background: var(--info-bg); color: var(--link); display: grid; place-items: center; font-weight: 700; font-size: 12px; flex: none; }
tr.clickable { cursor: pointer; }
tr.clickable:hover td { background: var(--surface-2); }
tr.off td { background: #fff7f7; }
.hint { font-size: 12px; color: var(--text-2); }
.tag-frozen { font-size: 11px; color: var(--err); font-weight: 600; }
.actions-cell { white-space: nowrap; text-align: right; }
.empty { padding: 32px; text-align: center; color: var(--text-2); display: grid; gap: 4px; }
</style>
