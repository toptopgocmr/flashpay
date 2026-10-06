<template>
  <div v-if="meta && meta.last_page > 1" class="pg">
    <span class="pg-info">{{ from }}–{{ to }} sur {{ nf.format(meta.total) }}</span>
    <div class="pg-btns">
      <button :disabled="meta.current_page <= 1" title="Première page" @click="$emit('go', 1)">«</button>
      <button :disabled="meta.current_page <= 1" title="Page précédente" @click="$emit('go', meta.current_page - 1)">‹</button>
      <span>{{ meta.current_page }} / {{ meta.last_page }}</span>
      <button :disabled="meta.current_page >= meta.last_page" title="Page suivante" @click="$emit('go', meta.current_page + 1)">›</button>
      <button :disabled="meta.current_page >= meta.last_page" title="Dernière page" @click="$emit('go', meta.last_page)">»</button>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
/** Pagination d'une réponse Laravel ({ current_page, last_page, total, from, to }). */
const props = defineProps({ meta: Object })
defineEmits(['go'])
const nf = new Intl.NumberFormat('fr-FR')
const from = computed(() => props.meta?.from ?? 0)
const to = computed(() => props.meta?.to ?? 0)
</script>

<style>
.pg { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 10px 16px; border-top: 1px solid var(--border); font-size: 12.5px; color: var(--text-2); flex-wrap: wrap; }
.pg-btns { display: flex; align-items: center; gap: 6px; }
.pg-btns button { border: 1px solid var(--border); background: #fff; border-radius: 8px; min-width: 30px; height: 30px; cursor: pointer; font-size: 14px; color: var(--text); }
.pg-btns button:hover:not(:disabled) { background: var(--surface-2); }
.pg-btns button:disabled { opacity: .35; cursor: default; }
</style>
