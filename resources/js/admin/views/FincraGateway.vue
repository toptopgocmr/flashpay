<template>
  <div class="gw-page accent-pawa">
    <div class="gw-top">
      <div class="gw-top-left">
        <h1 class="gw-h1-logo"><ProviderLogo name="Fincra" :size="34" /> Passerelle Fincra</h1>
        <div class="gw-pills" v-if="o">
          <span class="gw-pill" :class="o.configured && o.rail_enabled ? 'ok' : 'warn'">{{ o.configured && o.rail_enabled ? 'Configurée' : 'Non configurée' }}</span>
          <span class="gw-pill" :class="o.sandbox ? 'info' : 'accent'">{{ o.sandbox ? 'Sandbox' : 'Production' }}</span>
          <span class="gw-pill muted">Collecte · Versement · Remittance</span>
        </div>
      </div>
      <div class="actions">
        <a v-if="o" class="btn-normal" :href="o.docs_url" target="_blank" rel="noopener">Documentation</a>
        <a v-if="o" class="btn-normal" :href="o.dashboard_url" target="_blank" rel="noopener">Portail Fincra</a>
        <button class="btn-normal" :disabled="loading" @click="load">{{ loading ? '…' : 'Actualiser' }}</button>
      </div>
    </div>

    <div v-if="error" class="gw-note err">{{ error }}</div>
    <div v-if="toastMsg" class="fp-toast" :class="toastMsg.kind" @click="toastMsg = null">{{ toastMsg.text }}</div>
    <div v-if="!o && loading" class="gw-note">Chargement…</div>

    <div v-if="o" class="gw-stats">
      <div class="gw-stat"><span>Collectes · 30 j</span><b>{{ n(o.stats.deposit) }}</b><small>mobile money</small></div>
      <div class="gw-stat"><span>Versements · 30 j</span><b>{{ n(o.stats.payout) }}</b><small>dont remboursements</small></div>
      <div class="gw-stat"><span>International · 30 j</span><b>{{ n(o.stats.remit) }}</b><small>mobile money + banque</small></div>
      <div class="gw-stat"><span>En attente</span><b>{{ n(o.stats.pending) }}</b><small :class="{ 't-err': o.stats.failed }">{{ n(o.stats.failed) }} échec(s) · 30 j</small></div>
    </div>

    <div v-if="o" class="gw-cols">
      <section class="gw-section">
        <GwHead icon="key" title="Configuration (variables Railway)" />
        <div class="gw-card">
          <div class="gw-rows">
            <div class="gw-row"><span class="gw-dot" :class="{ off: !o.enabled_flag }"></span>
              <div class="gw-main"><b>FINCRA_ENABLED</b><small>active Fincra (collecte, versement, remittance)</small></div>
              <div class="gw-end"><span class="gw-pill" :class="o.enabled_flag ? 'ok' : 'err'">{{ o.enabled_flag ? 'true' : 'false' }}</span></div></div>
            <div class="gw-row"><span class="gw-dot" :class="{ off: !o.secret_key }"></span>
              <div class="gw-main"><b>FINCRA_SECRET_KEY</b><small class="mono">{{ o.secret_key || 'portail Fincra › Settings › API keys (en-tête api-key)' }}</small></div>
              <div class="gw-end"><span class="gw-pill" :class="o.secret_key ? 'ok' : 'err'">{{ o.secret_key ? 'Présente' : 'Absente' }}</span></div></div>
            <div class="gw-row"><span class="gw-dot" :class="{ off: !o.public_key }"></span>
              <div class="gw-main"><b>FINCRA_PUBLIC_KEY</b><small>en-tête x-pub-key</small></div>
              <div class="gw-end"><span class="gw-pill" :class="o.public_key ? 'ok' : 'muted'">{{ o.public_key ? 'Présente' : 'Absente' }}</span></div></div>
            <div class="gw-row"><span class="gw-dot" :class="{ off: !o.business_id }"></span>
              <div class="gw-main"><b>FINCRA_BUSINESS_ID</b><small class="mono">{{ o.business_id || 'identifiant entreprise (en-tête x-business-id, champ business)' }}</small></div>
              <div class="gw-end"><span class="gw-pill" :class="o.business_id ? 'ok' : 'err'">{{ o.business_id ? 'Présent' : 'Absent' }}</span></div></div>
            <div class="gw-row"><span class="gw-dot"></span>
              <div class="gw-main"><b>FINCRA_BASE_URL</b><small class="mono">{{ o.base_url }}</small></div></div>
            <div class="gw-row"><span class="gw-dot" :class="{ off: !o.remit_enabled }"></span>
              <div class="gw-main"><b>Remittance internationale</b><small>solde débité : {{ o.remit_source_currency ? o.remit_source_currency + ' (converti par Fincra, FINCRA_REMIT_SOURCE_CURRENCY)' : 'devise du bénéficiaire, sans conversion' }} · frais de change : {{ o.fee_bearer === 'customer' ? 'déduits du montant reçu' : 'à la charge de OfficePay' }}</small></div></div>
          </div>
          <div class="gw-card-b">
            <button class="btn-normal" :disabled="testing || !o.configured" @click="test">{{ testing ? 'Test…' : 'Tester la connexion' }}</button>
            <div v-if="testRes" class="gw-note" :class="testRes.ok ? 'ok' : 'err'" style="margin-top:10px;">{{ testRes.message }}<span v-if="testRes.ms" class="small muted"> · {{ testRes.ms }} ms</span></div>
            <div v-if="testRes?.balances?.length" class="flags" style="margin-top:8px;"><span v-for="b in testRes.balances" :key="b.currency" class="gw-pill muted">{{ b.currency }} {{ n(b.available) }}</span></div>
          </div>
        </div>
        <div class="gw-card">
          <div class="gw-card-h"><div class="t"><h3>URL des webhooks</h3><p>À déclarer dans le portail Fincra (charge.successful, payout.successful, payout.failed)</p></div>
            <span class="gw-pill" :class="o.webhook_signed ? 'ok' : 'warn'">{{ o.webhook_signed ? 'Signature vérifiée' : 'FINCRA_WEBHOOK_SECRET absent' }}</span></div>
          <div class="gw-card-b"><GwField :value="o.webhook_url" /></div>
        </div>
        <div class="gw-card">
          <div class="gw-card-h"><div class="t"><h3>Points d'accès utilisés</h3><p>API Fincra</p></div></div>
          <div class="gw-rows">
            <div v-for="e in o.endpoints" :key="e[1]" class="gw-row"><span class="gw-dot"></span>
              <div class="gw-main"><b>{{ e[2] }}</b><small class="mono">{{ e[0] }} {{ e[1] }}</small></div></div>
          </div>
        </div>
      </section>

      <section class="gw-section">
        <GwHead icon="globe" title="Pays" />
        <div class="gw-card">
          <div class="gw-card-h"><div class="t"><h3>Passerelle nationale (collecte + versement)</h3><p>FINCRA_COUNTRIES</p></div></div>
          <div class="gw-card-b flags"><span v-for="iso in o.countries" :key="iso" class="fl on"><Flag :iso="iso" :size="14" /> {{ countryName(iso) }}</span></div>
          <div class="gw-rows">
            <div class="gw-row"><span class="gw-dot" :class="{ off: !o.countries_collect.length }"></span>
              <div class="gw-main"><b>Collecte confiée à Fincra</b><small v-if="o.countries_collect.length" class="flags"><span v-for="iso in o.countries_collect" :key="iso" class="fl"><Flag :iso="iso" :size="12" /> {{ iso }}</span></small><small v-else>aucun pays</small></div></div>
            <div class="gw-row"><span class="gw-dot" :class="{ off: !o.countries_payout.length }"></span>
              <div class="gw-main"><b>Versement confié à Fincra</b><small v-if="o.countries_payout.length" class="flags"><span v-for="iso in o.countries_payout" :key="iso" class="fl"><Flag :iso="iso" :size="12" /> {{ iso }}</span></small><small v-else>aucun pays</small></div></div>
          </div>
          <div class="gw-card-b"><router-link class="btn-normal sm" to="/corridors">Choisir les pays (Pays &amp; change)</router-link></div>
        </div>
        <div class="gw-card">
          <div class="gw-card-h"><div class="t"><h3>Remittance internationale</h3><p>FINCRA_REMIT_COUNTRIES · partenaire choisi dans Pays &amp; change › International</p></div></div>
          <div class="gw-card-b flags"><span v-for="iso in o.remit_countries" :key="iso" class="fl" :class="{ on: o.countries_remit.includes(iso) }"><Flag :iso="iso" :size="12" /> {{ iso }}</span></div>
          <div class="gw-card-b small muted">{{ o.countries_remit.length }} pays confiés à Fincra.</div>
        </div>
        <div class="gw-card">
          <div class="gw-card-h"><div class="t"><h3>Opérateurs et banques d'un pays</h3><p>Codes Fincra (mobileMoneyCode, bankCode)</p></div></div>
          <div class="gw-card-b">
            <div style="display:flex; gap:8px;"><input v-model="iso" maxlength="2" placeholder="CG" style="width:70px; text-transform:uppercase;" />
              <button class="btn-normal sm" :disabled="!o.configured || inst.loading" @click="institutions">{{ inst.loading ? '…' : 'Afficher' }}</button></div>
            <div v-if="inst.data" style="margin-top:10px;">
              <div v-for="(label, k) in { mobile_money_wallet: 'Mobile money', bank_account: 'Banques' }" :key="k" style="margin-bottom:8px;">
                <b class="small">{{ label }}</b>
                <div v-if="inst.data[k]?.error" class="small t-err">{{ inst.data[k].error }}</div>
                <div v-else-if="!inst.data[k]?.length" class="small muted">aucun</div>
                <div v-else class="flags"><span v-for="i in inst.data[k].slice(0, 40)" :key="i.code" class="gw-pill muted">{{ i.name }} · <span class="mono">{{ i.code }}</span></span></div>
              </div>
            </div>
          </div>
        </div>
      </section>
    </div>

    <section v-if="o" class="gw-section">
      <GwHead icon="swap" title="Opérations Fincra" :count="o.requests.length" />
      <div class="gw-card">
        <div v-if="!o.requests.length" class="gw-empty">Aucune opération.</div>
        <div v-else class="gw-scroll">
          <table class="gw-table">
            <thead><tr><th>Date</th><th>Type</th><th>Référence</th><th>Réf. Fincra</th><th>Montant</th><th>Statut</th><th></th></tr></thead>
            <tbody>
              <tr v-for="r in o.requests" :key="r.id">
                <td class="nowrap">{{ dt(r.created_at) }}</td>
                <td>{{ r.method_label }}</td>
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
import { onMounted, reactive, ref } from 'vue'
import api from '../services/api'
import GwHead from '../components/GwHead.vue'
import GwField from '../components/GwField.vue'
import Flag from '../components/Flag.vue'
import ProviderLogo from '../components/ProviderLogo.vue'

