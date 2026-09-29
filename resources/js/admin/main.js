import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import '../../css/admin.css'
import { phone } from './utils/format'
import go from './utils/go'

const app = createApp(App)
app.use(createPinia())
app.use(router)
app.directive('go', go)
// $phone(x) : affichage des numéros au format international +242…
app.config.globalProperties.$phone = phone
app.mount('#app')
