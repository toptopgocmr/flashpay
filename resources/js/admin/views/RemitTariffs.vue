<template>
  <div>
    <div class="page-header">
      <div>
        <h1>Tarifs internationaux</h1>
        <p>Remittance, à part de la grille nationale : zones et seuils, grille par tranche (frais hors taxes), taxes (COBAC, timbre, TTF, TVA) et taux de change à partir du Congo.</p>
      </div>
      <div class="actions">
        <router-link class="btn-normal" to="/tariffs">Grille nationale</router-link>
        <button class="btn-normal" @click="load">Actualiser</button>
        <button v-if="tab === 'grid'" class="btn accent" @click="edit()">+ Ajouter un palier</button>
        <button v-else-if="tab === 'zones'" class="btn accent" @click="zoneForm = { name: '', description: '', threshold: null, countries: [], is_default: false, sort: zones.length + 1 }">+ Ajouter une zone</button>
        <button v-else-if="tab === 'fx'" class="btn accent" @click="curForm = { code: '', symbol: '', xaf_value: null, active: true }">+ Ajouter une devise</button>
        <button v-else class="btn accent" @click="taxForm = { code: '', label: '', type: 'percent', value: 0, base: 'amount', zone_id: null, active: true }">+ Ajouter une taxe</button>
      </div>
    </div>

    <div v-if="error" class="flash err"><div>{{ error }}</div></div>
    <div v-if="msg" class="flash info"><div>{{ msg }}</div></div>

    <div class="tabs mb">
      <button v-for="(l, k) in TABS" :key="k" :class="{ on: tab === k }" @click="tab = k">{{ l }}</button>
    </div>

    <div v-show="tab === 'grid'">
    <div class="kpis mb">
      <div class="kpi"><span>Paliers actifs</span><b>{{ n(kpi.active) }} <small>/ {{ n(kpi.tiers) }}</small></b></div>
      <div class="kpi"><span>Pays avec un tarif propre</span><b>{{ n(kpi.countries) }}</b><small>les autres : « Toutes destinations »</small></div>
      <div class="kpi"><span>Frais perçus (30 j)</span><b>{{ short(kpi.fees_30d) }} <small>{{ cur }}</small></b><small>{{ n(kpi.count_30d) }} transfert(s)</small></div>
      <div class="kpi"><span>Taux de prélèvement moyen</span><b>{{ kpi.volume_30d ? (kpi.fees_30d * 100 / kpi.volume_30d).toFixed(2).replace('.', ',') + ' %' : '—' }}</b></div>
    </div>

    <div class="rule mb">
      <b>Frais payés par l'expéditeur = frais hors taxes de la tranche + taxes</b> ({{ taxSummary }}).
      Ordre de priorité des paliers : pays &gt; zone &gt; type &gt; réception &gt; partenaire &gt; « Toutes destinations ».
      Sans palier, le tarif « Envoi d'argent · international » de la grille nationale sert de frais hors taxes.
    </div>

    <div class="layout mb">
      <section class="container">
        <div class="container-head">
          <h3>Paliers <span class="counter">({{ filtered.length }})</span></h3>
          <div class="filters">
            <input v-model="flt.q" type="search" placeholder="Pays…" />
            <select v-model="flt.zone"><option value="">Toutes les zones</option><option v-for="z in zones" :key="z.id" :value="String(z.id)">{{ z.name }}</option></select>
            <button class="btn-normal sm" @click="exportCsv('grille-remittance', gridRows())">Excel</button>
            <button class="btn-normal sm" @click="printTable">PDF</button>
            <select v-model="flt.bt"><option value="">Tous les types</option><option value="*">Tous (*)</option><option v-for="(l, k) in businessTypes" :key="k" :value="k">{{ l }}</option></select>
            <select v-model="flt.active"><option value="">Tous</option><option value="1">Actifs</option><option value="0">Inactifs</option></select>
          </div>
        </div>
        <div class="container-body flush" style="overflow-x:auto;">
          <table>
            <thead><tr><th>Tranche A</th><th>Tranche B</th><th>Frais (hors taxes)</th><th>Frais (avec taxes)</th><th>Zone / destination</th><th>Type · réception</th><th>Actif</th><th></th></tr></thead>
            <tbody>
              <tr v-for="t in filtered" :key="t.id" :class="{ off: !t.active }">
                <td class="mono nowrap">{{ n(t.min_amount) }}</td>
                <td class="mono nowrap">{{ t.max_amount >= 1e9 ? '∞' : n(t.max_amount) }}</td>
                <td class="nowrap"><strong :class="{ 't-ok': isFree(t) }">{{ feeText(t) }}</strong><div v-if="t.min_fee || t.max_fee" class="stat-label">min {{ t.min_fee ? n(t.min_fee) : '—' }} / max {{ t.max_fee ? n(t.max_fee) : '—' }}</div></td>
                <td class="nowrap mono">{{ ttcRange(t) }}</td>
                <td>
                  <template v-if="t.country !== '*'"><Flag :iso="t.country" :size="14" /> <strong>{{ countryName(t.country) }}</strong> <span class="stat-label">{{ t.country }}</span></template>
                  <template v-else-if="t.zone_id"><strong>{{ zoneName(t.zone_id) }}</strong></template>
                  <template v-else><span class="all">🌍 Toutes destinations</span></template>
                  <div v-if="t.label" class="stat-label">{{ t.label }}</div>
                </td>
                <td class="nowrap"><span class="tag">{{ t.business_type === '*' ? 'Tous' : t.business_type.toUpperCase() }}</span> <span class="stat-label">{{ t.method === '*' ? 'toutes réceptions' : methods[t.method] }}{{ t.partner === '*' ? '' : ' · ' + partners[t.partner] }}</span></td>
                <td><label class="switch"><input type="checkbox" :checked="t.active" @change="toggle(t, $event.target.checked)" /><span></span></label></td>
                <td style="white-space:nowrap;">
                  <IconAction icon="edit" label="Modifier" @click="edit(t)" />
                  <IconAction icon="copy" label="Dupliquer (tranche suivante)" @click="edit({ ...t, id: undefined, min_amount: t.max_amount + 1, max_amount: 1000000000 })" />
                  <IconAction icon="trash" tone="danger" label="Supprimer" @click="remove(t)" />
                </td>
              </tr>
              <tr v-if="!filtered.length"><td colspan="8" class="stat-label">Aucun palier : le tarif « Envoi d'argent · international » de la grille nationale s'applique. Ajoutez un palier « Toutes destinations » pour commencer.</td></tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="container sim">
        <div class="container-head"><div><h3>Simulateur</h3></div></div>
        <div class="container-body">
          <label class="field">Pays de destination</label>
          <select v-model="sim.country"><option v-for="c in countries" :key="c.iso" :value="c.iso">{{ c.name }} ({{ c.currency }})</option></select>
          <label class="field">Type</label>
          <select v-model="sim.business_type"><option v-for="(l, k) in businessTypes" :key="k" :value="k">{{ l }}</option></select>
          <label class="field">Réception</label>
          <select v-model="sim.method"><option v-for="(l, k) in methods" :key="k" :value="k">{{ l }}</option></select>
          <label class="field">Montant ({{ cur }})</label>
          <input v-model.number="sim.amount" type="number" min="1" />
          <div class="quick"><button v-for="a in [25000, 100000, 500000, 2000000]" :key="a" type="button" @click="sim.amount = a">{{ short(a) }}</button></div>
          <button class="btn" style="width:100%;" :disabled="simBusy" @click="simulate">{{ simBusy ? '…' : 'Calculer' }}</button>
          <div v-if="simRes" class="preview" style="margin-top:12px;">
            <span>Résultat</span>
            <div><span>Zone</span><b>{{ simRes.zone || '—' }}</b></div>
            <div><span>Frais hors taxes</span><b>{{ n(simRes.fee_ht) }} {{ cur }}</b></div>
            <div v-for="x in simRes.taxes" :key="x.code"><span>{{ x.code }} <small class="stat-label">{{ taxRule(x) }}</small></span><b>{{ n2(x.amount) }} {{ cur }}</b></div>
            <div><span>Frais avec taxes</span><b>{{ n(simRes.fee) }} {{ cur }}</b></div>
            <div><span>Total débité</span><b>{{ n(simRes.total) }} {{ cur }}</b></div>
            <div v-if="simRes.fx"><span>Reçu</span><b>{{ n(simRes.fx.amount) }} {{ simRes.fx.to }}</b></div>
            <div><span>Partenaire</span><b>{{ simRes.partner_name || '—' }}</b></div>
            <div v-if="simRes.over_threshold" class="t-ko">Au-dessus du seuil de la zone ({{ n(simRes.threshold) }} {{ cur }}) : refusé.</div>
            <small class="stat-label">{{ simRes.tariff ? 'Palier : ' + describe(simRes.tariff) : simRes.source }}</small>
          </div>
        </div>
      </section>
    </div>

    </div>

    <!-- Zones -->
    <section v-show="tab === 'zones'" class="container">
      <div class="container-head"><h3>Zones <span class="counter">({{ zones.length }})</span></h3>
        <div class="filters"><button class="btn-normal sm" @click="exportCsv('zones', zones.map((z) => ({ Nom: z.name, Description: z.description || '', Seuil: z.threshold || '', Pays: (z.countries || []).join(' ') })))">Excel</button><button class="btn-normal sm" @click="printTable">PDF</button></div>
      </div>
      <div class="container-body flush" style="overflow-x:auto;">
        <table>
          <thead><tr><th>Nom</th><th>Description</th><th>Seuil ({{ cur }})</th><th>Pays</th><th></th></tr></thead>
          <tbody>
            <tr v-for="z in zones" :key="z.id">
              <td><strong>{{ z.name }}</strong> <span v-if="z.is_default" class="tag">par défaut</span></td>
              <td class="stat-label">{{ z.description }}</td>
              <td class="mono nowrap">{{ z.threshold ? n(z.threshold) : '—' }}</td>
              <td><span v-if="z.is_default && !(z.countries || []).length" class="stat-label">Tous les pays non classés</span><span v-for="c in z.countries" :key="c" class="chip"><Flag :iso="c" :size="12" /> {{ c }}</span></td>
              <td class="nowrap"><IconAction icon="edit" label="Modifier" @click="zoneForm = { ...z, countries: [...(z.countries || [])] }" /><IconAction icon="trash" tone="danger" label="Supprimer" @click="del('remit-zones', z, 'la zone ' + z.name)" /></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Taux de change -->
    <section v-show="tab === 'fx'" class="container">
      <div class="container-head"><div><h3>Taux de change à partir du Congo <span class="counter">({{ currencies.length }})</span></h3><p class="stat-label">Valeur en {{ cur }} d'une unité de devise (marge comprise). Montant reçu = montant ÷ valeur.</p></div>
        <div class="filters"><input v-model="flt.cur" type="search" placeholder="Devise…" /><button class="btn-normal sm" @click="exportCsv('taux-de-change', currencies.map((c) => ({ Code: c.code, Symbole: c.symbol || '', ['Montant(' + cur + ')']: c.xaf_value })))">Excel</button><button class="btn-normal sm" @click="printTable">PDF</button></div>
      </div>
      <div class="container-body flush" style="overflow-x:auto;">
        <table>
          <thead><tr><th>Code</th><th>Symbole</th><th>Montant ({{ cur }})</th><th>Exemple</th><th>Actif</th><th></th></tr></thead>
          <tbody>
            <tr v-for="c in currencies.filter((c) => !flt.cur || c.code.includes(flt.cur.toUpperCase()))" :key="c.id" :class="{ off: !c.active }">
              <td><strong>{{ c.code }}</strong></td>
              <td>{{ c.symbol }}</td>
              <td class="mono">{{ n4(c.xaf_value) }}</td>
              <td class="stat-label nowrap">100 000 {{ cur }} → {{ n(Math.floor(100000 / c.xaf_value)) }} {{ c.code }}</td>
              <td>{{ c.active ? 'Oui' : 'Non' }}</td>
              <td class="nowrap"><IconAction icon="edit" label="Modifier" @click="curForm = { ...c }" /><IconAction icon="trash" tone="danger" label="Supprimer" @click="del('remit-currencies', c, 'la devise ' + c.code)" /></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Taxes -->
    <section v-show="tab === 'taxes'" class="container">
      <div class="container-head"><h3>Taxes <span class="counter">({{ taxes.length }})</span></h3>
        <div class="filters"><button class="btn-normal sm" @click="exportCsv('taxes', taxes.map((t) => ({ Code: t.code, Libelle: t.label, Type: t.type === 'fixed' ? 'Fixe' : 'Pourcentage', Valeur: t.value, Assiette: BASES[t.base], Zone: t.zone_id ? zoneName(t.zone_id) : 'Toutes zones', Statut: t.active ? 'Actif' : 'Inactif' })))">Excel</button><button class="btn-normal sm" @click="printTable">PDF</button></div>
      </div>
      <div class="container-body flush" style="overflow-x:auto;">
        <table>
          <thead><tr><th>Code</th><th>Libellé</th><th>Type</th><th>Valeur</th><th>Assiette</th><th>Zone</th><th>Statut</th><th></th></tr></thead>
          <tbody>
            <tr v-for="t in taxes" :key="t.id" :class="{ off: !t.active }">
              <td><strong>{{ t.code }}</strong></td>
              <td>{{ t.label }}</td>
              <td>{{ t.type === 'fixed' ? 'Fixe' : 'Pourcentage' }}</td>
              <td class="mono">{{ t.type === 'fixed' ? n(t.value) + ' ' + cur : String(Number(t.value)).replace('.', ',') + ' %' }}</td>
              <td>{{ BASES[t.base] }}</td>
              <td>{{ t.zone_id ? zoneName(t.zone_id) : 'Toutes zones' }}</td>
              <td><span class="tag" :class="{ ko: !t.active }">{{ t.active ? 'Actif' : 'Inactif' }}</span></td>
              <td class="nowrap"><IconAction icon="edit" label="Modifier" @click="taxForm = { ...t }" /><IconAction icon="trash" tone="danger" label="Supprimer" @click="del('remit-taxes', t, 'la taxe ' + t.code)" /></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <Modal v-if="zoneForm" :title="zoneForm.id ? 'Modifier la zone' : 'Nouvelle zone'" subtitle="Seuil = montant maximum par transfert vers la zone" @close="zoneForm = null">
      <div class="row2">
        <div><label class="field">Nom</label><input v-model="zoneForm.name" maxlength="80" /></div>
        <div><label class="field">Seuil ({{ cur }})</label><input v-model.number="zoneForm.threshold" type="number" min="0" placeholder="Aucun" /></div>
      </div>
      <label class="field">Description</label><input v-model="zoneForm.description" maxlength="190" />
      <label class="field">Pays ({{ zoneForm.countries.length }})</label>
      <select @change="addCountry($event)"><option value="">+ Ajouter un pays…</option><option v-for="c in countries.filter((c) => !zoneForm.countries.includes(c.iso))" :key="c.iso" :value="c.iso">{{ c.name }} ({{ c.iso }})</option></select>
      <div class="chips"><span v-for="c in zoneForm.countries" :key="c" class="chip"><Flag :iso="c" :size="12" /> {{ countryName(c) }} <a @click="zoneForm.countries = zoneForm.countries.filter((x) => x !== c)">×</a></span></div>
      <label class="check"><input v-model="zoneForm.is_default" type="checkbox" /> Zone par défaut (pays non classés ailleurs)</label>
      <template #foot>
        <button class="btn-normal" @click="zoneForm = null">Annuler</button>
        <button class="btn" :disabled="saving" @click="saveOther('remit-zones', zoneForm, () => (zoneForm = null))">Enregistrer</button>
      </template>
    </Modal>

    <Modal v-if="curForm" :title="curForm.id ? 'Modifier le taux' : 'Nouvelle devise'" :subtitle="'Valeur en ' + cur + ' d\'une unité'" @close="curForm = null">
      <div class="row2">
        <div><label class="field">Code ISO</label><input v-model="curForm.code" maxlength="3" placeholder="EUR" /></div>
        <div><label class="field">Symbole</label><input v-model="curForm.symbol" maxlength="12" placeholder="€" /></div>
      </div>
      <label class="field">1 {{ curForm.code || 'unité' }} = … {{ cur }}</label><input v-model.number="curForm.xaf_value" type="number" step="0.0001" min="0" />
      <label class="check"><input v-model="curForm.active" type="checkbox" /> Actif</label>
      <div v-if="curForm.xaf_value" class="preview"><span>Aperçu</span><div><span>100 000 {{ cur }}</span><b>{{ n(Math.floor(100000 / curForm.xaf_value)) }} {{ curForm.code }}</b></div></div>
      <template #foot>
        <button class="btn-normal" @click="curForm = null">Annuler</button>
        <button class="btn" :disabled="saving" @click="saveOther('remit-currencies', curForm, () => (curForm = null))">Enregistrer</button>
      </template>
    </Modal>

    <Modal v-if="taxForm" :title="taxForm.id ? 'Modifier la taxe' : 'Nouvelle taxe'" subtitle="Ajoutée aux frais hors taxes" @close="taxForm = null">
      <div class="row2">
        <div><label class="field">Code</label><input v-model="taxForm.code" maxlength="20" placeholder="TVA" /></div>
        <div><label class="field">Libellé</label><input v-model="taxForm.label" maxlength="100" /></div>
      </div>
      <div class="row2">
        <div><label class="field">Type</label><select v-model="taxForm.type"><option value="percent">Pourcentage</option><option value="fixed">Fixe</option></select></div>
        <div><label class="field">Valeur {{ taxForm.type === 'percent' ? '(%)' : '(' + cur + ')' }}</label><input v-model.number="taxForm.value" type="number" step="0.01" min="0" /></div>
      </div>
      <div class="row2">
        <div><label class="field">Assiette</label><select v-model="taxForm.base" :disabled="taxForm.type === 'fixed'"><option value="amount">Montant envoyé</option><option value="fee">Frais hors taxes</option></select></div>
        <div><label class="field">Zone</label><select v-model="taxForm.zone_id"><option :value="null">Toutes zones</option><option v-for="z in zones" :key="z.id" :value="z.id">{{ z.name }}</option></select></div>
      </div>
      <label class="check"><input v-model="taxForm.active" type="checkbox" /> Active</label>
      <template #foot>
        <button class="btn-normal" @click="taxForm = null">Annuler</button>
        <button class="btn" :disabled="saving" @click="saveOther('remit-taxes', taxForm, () => (taxForm = null))">Enregistrer</button>
      </template>
    </Modal>

    <Modal v-if="form" :title="form.id ? 'Modifier le palier' : 'Nouveau palier international'" subtitle="Payé par l'expéditeur, en plus du montant" @close="form = null">
      <label class="field">Zone</label>
      <select v-model="form.zone_id"><option :value="null">Toutes les zones</option><option v-for="z in zones" :key="z.id" :value="z.id">{{ z.name }}</option></select>
      <label class="field">Pays de destination</label>
      <select v-model="form.country">
        <option value="*">{{ form.zone_id ? 'Tous les pays de la zone' : '🌍 Toutes destinations' }}</option>
        <option v-for="c in countries" :key="c.iso" :value="c.iso">{{ c.name }} ({{ c.iso }})</option>
      </select>
      <div class="row2">
        <div><label class="field">Type de transfert</label><select v-model="form.business_type"><option value="*">Tous</option><option v-for="(l, k) in businessTypes" :key="k" :value="k">{{ l }}</option></select></div>
        <div><label class="field">Réception</label><select v-model="form.method"><option value="*">Toutes</option><option v-for="(l, k) in methods" :key="k" :value="k">{{ l }}</option></select></div>
      </div>
      <div class="row2">
        <div><label class="field">Partenaire</label><select v-model="form.partner"><option value="*">Tous</option><option v-for="(l, k) in partners" :key="k" :value="k">{{ l }}</option></select></div>
        <div><label class="field">Libellé (facultatif)</label><input v-model="form.label" maxlength="120" placeholder="ex. Promotion diaspora" /></div>
      </div>
      <div class="row2">
        <div><label class="field">Montant min ({{ cur }})</label><input v-model.number="form.min_amount" type="number" min="0" /></div>
        <div><label class="field">Montant max ({{ cur }})</label><input v-model.number="form.max_amount" type="number" min="1" /><div class="hint">1 000 000 000 = sans limite</div></div>
      </div>
      <div class="row2">
        <div><label class="field">Type de frais</label><select v-model="form.fee_type"><option value="percent">Pourcentage</option><option value="fixed">Montant fixe</option></select></div>
        <div><label class="field">Valeur {{ form.fee_type === 'percent' ? '(%)' : '(' + cur + ')' }}</label><input v-model.number="form.fee_value" type="number" step="0.01" min="0" /></div>
      </div>
      <div class="row2">
        <div><label class="field">Frais minimum ({{ cur }})</label><input v-model.number="form.min_fee" type="number" min="0" /></div>
        <div><label class="field">Frais maximum ({{ cur }})</label><input v-model.number="form.max_fee" type="number" min="0" placeholder="Aucun plafond" /></div>
      </div>
      <label class="check"><input v-model="form.active" type="checkbox" /> Palier actif</label>
      <div class="preview">
        <span>Aperçu</span>
        <div v-for="a in previewAmounts" :key="a"><span>{{ n(a) }} {{ cur }}</span><b>{{ n(feeOf(form, a)) }} HT · {{ n(ttcOf(form, a)) }} TTC</b></div>
      </div>
      <template #foot>
        <button class="btn-normal" @click="form = null">Annuler</button>
        <button class="btn" :disabled="saving" @click="save">{{ saving ? 'Enregistrement…' : 'Enregistrer' }}</button>
      </template>
    </Modal>
  </div>
