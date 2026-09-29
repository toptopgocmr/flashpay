// v-go : rend n'importe quelle case cliquable (curseur main, clavier, survol).
//   v-go="'/transactions'"            → navigation (chaîne ou objet de route)
//   v-go="'#section-id'"              → défilement vers une section de la page
//   v-go="() => faireQuelqueChose()"  → action
import router from '../router'

function scrollToId(id) {
  const el = document.getElementById(id)
  if (!el) return
  el.scrollIntoView({ behavior: 'smooth', block: 'start' })
  el.classList.remove('go-flash'); void el.offsetWidth; el.classList.add('go-flash')
  setTimeout(() => el.classList.remove('go-flash'), 1600)
}

function run(target, ev) {
  if (!target) return
  if (typeof target === 'function') return target(ev)
  if (typeof target === 'string' && target.startsWith('#')) return scrollToId(target.slice(1))
  if (ev && (ev.ctrlKey || ev.metaKey) && typeof target === 'string') return window.open(target, '_blank')
  router.push(target)
}

function bind(el, binding) {
  el.__go = binding.value
  el.classList.toggle('is-clickable', !!binding.value)
  if (binding.value) { el.setAttribute('role', 'button'); el.setAttribute('tabindex', '0') }
}

export default {
  mounted(el, binding) {
    bind(el, binding)
    el.__goClick = (ev) => {
      if (ev.target.closest('a,button,input,select,textarea') && ev.target.closest('a,button,input,select,textarea') !== el) return
      run(el.__go, ev)
    }
    el.__goKey = (ev) => { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); run(el.__go, ev) } }
    el.addEventListener('click', el.__goClick)
    el.addEventListener('keydown', el.__goKey)
  },
  updated: bind,
  unmounted(el) {
    el.removeEventListener('click', el.__goClick)
    el.removeEventListener('keydown', el.__goKey)
  },
}
