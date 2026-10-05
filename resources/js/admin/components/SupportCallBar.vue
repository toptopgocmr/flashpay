<template>
  <div v-if="call.phase !== 'idle'" class="sc-bar" :class="call.phase">
    <div class="sc-ico">📞</div>
    <div class="sc-txt">
      <strong>{{ call.name }}</strong>
      <span v-if="call.phase === 'incoming'">Appel entrant · Support FlashPay</span>
      <span v-else-if="call.phase === 'calling'">Appel en cours… ça sonne</span>
      <span v-else-if="call.phase === 'connecting'">Connexion…</span>
      <span v-else-if="call.phase === 'connected'">{{ mmss }}</span>
      <span v-else>{{ call.message }}</span>
    </div>
    <template v-if="call.phase === 'incoming'">
      <button class="sc-btn ok" @click="accept">Répondre</button>
      <button class="sc-btn ko" @click="decline">Refuser</button>
    </template>
    <template v-else-if="call.phase !== 'ended'">
      <button class="sc-btn" @click="toggleMute">{{ call.muted ? 'Micro coupé' : 'Micro' }}</button>
      <router-link v-if="call.conversationId" class="sc-btn" :to="`/support-chat?c=${call.conversationId}`">Discussion</router-link>
      <button class="sc-btn ko" @click="hangup()">Raccrocher</button>
    </template>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted } from 'vue'
import { call, accept, decline, hangup, toggleMute, startCallWatcher, stopCallWatcher } from '../services/supportCall'

const mmss = computed(() => `${String(Math.floor(call.seconds / 60)).padStart(2, '0')}:${String(call.seconds % 60).padStart(2, '0')}`)
onMounted(startCallWatcher)
onBeforeUnmount(stopCallWatcher)
</script>

<style scoped>
.sc-bar { position: fixed; right: 20px; bottom: 20px; z-index: 1000; display: flex; align-items: center; gap: 10px; background: #172e6e; color: #fff; padding: 12px 14px; border-radius: 14px; box-shadow: 0 10px 30px rgba(0,0,0,.3); min-width: 340px; }
.sc-bar.incoming { animation: sc-pulse 1.2s infinite; background: #166534; }
.sc-bar.ended { background: #374151; }
.sc-ico { font-size: 22px; }
.sc-txt { display: flex; flex-direction: column; flex: 1; }
.sc-txt span { font-size: 12.5px; opacity: .85; }
.sc-btn { border: 0; border-radius: 999px; padding: 7px 12px; font-weight: 700; cursor: pointer; background: rgba(255,255,255,.18); color: #fff; text-decoration: none; font-size: 13px; }
.sc-btn.ok { background: #22c55e; }
.sc-btn.ko { background: #dc2626; }
@keyframes sc-pulse { 0%,100% { box-shadow: 0 0 0 0 rgba(34,197,94,.6); } 50% { box-shadow: 0 0 0 10px rgba(34,197,94,0); } }
</style>
