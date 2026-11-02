<template>
  <div class="gw-page rd">
    <div class="gw-top">
      <div class="gw-top-left">
        <h1>Remittance</h1>
        <div class="gw-pills" v-if="d">
          <span class="gw-pill" :class="d.kpi.partners_ready ? 'ok' : 'err'">{{ d.kpi.partners_ready }}/{{ d.kpi.partners }} partenaire(s) prêt(s)</span>
          <span class="gw-pill muted">Envoi depuis {{ d.from.country }} · {{ d.from.currency }}</span>
          <span class="gw-pill muted">{{ n(d.limits.min) }} – {{ n(d.limits.max) }} {{ d.from.currency }} · KYC ≥ {{ d.limits.min_kyc_tier }}</span>
        </div>
      </div>
      <div class="actions">
        <router-link class="btn-normal" to="/corridors">Pays &amp; partenaires</router-link>
        <router-link class="btn-normal" to="/remit-tariffs">Tarifs internationaux</router-link>
        <button class="btn-normal" :disabled="loading" @click="load(true)">{{ loading ? '…' : 'Actualiser les soldes' }}</button>
      </div>
    </div>

    <div v-if="error" class="gw-note err">{{ error }}</div>
    <div v-if="toastMsg" class="fp-toast" :class="toastMsg.kind" @click="toastMsg = null">{{ toastMsg.text }}</div>
    <div v-if="!d && loading" class="gw-note">Chargement…</div>

    <template v-if="d">
      <!-- Indicateurs 30 jours -->
      <div class="gw-stats">
        <div class="gw-stat"><span>Pays ouverts</span><b>{{ d.kpi.countries_open }}</b><small>sur {{ d.kpi.countries }} pays</small></div>
        <div class="gw-stat"><span>Opérations · 30 j</span><b>{{ n(d.kpi.operations) }}</b><small>{{ n(d.kpi.successful) }} réussie(s)</small></div>
        <div class="gw-stat"><span>Volume réussi · 30 j</span><b>{{ n(d.kpi.volume) }}</b><small>{{ d.from.currency }}</small></div>
        <div class="gw-stat"><span>Frais encaissés · 30 j</span><b>{{ n(d.kpi.fees) }}</b><small>{{ d.from.currency }}</small></div>
        <div class="gw-stat"><span>En attente</span><b>{{ n(d.kpi.pending) }}</b><small :class="{ 't-err': d.kpi.failed }">{{ n(d.kpi.failed) }} échec(s) · 30 j</small></div>
      </div>

      <!-- IP à autoriser chez les partenaires -->
      <div class="gw-card">
        <div class="gw-card-h">
          <div class="t"><h3>IP du serveur OfficePay</h3><p>À faire autoriser chez chaque partenaire (liste blanche d'IP). Un refus « restricted » ou « IP not whitelisted » vient de là.</p></div>
        </div>
        <div class="gw-card-b"><GwField :value="d.server_ip || 'inconnue'" /></div>
      </div>

      <!-- Un bloc par partenaire -->
      <section class="gw-section">
        <GwHead icon="partners" title="Partenaires" :count="d.partners.length" />
        <div class="rd-grid">
          <div v-for="p in d.partners" :key="p.key" class="gw-card rd-card" :class="{ off: !p.configured }">
            <div class="gw-card-h">
              <ProviderLogo :name="p.name" :size="36" />
              <div class="t">
                <h3>{{ p.name }}</h3>
                <p>{{ p.methods.map((m) => METHODS[m] || m).join(' · ') }}</p>
              </div>
              <div class="side">
                <span v-if="p.mode" class="gw-pill" :class="p.mode === 'production' ? 'accent' : 'info'">{{ p.mode === 'production' ? 'Production' : 'Sandbox' }}</span>
                <span class="gw-pill" :class="p.ready ? 'ok' : (p.configured ? 'warn' : 'muted')">{{ p.ready ? 'Prêt' : (p.configured ? 'Désactivé' : 'Non configuré') }}</span>
              </div>
            </div>

            <!-- Solde -->
            <div class="gw-card-b rd-bal">
              <span class="lbl">Solde chez {{ p.name }}</span>
              <template v-if="p.balance?.ok && p.balance.accounts.length">
                <div class="rd-amounts">
                  <div v-for="(a, i) in p.balance.accounts" :key="i" class="rd-amount">
                    <b>{{ n(a.balance) }}</b> <span>{{ a.currency }}</span>
                    <small v-if="a.country" class="muted">{{ a.country }}</small>
                  </div>
                </div>
              </template>
              <div v-else-if="p.balance?.unsupported" class="small muted">{{ p.balance.error }}</div>
              <div v-else-if="p.balance?.ok" class="small muted">Aucun compte retourné.</div>
              <div v-else-if="p.configured" class="gw-note err">{{ p.balance?.error || 'Solde indisponible' }}</div>
              <div v-else class="small muted">Saisir les variables Railway ci-dessous pour activer ce partenaire.</div>
            </div>

            <!-- Activité -->
            <div class="rd-mini">
              <div><span>Opérations</span><b>{{ n(p.stats.total) }}</b></div>
              <div><span>Réussies</span><b class="t-ok">{{ n(p.stats.successful) }}</b></div>
              <div><span>Échouées</span><b :class="{ 't-err': p.stats.failed }">{{ n(p.stats.failed) }}</b></div>
              <div><span>En attente</span><b>{{ n(p.stats.pending) }}</b></div>
              <div><span>Taux de réussite</span><b>{{ p.stats.success_rate == null ? '—' : p.stats.success_rate + ' %' }}</b></div>
              <div><span>Volume · 30 j</span><b>{{ n(p.stats.volume) }}</b></div>
            </div>

            <!-- Configuration -->
            <details class="rd-cfg" :open="!p.ready">
              <summary>Configuration (variables Railway) <span v-if="missing(p).length" class="gw-pill err">{{ missing(p).length }} manquante(s)</span></summary>
              <div class="gw-rows">
                <div v-for="c in p.config" :key="c.env" class="gw-row">
                  <span class="gw-dot" :class="{ off: !c.present && !c.required, err: !c.present && c.required }"></span>
                  <div class="gw-main"><b class="mono">{{ c.env }}</b><small>{{ c.help }}</small></div>
                  <div class="gw-end">
                    <span v-if="c.type === 'flag' || c.type === 'secret'" class="gw-pill" :class="c.present ? 'ok' : (c.required ? 'err' : 'muted')">{{ c.value }}</span>
                    <span v-else class="mono small rd-val" :title="c.value">{{ c.value }}</span>
                  </div>
                </div>
              </div>
            </details>

            <!-- Pays confiés -->
            <div class="gw-card-b">
              <div class="small"><b>{{ p.countries_count }}</b> pays confiés à {{ p.name }}</div>
              <div v-if="p.countries.length" class="flags">
                <span v-for="iso in p.countries.slice(0, showAll[p.key] ? 999 : 14)" :key="iso" class="fl"><Flag :iso="iso" :size="13" /> {{ iso }}</span>
                <button v-if="p.countries.length > 14" class="btn-link small" @click="showAll[p.key] = !showAll[p.key]">{{ showAll[p.key] ? 'moins' : '+ ' + (p.countries.length - 14) }}</button>
              </div>
            </div>

            <div class="gw-card-b rd-actions">
              <button class="btn-normal sm" :disabled="testing[p.key] || !p.configured" @click="test(p)">{{ testing[p.key] ? 'Test…' : 'Tester la connexion' }}</button>
              <a v-if="p.portal" class="btn-normal sm" :href="p.portal" target="_blank" rel="noopener">Portail</a>
              <span class="mono small muted rd-url" :title="p.base_url">{{ p.base_url }}</span>
            </div>
            <div v-if="testRes[p.key]" class="gw-card-b" style="padding-top:0;">
              <div class="gw-note" :class="testRes[p.key].ok ? 'ok' : 'err'">{{ testRes[p.key].message }}<span v-if="testRes[p.key].ms" class="small"> · {{ testRes[p.key].ms }} ms</span></div>
            </div>
          </div>
        </div>
      </section>

      <!-- Opérations récentes -->
      <section class="gw-section">
        <GwHead icon="swap" title="Opérations récentes" :count="filtered.length">
          <select v-model="filter" class="rd-select">
            <option value="">Tous les partenaires</option>
            <option v-for="p in d.partners" :key="p.key" :value="p.key">{{ p.name }}</option>
          </select>
        </GwHead>
        <div class="gw-card">
          <div v-if="!filtered.length" class="gw-empty">Aucune opération internationale.</div>
          <div v-else class="gw-scroll">
            <table class="gw-table">
              <thead><tr><th>Date</th><th>Partenaire</th><th>Destination</th><th>Transaction</th><th>Réf. partenaire</th><th>Montant</th><th>Statut</th><th></th></tr></thead>
              <tbody>
                <tr v-for="r in filtered" :key="r.id">
                  <td class="nowrap">{{ dt(r.created_at) }}</td>
                  <td class="nowrap"><ProviderLogo :name="r.partner_name" :size="16" /> {{ r.partner_name }}</td>
                  <td class="nowrap"><template v-if="r.country"><Flag :iso="r.country" :size="13" /> {{ r.country }}</template><span v-else>—</span>
                    <div class="small muted">{{ METHODS[r.method] || r.method || '' }}{{ r.business_type ? ' · ' + r.business_type.toUpperCase() : '' }}</div></td>
                  <td class="mono"><router-link v-if="r.transaction" :to="'/transactions/' + r.transaction.id">{{ r.transaction.reference }}</router-link><span v-else>{{ r.reference }}</span></td>
                  <td class="mono small">{{ r.partner_ref || '—' }}</td>
                  <td class="nowrap"><template v-if="r.transaction">{{ n(r.transaction.amount) }} {{ r.transaction.currency }}
                    <div v-if="r.transaction.destination_amount" class="small muted">≈ {{ n(r.transaction.destination_amount) }} {{ r.transaction.destination_currency }}</div></template><span v-else>—</span></td>
                  <td><span class="gw-pill" :class="STATUS_CLS[r.status] || 'muted'">{{ STATUS[r.status] || r.status }}</span>
                    <span v-if="r.raw_status" class="small muted" style="margin-left:6px;">{{ r.raw_status }}</span>
                    <div v-if="r.message" class="small t-err rd-msg">{{ r.message }}</div></td>
                  <td><button v-if="!r.final" class="btn-link" :disabled="refreshing[r.id]" @click="refresh(r)">{{ refreshing[r.id] ? '…' : 'Vérifier' }}</button></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
        <div class="small muted">Mis à jour {{ dt(d.checked_at) }} · soldes gardés 2 min (« Actualiser les soldes » pour les relire).</div>
      </section>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import api from '../services/api'
import GwHead from '../components/GwHead.vue'
import GwField from '../components/GwField.vue'
import Flag from '../components/Flag.vue'
import ProviderLogo from '../components/ProviderLogo.vue'

const d = ref(null)
const loading = ref(false)
const error = ref('')
const toastMsg = ref(null)
const filter = ref('')
const testing = reactive({})
const testRes = reactive({})
const refreshing = reactive({})
const showAll = reactive({})
const METHODS = { wallet: 'Mobile money', bank: 'Compte bancaire', cash: 'Retrait cash' }
const STATUS = { new: 'Nouveau', pending: 'En cours', successful: 'Réussi', failed: 'Échoué' }
const STATUS_CLS = { pending: 'info', new: 'info', successful: 'ok', failed: 'err' }
const nf = new Intl.NumberFormat('fr-FR')
const n = (v) => nf.format(Math.floor(Number(v) || 0))
const dt = (s) => (s ? new Date(s).toLocaleString('fr-FR', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '')
const missing = (p) => p.config.filter((c) => c.required && !c.present)
const filtered = computed(() => (d.value?.recent || []).filter((r) => !filter.value || r.partner === filter.value))

function showToast(text, kind = 'ok') {
  toastMsg.value = { text, kind }
  setTimeout(() => { toastMsg.value = null }, 5000)
}
async function load(fresh = false) {
  loading.value = true
  error.value = ''
  try {
    d.value = (await api.get('/admin/remit/dashboard', { params: fresh ? { refresh: 1 } : {} })).data
  } catch (e) {
    error.value = e.response?.data?.message || e.message
  } finally {
    loading.value = false
  }
}
async function test(p) {
  testing[p.key] = true
  testRes[p.key] = null
  try {
    testRes[p.key] = (await api.post(`/admin/remit/test/${p.key}`)).data
    if (testRes[p.key].ok) load()
  } catch (e) {
    testRes[p.key] = { ok: false, message: e.response?.data?.message || e.message }
  } finally {
    testing[p.key] = false
  }
}
async function refresh(r) {
  refreshing[r.id] = true
  try {
    const { data } = await api.post(`/admin/remit/requests/${r.id}/refresh`)
    showToast(`${r.partner_name} : ${data.raw_status || STATUS[data.status] || data.status}`)
    load()
  } catch (e) {
    showToast(e.response?.data?.message || e.message, 'err')
  } finally {
    refreshing[r.id] = false
  }
}
onMounted(() => load())
</script>

<style scoped>
.mono { font-family: ui-monospace, monospace; }
.small { font-size: 12px; }
.muted { color: var(--text-2); }
.t-err { color: #b91c1c; }
.t-ok { color: #15803d; }
.nowrap { white-space: nowrap; }
.flags { display: flex; flex-wrap: wrap; gap: 6px 12px; align-items: center; }
.fl { display: inline-flex; align-items: center; gap: 4px; white-space: nowrap; font-size: 12.5px; }
.rd-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(460px, 100%), 1fr)); gap: 20px; align-items: start; }
.rd-card.off { opacity: .8; }
.rd-card .gw-card-h { align-items: center; }
.rd-bal { gap: 6px; }
.rd-bal .lbl { font-size: 12.5px; color: var(--text-2); }
.rd-amounts { display: flex; flex-wrap: wrap; gap: 6px 22px; }
.rd-amount b { font-size: 24px; letter-spacing: -.01em; }
.rd-amount span { font-weight: 600; color: var(--text-2); }
.rd-amount small { margin-left: 4px; }
.rd-mini { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); border-top: 1px solid var(--border); }
.rd-mini > div { padding: 10px 20px; display: grid; gap: 2px; border-bottom: 1px solid var(--border); }
.rd-mini > div:not(:nth-child(3n)) { border-right: 1px solid var(--border); }
.rd-mini span { font-size: 11.5px; color: var(--text-2); }
.rd-mini b { font-size: 16px; }
.rd-cfg { border-bottom: 1px solid var(--border); }
.rd-cfg summary { cursor: pointer; padding: 12px 20px; font-weight: 600; font-size: 13.5px; display: flex; gap: 8px; align-items: center; }
.rd-cfg .gw-rows { border-top: 1px solid var(--border); }
.rd-cfg .gw-row { padding-top: 10px; padding-bottom: 10px; }
.rd-val { max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: inline-block; }
.rd-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.rd-url { margin-left: auto; max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.rd-msg { margin-top: 4px; max-width: 320px; overflow-wrap: anywhere; }
.rd-select { padding: 6px 10px; border-radius: 8px; border: 1px solid var(--border); background: var(--surface); font: inherit; font-size: 13px; }
.fp-toast { position: fixed; top: 16px; right: 16px; z-index: 1000; max-width: 460px; padding: 12px 16px; border-radius: 10px; color: #fff; font-size: 13.5px; box-shadow: 0 8px 24px rgba(0,0,0,.18); cursor: pointer; background: #15803d; }
.fp-toast.err { background: #b91c1c; }
@media (max-width: 700px) { .rd-mini > div { padding: 8px 14px; } .rd-cfg summary { padding: 12px 14px; } }
</style>
