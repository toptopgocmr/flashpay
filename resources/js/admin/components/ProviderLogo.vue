<template>
  <span class="pv-logo" :class="{ txt: !src || failed }" :style="boxStyle" :title="title || name">
    <img v-if="src && !failed" :src="src" :alt="name" @error="failed = true" />
    <template v-else>{{ initials }}</template>
  </span>
</template>

<script setup>
import { computed, ref, watch } from 'vue'

/**
 * Logo d'un opérateur (MTN, Airtel, Orange…) ou d'une passerelle (PawaPay, WacePay, PEEX…).
 * Fichiers : public/images/logos/<clé>.png (ou .svg pour FlashPay). Logo absent ou
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
  ['flashpay', /flash\s*pay|wallet|interne/i, '#ffffff', 'FP', 'svg'],
]

const brand = computed(() => BRANDS.find(([, re]) => re.test(props.name || '')))
const src = computed(() => (brand.value ? `/images/logos/${brand.value[0]}.${brand.value[4]}` : null))
const initials = computed(() => brand.value?.[3]
  || (props.name || '?').replace(/\(.*\)/, '').split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0].toUpperCase()).join('') || '?')
const boxStyle = computed(() => {
  const s = props.size
  const bg = (!src.value || failed.value) ? (brand.value?.[2] || '#64748b') : '#fff'
  const dark = ['#ffcb05', '#ffffff'].includes(bg)
  return { width: s + 'px', height: s + 'px', fontSize: Math.max(8, Math.round(s * 0.42)) + 'px', background: bg, color: dark ? '#111' : '#fff' }
})
</script>

<style>
.pv-logo { display: inline-flex; align-items: center; justify-content: center; flex: none; vertical-align: middle; border-radius: 6px; overflow: hidden;
  box-shadow: 0 0 0 1px rgba(15, 23, 42, .1); font-weight: 800; letter-spacing: -.02em; line-height: 1; }
.pv-logo img { width: 100%; height: 100%; object-fit: contain; }
.pv-row { display: inline-flex; align-items: center; gap: 6px; flex-wrap: wrap; justify-content: flex-end; }
.pv-row .sep { color: var(--text-2, #94a3b8); font-weight: 400; }
</style>
