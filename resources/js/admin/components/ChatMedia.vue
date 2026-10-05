<template>
  <span>
    <img v-if="type === 'image' && url" :src="url" class="cm-img" @click="open" />
    <audio v-else-if="type === 'audio' && url" :src="url" controls preload="metadata" class="cm-audio"></audio>
    <button v-else-if="type === 'video'" class="btn-normal" @click="openVideo">▶ Lire la vidéo</button>
    <small v-else-if="loading" class="muted">Chargement…</small>
    <small v-else-if="failed" class="muted">Fichier indisponible</small>
  </span>
</template>

<script setup>
// Photo / note vocale / vidéo d'un message : le fichier exige le jeton, on le
// télécharge (blob) puis on l'affiche ; vidéo : lien signé ouvert dans un onglet.
import { onBeforeUnmount, onMounted, ref } from 'vue'
import api from '../services/api'

const props = defineProps({ type: String, fileUrl: String, linkUrl: String })
const url = ref('')
const loading = ref(false)
const failed = ref(false)

onMounted(async () => {
  if (props.type !== 'image' && props.type !== 'audio') return
  loading.value = true
  try {
    const res = await api.get(props.fileUrl, { responseType: 'blob' })
    url.value = URL.createObjectURL(res.data)
  } catch (_) {
    failed.value = true
  } finally {
    loading.value = false
  }
})
onBeforeUnmount(() => url.value && URL.revokeObjectURL(url.value))
function open() { window.open(url.value, '_blank') }
async function openVideo() {
  try {
    if (props.linkUrl) {
      const { data } = await api.get(props.linkUrl)
      window.open(data.url, '_blank')
    } else {
      const res = await api.get(props.fileUrl, { responseType: 'blob' })
      window.open(URL.createObjectURL(res.data), '_blank')
    }
  } catch (_) {
    failed.value = true
  }
}
</script>

<style scoped>
.cm-img { max-width: 240px; max-height: 240px; border-radius: 10px; cursor: zoom-in; display: block; }
.cm-audio { width: 250px; height: 36px; display: block; }
</style>
