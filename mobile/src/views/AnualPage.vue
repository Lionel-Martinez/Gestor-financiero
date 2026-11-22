<script setup lang="ts">
import { IonContent, IonHeader, IonLabel, IonPage, IonSegment, IonSegmentButton, IonTitle, IonToolbar } from '@ionic/vue'
import { computed, ref, watch } from 'vue'
import { formatAmount, toUnits } from '@/domain/money'
import { useApp, type Context } from '@/stores/app'

const app = useApp()
const currencies = computed(() => [...new Set(app.annual.flatMap((r) => [...Object.keys(r.income), ...Object.keys(r.expense)]))].sort((a, b) => (a === 'ARS' ? -1 : b === 'ARS' ? 1 : a.localeCompare(b))))
const cur = ref('ARS')
watch(currencies, (c) => { if (c.length && !c.includes(cur.value)) cur.value = c[0] }, { immediate: true })

const rows = computed(() => {
  const list = app.annual.map((r) => ({ year: r.year, income: r.income[cur.value] ?? '0', expense: r.expense[cur.value] ?? '0', result: r.result[cur.value] ?? '0' }))
  const max = Math.max(1, ...list.flatMap((r) => [Number(toUnits(r.income)), Number(toUnits(r.expense))]))
  const thisYear = String(new Date().getFullYear())
  return list.map((r) => ({ ...r, iw: (Number(toUnits(r.income)) / max) * 100, ew: (Number(toUnits(r.expense)) / max) * 100, tag: r.year === thisYear ? 'en curso' : '' }))
})
</script>

<template>
  <IonPage>
    <IonHeader>
      <IonToolbar><IonTitle>Resumen anual</IonTitle></IonToolbar>
      <IonToolbar>
        <IonSegment :value="app.context" @ionChange="app.setContext($event.detail.value as Context)">
          <IonSegmentButton value="general"><IonLabel>General</IonLabel></IonSegmentButton>
          <IonSegmentButton value="PER"><IonLabel>Personal</IonLabel></IonSegmentButton>
          <IonSegmentButton value="LMS"><IonLabel>LM Studio</IonLabel></IonSegmentButton>
        </IonSegment>
      </IonToolbar>
    </IonHeader>
    <IonContent>
      <IonSegment v-if="currencies.length > 1" :value="cur" style="margin: 10px 12px; width: auto" @ionChange="cur = String($event.detail.value)">
        <IonSegmentButton v-for="c in currencies" :key="c" :value="c"><IonLabel>{{ c }}</IonLabel></IonSegmentButton>
      </IonSegment>
      <div v-if="!rows.length" class="gf-panel gf-card" style="margin-top: 12px"><div class="gf-empty">Todavía no hay ingresos ni gastos confirmados. Aparecerán año por año cuando el servidor confirme tus operaciones.</div></div>
      <div v-for="r in rows" :key="r.year" class="gf-panel gf-card" style="margin-top: 10px">
        <div class="gf-row" style="padding-top: 0"><span style="font-size: 18px; font-weight: 700">{{ r.year }}</span><span class="gf-muted">{{ r.tag }}</span></div>
        <div class="gf-row"><span>Ingresos</span><span class="gf-amount gf-income">{{ formatAmount(r.income) }}</span></div>
        <div class="gf-bar"><span :style="{ width: r.iw + '%', background: 'var(--gf-income)' }" /></div>
        <div class="gf-row"><span>Gastos</span><span class="gf-amount gf-expense">{{ formatAmount(r.expense) }}</span></div>
        <div class="gf-bar"><span :style="{ width: r.ew + '%', background: 'var(--gf-expense)' }" /></div>
        <div class="gf-row" style="border-top: 1px solid var(--ion-border-color, #26302b); margin-top: 10px; padding-top: 10px"><b>Resultado</b><span class="gf-amount">{{ toUnits(r.result) > 0n ? '+' : '' }}{{ formatAmount(r.result) }} <span class="gf-muted">{{ cur }}</span></span></div>
      </div>
      <p class="gf-muted" style="padding: 4px 14px">Por fecha contable, solo operaciones confirmadas por el servidor. Cada moneda se muestra por separado; la conversión a ARS llegará cuando la app reciba las cotizaciones.</p>
      <div style="height: 24px" />
    </IonContent>
  </IonPage>
</template>
