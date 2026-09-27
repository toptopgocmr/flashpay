<template>
  <div class="auth-page">
    <header class="auth-top">
      <img src="/images/flashpay-logo.svg" alt="" class="auth-logo" />
      <span>FlashPay</span>
    </header>

    <main class="auth-main">
      <!-- ---------- Formulaire ---------- -->
      <section class="auth-card">
        <h1>Connexion</h1>
        <p class="lead">Accédez à la console d'administration FlashPay.</p>

        <form @submit.prevent="submit" novalidate>
          <fieldset class="roles">
            <legend>Type de compte</legend>
            <label :class="['role', { on: role === 'super_admin' }]">
              <input type="radio" value="super_admin" v-model="role" />
              <span>
                <strong>Super Admin</strong>
                <small>Configuration, validations, tarifs et supervision complète.</small>
              </span>
            </label>
            <label :class="['role', { on: role === 'support' }]">
              <input type="radio" value="support" v-model="role" />
              <span>
                <strong>Support / Opérations</strong>
                <small>Suivi des transactions, assistance clients et marchands.</small>
              </span>
            </label>
          </fieldset>

          <label class="field" for="phone">Numéro de téléphone</label>
          <input id="phone" ref="phoneInput" v-model.trim="phone" type="tel" inputmode="tel"
                 autocomplete="username" placeholder="242060000000" :class="{ invalid: submitted && !phone }" />
          <p class="hint">Format international sans le « + » (ex. 242 06 123 45 67).</p>

          <div class="pw-row">
            <label class="field" for="password">Mot de passe</label>
            <button type="button" class="linkish" @click="showPw = !showPw">{{ showPw ? 'Masquer' : 'Afficher' }}</button>
          </div>
          <input id="password" v-model="password" :type="showPw ? 'text' : 'password'" autocomplete="current-password"
                 :class="{ invalid: submitted && !password }" @keyup="capsCheck" @keydown="capsCheck" />
          <p v-if="caps" class="hint warn">Verr. Maj activé.</p>

          <label class="remember">
            <input type="checkbox" v-model="remember" /> Mémoriser mon numéro sur cet appareil
          </label>

          <div v-if="error" class="alert" role="alert">
            <strong>Connexion impossible</strong>
            <span>{{ error }}</span>
          </div>

          <button class="btn btn-block" type="submit" :disabled="loading">
            <span v-if="loading" class="spinner"></span>
            {{ loading ? 'Connexion…' : 'Se connecter' }}
          </button>
        </form>

        <div class="sep"><span>Besoin d'aide ?</span></div>
        <p class="help">
          Mot de passe oublié ou compte bloqué : contactez un Super Admin FlashPay.
          Les accès sont personnels et journalisés.
        </p>
      </section>

      <!-- ---------- Panneau d'information ---------- -->
      <aside class="auth-promo">
        <div class="promo-inner">
          <p class="kicker">FlashPay Switch</p>
          <h2>Une seule console pour tous vos rails de paiement</h2>
          <ul>
            <li><b>Interopérabilité</b> MTN Mobile Money, Airtel Money et PEEX</li>
            <li><b>Grand livre</b> en double écriture, traçabilité de chaque opération</li>
            <li><b>Réseau</b> clients, marchands et caissiers, agents, sous-agents et super-agents — chacun avec ses habilitations</li>
            <li><b>Supervision</b> temps réel des collectes et décaissements</li>
          </ul>
          <img src="/images/flashpay-logo.svg" alt="" class="promo-logo" />
        </div>
      </aside>
    </main>

    <footer class="auth-foot">
      <span>© {{ year }} FlashPay Group — Brazzaville, République du Congo</span>
      <span class="links"><a href="#" @click.prevent>Conditions d'utilisation</a> · <a href="#" @click.prevent>Confidentialité</a> · Français</span>
    </footer>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const KEY = 'flashpay_admin_remember_phone'
const router = useRouter()
const auth = useAuthStore()

const role = ref('super_admin')
const phone = ref('')
const password = ref('')
const remember = ref(false)
const showPw = ref(false)
const caps = ref(false)
const error = ref('')
const loading = ref(false)
const submitted = ref(false)
const phoneInput = ref(null)
const year = new Date().getFullYear()

onMounted(() => {
  try {
    const saved = localStorage.getItem(KEY)
    if (saved) { phone.value = saved; remember.value = true }
  } catch (_) {}
  phoneInput.value?.focus()
})

function capsCheck(e) {
  caps.value = !!e.getModifierState?.('CapsLock')
}

