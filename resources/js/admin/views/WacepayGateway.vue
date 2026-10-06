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
              <span v-if="s.type === 'card'" class="gw-pill warn">Carte</span>
              <span v-if="s.type === 'bank'" class="gw-pill warn">Banque</span>
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
      <div class="gw-cols three">
        <div class="gw-card">
          <div class="gw-card-h"><div class="t"><h3>Collecte mobile money</h3><p>POST payments/create</p></div></div>
          <form class="gw-card-b" @submit.prevent="testPayin">
            <div class="gw-form">
              <label class="full">Service (wp-subscription-key)
                <select v-model="tin.service_id"><option value="">— choisir —</option><option v-for="s in walletPayin" :key="s.id" :value="s.id">{{ s.name || s.id }}{{ s.country ? ' · ' + s.country : '' }}</option></select></label>
              <label class="full">Numéro (customer_msisdn)
                <div class="ph">
                  <select v-model="tin.iso" :title="country(tin.iso)?.name"><option v-for="c in dialList" :key="c.iso" :value="c.iso">{{ c.flag }} +{{ c.dial }} · {{ c.name }}</option></select>
                  <span class="dial">{{ country(tin.iso)?.flag }} +{{ country(tin.iso)?.dial }}</span>
                  <input v-model.trim="tin.local" inputmode="tel" :placeholder="sample(tin.iso)" />
                </div>
                <small class="hint">{{ fullPhone(tin) || '—' }}</small></label>
              <label>Montant (min. 100)<input v-model.number="tin.amount" type="number" min="100" /></label>
              <label>Devise<input v-model="tin.currency" maxlength="3" class="up" /></label>
              <label>Opérateur<input v-model="tin.operator" placeholder="MTN, AIRTEL…" class="up" /></label>
              <label>Nom du client<input v-model="tin.name" /></label>
            </div>
            <button class="gw-btn block" :disabled="testBusy || !tin.service_id || !tin.local || !tin.amount">{{ testBusy === 'in' ? 'Envoi…' : 'Envoyer la demande de paiement' }}</button>
          </form>
        </div>
        <div class="gw-card">
          <div class="gw-card-h"><div class="t"><h3>Carte · Compte bancaire</h3><p>Page de paiement WacePay</p></div>
            <div class="side">
              <span class="gw-pill" :class="o.checkout?.card ? 'ok' : 'muted'" :title="o.checkout?.card ? 'Recharges par carte de l\'app via WacePay' : 'FLASHPAY_CARD_DRIVER ≠ wacepay'">Carte</span>
              <span class="gw-pill" :class="o.checkout?.bank ? 'ok' : 'muted'" :title="o.checkout?.bank ? 'Recharges par compte bancaire de l\'app via WacePay' : 'FLASHPAY_BANK_DEBIT_DRIVER ≠ wacepay'">Banque</span>
            </div></div>
          <form class="gw-card-b" @submit.prevent="testCheckout">
            <div class="seg">
              <button type="button" :class="{ on: tck.method === 'card' }" @click="tck.method = 'card'">💳 Carte Visa / Mastercard</button>
              <button type="button" :class="{ on: tck.method === 'bank' }" @click="tck.method = 'bank'">🏦 Compte bancaire</button>
            </div>
            <div class="gw-form">
              <label class="full">Service WacePay
                <select v-model="tck.service_id">
                  <option value="">Automatique{{ autoCheckout ? ' (' + autoCheckout + ')' : ' — aucun service détecté' }}</option>
                  <option v-for="s in payinServices" :key="s.id" :value="s.id">{{ s.name || s.id }}{{ s.country ? ' · ' + s.country : '' }}{{ s.type !== 'wallet' ? ' · ' + (s.type === 'card' ? 'carte' : 'banque') : '' }}</option>
                </select></label>
              <label>Montant (min. 100)<input v-model.number="tck.amount" type="number" min="100" /></label>
              <label>Devise<input v-model="tck.currency" maxlength="3" class="up" /></label>
              <label class="full">Téléphone du client
                <div class="ph">
                  <select v-model="tck.iso"><option v-for="c in dialList" :key="c.iso" :value="c.iso">{{ c.flag }} +{{ c.dial }} · {{ c.name }}</option></select>
                  <span class="dial">{{ country(tck.iso)?.flag }} +{{ country(tck.iso)?.dial }}</span>
                  <input v-model.trim="tck.local" inputmode="tel" :placeholder="sample(tck.iso)" />
                </div></label>
              <label>Nom du client<input v-model="tck.name" /></label>
              <label>E-mail<input v-model.trim="tck.email" type="email" placeholder="client@exemple.com" /></label>
            </div>
            <button class="gw-btn block" :disabled="testBusy || !tck.amount">{{ testBusy === 'ck' ? 'Création…' : 'Créer la page de paiement' }}</button>
            <a v-if="checkoutUrl" class="gw-btn block ghost" :href="checkoutUrl" target="_blank" rel="noopener">Ouvrir la page de paiement ↗</a>
          </form>
        </div>
        <div class="gw-card">
          <div class="gw-card-h"><div class="t"><h3>Versement</h3><p>POST payout/execute</p></div></div>
          <form class="gw-card-b" @submit.prevent="testPayout">
            <div class="gw-form">
              <label class="full">Service (payoutSubscriptionId)
                <select v-model="tout.service_id"><option value="">— choisir —</option><option v-for="s in payoutServices" :key="s.id" :value="s.id">{{ s.name || s.id }}{{ s.country ? ' · ' + s.country : '' }}</option></select></label>
              <label class="full">Numéro (recipientMsisdn)
                <div class="ph">
                  <select v-model="tout.iso"><option v-for="c in dialList" :key="c.iso" :value="c.iso">{{ c.flag }} +{{ c.dial }} · {{ c.name }}</option></select>
                  <span class="dial">{{ country(tout.iso)?.flag }} +{{ country(tout.iso)?.dial }}</span>
                  <input v-model.trim="tout.local" inputmode="tel" :placeholder="sample(tout.iso)" />
                </div>
                <small class="hint">{{ fullPhone(tout) || '—' }}</small></label>
              <label>Montant<input v-model.number="tout.amount" type="number" min="1" /></label>
              <label>Bénéficiaire (recipientName)<input v-model="tout.name" /></label>
            </div>
            <button class="gw-btn block" :disabled="testBusy || !tout.service_id || !tout.local || !tout.amount">{{ testBusy === 'out' ? 'Envoi…' : 'Envoyer le versement' }}</button>
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
const tin = reactive({ service_id: '', iso: 'CG', local: '', amount: 100, currency: 'XAF', operator: '', name: 'Test FlashPay' })
const tout = reactive({ service_id: '', iso: 'CG', local: '', amount: 100, name: 'Test FlashPay' })
const tck = reactive({ method: 'card', service_id: '', iso: 'CG', local: '', amount: 100, currency: 'XAF', name: 'Test FlashPay', email: '' })
const checkoutUrl = ref('')
// Indicatifs : pays des corridors + référentiel WacePay (renvoyés par /admin/wacepay/overview)
const dialList = computed(() => o.value?.countries || [])
const country = (iso) => dialList.value.find((c) => c.iso === iso)
const sample = (iso) => { const c = country(iso); return c?.local_length ? `${c.local_length} chiffres` : 'Numéro' }
// Numéro international : +indicatif + numéro national (0 de tête retiré sauf pays où il fait partie du numéro)
function fullPhone(f) {
  const c = country(f.iso)
  let d = String(f.local || '').replace(/\D/g, '')
  if (!c || !d) return ''
  if (d.startsWith('00')) return '+' + d.slice(2)
  if (d.startsWith(c.dial) && d.length > (c.local_length || 0)) d = d.slice(c.dial.length)
  if (!c.leading_zero) d = d.replace(/^0+/, '')
  else if (c.local_length && d.length === c.local_length - 1 && !d.startsWith('0')) d = '0' + d
  return '+' + c.dial + d
}
// Numéro collé au format international : on choisit le bon pays automatiquement
function autoCountry(f) {
  const raw = String(f.local || '')
  if (!raw.startsWith('+') && !raw.startsWith('00')) return
  const d = raw.replace(/\D/g, '').replace(/^00/, '')
  const hit = [...dialList.value].sort((a, b) => b.dial.length - a.dial.length).find((c) => d.startsWith(c.dial))
  if (hit) { f.iso = hit.iso; f.local = d.slice(hit.dial.length) }
}
for (const f of [tin, tout, tck]) watch(() => f.local, () => autoCountry(f))
// Service choisi : pays, devise et opérateur repris du service WacePay
watch(() => tin.service_id, (id) => {
  const s = (services.value || []).find((x) => x.id === id)
  if (!s) return
  if (s.country) tin.iso = s.country
  if (s.currency) tin.currency = s.currency
  if (s.operator) tin.operator = s.operator
})
watch(() => tout.service_id, (id) => {
  const s = (services.value || []).find((x) => x.id === id)
  if (s?.country) tout.iso = s.country
})
watch(() => tck.service_id, (id) => {
  const s = (services.value || []).find((x) => x.id === id)
  if (s?.country) tck.iso = s.country
  if (s?.currency) tck.currency = s.currency
  if (s?.type === 'card' || s?.type === 'bank') tck.method = s.type
})
const autoCheckout = computed(() => {
  const id = tck.method === 'bank' ? o.value?.checkout?.bank_service : o.value?.checkout?.card_service
  if (!id) return ''
  const s = (services.value || []).find((x) => x.id === id)
  return s?.name || id
})

