<template>
  <span class="dchips">
    <span v-for="[k, v] in entries" :key="k" class="dchip" :title="k + ' : ' + full(v)"><i>{{ label(k) }}</i>{{ short(v) }}</span>
    <span v-if="!entries.length" class="dchip muted">—</span>
  </span>
</template>

<script setup>
import { computed } from 'vue'
/** Données d'un objet (journal d'audit, alerte fraude…) en étiquettes lisibles au lieu de JSON brut. */
const props = defineProps({ data: [Object, Array, String, null], max: { type: Number, default: 8 }, labels: { type: Object, default: () => ({}) } })
const LBL = { amount: 'montant', currency: 'devise', status: 'statut', reason: 'motif', note: 'note', phone: 'tél.', count: 'nb', window: 'fenêtre', threshold: 'seuil', total: 'total', user_id: 'utilisateur', transaction_id: 'transaction', reference: 'réf.' }
const entries = computed(() => {
  let d = props.data
  if (typeof d === 'string') { try { d = JSON.parse(d) } catch { return [['', d]] } }
  if (!d || typeof d !== 'object') return []
  return Object.entries(d).filter(([, v]) => v !== null && v !== '' && !(Array.isArray(v) && !v.length)).slice(0, props.max)
})
const label = (k) => (k === '' ? '' : (props.labels[k] || LBL[k] || k.replace(/_/g, ' ')) + ' ')
const full = (v) => (typeof v === 'object' ? JSON.stringify(v) : String(v))
const short = (v) => {
  if (typeof v === 'boolean') return v ? 'oui' : 'non'
  if (typeof v === 'number') return new Intl.NumberFormat('fr-FR').format(v)
  const s = full(v)
  return s.length > 60 ? s.slice(0, 57) + '…' : s
}
</script>

<style>
.dchips { display: inline-flex; flex-wrap: wrap; gap: 4px; }
.dchip { display: inline-flex; gap: 4px; align-items: baseline; background: var(--surface-2); border: 1px solid var(--border); border-radius: 6px; padding: 1px 7px; font-size: 12px; color: var(--text); max-width: 100%; overflow-wrap: anywhere; }
.dchip i { font-style: normal; color: var(--text-2); }
.dchip.muted { color: var(--text-3); }
</style>
