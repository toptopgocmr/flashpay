<template>
  <div>
    <div class="page-header">
      <div>
        <h1>Tarifs internationaux</h1>
        <p>Frais des transferts internationaux (remittance), à part de la grille nationale : grille par zone et par tranche, taxes et taux de change à partir du Congo.</p>
      </div>
      <div class="actions">
        <router-link class="btn-normal" to="/tariffs">Grille nationale</router-link>
        <button class="btn-normal" @click="load">Actualiser</button>
      </div>
    </div>

    <div v-if="error" class="flash err"><div>{{ error }}</div></div>
    <div v-if="msg" class="flash info"><div>{{ msg }}</div></div>

    <div class="tabs mb">
      <button v-for="(l, k) in TABS" :key="k" :class="{ on: tab === k }" @click="tab = k">{{ l }} <span class="tab-count">{{ tabCount(k) }}</span></button>
    </div>

    <!-- ===================== Grilles tarifaires ===================== -->
    <div v-show="tab === 'grid'">
      <!-- Indicateurs -->
      <div class="stats mb">
        <div><span>Paliers actifs</span><b>{{ n(kpi.active) }}<small> / {{ n(kpi.tiers) }}</small></b></div>
        <div><span>Frais perçus (30 j)</span><b>{{ short(kpi.fees_30d) }}<small> {{ cur }}</small></b></div>
        <div><span>Transferts (30 j)</span><b>{{ n(kpi.count_30d) }}</b></div>
        <div><span>Prélèvement moyen</span><b>{{ kpi.volume_30d ? (kpi.fees_30d * 100 / kpi.volume_30d).toFixed(2).replace('.', ',') + ' %' : '—' }}</b></div>
      </div>

      <!-- Formule -->
      <div class="formula mb">
        <div class="f-head"><b>Frais payés par l'expéditeur</b><button class="link-btn" @click="tab = 'taxes'">Modifier les taxes</button></div>
        <div class="f-row">
          <span class="f-part f-main">Frais HT de la tranche</span>
          <template v-for="t in activeTaxes" :key="t.id"><span class="f-op">+</span><span class="f-part" :title="t.label">{{ t.code }} <small>{{ taxRule(t) }}</small></span></template>
        </div>
      </div>

      <!-- Barre d'outils -->
      <div class="toolbar mb">
        <input v-model="flt.q" type="search" placeholder="Rechercher une zone ou un pays…" />
        <select v-model="flt.zone"><option value="">Toutes les zones</option><option v-for="z in zones" :key="z.id" :value="String(z.id)">{{ z.name }}</option></select>
        <select v-model="flt.active"><option value="">Actifs et inactifs</option><option value="1">Actifs</option><option value="0">Inactifs</option></select>
        <span class="grow"></span>
        <button class="btn-normal sm" :class="{ on: showSim }" @click="showSim = !showSim">Simulateur</button>
        <button class="btn-normal sm" @click="exportCsv('grille-remittance', gridRows())">Excel</button>
        <button class="btn-normal sm" @click="printTable">PDF</button>
        <button class="btn accent sm" @click="edit()">+ Ajouter un palier</button>
      </div>

      <!-- Simulateur (repliable, sur toute la largeur) -->
      <section v-if="showSim" class="container sim mb">
        <div class="container-body">
          <div class="sim-form">
            <div><label class="field">Pays de destination</label>
              <select v-model="sim.country"><option v-for="c in countries" :key="c.iso" :value="c.iso">{{ c.name }} ({{ c.currency }})</option></select></div>
            <div><label class="field">Type</label>
              <select v-model="sim.business_type"><option v-for="(l, k) in businessTypes" :key="k" :value="k">{{ l }}</option></select></div>
            <div><label class="field">Réception</label>
              <select v-model="sim.method"><option v-for="(l, k) in methods" :key="k" :value="k">{{ l }}</option></select></div>
            <div><label class="field">Montant ({{ cur }})</label>
              <input v-model.number="sim.amount" type="number" min="1" />
              <div class="quick"><button v-for="a in [25000, 100000, 500000, 2000000]" :key="a" type="button" @click="sim.amount = a">{{ short(a) }}</button></div></div>
            <div class="sim-go"><button class="btn" :disabled="simBusy" @click="simulate">{{ simBusy ? '…' : 'Calculer' }}</button></div>
          </div>
          <div v-if="simRes" class="sim-res">
            <div class="cell"><span>Zone</span><b>{{ simRes.zone || '—' }}</b></div>
            <div class="cell"><span>Frais HT</span><b>{{ n(simRes.fee_ht) }}</b></div>
            <div v-for="x in simRes.taxes" :key="x.code" class="cell"><span>{{ x.code }}</span><b>{{ n2(x.amount) }}</b></div>
            <div class="cell strong"><span>Frais TTC</span><b>{{ n(simRes.fee) }} {{ cur }}</b></div>
            <div class="cell strong"><span>Total débité</span><b>{{ n(simRes.total) }} {{ cur }}</b></div>
            <div v-if="simRes.fx" class="cell"><span>Reçu</span><b>{{ n(simRes.fx.amount) }} {{ simRes.fx.to }}</b></div>
            <div class="cell"><span>Partenaire</span><b>{{ simRes.partner_name || '—' }}</b></div>
            <div v-if="simRes.over_threshold" class="sim-warn">Au-dessus du seuil de la zone ({{ n(simRes.threshold) }} {{ cur }}) : transfert refusé.</div>
            <div class="sim-src stat-label">{{ simRes.tariff ? 'Palier appliqué : ' + describe(simRes.tariff) : simRes.source }}</div>
          </div>
        </div>
      </section>

      <!-- Une carte par zone -->
      <section v-for="g in zoneGroups" :key="g.key" class="container zone-card mb">
        <div class="container-head">
          <div class="zh">
            <h3>{{ g.title }} <span class="counter">({{ g.rows.length }})</span></h3>
            <div class="zh-meta">
              <span v-if="g.zone?.threshold" class="pill">Seuil {{ n(g.zone.threshold) }} {{ cur }}</span>
              <span v-if="g.zone?.is_default" class="pill soft">Pays non classés</span>
              <span v-else-if="g.zone" class="pill soft" :title="(g.zone.countries || []).map(countryName).join(', ')">{{ (g.zone.countries || []).length }} pays</span>
              <span v-if="g.hint" class="stat-label">{{ g.hint }}</span>
            </div>
          </div>
          <button class="btn-normal sm" @click="addTier(g)">+ Tranche</button>
        </div>
        <div class="container-body flush" style="overflow-x:auto;">
          <table class="tier-table" :class="{ special: g.special }">
            <colgroup>
              <col v-if="g.special" class="c-dest" />
              <col class="c-amt" /><col class="c-amt" /><col class="c-fee" /><col class="c-ttc" /><col class="c-on" /><col class="c-act" />
            </colgroup>
            <thead>
              <tr>
                <th v-if="g.special">Destination · conditions</th>
                <th>Tranche A</th><th>Tranche B</th><th>Frais HT</th><th>Frais TTC (avec taxes)</th><th>Actif</th><th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="t in g.rows" :key="t.id" :class="{ off: !t.active }">
                <td v-if="g.special">
                  <template v-if="t.country !== '*'"><Flag :iso="t.country" :size="14" /> <strong>{{ countryName(t.country) }}</strong></template>
                  <strong v-else>{{ t.zone_id ? zoneName(t.zone_id) : 'Toutes destinations' }}</strong>
                  <div class="conds">
                    <span v-if="t.business_type !== '*'" class="tag">{{ t.business_type.toUpperCase() }}</span>
                    <span v-if="t.method !== '*'" class="tag">{{ methods[t.method] }}</span>
                    <span v-if="t.partner !== '*'" class="tag">{{ partners[t.partner] }}</span>
                  </div>
                  <div v-if="customLabel(t)" class="stat-label">{{ t.label }}</div>
                </td>
                <td class="amt">{{ n(t.min_amount) }}</td>
                <td class="amt">{{ t.max_amount >= 1e9 ? 'sans limite' : n(t.max_amount) }}</td>
                <td class="amt"><strong :class="{ 't-ok': isFree(t) }">{{ feeText(t) }}</strong>
                  <div v-if="t.min_fee || t.max_fee" class="stat-label">min {{ t.min_fee ? n(t.min_fee) : '—' }} · max {{ t.max_fee ? n(t.max_fee) : '—' }}</div>
                  <div v-if="!g.special && customLabel(t)" class="stat-label">{{ t.label }}</div></td>
                <td class="amt">{{ ttcRange(t) }}</td>
                <td><label class="switch"><input type="checkbox" :checked="t.active" @change="toggle(t, $event.target.checked)" /><span></span></label></td>
                <td class="acts">
                  <IconAction icon="edit" label="Modifier" @click="edit(t)" />
                  <IconAction icon="copy" label="Tranche suivante" @click="edit({ ...t, id: undefined, label: '', min_amount: t.max_amount + 1, max_amount: 1000000000 })" />
                  <IconAction icon="trash" tone="danger" label="Supprimer" @click="remove(t)" />
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
      <div v-if="!zoneGroups.length" class="container"><div class="container-body stat-label">
        Aucun palier{{ flt.q || flt.zone || flt.active !== '' ? ' pour ces filtres' : '' }} : le tarif « Envoi d'argent · international » de la grille nationale s'applique.
      </div></div>
      <p class="stat-label foot-note">Priorité quand plusieurs paliers couvrent un montant : pays &gt; zone &gt; type de transfert &gt; réception &gt; partenaire &gt; toutes destinations.</p>
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

    <Modal v-if="form" :title="form.id ? 'Modifier le palier' : 'Nouveau palier'" subtitle="Frais hors taxes payés par l'expéditeur, en plus du montant ; les taxes s'ajoutent automatiquement" @close="form = null">
      <div v-if="formError" class="flash err"><div>{{ formError }}</div></div>

      <div class="fsec">
        <div class="fsec-t">1. Destination</div>
        <div class="row2">
          <div><label class="field">Zone</label>
            <select v-model="form.zone_id" @change="form.country = '*'"><option :value="null">Toutes les zones</option><option v-for="z in zones" :key="z.id" :value="z.id">{{ z.name }}</option></select></div>
          <div><label class="field">Pays</label>
            <select v-model="form.country">
              <option value="*">{{ form.zone_id ? 'Tous les pays de la zone' : 'Toutes destinations' }}</option>
              <option v-for="c in formCountries" :key="c.iso" :value="c.iso">{{ c.name }} ({{ c.iso }})</option>
            </select></div>
        </div>
      </div>

      <div class="fsec">
        <div class="fsec-t">2. Tranche ({{ cur }})</div>
        <div class="row2">
          <div><label class="field">Tranche A (de)</label><input v-model.number="form.min_amount" type="number" min="0" /></div>
          <div><label class="field">Tranche B (à)</label>
            <input v-model.number="form.max_amount" type="number" min="1" :disabled="form.max_amount >= 1e9" :placeholder="form.max_amount >= 1e9 ? 'Sans limite' : ''" />
            <label class="check small"><input type="checkbox" :checked="form.max_amount >= 1e9" @change="form.max_amount = $event.target.checked ? 1000000000 : Math.max((form.min_amount || 0) + 1, 50000)" /> Sans limite</label></div>
        </div>
      </div>

      <div class="fsec">
        <div class="fsec-t">3. Frais hors taxes</div>
        <div class="seg mb-s">
          <button type="button" :class="{ on: form.fee_type === 'fixed' }" @click="form.fee_type = 'fixed'">Montant fixe</button>
          <button type="button" :class="{ on: form.fee_type === 'percent' }" @click="form.fee_type = 'percent'">Pourcentage</button>
        </div>
        <div class="row2">
          <div><label class="field">{{ form.fee_type === 'percent' ? 'Taux (%)' : 'Frais HT (' + cur + ')' }}</label><input v-model.number="form.fee_value" type="number" step="0.01" min="0" /></div>
          <div v-if="form.fee_type === 'percent'" class="row2 inner">
            <div><label class="field">Minimum</label><input v-model.number="form.min_fee" type="number" min="0" /></div>
            <div><label class="field">Plafond</label><input v-model.number="form.max_fee" type="number" min="0" placeholder="Aucun" /></div>
          </div>
        </div>
      </div>

      <details class="fsec adv" :open="advancedUsed">
        <summary>Conditions avancées <span class="stat-label">(facultatif : type de transfert, réception, partenaire, libellé)</span></summary>
        <div class="row2">
          <div><label class="field">Type de transfert</label><select v-model="form.business_type"><option value="*">Tous</option><option v-for="(l, k) in businessTypes" :key="k" :value="k">{{ l }}</option></select></div>
          <div><label class="field">Réception</label><select v-model="form.method"><option value="*">Toutes</option><option v-for="(l, k) in methods" :key="k" :value="k">{{ l }}</option></select></div>
        </div>
        <div class="row2">
          <div><label class="field">Partenaire</label><select v-model="form.partner"><option value="*">Tous</option><option v-for="(l, k) in partners" :key="k" :value="k">{{ l }}</option></select></div>
          <div><label class="field">Libellé</label><input v-model="form.label" maxlength="120" placeholder="ex. Promotion diaspora" /></div>
        </div>
      </details>

      <div class="preview">
        <span>Aperçu</span>
        <table class="pv">
          <thead><tr><th>Montant</th><th>Frais HT</th><th>Taxes</th><th>Frais TTC</th></tr></thead>
          <tbody>
            <tr v-for="a in previewAmounts" :key="a"><td>{{ n(a) }}</td><td>{{ n(feeOf(form, a)) }}</td><td>{{ n(ttcOf(form, a) - feeOf(form, a)) }}</td><td><b>{{ n(ttcOf(form, a)) }}</b></td></tr>
          </tbody>
        </table>
      </div>
      <label class="check"><input v-model="form.active" type="checkbox" /> Palier actif</label>
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
const showSim = ref(false)
const formError = ref('')
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
  const hay = (t) => [t.country === '*' ? 'toutes destinations' : countryName(t.country) + ' ' + t.country, zoneName(zoneOf(t)), t.label || ''].join(' ').toLowerCase()
  return tariffs.value.filter((t) => (!q || hay(t).includes(q))
    && (!flt.zone || String(zoneOf(t)) === flt.zone)
    && (!flt.bt || t.business_type === flt.bt) && (flt.active === '' || String(+t.active) === flt.active))
})
const activeTaxes = computed(() => taxes.value.filter((t) => t.active))
// Palier « simple » : zone entière (ou toutes destinations), sans condition de type / réception / partenaire
const isPlain = (t) => t.country === '*' && t.business_type === '*' && t.method === '*' && t.partner === '*'
// Grille regroupée : une carte par zone, puis « Toutes destinations », puis les règles particulières
const zoneGroups = computed(() => {
  const byMin = (a, b) => a.min_amount - b.min_amount
  const out = []
  for (const z of zones.value) {
    const rows = filtered.value.filter((t) => isPlain(t) && t.zone_id === z.id).sort(byMin)
    if (rows.length || (!flt.q && !flt.zone && flt.active === '')) out.push({ key: 'z' + z.id, title: z.name, zone: z, rows, special: false, hint: rows.length ? '' : 'Aucune tranche : les paliers « Toutes destinations » s\'appliquent.' })
  }
  const all = filtered.value.filter((t) => isPlain(t) && !t.zone_id).sort(byMin)
  if (all.length) out.push({ key: 'all', title: 'Toutes destinations', zone: null, rows: all, special: false, hint: 'Utilisé quand la zone n\'a pas de tranche pour le montant.' })
  const special = filtered.value.filter((t) => !isPlain(t)).sort((a, b) => countryName(a.country).localeCompare(countryName(b.country)) || byMin(a, b))
  if (special.length) out.push({ key: 'special', title: 'Règles particulières', zone: null, rows: special, special: true, hint: 'Par pays, type de transfert, réception ou partenaire : prioritaires sur la zone.' })
  return out.filter((g) => g.rows.length || g.zone)
})
const tabCount = (k) => ({ grid: tariffs.value.length, zones: zones.value.length, fx: currencies.value.length, taxes: taxes.value.length })[k]
// Libellé saisi à la main (les libellés automatiques « CEMAC 0-25000 » ne sont pas répétés)
const customLabel = (t) => !!t.label && !/^.+ \d+-\d+$/.test(t.label) && t.label !== 'Repris de la grille générale'
const formCountries = computed(() => {
  const z = zones.value.find((x) => x.id === form.value?.zone_id)
  return z && !z.is_default && (z.countries || []).length ? countries.value.filter((c) => z.countries.includes(c.iso)) : countries.value
})
const advancedUsed = computed(() => !!form.value && (form.value.business_type !== '*' || form.value.method !== '*' || form.value.partner !== '*' || !!form.value.label))
function addTier(g) {
  const last = g.rows.filter((t) => t.max_amount < 1e9).reduce((m, t) => Math.max(m, t.max_amount), -1)
  edit({ zone_id: g.zone?.id ?? null, min_amount: last + 1, max_amount: last >= 0 ? last + 50000 : 25000, fee_type: 'fixed', fee_value: 0 })
}
const previewAmounts = computed(() => {
  if (!form.value) return []
  const lo = Math.max(form.value.min_amount || 0, 1)
  const hi = Math.min(form.value.max_amount || lo, 1e9)
  const mid = hi >= 1e9 ? Math.max(lo, 100000) : Math.round((lo + hi) / 2)
  const top = hi >= 1e9 ? Math.max(lo, 1000000) : hi
  return [lo, mid, top].filter((a, i, arr) => arr.indexOf(a) === i)
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
  formError.value = ''
  form.value = { zone_id: null, country: '*', business_type: '*', method: '*', partner: '*', fee_type: 'fixed', fee_value: 0, min_amount: 0, max_amount: 1000000000, min_fee: 0, max_fee: null, active: true, label: '', ...t }
}
async function save() {
  saving.value = true
  error.value = ''
  try {
    const body = { ...form.value }
    if (body.fee_type === 'fixed') { body.min_fee = 0; body.max_fee = null }
    const { data } = await api.post('/admin/remit-tariffs', body)
    form.value = null
    msg.value = data.message || 'Palier enregistré.'
    await load()
  } catch (e) {
    const errs = e.response?.data?.errors
    formError.value = e.response?.data?.message || (errs ? Object.values(errs).flat().join(' ') : e.message)
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
.tab-count { font-size: 11px; font-weight: 700; background: var(--surface-2); color: var(--text-2); border-radius: 99px; padding: 1px 7px; margin-left: 4px; }
.tabs button.on .tab-count { background: var(--info-bg); color: var(--link); }
.stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); }
.stats > div { padding: 12px 16px; display: grid; gap: 2px; border-left: 1px solid var(--border); }
.stats > div:first-child { border-left: 0; }
.stats span { font-size: 12px; color: var(--text-2); }
.stats b { font-size: 20px; }
.stats small { font-size: 12px; color: var(--text-2); font-weight: 500; }
@media (max-width: 900px) { .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } .stats > div:nth-child(3) { border-left: 0; } }
.formula { display: grid; gap: 8px; background: var(--surface); border: 1px solid var(--border); border-left: 3px solid var(--link); border-radius: 10px; padding: 10px 14px; font-size: 13px; }
.f-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
.f-row { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
.f-row > span { white-space: nowrap; }
.f-part { background: var(--surface-2); border: 1px solid var(--border); border-radius: 8px; padding: 3px 9px; font-weight: 600; }
.f-part.f-main { background: var(--info-bg); color: var(--link); border-color: transparent; }
.f-part small { font-weight: 500; color: var(--text-2); }
.f-op { color: var(--text-2); font-weight: 700; }
.link-btn { background: none; border: 0; color: var(--link); font: inherit; font-size: 12.5px; cursor: pointer; text-decoration: underline; }
.toolbar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.toolbar input, .toolbar select { padding: 8px 10px; border: 1px solid var(--border-strong); border-radius: 8px; font: inherit; background: #fff; }
.toolbar input { min-width: 240px; flex: 0 1 300px; }
.toolbar .grow { flex: 1; }
.btn-normal.on { border-color: var(--link); color: var(--link); }
.sim-form { display: grid; grid-template-columns: 1.4fr 1.2fr 1fr 1.2fr auto; gap: 12px; align-items: start; }
.sim-form select, .sim-form input { width: 100%; }
.sim-go { padding-top: 22px; }
@media (max-width: 1100px) { .sim-form { grid-template-columns: 1fr 1fr; } .sim-go { padding-top: 0; } }
.sim-res { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; padding-top: 12px; border-top: 1px dashed var(--border-strong); }
.sim-res .cell { background: var(--surface-2); border-radius: 8px; padding: 6px 10px; display: grid; min-width: 90px; }
.sim-res .cell span { font-size: 11px; color: var(--text-2); text-transform: uppercase; font-weight: 700; }
.sim-res .cell.strong { background: var(--info-bg); }
.sim-res .cell.strong b { color: var(--link); }
.sim-warn { width: 100%; color: var(--danger, #c0392b); font-weight: 600; font-size: 13px; }
.sim-src { width: 100%; }
.zone-card .container-head { align-items: center; }
.zh { display: grid; gap: 4px; }
.zh h3 { margin: 0; }
.zh-meta { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
.pill { font-size: 12px; font-weight: 600; padding: 2px 9px; border-radius: 99px; background: var(--info-bg); color: var(--link); }
.pill.soft { background: var(--surface-2); color: var(--text-2); }
table.tier-table { table-layout: fixed; width: 100% !important; min-width: 640px; }
table.tier-table col.c-amt { width: 17%; }
table.tier-table col.c-fee { width: 18%; }
table.tier-table col.c-ttc { width: 22%; }
table.tier-table col.c-on { width: 9%; }
table.tier-table col.c-act { width: 17%; }
table.tier-table.special col.c-dest { width: 24%; }
table.tier-table.special col.c-amt { width: 12%; }
table.tier-table.special col.c-fee { width: 13%; }
table.tier-table.special col.c-ttc { width: 16%; }
table.tier-table td { padding-top: 9px; padding-bottom: 9px; }
table.tier-table td.amt { font-variant-numeric: tabular-nums; white-space: nowrap; }
table.tier-table td.acts { white-space: nowrap; text-align: right; }
.conds { display: flex; gap: 4px; flex-wrap: wrap; margin-top: 3px; }
.foot-note { margin: 4px 2px 0; }
.fsec { border: 1px solid var(--border); border-radius: 12px; padding: 10px 14px 4px; margin-bottom: 12px; }
.fsec-t { font-size: 12px; font-weight: 700; text-transform: uppercase; color: var(--text-2); margin-bottom: 4px; letter-spacing: .02em; }
.fsec.adv summary { cursor: pointer; font-weight: 600; padding: 2px 0 8px; }
.row2.inner { gap: 8px; }
.check.small { font-size: 12.5px; margin-top: 4px; }
.seg { display: inline-flex; border: 1px solid var(--border-strong); border-radius: 10px; overflow: hidden; }
.seg button { border: 0; background: #fff; padding: 6px 14px; font: inherit; cursor: pointer; }
.seg button.on { background: var(--link); color: #fff; font-weight: 600; }
.mb-s { margin-bottom: 8px; }
table.pv { width: 100%; font-size: 13px; }
table.pv th { text-align: right; font-size: 11px; color: var(--text-2); font-weight: 700; text-transform: uppercase; padding: 2px 0; }
table.pv td { text-align: right; padding: 2px 0; }
table.pv th:first-child, table.pv td:first-child { text-align: left; }
.tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--border); flex-wrap: wrap; }
.tabs button { border: 0; background: none; padding: 9px 14px; font: inherit; font-weight: 600; color: var(--text-2); cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -1px; }
.tabs button.on { color: var(--link); border-bottom-color: var(--link); }
.btn-normal.sm { padding: 5px 10px; font-size: 12.5px; }
.chip { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; border: 1px solid var(--border); border-radius: 99px; padding: 1px 8px; margin: 2px 4px 2px 0; background: var(--surface-2); }
.chip a { cursor: pointer; color: var(--danger, #c0392b); font-weight: 700; }
.chips { margin: 6px 0 10px; max-height: 160px; overflow: auto; }
.tag.ko { background: #f1f1f1; color: var(--text-2); }
.t-ko { color: var(--danger, #c0392b); font-weight: 600; }
@media print { .page-header .actions, .tabs, .filters, .toolbar, .sim, .stats, .formula, .foot-note, .switch, td.acts, th:last-child { display: none !important; } }
</style>
