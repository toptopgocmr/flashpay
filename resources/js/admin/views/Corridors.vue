<template>
  <div>
    <div class="page-header">
      <div>
        <h1>Pays &amp; change</h1>
        <p>Pays et opérateurs couverts, partenaire de paiement qui gère chaque flux (PEEX, WacePay), ouverture des corridors et taux de change.</p>
      </div>
      <div class="actions"><button class="btn-normal" @click="load">Actualiser</button></div>
    </div>

    <div v-if="error" class="flash err"><div>{{ error }}</div></div>
    <div v-if="msg" class="flash info"><div>{{ msg }}</div></div>

    <!-- Partenaires de paiement : qui gère quels flux -->
    <div class="partners mb">
      <div v-for="p in partners" :key="p.key" class="partner-card" :class="'p-' + p.key">
        <div class="pc-head">
          <span class="pbadge" :class="'p-' + p.key">{{ p.name }}</span>
          <span class="status" :class="p.ready ? 'ok' : 'warn'">{{ p.ready ? 'Connecté' : 'Non configuré' }} · {{ p.mode }}</span>
        </div>
        <div class="pc-flows">
          <div><span>Collecte</span><b>{{ p.flows.includes('collect') ? n(p.collect_countries) + ' pays' : 'Non proposée' }}</b></div>
          <div><span>Versement</span><b>{{ n(p.payout_countries) }} pays</b></div>
        </div>
        <small class="mono" :title="'URL de notification (webhook) à déclarer chez ' + p.name">Webhook : {{ p.webhook }}</small>
        <button class="btn-link" @click="tab = 'corridors'; flt.partner = flt.partner === p.key ? '' : p.key">{{ flt.partner === p.key ? 'Tous les pays' : 'Voir ses pays' }}</button>
      </div>
    </div>

    <div class="kpis mb">
      <button class="kpi" :class="{ on: tab === 'corridors' }" @click="tab = 'corridors'"><span>Pays couverts</span><b>{{ n(kpi.countries) }}</b><small>{{ n(kpi.operators) }} opérateurs</small></button>
      <button class="kpi" :class="{ on: tab === 'corridors' && flt.open === 'collect' }" @click="tab = 'corridors'; flt.open = flt.open === 'collect' ? '' : 'collect'"><span><i class="dot ok"></i>Collecte ouverte</span><b>{{ n(kpi.collect) }} <small>/ {{ n(kpi.countries) }}</small></b><small>Recevoir depuis le mobile money</small></button>
      <button class="kpi" :class="{ on: tab === 'corridors' && flt.open === 'payout' }" @click="tab = 'corridors'; flt.open = flt.open === 'payout' ? '' : 'payout'"><span><i class="dot ok"></i>Versement ouvert</span><b>{{ n(kpi.payout) }} <small>/ {{ n(kpi.countries) }}</small></b><small>Envoyer vers le mobile money</small></button>
      <button class="kpi" :class="{ on: tab === 'rates' }" @click="tab = 'rates'"><span>Taux de change</span><b>{{ n(kpi.rates) }}</b><small :class="{ 't-warn': kpi.stale_rates }">{{ kpi.stale_rates ? kpi.stale_rates + ' à mettre à jour' : 'À jour' }}</small></button>
    </div>

    <div class="tabs-bar mb">
      <button :class="{ on: tab === 'corridors' }" @click="tab = 'corridors'">Pays &amp; corridors</button>
      <button :class="{ on: tab === 'rates' }" @click="tab = 'rates'">Taux de change</button>
    </div>

    <!-- ================= Corridors ================= -->
    <template v-if="tab === 'corridors'">
      <div class="flash info">
        <div>Ouvrez ou fermez la <strong>collecte</strong> et le <strong>versement</strong> par pays, et choisissez le <strong>partenaire</strong> qui gère chaque flux : la collecte (mobile money → FlashPay) passe par <strong>PEEX</strong> ; le versement (FlashPay → mobile money) par <strong>PEEX</strong> ou <strong>WacePay</strong>. Un corridor n'est réellement actif que s'il est aussi ouvert chez le partenaire{{ sandbox ? ' (PEEX en mode sandbox)' : '' }}.</div>
      </div>
      <div class="toolbar mb">
        <div class="search">
          <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2"><circle cx="7" cy="7" r="5"/><path d="M11 11l4 4"/></svg>
          <input v-model="flt.q" type="search" placeholder="Pays, indicatif ou opérateur…" />
        </div>
        <div class="chips">
          <button :class="{ on: !flt.zone }" @click="flt.zone = ''">Toutes les zones</button>
          <button v-for="(cur, z) in zones" :key="z" :class="{ on: flt.zone === z }" @click="flt.zone = z">{{ zoneLabel[z] || z }} · {{ cur }}</button>
        </div>
        <select v-model="flt.partner"><option value="">Tous les partenaires</option><option v-for="p in partners" :key="p.key" :value="p.key">{{ p.name }}</option></select>
        <select v-model="flt.open"><option value="">Tous les statuts</option><option value="collect">Collecte ouverte</option><option value="payout">Versement ouvert</option><option value="closed">Fermés</option><option value="changed">Modifiés dans la console</option></select>
      </div>

      <section class="container mb" v-for="(list, zone) in byZone" :key="zone">
        <div class="container-head">
          <div><h3>{{ zoneLabel[zone] || zone }} <span class="counter">({{ list.length }} pays · {{ zones[zone] }})</span></h3></div>
        </div>
        <div class="container-body flush" style="overflow-x:auto;">
          <table>
            <thead><tr><th>Pays</th><th>Indicatif</th><th>Opérateurs</th><th class="c">Collecte</th><th class="c">Versement</th><th>Partenaire des flux</th><th class="num">Activité 30 j</th><th></th></tr></thead>
            <tbody>
              <tr v-for="c in list" :key="c.country" :class="{ off: !c.collect && !c.payout }">
                <td><Flag :iso="c.country" :size="15" /> <strong>{{ c.name }}</strong> <span class="stat-label">{{ c.country }}</span>
                  <span v-if="c.overridden" class="changed" :title="'Défaut : collecte ' + (c.default_collect ? 'ouverte' : 'fermée') + ', versement ' + (c.default_payout ? 'ouvert' : 'fermé')">modifié</span></td>
                <td class="mono" style="white-space:nowrap;">{{ c.dial }} · {{ c.local_length }} ch.</td>
                <td><span v-for="o in c.operators" :key="o.corridor" class="op" :title="'Préfixes : ' + o.prefixes.join(', ')">{{ o.label }}</span></td>
                <td class="c"><label class="switch"><input type="checkbox" :checked="c.collect" @change="update(c, { collect: $event.target.checked })" /><span></span></label></td>
                <td class="c"><label class="switch"><input type="checkbox" :checked="c.payout" @change="update(c, { payout: $event.target.checked })" /><span></span></label></td>
                <td class="partner-cell">
                  <div class="pl" :class="{ dim: !c.collect }">
                    <span class="lbl">Collecte</span>
                    <select class="api" :class="'p-' + c.collect_partner" :value="c.collect_partner" @change="update(c, { collect_partner: $event.target.value })">
                      <option value="peex">PEEX</option>
                      <option value="digitwace" disabled>WacePay (non proposé)</option>
                    </select>
                  </div>
                  <div class="pl" :class="{ dim: !c.payout }">
                    <span class="lbl">Versement</span>
                    <select class="api" :class="'p-' + c.payout_partner" :value="c.payout_partner" @change="update(c, { payout_partner: $event.target.value })">
                      <option value="peex">PEEX</option>
                      <option value="digitwace">WacePay</option>
                    </select>
                  </div>
                  <div v-if="c.payout_partner === 'peex'" class="pl" :class="{ dim: !c.payout }">
                    <span class="lbl">API PEEX</span>
                    <select class="api" :value="c.payout_api" @change="update(c, { payout_api: $event.target.value })">
                      <option value="disbursement">disbursement</option><option value="remittance">remittance</option>
                    </select>
                  </div>
                  <div v-else-if="!partnerReady('digitwace')" class="t-warn small">⚠ WacePay non configuré : envois refusés</div>
                </td>
                <td class="num">{{ c.usage.count ? n(c.usage.count) + ' op. · ' + short(c.usage.volume) : '—' }}</td>
                <td><IconAction v-if="c.overridden" icon="undo" label="Rétablir la configuration par défaut" @click="reset(c)" /></td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
      <div v-if="!Object.keys(byZone).length" class="container empty">Aucun pays ne correspond à ces filtres.</div>
    </template>

    <!-- ================= Taux de change ================= -->
    <template v-else>
      <div class="layout mb">
        <section class="container conv">
          <div class="container-head"><div><h3>Convertisseur</h3><p>Montant reçu par le bénéficiaire</p></div></div>
          <div class="container-body conv-body">
            <div><label class="field">Montant envoyé</label>
            <div class="row"><input v-model.number="conv.amount" type="number" min="1" /><select v-model="conv.from"><option v-for="c in currencies" :key="c">{{ c }}</option></select></div>
            </div>
            <div><label class="field">Devise reçue</label>
            <select v-model="conv.to" style="width:100%;"><option v-for="c in currencies" :key="c">{{ c }}</option></select></div>
            <div class="conv-result">
              <template v-if="convResult">
                <b>{{ n(convResult.amount) }} {{ conv.to }}</b>
                <span>Taux client {{ convResult.rate }} · marge {{ convResult.margin }} %</span>
              </template>
              <span v-else class="t-warn">Aucun taux actif pour {{ conv.from }} → {{ conv.to }}</span>
            </div>
          </div>
        </section>
        <section class="container">
          <div class="container-head">
            <div><h3>Taux de change</h3><p>XAF ↔ XOF : parité fixe 1 : 1. 1 base = taux × devise cible ; la marge FlashPay est déduite du montant reçu.</p></div>
          </div>
          <div class="container-body flush" style="overflow-x:auto;">
            <table>
              <thead><tr><th>Paire</th><th class="num">Taux</th><th class="num">Marge</th><th class="num">Taux client</th><th>Exemple (10 000)</th><th>Origine</th><th>Actif</th><th></th></tr></thead>
              <tbody>
                <tr v-for="r in rates" :key="r.id" :class="{ off: !r.active }">
                  <td style="white-space:nowrap;"><strong>{{ r.base }} → {{ r.quote }}</strong></td>
                  <td class="num"><input v-if="editing === r.id" v-model.number="draft.rate" type="number" step="any" class="in" /><span v-else>{{ fmtRate(r.rate) }}</span></td>
                  <td class="num"><input v-if="editing === r.id" v-model.number="draft.margin_percent" type="number" step="0.1" class="in sm" /><span v-else>{{ r.margin_percent }} %</span></td>
                  <td class="num">{{ clientRate(editing === r.id ? draft : r) }}</td>
                  <td>{{ n(10000 * clientRate(editing === r.id ? draft : r)) }} {{ r.quote }}</td>
                  <td>
                    <span class="status" :class="stale(r) ? 'warn' : 'ok'">{{ stale(r) ? 'à mettre à jour' : (r.source || 'manuel') }}</span>
                    <div class="stat-label" style="font-size:12px;">{{ dt(r.updated_at) }}</div>
                  </td>
                  <td><label class="switch"><input type="checkbox" :checked="r.active" @change="toggleRate(r, $event.target.checked)" /><span></span></label></td>
                  <td style="white-space:nowrap;">
                    <template v-if="editing === r.id">
                      <button class="btn-link" @click="editing = null">Annuler</button>
                      <button class="btn" @click="saveRate(draft)">Enregistrer</button>
                    </template>
                    <template v-else>
                      <IconAction icon="edit" label="Modifier le taux" @click="startEdit(r)" />
                      <IconAction icon="trash" tone="danger" label="Supprimer le taux" @click="removeRate(r)" />
                    </template>
                  </td>
                </tr>
                <tr class="add">
                  <td style="white-space:nowrap;"><input v-model="add.base" maxlength="3" placeholder="XAF" class="in cur" /> → <input v-model="add.quote" maxlength="3" placeholder="CDF" class="in cur" /></td>
                  <td class="num"><input v-model.number="add.rate" type="number" step="any" placeholder="Taux" class="in" /></td>
                  <td class="num"><input v-model.number="add.margin_percent" type="number" step="0.1" placeholder="%" class="in sm" /></td>
                  <td class="num">{{ add.rate ? clientRate(add) : '' }}</td>
                  <td colspan="3"></td>
                  <td><button class="btn-normal" :disabled="!add.base || !add.quote || !add.rate" @click="saveRate(add, true)">+ Ajouter</button></td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>


      </div>
    </template>
  </div>