const o = ref(null)
const loading = ref(false)
const error = ref('')
const toastMsg = ref(null)
const testing = ref(false)
const testRes = ref(null)
const iso = ref('CG')
const inst = reactive({ loading: false, data: null })
const STATUS = { new: 'Nouveau', pending: 'En cours', successful: 'Réussi', failed: 'Échoué' }
const STATUS_CLS = { pending: 'info', successful: 'ok', failed: 'err' }
let dn = null
try { dn = new Intl.DisplayNames(['fr'], { type: 'region' }) } catch { dn = null }
const countryName = (c) => { try { return (dn && dn.of(c)) || c } catch { return c } }
const nf = new Intl.NumberFormat('fr-FR')
const n = (v) => nf.format(Math.floor(v || 0))
const dt = (s) => (s ? new Date(s).toLocaleString('fr-FR', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '')
function showToast(text, kind = 'ok') {
  toastMsg.value = { text, kind }
  setTimeout(() => { toastMsg.value = null }, 5000)
}
async function load() {
  loading.value = true
  error.value = ''
  try {
    o.value = (await api.get('/admin/fincra/overview')).data
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
    testRes.value = (await api.post('/admin/fincra/test')).data
  } catch (e) {
    testRes.value = { ok: false, message: e.response?.data?.message || e.message }
  } finally {
    testing.value = false
  }
}
async function institutions() {
  inst.loading = true
  try {
    inst.data = (await api.get(`/admin/fincra/institutions/${(iso.value || '').toUpperCase()}`)).data
  } catch (e) {
    showToast(e.response?.data?.message || e.message, 'err')
  } finally {
    inst.loading = false
  }
}
async function refresh(r) {
  try {
    const { data } = await api.post(`/admin/fincra/requests/${r.id}/refresh`)
    showToast(`Statut Fincra : ${data.raw_status || data.status}`)
    load()
  } catch (e) {
    showToast(e.response?.data?.message || e.message, 'err')
  }
}
onMounted(load)
</script>

<style scoped>
.mono { font-family: ui-monospace, monospace; }
.small { font-size: 12px; }
.muted { color: var(--text-2); }
.t-err { color: #b91c1c; }
.nowrap { white-space: nowrap; }
.flags { display: flex; flex-wrap: wrap; gap: 6px 12px; }
.fl { display: inline-flex; align-items: center; gap: 4px; white-space: nowrap; opacity: .55; }
.fl.on, .gw-row .fl { opacity: 1; }
.fp-toast { position: fixed; top: 16px; right: 16px; z-index: 1000; max-width: 460px; padding: 12px 16px; border-radius: 10px; color: #fff; font-size: 13.5px; box-shadow: 0 8px 24px rgba(0,0,0,.18); cursor: pointer; background: #15803d; }
.fp-toast.err { background: #b91c1c; }
.gw-h1-logo { display: flex; align-items: center; gap: 10px; }
</style>
