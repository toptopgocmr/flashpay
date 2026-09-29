import { createRouter, createWebHistory } from 'vue-router'

const routes = [
  { path: '/login', component: () => import('../views/Login.vue') },
  { path: '/', component: () => import('../views/Dashboard.vue') },
  { path: '/merchants', component: () => import('../views/Merchants.vue') },
  { path: '/agents', component: () => import('../views/Agents.vue') },
  { path: '/agents/:id', component: () => import('../views/AgentDetail.vue') },
  { path: '/cashiers', component: () => import('../views/Cashiers.vue') },
  { path: '/roles', component: () => import('../views/Roles.vue') },
  { path: '/clients', component: () => import('../views/Clients.vue') },
  { path: '/clients/:id', component: () => import('../views/ClientDetail.vue') },
  { path: '/settlements', component: () => import('../views/Settlements.vue') },
  { path: '/transactions', component: () => import('../views/Transactions.vue') },
  { path: '/transactions/:id', component: () => import('../views/TransactionDetail.vue') },
  { path: '/tariffs', component: () => import('../views/Tariffs.vue') },
  { path: '/users', component: () => import('../views/Users.vue') },
  { path: '/accounts', component: () => import('../views/Accounts.vue') },
  { path: '/peex', component: () => import('../views/PeexSandbox.vue') },
  { path: '/corridors', component: () => import('../views/Corridors.vue') },
  // Cahier des charges v1.5
  { path: '/notifications', component: () => import('../views/Notifications.vue') },
  { path: '/kyc', component: () => import('../views/Kyc.vue') },
  { path: '/float-requests', component: () => import('../views/FloatRequests.vue') },
  { path: '/support', component: () => import('../views/Support.vue') },
  { path: '/fraud', component: () => import('../views/Fraud.vue') },
  { path: '/reconciliation', component: () => import('../views/Reconciliation.vue') },
  { path: '/audit', component: () => import('../views/Audit.vue') },
  { path: '/ecommerce', component: () => import('../views/Ecommerce.vue') },
  { path: '/mini-programs', component: () => import('../views/MiniPrograms.vue') },
  { path: '/commissions', component: () => import('../views/Commissions.vue') },
  { path: '/settings', component: () => import('../views/Settings.vue') },
]

const router = createRouter({
  // L'admin est servi par Laravel sous le préfixe /admin (cf. routes/web.php)
  history: createWebHistory('/admin'),
  routes,
})

router.beforeEach((to) => {
  const isAuthenticated = !!localStorage.getItem('flashpay_admin_token')
  if (to.path !== '/login' && !isAuthenticated) return '/login'
  if (to.path === '/login' && isAuthenticated) return '/'
})

// Écrans récemment visités (widget « Récemment visités » du tableau de bord)
router.afterEach((to) => {
  if (to.path === '/' || to.path === '/login') return
  try {
    const list = JSON.parse(localStorage.getItem('fp_admin_recent') || '[]').filter((p) => p !== to.path)
    list.unshift(to.path)
    localStorage.setItem('fp_admin_recent', JSON.stringify(list.slice(0, 10)))
  } catch (_) {}
})

export default router
