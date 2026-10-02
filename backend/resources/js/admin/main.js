import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import './assets/style.css'
// Noto Sans Bengali for the Bangla print views, bundled instead of loaded from Google Fonts.
import '@fontsource/noto-sans-bengali/400.css'
import '@fontsource/noto-sans-bengali/600.css'
import '@fontsource/noto-sans-bengali/700.css'

const app = createApp(App)
const pinia = createPinia()

app.use(pinia)
app.use(router)
app.mount('#app')

