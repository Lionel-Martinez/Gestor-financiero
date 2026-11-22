<script setup lang="ts">
import { IonApp, IonIcon, IonLabel, IonRouterOutlet, IonTabBar, IonTabButton, IonTabs, IonToast } from '@ionic/vue'
import { addOutline, barChartOutline, homeOutline, listOutline } from 'ionicons/icons'
import { computed, onMounted } from 'vue'
import OperationModal from '@/components/OperationModal.vue'
import { useApp } from '@/stores/app'

const app = useApp()
onMounted(() => { void app.init() })
const open = computed({ get: () => !!app.message, set: (v) => { if (!v) app.message = null } })
</script>

<template>
  <IonApp>
    <IonTabs>
      <IonRouterOutlet />
      <IonTabBar slot="bottom">
        <IonTabButton tab="inicio" href="/"><IonIcon :icon="homeOutline" /><IonLabel>Inicio</IonLabel></IonTabButton>
        <IonTabButton tab="movimientos" href="/movimientos"><IonIcon :icon="listOutline" /><IonLabel>Movim.</IonLabel></IonTabButton>
        <IonTabButton tab="nuevo" aria-label="Nueva operación" @click.prevent="app.newOp = 'expense'"><IonIcon :icon="addOutline" /><IonLabel>Nuevo</IonLabel></IonTabButton>
        <IonTabButton tab="anual" href="/anual"><IonIcon :icon="barChartOutline" /><IonLabel>Anual</IonLabel></IonTabButton>
      </IonTabBar>
    </IonTabs>
    <OperationModal :open="app.newOp !== null" :kind="app.newOp ?? 'expense'" @close="app.newOp = null" />
    <IonToast :is-open="open" :message="app.message ?? ''" :duration="5000" position="top" @didDismiss="open = false" />
  </IonApp>
</template>
