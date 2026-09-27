import { defineStore } from 'pinia'
import api from '../services/api'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: JSON.parse(localStorage.getItem('flashpay_admin_user') || 'null'),
  }),
  actions: {
    async login(phone, password, profile = 'super_admin') {
      const { data } = await api.post('/auth/login', { phone, password, profile })
      localStorage.setItem('flashpay_admin_token', data.token)
      localStorage.setItem('flashpay_admin_user', JSON.stringify(data.user))
      this.user = data.user
    },
    logout() {
      localStorage.removeItem('flashpay_admin_token')
      localStorage.removeItem('flashpay_admin_user')
      this.user = null
    },
  },
})
