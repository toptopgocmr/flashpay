<template>
  <div class="gw-page accent-wace">
    <div class="gw-top">
      <div class="gw-top-left">
        <h1>Passerelle WacePay</h1>
        <div class="gw-pills" v-if="o">
          <span class="gw-pill" :class="o.configured ? 'ok' : 'warn'">{{ o.configured ? 'Configurée' : 'Non configurée' }}</span>
          <span class="gw-pill" :class="o.sandbox ? 'info' : 'accent'">{{ o.sandbox ? 'Sandbox' : 'Production' }}</span>
          <span class="gw-pill muted">API {{ o.api === 'partner' ? 'Partenaire' : 'Business' }}</span>
        </div>
      </div>
      <div class="actions">
        <a v-if="o" class="btn-normal" :href="o.dashboard_url" target="_blank" rel="noopener">Tableau de bord WacePay</a>
        <button class="btn-normal" :disabled="loading" @click="load">Actualiser</button>
      </div>
    </div>

    <div v-if="error" class="gw-note err">{{ error }}</div>
    <div v-if="toast" class="fp-toast" :class="toast.kind" @click="toast = null">{{ toast.text }}</div>

    <!-- Indicateurs -->
    <div v-if="o" class="gw-stats">
      <div class="gw-stat"><span>Solde</span><b>{{ balanceText }}</b><small>{{ bal?.ok ? 'compte de versement' : (bal ? 'indisponible' : '…') }}</small></div>
      <div class="gw-stat"><span>Collectes · 30 j</span><b>{{ n(o.stats.payin) }}</b></div>
      <div class="gw-stat"><span>Versements · 30 j</span><b>{{ n(o.stats.payout) }}</b></div>
      <div class="gw-stat"><span>En attente</span><b>{{ n(o.stats.pending) }}</b><small :class="{ 't-err': o.stats.failed }">{{ n(o.stats.failed) }} échec(s) · 30 j</small></div>
    </div>

    <div v-if="o" class="gw-cols">
      <!-- Identifiants -->
      <section class="gw-section">
        <GwHead icon="key" title="Identifiants API" />
        <div class="gw-card">
          <div class="gw-card-h"><LockIco /><div class="t"><h3>Clé publique (API Key)</h3><p>DIGITWACE_PUBLIC_KEY</p></div></div>
          <div class="gw-card-b"><GwField :value="o.public_key" placeholder="Absente" /></div>
        </div>
        <div class="gw-card">
          <div class="gw-card-h"><LockIco /><div class="t"><h3>Clé privée (Secret Key)</h3><p>DIGITWACE_PRIVATE_KEY</p></div>
            <div class="side"><span class="gw-pill" :class="o.private_key ? 'ok' : 'err'">{{ o.private_key ? 'Présente' : 'Absente' }}</span></div></div>
        </div>
        <div class="gw-card">
          <div class="gw-card-h"><LockIco /><div class="t"><h3>Adresse de l'API</h3><p>DIGITWACE_BASE_URL</p></div></div>
          <div class="gw-card-b">
            <GwField :value="o.base_url" />
            <div v-if="wrongHost" class="gw-note">Adresse sandbox attendue : <b>https://sandbox-payinws.wacepay.io/api/v1/</b></div>
          </div>
        </div>
      </section>

      <!-- IP & connexion -->
      <section class="gw-section">
        <GwHead icon="shield" title="IP autorisées" />
        <div class="gw-card">
          <div class="gw-card-h"><div class="t"><h3>IP du serveur FlashPay</h3><p>À déclarer dans WacePay › Developers › IP Whitelist</p></div></div>
          <div class="gw-card-b"><GwField :value="o.server_ip" placeholder="Inconnue" /></div>
        </div>
        <div class="gw-card">
          <div class="gw-card-h">
            <div class="t"><h3>Connexion</h3><p>GET payments/get-token</p></div>
            <div class="side">
              <span v-if="diag" class="gw-pill" :class="diag.token_ok ? 'ok' : 'err'">{{ diag.token_ok ? 'Active' : 'Refusée' }}</span>
            </div>
          </div>
          <div class="gw-card-b">
            <template v-if="diag">
              <dl class="gw-kv">
                <dt>Réponse</dt><dd>{{ diag.http ? 'HTTP ' + diag.http : 'aucune' }} · {{ diag.ms }} ms</dd>
                <template v-if="diag.token_ok && diag.api === 'partner'">
                  <dt>Services collecte</dt><dd>{{ diag.services_payin ?? '—' }}<small v-if="diag.services_payin_error" class="t-err"> {{ diag.services_payin_error }}</small></dd>
                  <dt>Services versement</dt><dd>{{ diag.services_payout ?? '—' }}<small v-if="diag.services_payout_error" class="t-err"> {{ diag.services_payout_error }}</small></dd>
                </template>
                <template v-else><dt>Message</dt><dd>{{ diag.message }}</dd></template>
              </dl>
            </template>
            <button class="gw-btn block" :disabled="diagBusy" @click="diagnose">{{ diagBusy ? 'Test en cours…' : 'Tester la connexion' }}</button>
          </div>
        </div>
      </section>
    </div>

    <!-- Webhook -->
    <section v-if="o" class="gw-section">
      <GwHead icon="link" title="Webhook" />
      <div class="gw-card">
        <div class="gw-card-h"><div class="t"><h3>Endpoint de notification</h3><p>callback_url (collecte) · callbackUrl (versement)</p></div>
          <div class="side"><span class="gw-pill" :class="o.webhook_signed ? 'ok' : 'muted'">{{ o.webhook_signed ? 'Signé' : 'Non signé' }}</span></div></div>
        <div class="gw-card-b"><GwField :value="o.webhook" /></div>
      </div>
    </section>

    <!-- Services -->
    <section v-if="o" class="gw-section">
      <GwHead icon="list" title="Services" :count="services ? services.length : null">
        <button class="btn-normal" :disabled="svcBusy || !o.configured" @click="loadServices">{{ svcBusy ? 'Chargement…' : (services ? 'Recharger' : 'Charger les services') }}</button>
        <button class="btn-normal" :disabled="syncing || !o.configured" @click="sync">{{ syncing ? 'Synchronisation…' : 'Synchroniser les pays' }}</button>
      </GwHead>
      <div class="gw-card">
        <div v-if="svcError" class="gw-card-b"><div class="gw-note err">{{ svcError }}</div></div>
        <div v-if="!services && !svcError" class="gw-empty">payments/services · payout/services</div>
        <div v-else-if="services && !services.length" class="gw-empty">Aucun service actif sur ce compte.</div>
        <div v-else-if="services" class="gw-rows">
          <div v-for="s in services" :key="s.id" class="gw-row">
            <span class="gw-dot" :class="{ off: s.status && !/activ|enable|on/i.test(s.status) }"></span>
            <div class="gw-main">
              <b>{{ s.name || s.id }}</b>
              <small>{{ [s.country, s.currency, s.operator].filter(Boolean).join(' · ') || '—' }} · <span class="mono">{{ s.id }}</span></small>
            </div>
            <div class="gw-end">
              <span v-if="s.payin" class="gw-pill ok">Collecte</span>
              <span v-if="s.payout" class="gw-pill info">Versement</span>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Tests -->
    <section v-if="o" class="gw-section">
      <GwHead icon="flask" title="Tester" />
      <div class="gw-cols">
        <div class="gw-card">
          <div class="gw-card-h"><div class="t"><h3>Collecte</h3><p>POST payments/create</p></div></div>
          <form class="gw-card-b" @submit.prevent="testPayin">
            <div class="gw-form">
              <label class="full">Service (wp-subscription-key)
                <select v-model="tin.service_id"><option value="">— choisir —</option><option v-for="s in payinServices" :key="s.id" :value="s.id">{{ s.name || s.id }}{{ s.country ? ' · ' + s.country : '' }}</option></select></label>
              <label>Numéro (customer_msisdn)<input v-model.trim="tin.phone" placeholder="+237695562570" /></label>
              <label>Montant (min. 100)<input v-model.number="tin.amount" type="number" min="100" /></label>
              <label>Devise<input v-model="tin.currency" maxlength="3" class="up" /></label>
              <label>Pays (ISO2)<input v-model="tin.country" maxlength="2" class="up" /></label>
              <label>Opérateur<input v-model="tin.operator" placeholder="MTN, ORANGE…" class="up" /></label>
              <label>Nom du client<input v-model="tin.name" /></label>
            </div>
            <button class="gw-btn block" :disabled="testBusy || !tin.service_id || !tin.phone || !tin.amount">{{ testBusy === 'in' ? 'Envoi…' : 'Envoyer la demande de paiement' }}</button>
          </form>
        </div>
        <div class="gw-card">
          <div class="gw-card-h"><div class="t"><h3>Versement</h3><p>POST payout/execute</p></div></div>
          <form class="gw-card-b" @submit.prevent="testPayout">
            <div class="gw-form">
              <label class="full">Service (payoutSubscriptionId)
                <select v-model="tout.service_id"><option value="">— choisir —</option><option v-for="s in payoutServices" :key="s.id" :value="s.id">{{ s.name || s.id }}{{ s.country ? ' · ' + s.country : '' }}</option></select></label>
              <label>Numéro (recipientMsisdn)<input v-model.trim="tout.phone" placeholder="+237691234567" /></label>
              <label>Montant<input v-model.number="tout.amount" type="number" min="1" /></label>
              <label class="full">Bénéficiaire (recipientName)<input v-model="tout.name" /></label>
            </div>
            <button class="gw-btn block" :disabled="testBusy || !tout.service_id || !tout.phone || !tout.amount">{{ testBusy === 'out' ? 'Envoi…' : 'Envoyer le versement' }}</button>
          </form>
        </div>
      </div>
    </section>

    <!-- Transactions -->
    <section v-if="o" class="gw-section">
      <GwHead icon="swap" title="Transactions WacePay" :count="o.requests.length" />
      <div class="gw-card">
        <div v-if="!o.requests.length" class="gw-empty">Aucune transaction.</div>
        <div v-else class="gw-scroll">
          <table class="gw-table">
            <thead><tr><th>Date</th><th>Type</th><th>Référence</th><th>ID WacePay</th><th>Montant</th><th>Statut</th><th></th></tr></thead>
            <tbody>
              <tr v-for="r in o.requests" :key="r.id">
                <td class="nowrap">{{ dt(r.created_at) }}</td>
                <td>{{ OP[r.operation] || r.operation }}<span v-if="r.test" class="gw-pill muted" style="margin-left:6px;">test</span></td>
                <td class="mono"><router-link v-if="r.transaction" :to="'/transactions/' + r.transaction.id">{{ r.transaction.reference }}</router-link><span v-else>{{ r.reference }}</span></td>
                <td class="mono">{{ r.wace_id || '—' }}</td>
                <td class="nowrap">{{ r.amount != null ? n(r.amount) + ' ' + (r.currency || '') : '—' }}</td>
                <td><span class="gw-pill" :class="STATUS_CLS[r.status] || 'muted'">{{ STATUS[r.status] || r.status }}</span>
                  <div v-if="r.message" class="small t-err" style="margin-top:4px; max-width:320px; overflow-wrap:anywhere;">{{ r.message }}</div></td>
                <td><button v-if="!r.final" class="btn-link" @click="refresh(r)">Vérifier</button></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <!-- Points d'accès -->
    <section v-if="o?.endpoints" class="gw-section">
      <GwHead icon="route" title="Points d'accès" />
      <div class="gw-card">
        <div class="gw-rows">
          <div v-for="(path, k) in o.endpoints" :key="k" class="gw-row">
            <span class="gw-pill muted" style="min-width:58px; justify-content:center;">{{ METHOD[k] || 'GET' }}</span>
            <div class="gw-main"><span class="mono ep">{{ path }}</span></div>
            <div class="gw-end small" style="color:var(--text-2);">{{ ENDPOINT[k] || k }}</div>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup>
