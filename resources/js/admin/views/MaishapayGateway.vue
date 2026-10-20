<template>
  <div class="gw-page accent-pawa">
    <div class="gw-top">
      <div class="gw-top-left">
        <h1 class="gw-h1-logo"><ProviderLogo name="MaishaPay" :size="34" /> Passerelle MaishaPay</h1>
        <div class="gw-pills" v-if="o">
          <span class="gw-pill" :class="o.configured && o.rail_enabled ? 'ok' : 'warn'">{{ o.configured && o.rail_enabled ? 'Configurée' : 'Non configurée' }}</span>
          <span class="gw-pill" :class="o.sandbox ? 'info' : 'accent'">{{ o.sandbox ? 'Sandbox' : 'Production' }}</span>
          <span class="gw-pill muted">API marchand v2</span>
        </div>
      </div>
      <div class="actions">
        <a v-if="o" class="btn-normal" :href="o.docs_url" target="_blank" rel="noopener">Documentation</a>
        <a v-if="o" class="btn-normal" :href="o.dashboard_url" target="_blank" rel="noopener">Tableau de bord MaishaPay</a>
        <button class="btn-normal" :disabled="loading" @click="load">{{ loading ? '…' : 'Actualiser' }}</button>
      </div>
    </div>

    <div v-if="error" class="gw-note err">{{ error }}</div>
    <div v-if="toastMsg" class="fp-toast" :class="toastMsg.kind" @click="toastMsg = null">{{ toastMsg.text }}</div>
    <div v-if="!o && loading" class="gw-note">Chargement…</div>

    <div v-if="o" class="gw-stats">
      <div class="gw-stat"><span>Collectes · 30 j</span><b>{{ n(o.stats.deposit) }}</b><small>mobile money + carte</small></div>
      <div class="gw-stat"><span>Versements · 30 j</span><b>{{ n(o.stats.payout) }}</b><small>dont remboursements</small></div>
      <div class="gw-stat"><span>Services · 30 j</span><b>{{ n(o.stats.services) }}</b><small>crédit, TV, factures</small></div>
      <div class="gw-stat"><span>En attente</span><b>{{ n(o.stats.pending) }}</b><small :class="{ 't-err': o.stats.failed }">{{ n(o.stats.failed) }} échec(s) · 30 j</small></div>
    </div>

    <div v-if="o" class="gw-cols">
      <section class="gw-section">
        <GwHead icon="key" title="Configuration (variables Railway)" />
        <div class="gw-card">
          <div class="gw-rows">
            <div class="gw-row"><span class="gw-dot" :class="{ off: !o.enabled_flag }"></span>
              <div class="gw-main"><b>MAISHAPAY_ENABLED</b><small>active MaishaPay (collecte, versement, carte, services)</small></div>
              <div class="gw-end"><span class="gw-pill" :class="o.enabled_flag ? 'ok' : 'err'">{{ o.enabled_flag ? 'true' : 'false' }}</span></div></div>
            <div class="gw-row"><span class="gw-dot" :class="{ off: !o.public_key }"></span>
              <div class="gw-main"><b>MAISHAPAY_PUBLIC_KEY</b><small class="mono">{{ o.public_key || 'tableau de bord marchand › clés API (MP-SBPK-… / MP-LIVEPK-…)' }}</small></div>
              <div class="gw-end"><span class="gw-pill" :class="o.public_key ? 'ok' : 'err'">{{ o.public_key ? 'Présente' : 'Absente' }}</span></div></div>
            <div class="gw-row"><span class="gw-dot" :class="{ off: !o.secret_key }"></span>
              <div class="gw-main"><b>MAISHAPAY_SECRET_KEY</b><small>clé secrète, jamais dans l'app mobile</small></div>
              <div class="gw-end"><span class="gw-pill" :class="o.secret_key ? 'ok' : 'err'">{{ o.secret_key ? 'Présente' : 'Absente' }}</span></div></div>
            <div class="gw-row"><span class="gw-dot"></span>
              <div class="gw-main"><b>MAISHAPAY_BASE_URL</b><small class="mono">{{ o.base_url }}</small></div></div>
            <div class="gw-row"><span class="gw-dot" :class="{ off: !o.card }"></span>
              <div class="gw-main"><b>Carte Visa / Mastercard</b><small>FLASHPAY_CARD_DRIVER=maishapay</small></div>
              <div class="gw-end"><span class="gw-pill" :class="o.card ? 'ok' : 'muted'">{{ o.card ? 'Active' : 'Inactive' }}</span></div></div>
            <div class="gw-row"><span class="gw-dot" :class="{ off: !o.bills }"></span>
              <div class="gw-main"><b>Services (crédit, TV, factures)</b><small>FLASHPAY_BILLS_ENABLED</small></div>
              <div class="gw-end"><span class="gw-pill" :class="o.bills ? 'ok' : 'muted'">{{ o.bills ? 'Actifs' : 'Inactifs' }}</span></div></div>
          </div>
          <div class="gw-card-b">
            <button class="btn-normal" :disabled="testing || !o.configured" @click="test">{{ testing ? 'Test…' : 'Tester la connexion' }}</button>
            <div v-if="testRes" class="gw-note" :class="testRes.ok ? 'ok' : 'err'" style="margin-top:10px;">{{ testRes.message }}<span v-if="testRes.ms" class="small muted"> · {{ testRes.ms }} ms</span></div>
          </div>
        </div>
        <div class="gw-card">
          <div class="gw-card-h"><div class="t"><h3>URL de notification (callbackUrl)</h3><p>Envoyée à chaque opération ; à déclarer aussi dans le tableau de bord MaishaPay si demandé</p></div>
            <span class="gw-pill" :class="o.webhook_signed ? 'ok' : 'muted'">{{ o.webhook_signed ? 'Signature vérifiée' : 'Non signé' }}</span></div>
          <div class="gw-card-b"><GwField :value="o.callback_url" /></div>
        </div>
      </section>

      <section class="gw-section">
        <GwHead icon="globe" title="Pays" />
        <div class="gw-card">
          <div class="gw-card-h"><div class="t"><h3>Pays couverts par MaishaPay</h3><p>MAISHAPAY_COUNTRIES</p></div></div>
          <div class="gw-card-b flags"><span v-for="iso in o.countries" :key="iso" class="fl"><Flag :iso="iso" :size="14" /> {{ countryName(iso) }}</span></div>
          <div class="gw-rows">
            <div class="gw-row"><span class="gw-dot" :class="{ off: !o.countries_collect.length }"></span>
              <div class="gw-main"><b>Collecte confiée à MaishaPay</b><small v-if="o.countries_collect.length" class="flags"><span v-for="iso in o.countries_collect" :key="iso" class="fl"><Flag :iso="iso" :size="12" /> {{ iso }}</span></small><small v-else>aucun pays</small></div></div>
            <div class="gw-row"><span class="gw-dot" :class="{ off: !o.countries_payout.length }"></span>
              <div class="gw-main"><b>Versement confié à MaishaPay</b><small v-if="o.countries_payout.length" class="flags"><span v-for="iso in o.countries_payout" :key="iso" class="fl"><Flag :iso="iso" :size="12" /> {{ iso }}</span></small><small v-else>aucun pays</small></div></div>
          </div>
          <div class="gw-card-b"><router-link class="btn-normal sm" to="/corridors">Choisir les pays (Pays &amp; change)</router-link></div>
        </div>
        <div class="gw-card">
          <div class="gw-card-h"><div class="t"><h3>Points d'accès</h3><p>Ajustables par variables MAISHAPAY_PATH_*</p></div></div>
          <div class="gw-rows">
            <div v-for="(p, k) in o.paths" :key="k" class="gw-row"><span class="gw-dot"></span>
              <div class="gw-main"><b>{{ PATHS[k] || k }}</b><small class="mono">POST /{{ p }}</small></div></div>
          </div>
        </div>
      </section>
    </div>

    <section v-if="o" class="gw-section">
      <GwHead icon="swap" title="Opérations MaishaPay" :count="o.requests.length" />
      <div class="gw-card">
        <div v-if="!o.requests.length" class="gw-empty">Aucune opération.</div>
        <div v-else class="gw-scroll">
          <table class="gw-table">
            <thead><tr><th>Date</th><th>Type</th><th>Référence</th><th>ID MaishaPay</th><th>Montant</th><th>Statut</th><th></th></tr></thead>
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
import { onMounted, ref } from 'vue'
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
const PATHS = { collect_mobile: 'Collecte mobile money', collect_card: 'Paiement carte', payout_mobile: 'Versement mobile money', check: 'Statut', airtime: 'Crédit téléphonique', paytv: 'Abonnement TV', utility: 'Factures' }
const STATUS = { new: 'Nouveau', pending: 'En cours', successful: 'Réussi', failed: 'Échoué' }
const STATUS_CLS = { pending: 'info', successful: 'ok', failed: 'err' }
let dn = null
try { dn = new Intl.DisplayNames(['fr'], { type: 'region' }) } catch { dn = null }
const countryName = (iso) => { try { return (dn && dn.of(iso)) || iso } catch { return iso } }
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
    o.value = (await api.get('/admin/maishapay/overview')).data
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
    testRes.value = (await api.post('/admin/maishapay/test')).data
  } catch (e) {
    testRes.value = { ok: false, message: e.response?.data?.message || e.message }
  } finally {
    testing.value = false
  }
}
async function refresh(r) {
  try {
    const { data } = await api.post(`/admin/maishapay/requests/${r.id}/refresh`)
    showToast(`Statut MaishaPay : ${data.raw_status || data.status}`)
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
.fl { display: inline-flex; align-items: center; gap: 4px; white-space: nowrap; }
.fp-toast { position: fixed; top: 16px; right: 16px; z-index: 1000; max-width: 460px; padding: 12px 16px; border-radius: 10px; color: #fff; font-size: 13.5px; box-shadow: 0 8px 24px rgba(0,0,0,.18); cursor: pointer; background: #15803d; }
.fp-toast.err { background: #b91c1c; }
.gw-h1-logo { display: flex; align-items: center; gap: 10px; }
</style>
