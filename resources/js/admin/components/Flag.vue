<template>
  <img v-if="!failed && iso" :src="`/images/flags/${iso.toLowerCase()}.svg`" :alt="iso" :title="title || iso"
       class="flag-img" :style="{ height: size + 'px', width: Math.round(size * 1.4) + 'px' }" @error="failed = true" />
  <span v-else class="flag-txt">{{ iso }}</span>
</template>

<script setup>
import { ref } from 'vue'
// Drapeau en image locale (public/images/flags, flag-icons MIT) : Windows n'affiche pas les emojis drapeaux.
defineProps({ iso: String, size: { type: Number, default: 14 }, title: String })
const failed = ref(false)
</script>

<style>
.flag-img { display: inline-block; vertical-align: -2px; object-fit: cover; border-radius: 2px; box-shadow: 0 0 0 1px rgba(0, 0, 0, .12); }
.flag-txt { font-size: 11px; font-weight: 700; color: var(--text-2); }
</style>
