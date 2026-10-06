<template>
  <!-- Barre de chargement des appels API -->
  <div class="ui-loadbar" :class="{ on: ui.loading > 0 }"><span></span></div>

  <!-- Notifications -->
  <Teleport to="body">
    <div class="ui-toasts" aria-live="polite">
      <div v-for="t in ui.toasts" :key="t.id" class="ui-toast" :class="t.type" role="status">
        <svg v-if="t.type === 'ok'" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M8 12.5l2.7 2.7L16 10"/></svg>
        <svg v-else-if="t.type === 'err'" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v6M12 16.5v.5"/></svg>
        <svg v-else viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7.5v.5"/></svg>
        <span>{{ t.message }}</span>
        <button title="Fermer" @click="dismiss(t.id)">×</button>
      </div>
    </div>

    <!-- Boîte de dialogue -->
    <div v-if="d" class="ui-dlg-bg" @mousedown.self="cancel" @keydown.esc="cancel">
      <form class="ui-dlg" role="dialog" :aria-label="d.title" @submit.prevent="ok">
        <div class="ui-dlg-h">
          <span class="ic" :class="{ danger: d.danger }">
            <svg v-if="d.danger" viewBox="0 0 24 24"><path d="M12 3l9 16H3z"/><path d="M12 10v4M12 17v.5"/></svg>
            <svg v-else viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7.5v.5"/></svg>
          </span>
          <div>
            <b>{{ d.title }}</b>
            <p v-if="d.message">{{ d.message }}</p>
          </div>
        </div>
        <label v-if="d.input" class="ui-dlg-in">
          <span>{{ d.input.label }}<i v-if="d.input.required"> *</i></span>
          <textarea v-if="d.input.multiline" ref="field" v-model="d.value" rows="3" :placeholder="d.input.placeholder" />
          <input v-else ref="field" v-model="d.value" :placeholder="d.input.placeholder" />
        </label>
        <div class="ui-dlg-f">
          <button type="button" class="btn-normal" @click="cancel">{{ d.cancelLabel }}</button>
          <button ref="okBtn" type="submit" class="btn" :class="{ danger: d.danger }" :disabled="d.input?.required && !String(d.value).trim()">{{ d.confirmLabel }}</button>
        </div>
      </form>
    </div>
  </Teleport>
</template>

<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import { dismiss, ui } from '../utils/ui'

const d = computed(() => ui.dialog)
const field = ref(null)
const okBtn = ref(null)
watch(d, async (v) => {
  if (!v) return
  await nextTick()
  ;(field.value || okBtn.value)?.focus()
})
function ok() { d.value?.resolve(d.value.input ? String(d.value.value).trim() : true) }
function cancel() { d.value?.resolve(d.value.input ? null : false) }
</script>

<style>
.ui-loadbar { position: fixed; top: 0; left: 0; right: 0; height: 3px; z-index: 300; pointer-events: none; opacity: 0; transition: opacity .2s; overflow: hidden; }
.ui-loadbar.on { opacity: 1; }
.ui-loadbar span { position: absolute; top: 0; bottom: 0; width: 35%; background: var(--accent, #e11d2a); animation: uiLoad 1.1s ease-in-out infinite; }
@keyframes uiLoad { from { left: -35%; } to { left: 100%; } }

.ui-toasts { position: fixed; right: 18px; bottom: 18px; z-index: 260; display: flex; flex-direction: column; gap: 8px; max-width: min(420px, calc(100vw - 36px)); }
.ui-toast { display: flex; align-items: flex-start; gap: 10px; background: #111827; color: #fff; border-radius: 12px; padding: 11px 12px 11px 14px; box-shadow: 0 12px 30px rgba(15, 23, 42, .28); font-size: 13.5px; line-height: 1.4; animation: uiIn .2s ease-out; }
.ui-toast svg { flex: none; width: 18px; height: 18px; margin-top: 1px; fill: none; stroke-width: 2; stroke-linecap: round; stroke: #86efac; }
.ui-toast.err svg { stroke: #fca5a5; } .ui-toast.warn svg, .ui-toast.info svg { stroke: #93c5fd; }
.ui-toast.err { background: #7f1d1d; }
.ui-toast span { flex: 1; overflow-wrap: anywhere; }
.ui-toast button { border: 0; background: none; color: rgba(255,255,255,.7); font-size: 18px; line-height: 1; cursor: pointer; padding: 0 2px; }
@keyframes uiIn { from { transform: translateY(8px); opacity: 0; } to { transform: none; opacity: 1; } }

.ui-dlg-bg { position: fixed; inset: 0; z-index: 280; background: rgba(15, 23, 42, .45); backdrop-filter: blur(2px); display: flex; align-items: flex-start; justify-content: center; padding: 14vh 16px 16px; }
.ui-dlg { width: 100%; max-width: 460px; background: #fff; border-radius: 16px; box-shadow: 0 24px 60px rgba(15, 23, 42, .3); padding: 20px; display: grid; gap: 16px; animation: uiIn .15s ease-out; }
.ui-dlg-h { display: flex; gap: 12px; align-items: flex-start; }
.ui-dlg-h .ic { flex: none; width: 38px; height: 38px; border-radius: 50%; display: grid; place-items: center; background: var(--soft, #e6edfb); color: var(--brand, #1e3a8a); }
.ui-dlg-h .ic.danger { background: var(--rose, #fce7ea); color: var(--accent, #e11d2a); }
.ui-dlg-h .ic svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.ui-dlg-h b { display: block; font-size: 16px; margin-top: 2px; }
.ui-dlg-h p { margin: 6px 0 0; color: var(--text-2, #64748b); font-size: 13.5px; line-height: 1.5; white-space: pre-line; overflow-wrap: anywhere; }
.ui-dlg-in { display: grid; gap: 6px; font-size: 12.5px; font-weight: 600; color: var(--text-2, #64748b); }
.ui-dlg-in i { color: var(--accent, #e11d2a); font-style: normal; }
.ui-dlg-in textarea, .ui-dlg-in input { width: 100%; font: inherit; font-size: 13.5px; font-weight: 400; color: var(--text, #0f172a); padding: 8px 10px; resize: vertical; }
.ui-dlg-f { display: flex; justify-content: flex-end; gap: 8px; }
.btn.danger { background: var(--accent, #e11d2a); border-color: var(--accent, #e11d2a); }
.btn.danger:hover { background: var(--accent-2, #c81e28); }
</style>