import { computed, h, onMounted, reactive, ref, watch } from 'vue'
import api from '../services/api'
import GwHead from '../components/GwHead.vue'
import GwField from '../components/GwField.vue'

const LockIco = () => h('svg', { class: 'lock', viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', 'stroke-width': 1.9 }, [
  h('rect', { x: 5, y: 11, width: 14, height: 10, rx: 2 }), h('path', { d: 'M8 11V7a4 4 0 0 1 8 0v4' }),
])

const OP = { payin: 'Collecte', payout: 'Versement', checkout: 'Carte / banque' }
const STATUS = { new: 'Nouveau', pending: 'En cours', successful: 'Réussi', failed: 'Échoué' }
const STATUS_CLS = { new: 'muted', pending: 'info', successful: 'ok', failed: 'err' }
const METHOD = { payin: 'POST', payout: 'POST' }
const ENDPOINT = { login: 'Jeton', payin_services: 'Services collecte', countries: 'Pays', payin: 'Créer une collecte', payin_status: 'Statut collecte', payout_services: 'Services versement', payout: 'Créer un versement', payout_list: 'Liste des versements', payout_tx: 'Détail versement', payout_refresh: 'Rafraîchir statut', balance: 'Historique du solde' }

const o = ref(null)
const bal = ref(null)
const diag = ref(null)
const services = ref(null)
const loading = ref(false)
const diagBusy = ref(false)
const svcBusy = ref(false)
const syncing = ref(false)
const testBusy = ref('')
const error = ref('')
const svcError = ref('')
const toast = ref(null)
const tin = reactive({ service_id: '', phone: '', amount: 100, currency: 'XAF', country: 'CM', operator: '', name: 'Test FlashPay' })
const tout = reactive({ service_id: '', phone: '', amount: 100, name: 'Test FlashPay' })
// Service choisi : pays, devise et opérateur repris du service WacePay
watch(() => tin.service_id, (id) => {
  const s = (services.value || []).find((x) => x.id === id)
  if (!s) return
  if (s.country) tin.country = s.country
  if (s.currency) tin.currency = s.currency
  if (s.operator) tin.operator = s.operator
})