const nf = new Intl.NumberFormat('fr-FR')
const n = (v) => nf.format(Math.floor(v || 0))
const dt = (s) => (s ? new Date(s).toLocaleString('fr-FR', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '')
const payinServices = computed(() => (services.value || []).filter((s) => s.payin))
const walletPayin = computed(() => payinServices.value.filter((s) => (s.type || 'wallet') === 'wallet'))
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
    const body = kind === 'in'
      ? { service_id: tin.service_id, phone: fullPhone(tin), amount: tin.amount, currency: tin.currency, country: tin.iso, operator: tin.operator, name: tin.name }
      : { service_id: tout.service_id, phone: fullPhone(tout), amount: tout.amount, name: tout.name }
    const { data } = await api.post(kind === 'in' ? '/admin/wacepay/test-payin' : '/admin/wacepay/test-payout', body)
    const r = data.request
    showToast(r.status === 'failed' ? (r.message || 'Refusé par WacePay') : `${r.reference} envoyé`, r.status === 'failed' ? 'err' : 'ok')
    await load()
  } catch (e) {
    const errs = e.response?.data?.errors
    showToast((errs && Object.values(errs).flat().join(' ')) || msg(e), 'err')
  } finally { testBusy.value = '' }
}
async function testCheckout() {
  testBusy.value = 'ck'
  checkoutUrl.value = ''
  try {
    const { data } = await api.post('/admin/wacepay/test-checkout', {
      method: tck.method, service_id: tck.service_id || null, amount: tck.amount, currency: tck.currency,
      country: tck.iso, name: tck.name, email: tck.email || null, phone: fullPhone(tck) || null,
    })
    const r = data.request
    checkoutUrl.value = data.url || ''
    if (data.url) window.open(data.url, '_blank', 'noopener')
    showToast(r.status === 'failed' ? (r.message || 'Refusé par WacePay') : 'Page de paiement créée', r.status === 'failed' ? 'err' : 'ok')
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
.gw-cols.three { grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); }
.ph { display: flex; align-items: stretch; border: 1px solid var(--border); border-radius: 10px; overflow: hidden; background: var(--surface); margin-top: 6px; position: relative; }
.ph select { width: 108px; flex: none; border: 0; border-right: 1px solid var(--border); border-radius: 0; margin: 0; opacity: 0; position: absolute; left: 0; top: 0; bottom: 0; cursor: pointer; }
.ph .dial { width: 108px; flex: none; display: flex; align-items: center; gap: 4px; padding: 0 10px; font-weight: 700; border-right: 1px solid var(--border); background: var(--bg, #f8fafc); pointer-events: none; }
.ph .dial::after { content: '▾'; margin-left: auto; font-size: 11px; color: var(--text-2); }
.ph input { flex: 1; min-width: 0; border: 0; border-radius: 0; margin: 0; }
.hint { display: block; margin-top: 4px; font-size: 12px; color: var(--text-2); font-family: ui-monospace, monospace; }
.seg { display: flex; gap: 6px; margin-bottom: 12px; }
.seg button { flex: 1; padding: 9px 10px; border: 1px solid var(--border); border-radius: 10px; background: var(--surface); font: inherit; font-size: 13px; cursor: pointer; }
.seg button.on { border-color: var(--link); background: color-mix(in srgb, var(--link) 10%, transparent); font-weight: 700; }
.gw-btn.ghost { margin-top: 8px; text-align: center; text-decoration: none; background: transparent; color: var(--link); border: 1px solid var(--link); }
.ep { font-size: 13px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.t-err { color: #b91c1c; }
.small { font-size: 12px; }
.nowrap { white-space: nowrap; }
.fp-toast { position: fixed; top: 16px; right: 16px; z-index: 1000; max-width: 460px; padding: 12px 16px; border-radius: 10px; color: #fff; font-size: 13.5px; box-shadow: 0 8px 24px rgba(0,0,0,.18); cursor: pointer; background: #15803d; }
.fp-toast.err { background: #b91c1c; }
</style>
