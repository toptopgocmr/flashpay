<template>
  <section class="container mb">
    <div class="container-head"><h3>Photo et pièces d'identité</h3><button class="btn-normal" @click="load">Actualiser</button></div>
    <div class="container-body">
      <p v-if="loading" class="muted">Chargement…</p>
      <p v-else-if="!docs.length" class="muted">Aucune pièce envoyée.</p>
      <div v-else class="kyc-grid">
        <figure v-for="d in docs" :key="d.id" class="kyc-item" @click="d.url && (zoom = d)">
          <div class="thumb" :class="{ round: d.type === 'profile_photo' }">
            <img v-if="d.url && !d.pdf" :src="d.url" :alt="d.type_label" />
            <span v-else-if="d.pdf" class="muted">PDF</span>
            <span v-else class="muted small">{{ d.error || '…' }}</span>
          </div>
          <figcaption>
            <strong>{{ d.type_label }}</strong>
            <span class="status" :class="ST[d.status]?.cls">{{ ST[d.status]?.label || d.status }}</span>
          </figcaption>
        </figure>
      </div>
    </div>
    <Modal v-if="zoom" :title="zoom.type_label" @close="zoom = null">
      <img v-if="!zoom.pdf" :src="zoom.url" style="max-width:100%;border-radius:8px" />
      <iframe v-else :src="zoom.url" style="width:100%;height:60vh;border:0"></iframe>
    </Modal>
  </section>
</template>

<script setup>
import { onBeforeUnmount, ref, watch } from 'vue'
import api from '../services/api'
import Modal from './Modal.vue'

const props = defineProps({ userId: { type: [Number, String], required: true } })
const docs = ref([])
const loading = ref(false)
const zoom = ref(null)
const ST = { pending: { label: 'À vérifier', cls: 'pending' }, approved: { label: 'Validée', cls: 'ok' }, rejected: { label: 'Rejetée', cls: 'err' } }

function revoke() { docs.value.forEach((d) => d.url && URL.revokeObjectURL(d.url)) }

async function load() {
  loading.value = true
  revoke()
  try {
    const list = (await api.get(`/support/desk/kyc/users/${props.userId}`)).data || []
    // Une seule pièce par type (la plus récente), les remplacées sont masquées.
    const seen = new Set()
    docs.value = list.filter((d) => d.rejection_reason !== 'Remplacé par un nouvel envoi' && !seen.has(d.type) && seen.add(d.type)).map((d) => ({ ...d, url: '', pdf: false, error: '' }))
    loading.value = false
    await Promise.all(docs.value.map(async (d) => {
      if (d.has_file === false) { d.error = 'Fichier perdu — à renvoyer'; return }
      try {
        const res = await api.get(`/support/desk/kyc/documents/${d.id}/file`, { responseType: 'blob' })
        d.pdf = res.data.type === 'application/pdf'
        d.url = URL.createObjectURL(res.data)
      } catch { d.error = 'Fichier introuvable' }
    }))
  } catch { docs.value = [] } finally { loading.value = false }
}

watch(() => props.userId, (id) => { if (id) load() }, { immediate: true })
onBeforeUnmount(revoke)
</script>

<style scoped>
.kyc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 14px; }
.kyc-item { margin: 0; cursor: zoom-in; }
.thumb { height: 130px; border: 1px solid var(--border); border-radius: 10px; overflow: hidden; display: flex; align-items: center; justify-content: center; background: var(--surface-2); }
.thumb.round { width: 130px; border-radius: 50%; margin: 0 auto; }
.thumb img { width: 100%; height: 100%; object-fit: cover; }
figcaption { display: flex; flex-direction: column; gap: 4px; margin-top: 8px; font-size: 13px; align-items: flex-start; }
.small { font-size: 12px; text-align: center; padding: 6px; }
</style>