async function submit() {
  submitted.value = true
  error.value = ''
  if (!phone.value || !password.value) {
    error.value = 'Renseignez votre numéro et votre mot de passe.'
    return
  }
  loading.value = true
  try {
    await auth.login(phone.value.replace(/\D/g, ''), password.value, role.value)
    try { remember.value ? localStorage.setItem(KEY, phone.value) : localStorage.removeItem(KEY) } catch (_) {}
    router.push('/')
  } catch (e) {
    const status = e.response?.status
    error.value = e.response?.data?.message
      || (status === 401 ? 'Identifiants invalides pour ce type de compte.' : "Le serveur ne répond pas. Vérifiez que l'API est démarrée.")
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.auth-page { min-height: 100vh; display: flex; flex-direction: column; background: #fff; }
.auth-top { display: flex; align-items: center; gap: 10px; padding: 20px 32px; font-size: 22px; font-weight: 700; color: #16191f; }
.auth-logo { width: 36px; height: 36px; }

.auth-main { flex: 1; display: flex; justify-content: center; align-items: flex-start; gap: 40px; padding: 24px 24px 48px; }
.auth-card { width: 100%; max-width: 440px; border: 1px solid #e9ebed; border-radius: 28px; padding: 28px 32px; box-shadow: 0 2px 8px rgba(0, 7, 22, .06); }
.auth-card h1 { font-size: 28px; line-height: 34px; margin-bottom: 4px; }
.lead { color: #5f6b7a; margin: 0 0 20px; }

.roles { border: 0; padding: 0; margin: 0 0 20px; display: grid; gap: 10px; }
.roles legend { font-weight: 700; margin-bottom: 8px; padding: 0; }
.role { display: flex; gap: 10px; align-items: flex-start; border: 1px solid #c6c6cd; border-radius: 10px; padding: 10px 12px; cursor: pointer; }
.role.on { border: 2px solid var(--brand); background: var(--soft); padding: 9px 11px; }
.role input { margin-top: 3px; min-height: auto; accent-color: var(--brand); }
.role strong { display: block; }
.role small { color: #5f6b7a; font-size: 12px; line-height: 16px; }

form input[type=tel], form input[type=text], form input[type=password] { width: 100%; height: 36px; }
input.invalid { border-color: var(--accent); }
.hint { margin: 4px 0 16px; }
.hint.warn { color: #8d6605; margin-top: -10px; }
.pw-row { display: flex; justify-content: space-between; align-items: baseline; }
.linkish { background: none; border: 0; color: var(--brand); cursor: pointer; font: inherit; padding: 0; }
.linkish:hover { text-decoration: underline; }
.remember { display: flex; align-items: center; gap: 8px; margin: 16px 0; color: #16191f; }
.remember input { min-height: auto; accent-color: var(--brand); }

.alert { border: 2px solid var(--accent); background: #fff7f7; border-radius: 10px; padding: 10px 14px; margin-bottom: 16px; display: grid; gap: 2px; }
.alert strong { color: var(--accent); }

.btn-block { height: 40px; font-size: 15px; }
.spinner { width: 14px; height: 14px; border: 2px solid #16191f; border-top-color: transparent; border-radius: 50%; animation: spin 1s linear infinite; }

.sep { display: flex; align-items: center; gap: 12px; color: #5f6b7a; font-size: 12px; margin: 24px 0 8px; }
.sep::before, .sep::after { content: ""; flex: 1; border-top: 1px solid #e9ebed; }
.help { color: #5f6b7a; font-size: 12px; line-height: 18px; margin: 0; }

.auth-promo { width: 420px; border-radius: 16px; overflow: hidden; background: linear-gradient(150deg, #1e3a8a 0%, #172e6e 60%, #e11d2a 160%); color: #fff; position: relative; }
.promo-inner { padding: 36px 32px 180px; position: relative; }
.kicker { text-transform: uppercase; letter-spacing: 1px; font-size: 12px; color: #fecaca; font-weight: 700; margin: 0 0 8px; }
.auth-promo h2 { color: #fff; font-size: 24px; line-height: 30px; margin-bottom: 20px; }
.auth-promo ul { list-style: none; padding: 0; margin: 0; display: grid; gap: 12px; }
.auth-promo li { padding-left: 26px; position: relative; color: #d1d5db; }
.auth-promo li::before { content: ""; position: absolute; left: 0; top: 4px; width: 14px; height: 14px; border-radius: 50%; background: var(--accent) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Cpath d='M4 8.5l2.5 2.5L12 5.5' stroke='%23ffffff' stroke-width='2' fill='none'/%3E%3C/svg%3E") center no-repeat; }
.auth-promo li b { color: #fff; }
.promo-logo { position: absolute; right: -30px; bottom: -40px; width: 230px; opacity: .9; background: #fff; border-radius: 40px; padding: 26px; transform: rotate(-8deg); }

.auth-foot { display: flex; justify-content: space-between; flex-wrap: wrap; gap: 8px; padding: 16px 32px; border-top: 1px solid #e9ebed; color: #5f6b7a; font-size: 12px; }
.auth-foot a { color: #5f6b7a; }

@media (max-width: 900px) {
  .auth-promo { display: none; }
  .auth-top { padding: 16px; }
  .auth-main { padding: 8px 16px 32px; }
  .auth-card { padding: 22px 18px; }
}
</style>
