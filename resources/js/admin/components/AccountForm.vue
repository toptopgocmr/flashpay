<template>
  <Modal :title="done ? 'Compte prêt' : title" :subtitle="done ? '' : subtitle" @close="$emit('close')">
    <template v-if="!done">
      <div v-if="error" class="flash err" style="margin:0;"><div>{{ error }}</div></div>
      <div class="row2">
        <div>
          <label class="field">Pays</label>
          <div class="with-flag">
            <Flag :iso="f.country" :size="14" />
            <select v-model="f.country" @change="f.city = ''">
              <option v-for="c in countries" :key="c.country" :value="c.country">{{ c.name }} ({{ c.currency }})</option>
            </select>
          </div>
        </div>
        <div>
          <label class="field">Ville</label>
          <input v-model.trim="f.city" list="fp-cities" required placeholder="Choisir ou saisir une ville" />
          <datalist id="fp-cities"><option v-for="v in cities" :key="v" :value="v" /></datalist>
        </div>
      </div>
      <div class="row2">
        <div><label class="field">Nom complet</label><input v-model.trim="f.full_name" required placeholder="Ex. Jean Mabiala" /></div>
        <div><label class="field">Téléphone <small style="color:var(--text-2);font-weight:400;">{{ dial }}</small></label><input v-model.trim="f.phone" required placeholder="Numéro local ou international" /></div>
      </div>
      <div v-if="kind === 'merchant'" class="row2">
        <div><label class="field">Nom du commerce</label><input v-model.trim="f.business_name" required placeholder="Ex. Boutique Jean" /></div>
        <div>
          <label class="field">Catégorie</label>
          <select v-model="f.business_category">
            <option value="">—</option>
            <option v-for="c in CATEGORIES" :key="c">{{ c }}</option>
          </select>
        </div>
      </div>
      <div v-if="kind === 'merchant'"><label class="field">Adresse / quartier</label><input v-model.trim="f.address" placeholder="Ex. Avenue de la Paix, Poto-Poto" /></div>
      <div v-else><label class="field">Quartier / point de service</label><input v-model.trim="f.zone" placeholder="Ex. Poto-Poto, marché Total" /></div>
      <div class="row2">
        <div>
          <label class="field">Code secret (app mobile)</label>
          <div style="display:flex; gap:6px;">
            <input v-model="f.password" type="text" minlength="4" placeholder="Code secret (4 chiffres min.)" />
            <button type="button" class="btn-normal" title="Générer" @click="gen">↻</button>
          </div>
          <div class="hint">Code à 4 chiffres pour l'app mobile. Si le numéro a déjà un compte FlashPay, ce code remplace son code secret actuel (laissez vide pour le garder).</div>
        </div>
      </div>
      <template v-if="kind === 'merchant'">
        <div class="section-title">Modes de retrait (multicanal)</div>
        <div class="hint" style="margin-top:-8px;">Cochez un ou plusieurs canaux par lesquels le marchand pourra retirer ses fonds, puis choisissez celui par défaut.</div>
        <div class="seg-types">
          <button v-for="t in SETTLE_TYPES" :key="t.key" type="button" class="multi" :class="{ on: chosen[t.key] }" @click="toggleType(t.key)">
            <span class="tick">{{ chosen[t.key] ? '✓' : '' }}</span>
            <strong>{{ t.label }}</strong><small>{{ t.hint }}</small>
          </button>
        </div>
        <div v-for="t in SETTLE_TYPES.filter((x) => chosen[x.key])" :key="'f-' + t.key" class="settle-block">
          <div class="settle-head">
            <strong>{{ t.label }}</strong>
            <label class="def"><input type="radio" name="settle-default" :value="t.key" v-model="defaultType" /> Par défaut</label>
          </div>
          <div v-if="t.key === 'mobile_money' || t.key === 'wallet'">
            <label class="field">{{ t.key === 'wallet' ? 'Numéro du wallet FlashPay' : 'Numéro mobile money (tout opérateur)' }}</label>
            <input v-model.trim="acc[t.key].phone" placeholder="Par défaut : le téléphone du marchand" />
          </div>
          <template v-if="t.key === 'bank'">
            <div class="row2">
              <div><label class="field">Banque</label><input v-model.trim="acc.bank.bank_name" list="fp-banks" placeholder="Ex. BGFI Bank Congo" /><datalist id="fp-banks"><option v-for="b in BANKS" :key="b" :value="b" /></datalist></div>
              <div><label class="field">Titulaire du compte</label><input v-model.trim="acc.bank.account_holder" :placeholder="f.business_name || 'Nom du titulaire'" /></div>
            </div>
            <div class="row2">
              <div><label class="field">RIB / IBAN</label><input v-model.trim="acc.bank.account_number" placeholder="CG39 3001 1000 …" /></div>
              <div><label class="field">SWIFT / BIC (facultatif)</label><input v-model.trim="acc.bank.swift" /></div>
            </div>
          </template>
          <div v-if="t.key === 'cash_pickup'" class="hint" style="margin:0;">Le marchand reçoit un code de retrait à présenter chez n'importe quel agent FlashPay.</div>
        </div>
        <div class="hint" style="margin-top:-6px;">Le marchand pourra ajouter d'autres comptes et régler vers chacun depuis son application (menu Règlements).</div>
      </template>
    </template>

    <template v-else>
      <div class="flash info" style="margin:0;"><div>{{ done.message }} Transmettez ces identifiants à la personne : elle se connecte dans l'application FlashPay en choisissant le profil <strong>{{ done.login.profile }}</strong>.</div></div>
      <div class="creds">
        <div><span>Profil</span><strong>{{ done.login.profile }}</strong></div>
        <div v-if="kind === 'merchant'"><span>Modes de retrait</span><strong>{{ SETTLE_TYPES.filter((t) => chosen[t.key]).map((t) => t.label + (t.key === defaultType ? ' (défaut)' : '')).join(', ') }}</strong></div>
        <div><span>Localisation</span><strong><Flag :iso="f.country" /> {{ f.city }}</strong></div>
        <div><span>Téléphone</span><strong class="mono">{{ $phone(done.login.phone) }}</strong></div>
        <div v-if="sentPassword"><span>Code secret</span><strong class="mono">{{ sentPassword }}</strong></div>
        <div v-else><span>Code secret</span><strong>inchangé (compte existant)</strong></div>
      </div>
    </template>

    <template #foot>
      <template v-if="!done">
        <button class="btn-normal" @click="$emit('close')">Annuler</button>
        <button class="btn" :disabled="saving" @click="save">{{ saving ? 'Création…' : title }}</button>
      </template>
      <template v-else>
        <button class="btn-normal" @click="copy">{{ copied ? 'Copié ✓' : 'Copier les identifiants' }}</button>
        <button class="btn" @click="$emit('close')">Terminer</button>
      </template>
    </template>
  </Modal>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import Modal from './Modal.vue'