const nf = new Intl.NumberFormat('fr-FR')
const n = (v) => nf.format(Math.floor(v || 0))
const dt = (s) => (s ? new Date(s).toLocaleString('fr-FR', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '')
const payinServices = computed(() => (services.value || []).filter((s) => s.payin))
const payoutServices = computed(() => (services.value || []).filter((s) => s.payout))
const balanceText = computed(() => {
  if (!bal.value) return '…'
  if (!bal.value.ok) return '—'
  if (!bal.value.accounts.length) return '0'
  return bal.value.accounts.map((a) => n(a.balance) + ' ' + a.currency).join(' · ')
})
const wrongHost = computed(() => o.value?.sandbox && o.value.api === 'partner' && !/sandbox-payinws\.wacepay\.io/.test(o.value.base_url || ''))

function showToast(text, kind = 'ok') {
  toast.value = { text, kind }
  setTimeout(() => (toast.value = null), 5000)
}
const msg = (e) => e.response?.data?.message || e.message

async function load() {
  loading.value = true
  error.value = ''
  try {
    o.value = (await api.get('/admin/wacepay/overview')).data
    if (o.value.configured) {
      api.get('/admin/digitwace/balances', { params: { refresh: 1 } }).then((r) => (bal.value = r.data)).catch(() => (bal.value = { ok: false }))
    } else bal.value = { ok: false }
  } catch (e) { error.value = msg(e) } finally { loading.value = false }
}

async function diagnose() {
  diagBusy.value = true
  try { diag.value = (await api.get('/admin/digitwace/diagnose')).data } catch (e) { showToast(msg(e), 'err') } finally { diagBusy.value = false }
}

async function loadServices() {
  svcBusy.value = true
  svcError.value = ''
  try {
    services.value = (await api.get('/admin/wacepay/services')).data.services
    if (!tin.service_id && payinServices.value.length) tin.service_id = payinServices.value[0].id
    if (!tout.service_id && payoutServices.value.length) tout.service_id = payoutServices.value[0].id
  } catch (e) { svcError.value = msg(e) } finally { svcBusy.value = false }
}

async function sync() {
  syncing.value = true
  try {
    const { data } = await api.post('/admin/corridors-sync/wacepay')
    showToast(`${data.countries ?? 0} pays · ${data.payers ?? 0} services synchronisés`)
  } catch (e) { showToast(msg(e), 'err') } finally { syncing.value = false }
}

async function runTest(kind) {
  testBusy.value = kind
  try {
    const { data } = await api.post(kind === 'in' ? '/admin/wacepay/test-payin' : '/admin/wacepay/test-payout', kind === 'in' ? { ...tin } : { ...tout })
    const r = data.request
    showToast(r.status === 'failed' ? (r.message || 'Refusé par WacePay') : `${r.reference} envoyé`, r.status === 'failed' ? 'err' : 'ok')
    await load()
  } catch (e) {
    const errs = e.response?.data?.errors
    showToast((errs && Object.values(errs).flat().join(' ')) || msg(e), 'err')
  } finally { testBusy.value = '' }
}
const testPayin = () => runTest('in')
const testPayout = () => runTest('out')

async function refresh(r) {
  try { await api.post(`/admin/wacepay/requests/${r.id}/refresh`); await load() } catch (e) { showToast(msg(e), 'err') }
}

onMounted(load)
</script>

<style scoped>
.up { text-transform: uppercase; }
.ep { font-size: 13px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.t-err { color: #b91c1c; }
.small { font-size: 12px; }
.nowrap { white-space: nowrap; }
.fp-toast { position: fixed; top: 16px; right: 16px; z-index: 1000; max-width: 460px; padding: 12px 16px; border-radius: 10px; color: #fff; font-size: 13.5px; box-shadow: 0 8px 24px rgba(0,0,0,.18); cursor: pointer; background: #15803d; }
.fp-toast.err { background: #b91c1c; }
</style>
