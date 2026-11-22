<script setup lang="ts">
import { IonApp, IonRouterOutlet, IonToast } from '@ionic/vue'
import { computed, onMounted } from 'vue'
import { useApp } from '@/stores/app'

const app = useApp()
onMounted(() => { void app.init() })
const open = computed({ get: () => !!app.message, set: (v) => { if (!v) app.message = null } })
</script>

<template>
  <IonApp>
    <IonRouterOutlet />
    <IonToast :is-open="open" :message="app.message ?? ''" :duration="5000" position="top" @didDismiss="open = false" />
  </IonApp>
</template>
