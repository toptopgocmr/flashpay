<template>
  <button class="btn-normal" :disabled="busy" :title="'Exporter la liste filtrée vers Excel'" @click="run">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="vertical-align:-3px;margin-right:6px"><path d="M12 3v12M7 10l5 5 5-5"/><path d="M4 17v3h16v-3"/></svg>
    {{ busy ? `Export… ${progress}` : label }}
  </button>
</template>

<script setup>
import { toast } from '../utils/ui'
// Export de liste : récupère TOUTES les lignes correspondant aux filtres (toutes les pages), puis télécharge un fichier Excel (.xlsx), avec repli CSV.
import { ref } from 'vue'
import { downloadCsv, downloadXlsx } from '../utils/export'

const props = defineProps({
  label: { type: String, default: 'Exporter' },
  filename: { type: String, required: true },
  columns: { type: Array, required: true },
  fetch: { type: Function, required: true }, // async (onProgress) => rows
})
const busy = ref(false)
const progress = ref('')

async function run() {
  busy.value = true
  progress.value = ''
  try {
    const rows = await props.fetch((p, last) => { progress.value = last > 1 ? `${p}/${last}` : '' })
    if (!rows.length) {
      toast('Aucune ligne à exporter pour ces filtres.', 'info')
      return
    }
    try {
      await downloadXlsx(props.filename, props.columns, rows)
    } catch (err) {
      console.warn('xlsx indisponible, repli CSV', err)
      downloadCsv(props.filename, props.columns, rows)
    }
  } catch (e) {
    toast('Export impossible : ' + (e.response?.data?.message || e.message), 'err')
  } finally {
    busy.value = false
  }
}
</script>