</template>

<script setup>
import { confirmBox } from '../utils/ui'
import IconAction from '../components/IconAction.vue'
import Flag from '../components/Flag.vue'
import Modal from '../components/Modal.vue'
import { computed, onMounted, reactive, ref } from 'vue'
import api from '../services/api'

const TABS = { grid: 'Grilles tarifaires', zones: 'Zones', fx: 'Taux de change', taxes: 'Taxes' }
const BASES = { amount: 'Montant', fee: 'Frais', none: '—' }
const tab = ref('grid')
const zones = ref([])
const taxes = ref([])
const currencies = ref([])
const zoneForm = ref(null)
const curForm = ref(null)
const taxForm = ref(null)
const tariffs = ref([])
const countries = ref([])
const businessTypes = ref({})
const methods = ref({})
const partners = ref({})
const kpi = ref({})
const cur = ref('XAF')
const error = ref('')
const msg = ref('')
const form = ref(null)
const saving = ref(false)
const flt = reactive({ q: '', bt: '', active: '', zone: '', cur: '' })
const sim = reactive({ country: 'CI', business_type: 'p2p', method: 'wallet', amount: 100000 })
const simRes = ref(null)
const simBusy = ref(false)

const nf = new Intl.NumberFormat('fr-FR')
const n = (v) => nf.format(v || 0)
const short = (v) => (!v ? '0' : v >= 1e6 ? (v / 1e6).toFixed(1).replace('.', ',') + ' M' : v >= 1e4 ? Math.round(v / 1e3) + ' k' : nf.format(v))
const n2 = (v) => new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 2 }).format(v || 0)
const n4 = (v) => new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 4 }).format(v || 0)
const zoneName = (id) => zones.value.find((z) => z.id === id)?.name || '—'
const zoneOf = (t) => (t.zone_id ? t.zone_id : t.country !== '*' ? (zones.value.find((z) => (z.countries || []).includes(t.country)) || zones.value.find((z) => z.is_default))?.id : null)
const taxRule = (x) => (x.type === 'fixed' ? 'fixe' : String(Number(x.value)).replace('.', ',') + ' % ' + (x.base === 'fee' ? 'des frais' : 'du montant'))
const taxSummary = computed(() => taxes.value.filter((t) => t.active).map((t) => t.code + ' ' + taxRule(t)).join(', ') || 'aucune taxe active')
// Frais avec taxes (même calcul que le serveur) : HT + Σ taxes actives de la zone ou toutes zones, arrondi
const ttcOf = (t, amount) => {
  const ht = feeOf(t, amount)
  const zid = zoneOf(t)
  const sum = taxes.value.filter((x) => x.active && (!x.zone_id || x.zone_id === zid))
    .reduce((acc, x) => acc + (x.type === 'fixed' ? Number(x.value) : ((x.base === 'fee' ? ht : amount) * Number(x.value)) / 100), 0)
  return Math.round(ht + sum)
}
const ttcRange = (t) => {
  const a = ttcOf(t, t.min_amount)
  const b = ttcOf(t, Math.min(t.max_amount, 1e9))
  return a === b ? n(a) : n(a) + ' - ' + n(b)
}
const countryName = (iso) => countries.value.find((c) => c.iso === iso)?.name || iso
const isFree = (t) => Number(t.fee_value) === 0 && !t.min_fee
const feeText = (t) => (isFree(t) ? 'Gratuit' : t.fee_type === 'percent' ? String(Number(t.fee_value)).replace('.', ',') + ' %' : n(t.fee_value) + ' ' + cur.value)
const feeOf = (t, amount) => {
  let fee = t.fee_type === 'fixed' ? Math.round(t.fee_value || 0) : Math.ceil((amount * (t.fee_value || 0)) / 100)
  fee = Math.max(fee, t.min_fee || 0)
  if (t.max_fee) fee = Math.min(fee, t.max_fee)
  return fee
}
const describe = (t) => [t.country === '*' ? 'Toutes destinations' : countryName(t.country), t.business_type === '*' ? null : t.business_type.toUpperCase(), t.method === '*' ? null : methods.value[t.method], feeText(t)].filter(Boolean).join(' · ')
const filtered = computed(() => {
  const q = flt.q.trim().toLowerCase()
  return tariffs.value.filter((t) => (!q || (t.country === '*' ? 'toutes destinations' : countryName(t.country) + ' ' + t.country).toLowerCase().includes(q))
    && (!flt.zone || String(zoneOf(t)) === flt.zone)
    && (!flt.bt || t.business_type === flt.bt) && (flt.active === '' || String(+t.active) === flt.active))
})
const previewAmounts = computed(() => {
  if (!form.value) return []
  const lo = Math.max(form.value.min_amount || 0, 10000)
  return [lo, Math.max(lo, 100000), Math.max(lo, 1000000)].filter((a, i, arr) => a <= form.value.max_amount && arr.indexOf(a) === i)
})

