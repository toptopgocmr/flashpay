<template>
  <div class="gw-field" :class="{ plain: !value }">
    <code :title="value || ''">{{ value || placeholder || '—' }}</code>
    <button v-if="value" type="button" class="gw-copy" :title="done ? 'Copié' : 'Copier'" @click="copy">
      <svg v-if="!done" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/></svg>
      <svg v-else viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2"><path d="m5 12 5 5 9-10"/></svg>
    </button>
  </div>
</template>

<script setup>
import { ref } from 'vue'
const props = defineProps({ value: [String, Number], placeholder: String })
const done = ref(false)
async function copy() {
  try { await navigator.clipboard.writeText(String(props.value)) } catch (_) {
    const t = document.createElement('textarea'); t.value = String(props.value); document.body.appendChild(t); t.select(); document.execCommand('copy'); t.remove()
  }
  done.value = true
  setTimeout(() => (done.value = false), 1500)
}
</script>
