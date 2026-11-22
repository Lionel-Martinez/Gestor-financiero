<script setup lang="ts">
import { IonBackButton, IonButton, IonButtons, IonContent, IonHeader, IonInput, IonItem, IonLabel, IonList, IonListHeader, IonNote, IonPage, IonSegment, IonSegmentButton, IonTitle, IonToolbar } from '@ionic/vue'
import { ref } from 'vue'
import { discardOperation } from '@/data/repo'
import { useApp, type ThemeMode } from '@/stores/app'

const app = useApp()
const url = ref(app.serverUrl)
const email = ref('')
const password = ref('')
const totp = ref('')

async function doLogin() {
  if (await app.login(url.value, email.value, password.value, totp.value || undefined)) { password.value = ''; totp.value = '' }
}
async function discard(id: string) {
  await app.write((db) => discardOperation(db, id))
}
const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString('es-AR') : 'nunca')
</script>

<template>
  <IonPage>
    <IonHeader>
      <IonToolbar>
        <IonButtons slot="start"><IonBackButton default-href="/" text="Atrás" /></IonButtons>
        <IonTitle>Ajustes</IonTitle>
      </IonToolbar>
    </IonHeader>
    <IonContent>
      <IonList>
        <IonListHeader>Servidor y sesión</IonListHeader>
        <template v-if="!app.loggedIn">
          <IonItem><IonInput v-model="url" label="URL del servidor" label-placement="stacked" placeholder="https://api.midominio.com" inputmode="url" /></IonItem>
          <IonItem><IonInput v-model="email" label="Correo" label-placement="stacked" type="email" autocomplete="username" /></IonItem>
          <IonItem><IonInput v-model="password" label="Contraseña" label-placement="stacked" type="password" autocomplete="current-password" /></IonItem>
          <IonItem><IonInput v-model="totp" label="Código TOTP o de recuperación (si tienes 2FA)" label-placement="stacked" inputmode="numeric" /></IonItem>
          <div class="ion-padding"><IonButton expand="block" @click="doLogin">Iniciar sesión y sincronizar</IonButton></div>
        </template>
        <template v-else>
          <IonItem><IonLabel>Conectado a {{ app.serverUrl }}<p>Última sincronización: {{ when(app.lastSync) }}</p></IonLabel></IonItem>
          <div class="ion-padding">
            <IonButton :disabled="app.syncing" @click="app.sync(false)">{{ app.syncing ? 'Sincronizando…' : 'Sincronizar ahora' }}</IonButton>
            <IonButton fill="outline" color="medium" @click="app.logout()">Cerrar sesión</IonButton>
          </div>
        </template>

        <IonListHeader>Cola de sincronización</IonListHeader>
        <IonItem><IonLabel>{{ app.counts.pending }} pendiente(s)<p>Se envían solas cuando hay conexión; reintentarlas es seguro (cada operación es idempotente).</p></IonLabel></IonItem>
        <IonItem v-for="p in app.problems" :key="p.id" lines="full">
          <IonLabel class="ion-text-wrap">
            <h3>{{ p.status === 'conflict' ? 'Conflicto' : 'Rechazada' }} · {{ p.entity }}:{{ p.action }}</h3>
            <p>{{ p.error }}</p>
            <p v-if="p.status === 'conflict'">La versión del servidor se conservó; corrige el dato y vuelve a cargarlo.</p>
          </IonLabel>
          <IonButton slot="end" fill="outline" color="danger" size="small" @click="discard(p.id)">Descartar</IonButton>
        </IonItem>

        <IonListHeader>Apariencia</IonListHeader>
        <div class="ion-padding-horizontal">
          <IonSegment :value="app.theme" @ionChange="app.setTheme($event.detail.value as ThemeMode)">
            <IonSegmentButton value="system"><IonLabel>Sistema</IonLabel></IonSegmentButton>
            <IonSegmentButton value="light"><IonLabel>Claro</IonLabel></IonSegmentButton>
            <IonSegmentButton value="dark"><IonLabel>Oscuro</IonLabel></IonSegmentButton>
          </IonSegment>
        </div>
        <IonItem lines="none"><IonNote class="ion-text-wrap">Los datos se guardan en una base SQLite local sin cifrar en esta versión; usa el bloqueo de pantalla del dispositivo.</IonNote></IonItem>
      </IonList>
    </IonContent>
  </IonPage>
</template>