import Flag from './Flag.vue'
import api from '../services/api'

const props = defineProps({ kind: { type: String, required: true }, country: String, city: String }) // merchant | agent
const emit = defineEmits(['close', 'created'])
const CATEGORIES = ['Alimentation', 'Restaurant / bar', 'Boutique / mode', 'Pharmacie', 'Transport', 'Services', 'Télécom', 'Station-service', 'Autre']
const title = props.kind === 'merchant' ? 'Créer un marchand' : 'Créer un agent'
const subtitle = props.kind === 'merchant'
  ? "Le marchand est validé d'office et peut encaisser dès sa première connexion."
  : "L'agent est actif d'office : dépôts d'espèces (QR client) et retraits cash (code)."

const props2 = props
const f = reactive({ full_name: '', phone: '', business_name: '', business_category: '', zone: '', address: '', country: props2.country || 'CG', city: props2.city || '', password: '', settlement_phone: '' })
const countries = ref([])
const SETTLE_TYPES = [
  { key: 'mobile_money', label: 'Mobile money', hint: 'MTN, Airtel, Orange…' },
  { key: 'bank', label: 'Compte bancaire', hint: 'Virement' },
  { key: 'wallet', label: 'Wallet FlashPay', hint: 'Instantané' },
  { key: 'cash_pickup', label: 'Cash agent', hint: 'Code de retrait' },
]
const BANKS = ['BGFI Bank Congo', 'LCB Bank', 'Ecobank Congo', 'Société Générale Congo', 'UBA Congo', 'BSCA Bank', 'Crédit du Congo', 'BCH', 'Banque Postale du Congo', 'Afriland First Bank', 'Mucodec']
// Modes de retrait cochés (multicanal) et leurs champs
const chosen = reactive({ mobile_money: true, bank: false, wallet: false, cash_pickup: false })
const acc = reactive({ mobile_money: { phone: '' }, wallet: { phone: '' }, bank: { bank_name: '', account_holder: '', account_number: '', swift: '' }, cash_pickup: {} })
const defaultType = ref('mobile_money')
function toggleType(k) {
  const on = Object.values(chosen).filter(Boolean).length
  if (chosen[k] && on === 1) return // au moins un mode
  chosen[k] = !chosen[k]
  if (!chosen[defaultType.value]) defaultType.value = Object.keys(chosen).find((x) => chosen[x])
}
const saving = ref(false)
const error = ref('')
const done = ref(null)
const sentPassword = ref('')
const copied = ref(false)
const current = computed(() => countries.value.find((c) => c.country === f.country))
const cities = computed(() => current.value?.cities || [])
const dial = computed(() => current.value?.dial || '')

