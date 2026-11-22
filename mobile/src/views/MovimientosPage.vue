<script setup lang="ts">
import { IonContent, IonHeader, IonLabel, IonPage, IonSegment, IonSegmentButton, IonTitle, IonToolbar } from '@ionic/vue'
import { computed, ref } from 'vue'
import { formatAmount } from '@/domain/money'
import { useApp, type Context } from '@/stores/app'

const app = useApp()
const filter = ref<'all' | 'income' | 'expense' | 'transfer'>('all')
const TYPE_LABEL = { income: 'Ingreso', expense: 'Gasto', transfer: 'Transferencia', conversion: 'Conversión' } as const
const sign = (t: string) => (t === 'income' ? '+' : t === 'expense' ? '−' : '')

const filtered = computed(() => app.movements.filter((e) => filter.value === 'all' || (filter.value === 'transfer' ? e.type === 'transfer' || e.type === 'conversion' : e.type === filter.value)))
const dayLabel = (iso: string) => new Date(iso).toLocaleDateString('es-AR', { weekday: 'long', day: '2-digit', month: 'long' })
const groups = computed(() => {
  const out: { day: string; label: string; rows: typeof filtered.value }[] = []
  for (const e of filtered.value) {
    const day = e.occurred_at.slice(0, 10)
    const last = out[out.length - 1]
    if (last && last.day === day) last.rows.push(e)
    else out.push({ day, label: dayLabel(e.occurred_at), rows: [e] })
  }
  return out
})
</script>

<template>
  <IonPage>
    <IonHeader>
      <IonToolbar><IonTitle>Movimientos</IonTitle></IonToolbar>
      <IonToolbar>
        <IonSegment :value="app.context" @ionChange="app.setContext($event.detail.value as Context)">
          <IonSegmentButton value="general"><IonLabel>General</IonLabel></IonSegmentButton>
          <IonSegmentButton value="PER"><IonLabel>Personal</IonLabel></IonSegmentButton>
          <IonSegmentButton value="LMS"><IonLabel>LM Studio</IonLabel></IonSegmentButton>
        </IonSegment>
      </IonToolbar>
    </IonHeader>
    <IonContent>
      <IonSegment :value="filter" style="margin: 10px 12px; width: auto" @ionChange="filter = $event.detail.value as typeof filter">
        <IonSegmentButton value="all"><IonLabel>Todo</IonLabel></IonSegmentButton>
        <IonSegmentButton value="income"><IonLabel>Ingresos</IonLabel></IonSegmentButton>
        <IonSegmentButton value="expense"><IonLabel>Gastos</IonLabel></IonSegmentButton>
        <IonSegmentButton value="transfer"><IonLabel>Transf.</IonLabel></IonSegmentButton>
      </IonSegment>
      <div v-if="!groups.length" class="gf-panel gf-card"><div class="gf-empty">Todavía no hay movimientos.</div></div>
      <template v-for="g in groups" :key="g.day">
        <div class="gf-section" style="text-transform: capitalize">{{ g.label }}</div>
        <div class="gf-panel gf-card">
          <div v-for="e in g.rows" :key="e.id" class="gf-row">
            <div style="min-width: 0">
              <div>{{ e.description || TYPE_LABEL[e.type] }}</div>
              <div class="gf-muted">{{ e.account || '—' }} · {{ e.number ?? 'sin número aún' }}<template v-if="app.context === 'general'"> · {{ e.space_code === 'PER' ? 'Personal' : 'LM Studio' }}</template></div>
              <span class="gf-chip" :class="e.status === 'confirmed' ? 'ok' : 'pending'">{{ e.status === 'confirmed' ? 'Confirmado' : 'Pendiente de sincronizar' }}</span>
            </div>
            <div class="gf-amount" :class="{ 'gf-income': e.type === 'income', 'gf-expense': e.type === 'expense' }">{{ sign(e.type) }}{{ formatAmount(e.amount) }} <span class="gf-muted">{{ e.currency }}</span></div>
          </div>
        </div>
      </template>
      <div style="height: 24px" />
    </IonContent>
  </IonPage>
</template>