async function load() {
  error.value = ''
  try {
    const { data } = await api.get('/admin/remit-tariffs')
    tariffs.value = data.tariffs
    zones.value = data.zones || []
    taxes.value = data.taxes || []
    currencies.value = data.currencies || []
    countries.value = data.countries
    businessTypes.value = data.business_types
    methods.value = data.methods
    partners.value = data.partners
    kpi.value = data.kpi || {}
    cur.value = data.currency || 'XAF'
  } catch (e) {
    error.value = e.response?.data?.message || e.message
  }
}
function edit(t = {}) {
  form.value = { zone_id: null, country: '*', business_type: '*', method: '*', partner: '*', fee_type: 'percent', fee_value: 2, min_amount: 0, max_amount: 1000000000, min_fee: 0, max_fee: null, active: true, label: '', ...t }
}
async function save() {
  saving.value = true
  error.value = ''
  try {
    const { data } = await api.post('/admin/remit-tariffs', form.value)
    form.value = null
    msg.value = data.message || 'Palier enregistré.'
    await load()
  } catch (e) {
    const errs = e.response?.data?.errors
    error.value = errs ? Object.values(errs).flat().join(' ') : (e.response?.data?.message || e.message)
  } finally {
    saving.value = false
  }
}
async function toggle(t, active) {
  try {
    await api.post(`/admin/remit-tariffs/${t.id}/active`, { active })
    await load()
  } catch (e) {
    error.value = e.response?.data?.message || e.message
  }
}
async function remove(t) {
  if (!(await confirmBox(describe(t), { title: 'Supprimer ce palier international ?', confirmLabel: 'Supprimer', danger: true }))) return
  await api.delete(`/admin/remit-tariffs/${t.id}`)
  msg.value = 'Palier supprimé.'
  await load()
}
async function simulate() {
  simBusy.value = true
  try {
    simRes.value = (await api.post('/admin/remit-tariffs/simulate', sim)).data
  } catch (e) {
    error.value = e.response?.data?.message || e.message
  } finally {
    simBusy.value = false
  }
}
async function saveOther(path, body, close) {
  saving.value = true
  error.value = ''
  try {
    const { data } = await api.post('/admin/' + path, body)
    close()
    msg.value = data.message || 'Enregistré.'
    await load()
  } catch (e) {
    const errs = e.response?.data?.errors
    error.value = e.response?.data?.message || (errs ? Object.values(errs).flat().join(' ') : e.message)
  } finally {
    saving.value = false
  }
}
async function del(path, row, what) {
  if (!(await confirmBox('Supprimer ' + what + ' ?', { title: 'Confirmer la suppression', confirmLabel: 'Supprimer', danger: true }))) return
  try {
    const { data } = await api.delete(`/admin/${path}/${row.id}`)
    msg.value = data.message
    await load()
  } catch (e) {
    error.value = e.response?.data?.message || e.message
  }
}
function addCountry(ev) {
  const v = ev.target.value
  if (v && !zoneForm.value.countries.includes(v)) zoneForm.value.countries.push(v)
  ev.target.value = ''
}
const gridRows = () => filtered.value.map((t) => ({
  'Tranche A': t.min_amount, 'Tranche B': t.max_amount >= 1e9 ? '' : t.max_amount,
  'Frais (hors taxes)': feeText(t), 'Frais (avec taxes)': ttcRange(t),
  Zone: t.country !== '*' ? countryName(t.country) : t.zone_id ? zoneName(t.zone_id) : 'Toutes destinations',
  Type: t.business_type === '*' ? 'Tous' : t.business_type.toUpperCase(), Reception: t.method === '*' ? 'Toutes' : methods.value[t.method], Actif: t.active ? 'Oui' : 'Non',
}))
// Export Excel (CSV ; séparateur « ; », BOM UTF-8 pour Excel)
function exportCsv(name, rows) {
  if (!rows.length) return
  const cols = Object.keys(rows[0])
  const esc = (v) => '"' + String(v ?? '').replace(/"/g, '""') + '"'
  const csv = '\ufeff' + [cols.map(esc).join(';'), ...rows.map((r) => cols.map((c) => esc(r[c])).join(';'))].join('\r\n')
  const a = document.createElement('a')
  a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }))
  a.download = `${name}-${new Date().toISOString().slice(0, 10)}.csv`
  a.click()
  URL.revokeObjectURL(a.href)
}
const printTable = () => window.print()
onMounted(load)
</script>