// Code secret de connexion à l'app mobile : 4 chiffres, sans suite triviale
function gen() {
  let c
  do {
    c = String(Math.floor(1000 + Math.random() * 9000))
  } while (/^(\d)\1+$/.test(c) || ['1234', '4321'].includes(c))
  f.password = c
}

async function save() {
  error.value = ''
  saving.value = true
  try {
    const payload = Object.fromEntries(Object.entries(f).filter(([, v]) => v !== ''))
    if (props.kind === 'merchant') {
      payload.settlements = Object.keys(chosen).filter((k) => chosen[k]).map((type) => ({
        type,
        ...Object.fromEntries(Object.entries(acc[type]).filter(([, v]) => v !== '')),
        is_default: type === defaultType.value,
      }))
    }
    const { data } = await api.post(props.kind === 'merchant' ? '/admin/merchants' : '/admin/agents', payload)
    sentPassword.value = f.password
    done.value = data
    emit('created')
  } catch (e) {
    const errs = e.response?.data?.errors
    error.value = errs ? Object.values(errs).flat().join(' ') : (e.response?.data?.message || e.message)
  } finally {
    saving.value = false
  }
}

async function copy() {
  const l = done.value.login
  const text = `FlashPay — profil ${l.profile}\nLocalisation : ${f.city} (${f.country})\nTéléphone : +${l.phone}\n` + (sentPassword.value ? `Code secret : ${sentPassword.value}\n` : '') + "Application : ouvrez FlashPay et choisissez « " + l.profile + ' ».'
  try { await navigator.clipboard.writeText(text); copied.value = true } catch (_) {}
}

onMounted(async () => {
  gen()
  try {
    const { data } = await api.get('/admin/geo')
    countries.value = data.countries
  } catch (_) {
    countries.value = [{ country: 'CG', name: 'Congo-Brazzaville', currency: 'XAF', dial: '+242', cities: ['Brazzaville', 'Pointe-Noire'] }]
  }
})
</script>

<style scoped>
.multi { position: relative; padding-right: 30px !important; }
.tick { position: absolute; top: 6px; right: 8px; width: 18px; height: 18px; border-radius: 5px; border: 1.5px solid var(--border-strong); display: grid; place-items: center; font-size: 12px; font-weight: 700; color: #fff; }
.multi.on .tick { background: var(--brand); border-color: var(--brand); }
.settle-block { border: 1px solid var(--border); border-radius: 10px; padding: 12px 14px; display: grid; gap: 10px; background: var(--surface-2); }
.settle-head { display: flex; justify-content: space-between; align-items: center; }
.def { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: var(--text-2); cursor: pointer; }
.def input { width: auto !important; }
</style>