</template>

<script setup>
import IconAction from '../components/IconAction.vue'
import Flag from '../components/Flag.vue'
import { computed, onMounted, reactive, ref } from 'vue'
import api from '../services/api'

const tab = ref('corridors')
const corridors = ref([])
const zones = ref({})
const kpi = ref({})
const sandbox = ref(false)
const partners = ref([])
const partnerReady = (k) => !!partners.value.find((p) => p.key === k)?.ready
const partnerName = (k) => partners.value.find((p) => p.key === k)?.name || k
const rates = ref([])
const editing = ref(null)
const draft = reactive({})
const add = reactive({ base: 'XAF', quote: '', rate: null, margin_percent: 1.5 })
const error = ref('')
const msg = ref('')
const flt = reactive({ q: '', zone: '', open: '', partner: '' })
const conv = reactive({ amount: 10000, from: 'XAF', to: 'CDF' })
const zoneLabel = { CEMAC: 'CEMAC — Afrique centrale', UEMOA: 'UEMOA — Afrique de l\'Ouest', RDC: 'RD Congo', GUINEE: 'Guinée' }

const nf = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 2 })
const n = (v) => nf.format(Math.floor(v || 0))
const short = (v) => (!v ? '0' : v >= 1e6 ? (v / 1e6).toFixed(1).replace('.', ',') + ' M' : v >= 1e4 ? Math.round(v / 1e3) + ' k' : nf.format(v))
const dt = (s) => (s ? new Date(s).toLocaleString('fr-FR', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '')
const fmtRate = (v) => (v >= 1 ? +Number(v).toFixed(4) : +Number(v).toPrecision(4))
const clientRate = (r) => +(Number(r.rate || 0) * (1 - Number(r.margin_percent || 0) / 100)).toFixed(6)
const stale = (r) => (r.source || '').includes('indicatif') || Date.now() - new Date(r.updated_at).getTime() > 7 * 864e5

const byZone = computed(() => {
  const q = flt.q.toLowerCase().trim()
  return corridors.value.filter((c) => (!flt.zone || c.zone === flt.zone)
    && (!flt.partner || c.payout_partner === flt.partner || c.collect_partner === flt.partner)
    && (!q || [c.name, c.country, c.dial, ...c.operators.map((o) => o.label)].join(' ').toLowerCase().includes(q))
    && (!flt.open || (flt.open === 'collect' && c.collect) || (flt.open === 'payout' && c.payout) || (flt.open === 'closed' && !c.collect && !c.payout) || (flt.open === 'changed' && c.overridden)))
    .reduce((acc, c) => ((acc[c.zone] ||= []).push(c), acc), {})
})
const currencies = computed(() => [...new Set(['XAF', 'XOF', ...rates.value.flatMap((r) => [r.base, r.quote])])])
const convResult = computed(() => {
  if (conv.from === conv.to || [conv.from, conv.to].every((c) => ['XAF', 'XOF'].includes(c))) return { amount: conv.amount, rate: 1, margin: 0 }
  const d = rates.value.find((r) => r.active && r.base === conv.from && r.quote === conv.to)
  if (d) return { amount: conv.amount * clientRate(d), rate: clientRate(d), margin: d.margin_percent }
  const i = rates.value.find((r) => r.active && r.base === conv.to && r.quote === conv.from)
  if (i) { const rate = +((1 / i.rate) * (1 - i.margin_percent / 100)).toFixed(6); return { amount: conv.amount * rate, rate, margin: i.margin_percent } }
  return null
})

async function load() {
  error.value = ''
  try {
    const [c, r] = await Promise.all([api.get('/admin/corridors'), api.get('/admin/fx-rates')])
    corridors.value = c.data.corridors
    zones.value = c.data.zones
    kpi.value = c.data.kpi
    sandbox.value = c.data.sandbox
    partners.value = c.data.partners || []
    rates.value = r.data
  } catch (e) {
    error.value = e.response?.data?.message || e.message
  }
}
async function run(fn) {
  error.value = ''; msg.value = ''
  try { const { data } = await fn(); msg.value = data?.message || 'Enregistré.'; await load() } catch (e) { error.value = e.response?.data?.message || e.message; await load() }
}
function update(c, patch) {
  if (patch.payout === false && !confirm(`Fermer les versements vers ${c.name} ? Les envois vers ce pays seront refusés.`)) return load()
  if (patch.collect === false && !confirm(`Fermer la collecte depuis ${c.name} ?`)) return load()
  if (patch.payout_partner && patch.payout_partner !== c.payout_partner
    && !confirm(`Confier les versements vers ${c.name} à ${partnerName(patch.payout_partner)} (au lieu de ${partnerName(c.payout_partner)}) ?\n\nLes nouveaux envois vers ce pays passeront par ce partenaire ; les opérations en cours restent chez l'ancien.`)) return load()
  run(() => api.post(`/admin/corridors/${c.country}`, patch))
}
const reset = (c) => run(() => api.delete(`/admin/corridors/${c.country}`))
function startEdit(r) {
  editing.value = r.id
  Object.assign(draft, { base: r.base, quote: r.quote, rate: r.rate, margin_percent: r.margin_percent, active: r.active })
}
async function saveRate(r, isNew = false) {
  await run(() => api.post('/admin/fx-rates', { ...r, base: r.base.toUpperCase(), quote: r.quote.toUpperCase(), active: r.active ?? true }))
  editing.value = null
  if (isNew && !error.value) Object.assign(add, { quote: '', rate: null })
}
const toggleRate = (r, active) => run(() => api.post(`/admin/fx-rates/${r.id}/active`, { active }))
const removeRate = (r) => { if (confirm(`Supprimer le taux ${r.base} → ${r.quote} ?`)) run(() => api.delete(`/admin/fx-rates/${r.id}`)) }

onMounted(load)
</script>

<style scoped>
.kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; }
.kpi { text-align: left; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 14px 16px; box-shadow: var(--shadow); display: grid; gap: 2px; cursor: pointer; font: inherit; }
.kpi:hover { border-color: var(--link); }
.kpi.on { border-color: var(--brand); box-shadow: 0 0 0 3px rgba(30, 58, 138, .12); }
.kpi span { color: var(--text-2); font-size: 12.5px; }
.kpi b { font-size: 24px; }
.kpi b small { font-size: 13px; color: var(--text-2); font-weight: 500; }
.kpi > small { font-size: 12px; color: var(--text-2); }
.dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 6px; }
.dot.ok { background: var(--ok); }
.t-warn { color: var(--warn) !important; }
.tabs-bar { display: flex; gap: 4px; border-bottom: 1px solid var(--border); }
.tabs-bar button { background: none; border: 0; border-bottom: 2px solid transparent; padding: 10px 14px; font: inherit; font-weight: 600; color: var(--text-2); cursor: pointer; }
.tabs-bar button.on { color: var(--text); border-bottom-color: var(--brand); }
.toolbar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
.toolbar select { padding: 8px 10px; border: 1px solid var(--border-strong); border-radius: 8px; font: inherit; }
.chips { display: flex; flex-wrap: wrap; gap: 6px; }
.chips button { border: 1px solid var(--border-strong); background: #fff; border-radius: 99px; padding: 5px 12px; font: inherit; font-size: 13px; cursor: pointer; }
.chips button.on { background: var(--brand); border-color: var(--brand); color: #fff; }
.c { text-align: center; }
.op { display: inline-block; border: 1px solid var(--border-strong); border-radius: 12px; padding: 0 8px; margin: 2px 4px 2px 0; font-size: 12px; white-space: nowrap; }
.changed { font-size: 11px; font-weight: 600; color: var(--warn); background: var(--warn-bg); border-radius: 99px; padding: 1px 7px; margin-left: 6px; }
.partners { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 12px; }
.partner-card { background: var(--surface); border: 1px solid var(--border); border-left: 4px solid #1e3a8a; border-radius: var(--radius); padding: 14px 16px; box-shadow: var(--shadow); display: grid; gap: 8px; }
.partner-card.p-digitwace { border-left-color: #ea7a17; }
.pc-head { display: flex; justify-content: space-between; align-items: center; gap: 8px; flex-wrap: wrap; }
.pc-flows { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.pc-flows div { background: var(--surface-2); border-radius: 8px; padding: 8px 10px; display: grid; }
.pc-flows span { font-size: 12px; color: var(--text-2); }
.partner-card small { font-size: 11.5px; color: var(--text-2); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.partner-card .btn-link { justify-self: start; padding: 0; }
.pbadge { font-weight: 800; font-size: 13px; border-radius: 99px; padding: 3px 10px; color: #fff; background: #1e3a8a; }
.pbadge.p-digitwace { background: #ea7a17; }
.partner-cell { min-width: 230px; }
.partner-cell .api { width: 140px; }
.pl { display: flex; align-items: center; gap: 8px; margin: 3px 0; }
.pl.dim { opacity: .5; }
.pl .lbl { width: 72px; font-size: 12px; color: var(--text-2); }
.api.p-peex { border-color: #1e3a8a; color: #1e3a8a; font-weight: 700; }
.api.p-digitwace { border-color: #ea7a17; color: #b45309; font-weight: 700; }
.small { font-size: 12px; }
.api { padding: 4px 6px; border: 1px solid var(--border-strong); border-radius: 6px; font: inherit; font-size: 12.5px; }
tr.off td { color: var(--text-2); background: #fafafa; }
.switch { position: relative; display: inline-block; width: 36px; height: 20px; }
.switch input { opacity: 0; width: 0; height: 0; }
.switch span { position: absolute; inset: 0; background: #cbd2dc; border-radius: 99px; transition: .15s; cursor: pointer; }
.switch span::before { content: ''; position: absolute; width: 16px; height: 16px; left: 2px; top: 2px; background: #fff; border-radius: 50%; transition: .15s; }
.switch input:checked + span { background: var(--ok); }
.switch input:checked + span::before { transform: translateX(16px); }
.layout { display: grid; grid-template-columns: 1fr; gap: 16px; }
.layout .container { margin: 0; }
.in { width: 110px; padding: 5px 8px; border: 1px solid var(--border-strong); border-radius: 6px; font: inherit; }
.in.sm { width: 70px; } .in.cur { width: 60px; text-transform: uppercase; }
tr.add td { background: var(--surface-2); }
.conv-body { display: grid; grid-template-columns: 1fr 1fr 1.2fr; gap: 14px; align-items: end; }
.conv-body .field { display: block; }
@media (max-width: 800px) { .conv-body { grid-template-columns: 1fr; } }
.conv .row { display: flex; gap: 6px; }
.conv .row input { flex: 1; min-width: 0; }
.conv-result { padding: 12px; border-radius: 10px; background: var(--surface-2); display: grid; gap: 4px; }
.conv-result b { font-size: 22px; }
.conv-result span { font-size: 12.5px; color: var(--text-2); }
.empty { padding: 30px; text-align: center; color: var(--text-2); }
</style>
