import { createRouter, createWebHistory } from '@ionic/vue-router'
import DashboardPage from '@/views/DashboardPage.vue'
import SettingsPage from '@/views/SettingsPage.vue'

export default createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/', component: DashboardPage },
    { path: '/ajustes', component: SettingsPage },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})
