<template>
  <span class="pv-logo" :class="{ txt: !src || failed, ico: !!icon }" :style="boxStyle" :title="title || name">
    <svg v-if="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" v-html="icon.path"></svg>
    <img v-else-if="src && !failed" :src="src" :alt="name" @error="failed = true" />
    <template v-else>{{ initials }}</template>
  </span>
</template>

<script setup>
import { computed, ref, watch } from 'vue'

/**
 * Logo d'un opérateur (MTN, Airtel, Orange…) ou d'une passerelle (PawaPay, WacePay, PEEX…).
 * Fichiers : public/images/logos/<clé>.png (ou .svg pour OfficePay). Logo absent ou
 * illisible → pastille aux initiales, à la couleur de la marque.
 * Pour ajouter un logo partenaire : déposez le fichier officiel (PNG carré, fond clair)
 * sous public/images/logos/pawapay.png, wacepay.png, peex.png, notchpay.png, terrapay.png.
 */
const props = defineProps({ name: String, size: { type: Number, default: 20 }, title: String })
const failed = ref(false)
watch(() => props.name, () => { failed.value = false })

const BRANDS = [
  // [clé, motif, couleur de secours, initiales, extension]
  ['mtn', /\bmtn\b|momo/i, '#ffcb05', 'M', 'png'],
  ['airtel', /airtel/i, '#e40000', 'A', 'png'],
  ['orange', /orange/i, '#ff7900', 'O', 'png'],
  ['moov', /moov|flooz/i, '#0066b3', 'M', 'png'],
  ['africell', /africell|afrimoney/i, '#7b2a8c', 'A', 'png'],
  ['visa', /visa/i, '#1a1f71', 'V', 'png'],
  ['mastercard', /mastercard/i, '#eb001b', 'MC', 'png'],
  ['pawapay', /pawa\s*pay/i, '#0b8a5f', 'PP', 'png'],
  ['wacepay', /wace\s*pay|digitwace/i, '#f39200', 'WP', 'png'],
  ['peex', /peex/i, '#1e3a8a', 'PX', 'png'],
  ['notchpay', /notch/i, '#111827', 'NP', 'png'],
  ['terrapay', /terra\s*pay/i, '#00a19a', 'TP', 'png'],
  ['maishapay', /maisha\s*pay/i, '#16a34a', 'MP', 'png'],
  ['fincra', /fincra/i, '#6d28d9', 'FC', 'png'],
  // Trésorerie / remboursement OfficePay : logo OfficePay (plus de pictogramme générique)
  ['flashpay', /flash\s*pay|office\s*pay|wallet|interne|tr[eé]sorerie|treasury|rembours/i, '#ffffff', 'FP', 'svg'],
]

// Moyens sans marque (espèces, agent, marchand, banque, carte non identifiée) : pictogramme
const ICONS = [
  { re: /esp[eè]ces|cash|retrait au gab|\bgab\b|atm/i, bg: '#dcfce7', fg: '#15803d',
    path: '<rect x="2.5" y="6" width="19" height="12" rx="2"/><circle cx="12" cy="12" r="2.6"/><path d="M6 9.5v5M18 9.5v5"/>' },
  { re: /\bagent\b/i, bg: '#e0e7ff', fg: '#3730a3',
    path: '<circle cx="12" cy="8" r="3.5"/><path d="M5 20c.8-3.8 3.6-6 7-6s6.2 2.2 7 6"/><path d="M16.5 4.5l1.5 1.5 3-3"/>' },
  { re: /marchand|boutique|merchant/i, bg: '#fef3c7', fg: '#b45309',
    path: '<path d="M4 9l1.5-5h13L20 9"/><path d="M4 9h16v2a2.7 2.7 0 0 1-5.3 0 2.7 2.7 0 0 1-5.4 0A2.7 2.7 0 0 1 4 11z"/><path d="M5.5 13v7h13v-7M10 20v-4h4v4"/>' },
  { re: /banque|bancaire|virement|\bbank\b|\biban\b/i, bg: '#e0f2fe', fg: '#0369a1',
    path: '<path d="M3 9.5L12 4l9 5.5"/><path d="M5 10v7M9.5 10v7M14.5 10v7M19 10v7"/><path d="M3 20h18"/>' },
  { re: /carte|\bcard\b/i, bg: '#ede9fe', fg: '#5b21b6',
    path: '<rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="M2.5 10h19M6 15h4"/>' },
]

const brand = computed(() => BRANDS.find(([, re]) => re.test(props.name || '')))
// Une marque connue (Visa, Mastercard, opérateur, passerelle) passe avant le pictogramme
const icon = computed(() => (brand.value && !/^(flashpay)$/.test(brand.value[0]) ? null : ICONS.find((i) => i.re.test(props.name || ''))) || null)
const src = computed(() => (brand.value ? `/images/logos/${brand.value[0]}.${brand.value[4]}` : null))
const initials = computed(() => brand.value?.[3]
  || (props.name || '?').replace(/\(.*\)/, '').split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0].toUpperCase()).join('') || '?')
const boxStyle = computed(() => {
  const s = props.size
  if (icon.value) return { width: s + 'px', height: s + 'px', background: icon.value.bg, color: icon.value.fg }
  const bg = (!src.value || failed.value) ? (brand.value?.[2] || '#64748b') : '#fff'
  const dark = ['#ffcb05', '#ffffff'].includes(bg)
  return { width: s + 'px', height: s + 'px', fontSize: Math.max(8, Math.round(s * 0.42)) + 'px', background: bg, color: dark ? '#111' : '#fff' }
})
</script>

<style>
.pv-logo { display: inline-flex; align-items: center; justify-content: center; flex: none; vertical-align: middle; border-radius: 6px; overflow: hidden;
  box-shadow: 0 0 0 1px rgba(15, 23, 42, .1); font-weight: 800; letter-spacing: -.02em; line-height: 1; }
.pv-logo img { width: 100%; height: 100%; object-fit: contain; }
.pv-logo.ico svg { width: 72%; height: 72%; }
.pv-row { display: inline-flex; align-items: center; gap: 6px; flex-wrap: wrap; justify-content: flex-end; }
.pv-row .sep { color: var(--text-2, #94a3b8); font-weight: 400; }
</style>
