<template>
  <div class="gw-page accent-pawa">
    <div class="gw-top">
      <div class="gw-top-left">
        <h1>Passerelle PawaPay</h1>
        <div class="gw-pills" v-if="o">
          <span class="gw-pill" :class="o.configured && o.rail_enabled ? 'ok' : 'warn'">{{ o.configured && o.rail_enabled ? 'Configurée' : 'Non configurée' }}</span>
          <span class="gw-pill" :class="o.sandbox ? 'info' : 'accent'">{{ o.sandbox ? 'Sandbox' : 'Production' }}</span>
          <span class="gw-pill muted">API v2</span>
        </div>
      </div>
      <div class="actions">
        <a v-if="o" class="btn-normal" :href="o.dashboard_url" target="_blank" rel="noopener">Tableau de bord PawaPay</a>
        <button class="btn-normal" :disabled="loading" @click="load(true)">{{ loading ? '…' : 'Actualiser' }}</button>
      </div>
    </div>

    <div v-if="error" class="gw-note err">{{ error }}</div>
    <div v-if="toast" class="fp-toast" :class="toast.kind" @click="toast = null">{{ toast.text }}</div>
    <div v-if="!o && loading" class="gw-note">Chargement…</div>

    <div v-if="o" class="gw-stats">
      <div class="gw-stat"><span>Solde</span><b>{{ balanceText }}</b><small>{{ o.balances ? 'compte PawaPay' : (o.balances_error ? 'indisponible' : '—') }}</small></div>
      <div class="gw-stat"><span>Collectes · 30 j</span><b>{{ n(o.stats.deposit) }}</b></div>
      <div class="gw-stat"><span>Versements · 30 j</span><b>{{ n(o.stats.payout) }}</b></div>
      <div class="gw-stat"><span>En attente</span><b>{{ n(o.stats.pending) }}</b><small :class="{ 't-err': o.stats.failed }">{{ n(o.stats.failed) }} échec(s) · 30 j</small></div>
    </div>

    <div v-if="o" class="gw-cols">
      <!-- Configuration -->
      <section class="gw-section">
        <GwHead icon="key" title="Configuration (variables Railway)" />
        <div class="gw-card">
          <div class="gw-rows">
            <div class="gw-row"><span class="gw-dot" :class="{ off: !o.enabled_flag }"></span>
              <div class="gw-main"><b>PAWAPAY_ENABLED</b><small>active PawaPay (collecte, versement, remittance)</small></div>
              <div class="gw-end"><span class="gw-pill" :class="o.enabled_flag ? 'ok' : 'err'">{{ o.enabled_flag ? 'true' : 'false' }}</span></div></div>
            <div class="gw-row"><span class="gw-dot" :class="{ off: !o.token }"></span>
              <div class="gw-main"><b>PAWAPAY_API_TOKEN</b><small>jeton API (tableau de bord › Developers)</small></div>
              <div class="gw-end"><span class="gw-pill" :class="o.token ? 'ok' : 'err'">{{ o.token ? 'Présent' : 'Absent' }}</span></div></div>
            <div class="gw-row"><span class="gw-dot"></span>
              <div class="gw-main"><b>PAWAPAY_BASE_URL</b><small class="mono">{{ o.base_url }}</small></div></div>
            <div class="gw-row"><span class="gw-dot" :class="{ off: !o.check_balance }"></span>
              <div class="gw-main"><b>Contrôle du solde</b><small>avant chaque versement (PAWAPAY_CHECK_BALANCE)</small></div>
              <div class="gw-end"><span class="gw-pill" :class="o.check_balance ? 'ok' : 'muted'">{{ o.check_balance ? 'Actif' : 'Inactif' }}</span></div></div>
          </div>
        </div>
        <div class="gw-card">
          <div class="gw-card-h"><div class="t"><h3>URL de callback</h3><p>À saisir dans PawaPay › Developers › Callback URLs (Deposits, Payouts, Refunds, Remittances)</p></div></div>
          <div class="gw-card-b"><GwField :value="o.callback_url" /></div>
        </div>
      </section>

      <!-- Test -->
      <section class="gw-section">
        <GwHead icon="shield" title="Test du numéro" />
        <div class="gw-card">
          <div class="gw-card-h"><div class="t"><h3>Opérateur reconnu par PawaPay</h3><p>POST /v2/predict-provider — aucun mouvement d'argent</p></div></div>
          <div class="gw-card-b">
            <div class="row-test">
              <input v-model="phone" type="tel" placeholder="242063456789" @keyup.enter="test" />
              <button class="btn-normal" :disabled="testing || !o.configured || !phone" @click="test">{{ testing ? '…' : 'Tester' }}</button>
            </div>
            <div v-if="testRes" class="gw-note" :class="testRes.ok ? 'ok' : 'err'" style="margin-top:10px;">
              <template v-if="testRes.ok">Opérateur <b>{{ testRes.provider }}</b> · pays {{ testRes.country }} · numéro {{ testRes.phone }}</template>
              <template v-else>{{ testRes.message }}</template>
            </div>
            <p v-if="o.sandbox" class="small muted" style="margin-top:10px;">Sandbox Congo : 242063456789 (MTN) et 242053456789 (Airtel) réussissent ; 242063456039 refuse une collecte ; 242063456089 refuse un versement.</p>
          </div>
        </div>
        <div class="gw-card">
          <div class="gw-card-h"><div class="t"><h3>Pays confiés à PawaPay</h3><p>Console › Pays &amp; change</p></div></div>
          <div class="gw-rows">
            <div class="gw-row"><span class="gw-dot" :class="{ off: !o.countries_collect.length }"></span>
              <div class="gw-main"><b>Collecte</b><small>{{ o.countries_collect.join(', ') || 'aucun pays' }}</small></div></div>
            <div class="gw-row"><span class="gw-dot" :class="{ off: !o.countries_payout.length }"></span>
              <div class="gw-main"><b>Versement</b><small>{{ o.countries_payout.join(', ') || 'aucun pays' }}</small></div></div>
          </div>
          <div class="gw-card-b"><router-link class="btn-normal sm" to="/corridors">Choisir les pays</router-link></div>
        </div>
      </section>
    </div>

    <!-- Couverture -->
    <section v-if="o" class="gw-section">
      <GwHead icon="globe" title="Couverture du compte" :count="coverage.length || null" />
      <div class="gw-card">
        <div v-if="o.coverage_error" class="gw-note err">Couverture non lue : {{ o.coverage_error }}</div>
        <div v-else-if="!o.configured" class="gw-empty">Renseignez PAWAPAY_ENABLED et PAWAPAY_API_TOKEN sur Railway.</div>
        <div v-else-if="!coverage.length" class="gw-empty">Aucun pays ouvert sur ce compte.</div>
        <div v-else class="gw-scroll">
          <table class="gw-table">
            <thead><tr><th>Pays</th><th>Opérateurs</th><th>Collecte</th><th>Versement</th><th>Solde</th></tr></thead>
            <tbody>
              <tr v-for="c in coverage" :key="c.iso">
                <td><b>{{ c.iso }}</b></td>
                <td>{{ c.providers.join(', ') || '—' }}</td>
                <td><span class="gw-pill" :class="c.collect ? 'ok' : 'muted'">{{ c.collect ? 'Ouverte' : 'Fermée' }}</span></td>
                <td><span class="gw-pill" :class="c.payout ? 'ok' : 'muted'">{{ c.payout ? 'Ouvert' : 'Fermé' }}</span></td>
                <td class="nowrap">{{ balanceOf(c.iso) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="o.coverage_synced_at" class="small muted" style="padding:10px 16px;">Lue le {{ dt(o.coverage_synced_at) }}</div>
      </div>
    </section>

    <!-- Opérations -->
    <section v-if="o" class="gw-section">
      <GwHead icon="swap" title="Opérations PawaPay" :count="o.requests.length" />
      <div class="gw-card">
        <div v-if="!o.requests.length" class="gw-empty">Aucune opération.</div>
        <div v-else class="gw-scroll">
          <table class="gw-table">
            <thead><tr><th>Date</th><th>Type</th><th>Référence</th><th>ID PawaPay</th><th>Montant</th><th>Statut</th><th></th></tr></thead>
            <tbody>
              <tr v-for="r in o.requests" :key="r.id">
                <td class="nowrap">{{ dt(r.created_at) }}</td>
                <td>{{ r.partner === 'pawapay' ? 'International' : (r.method === 'deposit' ? 'Collecte' : 'Versement') }}</td>
                <td class="mono"><router-link v-if="r.transaction" :to="'/transactions/' + r.transaction.id">{{ r.transaction.reference }}</router-link><span v-else>{{ r.reference }}</span></td>
                <td class="mono small">{{ r.partner_ref || '—' }}</td>
                <td class="nowrap">{{ r.amount != null ? n(r.amount) + ' ' + (r.currency || '') : '—' }}</td>
                <td><span class="gw-pill" :class="STATUS_CLS[r.status] || 'muted'">{{ STATUS[r.status] || r.status }}</span>
                  <span v-if="r.raw_status" class="small muted" style="margin-left:6px;">{{ r.raw_status }}</span>
                  <div v-if="r.message" class="small t-err" style="margin-top:4px; max-width:320px; overflow-wrap:anywhere;">{{ r.message }}</div></td>
                <td><button v-if="!r.final" class="btn-link" @click="refresh(r)">Vérifier</button></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '../services/api'
import GwHead from '../components/GwHead.vue'
import GwField from '../components/GwField.vue'

const o = ref(null)
const loading = ref(false)
const error = ref('')
const toast = ref(null)
const phone = ref('')
const testing = ref(false)
const testRes = ref(null)
const STATUS = { new: 'Nouveau', pending: 'En cours', successful: 'Réussi', failed: 'Échoué' }
const STATUS_CLS = { pending: 'info', successful: 'ok', failed: 'err' }
const nf = new Intl.NumberFormat('fr-FR')
const n = (v) => nf.format(Math.floor(v || 0))
const dt = (s) => (s ? new Date(s).toLocaleString('fr-FR', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '')
const ISO3 = { CG: 'COG', CD: 'COD', CM: 'CMR', GA: 'GAB', BJ: 'BEN', BF: 'BFA', CI: 'CIV', SN: 'SEN', TG: 'TGO', ML: 'MLI', NE: 'NER', GN: 'GIN', TD: 'TCD', CF: 'CAF', GQ: 'GNQ', GH: 'GHA', KE: 'KEN', NG: 'NGA', RW: 'RWA', UG: 'UGA', ZM: 'ZMB', TZ: 'TZA', MW: 'MWI', MZ: 'MOZ', SL: 'SLE', ET: 'ETH', LS: 'LSO', ZW: 'ZWE' }

const coverage = computed(() => Object.entries(o.value?.coverage || {}).map(([iso, c]) => ({ iso, ...c })).filter((c) => c.collect || c.payout))
const balanceText = computed(() => {
  const b = o.value?.balances
  if (!b) return '—'
  if (!b.length) return '0'
  return b.map((x) => n(x.balance) + ' ' + x.currency).join(' · ')
})
const balanceOf = (iso) => {
  const rows = (o.value?.balances || []).filter((b) => !b.country || b.country === ISO3[iso])
  return rows.length ? rows.map((b) => n(b.balance) + ' ' + b.currency).join(' · ') : '—'
}
function showToast(text, kind = 'ok') {
  toast.value = { text, kind }
  setTimeout(() => { toast.value = null }, 5000)
}
async function load(refresh = false) {
  loading.value = true
  error.value = ''
  try {
    o.value = (await api.get('/admin/pawapay/overview', { params: refresh === true ? { refresh: 1 } : {} })).data
  } catch (e) {
    error.value = e.response?.data?.message || e.message
  } finally {
    loading.value = false
  }
}
async function test() {
  testing.value = true
  testRes.value = null
  try {
    testRes.value = (await api.post('/admin/pawapay/test-number', { phone: phone.value })).data
  } catch (e) {
    testRes.value = { ok: false, message: e.response?.data?.message || e.message }
  } finally {
    testing.value = false
  }
}
async function refresh(r) {
  try {
    const { data } = await api.post(`/admin/pawapay/requests/${r.id}/refresh`)
    showToast(`Statut PawaPay : ${data.raw_status || data.status}`)
    load()
  } catch (e) {
    showToast(e.response?.data?.message || e.message, 'err')
  }
}
onMounted(load)
</script>

<style scoped>
.row-test { display: flex; gap: 8px; }
.row-test input { flex: 1; min-width: 0; margin: 0; }
.mono { font-family: ui-monospace, monospace; }
.small { font-size: 12px; }
.muted { color: var(--text-2); }
.t-err { color: #b91c1c; }
.nowrap { white-space: nowrap; }
.fp-toast { position: fixed; top: 16px; right: 16px; z-index: 1000; max-width: 460px; padding: 12px 16px; border-radius: 10px; color: #fff; font-size: 13.5px; box-shadow: 0 8px 24px rgba(0,0,0,.18); cursor: pointer; background: #15803d; }
.fp-toast.err { background: #b91c1c; }
</style>
