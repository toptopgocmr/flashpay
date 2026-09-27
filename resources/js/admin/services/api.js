import axios from 'axios'

const api = axios.create({
  baseURL: '/api',
})

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('flashpay_admin_token')
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

api.interceptors.response.use(
  (res) => res,
  (err) => {
    const isLogin = (err.config?.url || '').includes('/auth/login')
    if (err.response?.status === 401 && !isLogin) {
      localStorage.removeItem('flashpay_admin_token')
      window.location.href = '/admin/login'
    }
    return Promise.reject(err)
  }
)

export default api
