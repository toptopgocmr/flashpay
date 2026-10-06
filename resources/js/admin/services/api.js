import axios from 'axios'
import { toast, ui } from '../utils/ui'

const api = axios.create({
  baseURL: '/api',
})

api.interceptors.request.use((config) => {
  // Barre de chargement (sauf appels de fond : badges, sondages)
  if (!config.silent) { ui.loading++; config._counted = true }
  const token = localStorage.getItem('flashpay_admin_token')
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

const done = (cfg) => { if (cfg?._counted) ui.loading = Math.max(0, ui.loading - 1) }

api.interceptors.response.use(
  (res) => { done(res.config); return res },
  (err) => {
    done(err.config)
    // Erreur réseau ou serveur : toujours signalée (les écrans gèrent eux-mêmes les 4xx)
    if (!err.config?.silent) {
      if (!err.response) toast('Connexion au serveur impossible. Vérifiez votre réseau puis réessayez.', 'err')
      else if (err.response.status >= 500) toast(err.response.data?.message || `Erreur serveur (${err.response.status}). Réessayez dans un instant.`, 'err')
      else if (err.response.status === 403) toast(err.response.data?.message || 'Action non autorisée pour votre profil.', 'err')
    }
    const isLogin = (err.config?.url || '').includes('/auth/login')
    if (err.response?.status === 401 && !isLogin) {
      localStorage.removeItem('flashpay_admin_token')
      window.location.href = '/admin/login'
    }
    return Promise.reject(err)
  }
)

export default api
