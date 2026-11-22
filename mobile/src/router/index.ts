import { createRouter, createWebHistory } from '@ionic/vue-router'
import DashboardPage from '@/views/DashboardPage.vue'
import MovimientosPage from '@/views/MovimientosPage.vue'
import AnualPage from '@/views/AnualPage.vue'
import SettingsPage from '@/views/SettingsPage.vue'

export default createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/', component: DashboardPage },
    { path: '/movimientos', component: MovimientosPage },
    { path: '/anual', component: AnualPage },
    { path: '/ajustes', component: SettingsPage },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})