<style scoped>
.kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; }
.kpi { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 14px 16px; box-shadow: var(--shadow); display: grid; gap: 2px; }
.kpi span { color: var(--text-2); font-size: 12.5px; }
.kpi b { font-size: 24px; }
.kpi small { font-size: 12.5px; color: var(--text-2); font-weight: 500; }
.rule { font-size: 13px; color: var(--text-2); background: var(--surface); border: 1px solid var(--border); border-left: 3px solid var(--link); border-radius: 10px; padding: 10px 14px; }
.rule b { color: var(--text); }
.layout { display: grid; grid-template-columns: minmax(0, 1fr) 340px; gap: 16px; align-items: start; }
.layout .container { margin: 0; }
@media (max-width: 1200px) { .layout { grid-template-columns: 1fr; } }
.filters { display: flex; gap: 8px; flex-wrap: wrap; }
.filters select, .filters input { padding: 6px 8px; border: 1px solid var(--border-strong); border-radius: 8px; font: inherit; }
.all { font-weight: 700; }
.nowrap { white-space: nowrap; }
.tag { font-size: 12px; padding: 2px 8px; border-radius: 99px; background: var(--info-bg); color: var(--link); font-weight: 600; }
.t-ok { color: var(--ok); }
.sim select, .sim input { width: 100%; margin-bottom: 10px; }
.quick { display: flex; gap: 6px; margin: -4px 0 12px; }
.quick button { flex: 1; border: 1px solid var(--border-strong); background: #fff; border-radius: 8px; padding: 4px; cursor: pointer; font: inherit; font-size: 12px; }
.quick button:hover { border-color: var(--link); }
tr.off td { color: var(--text-2); background: #fafafa; }
.switch { position: relative; display: inline-block; width: 36px; height: 20px; }
.switch input { opacity: 0; width: 0; height: 0; }
.switch span { position: absolute; inset: 0; background: #cbd2dc; border-radius: 99px; transition: .15s; cursor: pointer; }
.switch span::before { content: ''; position: absolute; width: 16px; height: 16px; left: 2px; top: 2px; background: #fff; border-radius: 50%; transition: .15s; }
.switch input:checked + span { background: var(--ok); }
.switch input:checked + span::before { transform: translateX(16px); }
.check { display: inline-flex; gap: 8px; align-items: center; }
.check input { width: auto !important; }
.preview { background: var(--surface-2); border: 1px dashed var(--border-strong); border-radius: 10px; padding: 10px 14px; display: grid; gap: 4px; font-size: 13px; }
.preview > span { font-size: 11.5px; font-weight: 700; text-transform: uppercase; color: var(--text-2); }
.preview div { display: flex; justify-content: space-between; }
.tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--border); flex-wrap: wrap; }
.tabs button { border: 0; background: none; padding: 9px 14px; font: inherit; font-weight: 600; color: var(--text-2); cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -1px; }
.tabs button.on { color: var(--link); border-bottom-color: var(--link); }
.btn-normal.sm { padding: 5px 10px; font-size: 12.5px; }
.chip { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; border: 1px solid var(--border); border-radius: 99px; padding: 1px 8px; margin: 2px 4px 2px 0; background: var(--surface-2); }
.chip a { cursor: pointer; color: var(--danger, #c0392b); font-weight: 700; }
.chips { margin: 6px 0 10px; max-height: 160px; overflow: auto; }
.tag.ko { background: #f1f1f1; color: var(--text-2); }
.t-ko { color: var(--danger, #c0392b); font-weight: 600; }
@media print { .page-header .actions, .tabs, .filters, .sim, .kpis, .rule, td:last-child, th:last-child { display: none !important; } }
</style>
