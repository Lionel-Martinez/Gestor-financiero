<script setup lang="ts">
import { IonButton, IonButtons, IonContent, IonHeader, IonIcon, IonInput, IonPage, IonRefresher, IonRefresherContent, IonSegment, IonSegmentButton, IonLabel, IonSelect, IonSelectOption, IonTitle, IonToolbar } from '@ionic/vue'
import { cloudOfflineOutline, settingsOutline, syncOutline } from 'ionicons/icons'
import { computed, ref } from 'vue'
import AccountModal from '@/components/AccountModal.vue'
import OperationModal from '@/components/OperationModal.vue'
import { variationPct, type AccountBalance } from '@/data/repo'
import type { PeriodKey } from '@/data/period'
import { formatAmount, isZero, toUnits } from '@/domain/money'
import { useApp, type Context } from '@/stores/app'

const app = useApp()
const modal = ref<null | 'income' | 'expense' | 'transfer' | 'account'>(null)
const filter = ref<'all' | 'income' | 'expense' | 'transfer'>('all')

const d = computed(() => app.dashboard)
const entries = (m: Record<string, string> | undefined) => Object.entries(m ?? {}).sort(([a], [b]) => (a === 'ARS' ? -1 : b === 'ARS' ? 1 : a.localeCompare(b)))

function variation(cur: Record<string, string> | undefined, prev: Record<string, string> | undefined): string | null {
  const keys = new Set([...Object.keys(cur ?? {}), ...Object.keys(prev ?? {})])
  if (keys.size !== 1) return null
  const k = [...keys][0]
  const v = variationPct(cur?.[k] ?? '0', prev?.[k] ?? '0')
  return v === null ? null : `${v > 0 ? '+' : ''}${String(v).replace('.', ',')} % vs. período anterior`
}

// Gráfico: ingresos frente a gastos, por moneda (nunca se mezclan monedas sin conversión explícita).
const flowBars = computed(() => {
  const cur = new Set([...Object.keys(d.value?.income ?? {}), ...Object.keys(d.value?.expense ?? {})])
  return [...cur].map((c) => {
    const i = d.value?.income[c] ?? '0'
    const e = d.value?.expense[c] ?? '0'
    const max = Math.max(Number(toUnits(i)), Number(toUnits(e)), 1)
    return { currency: c, income: i, expense: e, iw: (Number(toUnits(i)) / max) * 100, ew: (Number(toUnits(e)) / max) * 100 }
  })
})
const categoryBars = computed(() => {
  const rows = d.value?.expenseByCategory ?? []
  return rows.slice(0, 6).map((r) => {
    const total = rows.filter((x) => x.currency === r.currency).reduce((a, x) => a + Number(toUnits(x.total)), 0) || 1
    return { ...r, pct: (Number(toUnits(r.total)) / total) * 100 }
  })
})

const GROUPS: { label: string; kinds: string[] }[] = [
  { label: 'Bancos', kinds: ['bank'] }, { label: 'Billeteras virtuales', kinds: ['wallet'] },
  { label: 'Tarjetas y cuentas virtuales', kinds: ['virtual', 'credit_card'] }, { label: 'Efectivo', kinds: ['cash'] },
  { label: 'Dólares físicos', kinds: ['usd_cash'] }, { label: 'Dólares digitales', kinds: ['digital_usd'] }, { label: 'Otros', kinds: ['other'] },
]
const groups = computed(() => GROUPS.map((g) => ({ label: g.label, rows: app.balances.filter((b) => g.kinds.includes(b.kind)) })).filter((g) => g.rows.length))

const recent = computed(() => (d.value?.recent ?? []).filter((e) => filter.value === 'all' || (filter.value === 'transfer' ? e.type === 'transfer' || e.type === 'conversion' : e.type === filter.value)))
const TYPE_LABEL = { income: 'Ingreso', expense: 'Gasto', transfer: 'Transferencia', conversion: 'Conversión' } as const
const sign = (t: string) => (t === 'income' ? '+' : t === 'expense' ? '−' : '')
const dateLabel = (iso: string) => new Date(iso).toLocaleDateString('es-AR', { day: '2-digit', month: 'short' })
const balanceLabel = (b: AccountBalance) => (b.kind === 'credit_card' ? 'Deuda' : '')
const onContext = (c: unknown) => app.setContext(c as Context)
const onPeriod = (p: unknown) => app.setPeriod(p as PeriodKey)
async function onRefresh(ev: CustomEvent) {
  await app.sync(false)
  ;(ev.target as HTMLIonRefresherElement).complete()
}
const hasPending = (b: AccountBalance) => !isZero(b.pendingDelta)
</script>

<template>
  <IonPage>
    <IonHeader>
      <IonToolbar>
        <IonTitle>Gestor Financiero</IonTitle>
        <IonButtons slot="end">
          <IonButton aria-label="Sincronizar" :disabled="app.syncing || !app.loggedIn" @click="app.sync(false)"><IonIcon slot="icon-only" :icon="syncOutline" /></IonButton>
          <IonButton aria-label="Ajustes" router-link="/ajustes"><IonIcon slot="icon-only" :icon="settingsOutline" /></IonButton>
        </IonButtons>
      </IonToolbar>
      <IonToolbar>
        <IonSegment :value="app.context" @ionChange="onContext($event.detail.value)">
          <IonSegmentButton value="general"><IonLabel>General</IonLabel></IonSegmentButton>
          <IonSegmentButton value="PER"><IonLabel>Personal</IonLabel></IonSegmentButton>
          <IonSegmentButton value="LMS"><IonLabel>LM Studio</IonLabel></IonSegmentButton>
        </IonSegment>
      </IonToolbar>
    </IonHeader>

    <IonContent>
      <IonRefresher slot="fixed" @ionRefresh="onRefresh"><IonRefresherContent /></IonRefresher>

      <div v-if="!app.spaces.length" class="gf-panel gf-card" style="margin-top: 12px">
        <h4>Primer uso</h4>
        <p>Inicia sesión una vez con conexión para traer tus espacios, categorías y cuentas. Después podrás trabajar sin Internet.</p>
        <IonButton router-link="/ajustes" size="small">Ir a Ajustes</IonButton>
      </div>

      <template v-else-if="d">
        <div v-if="app.counts.pending || app.counts.problems" class="gf-panel gf-card">
          <div class="gf-row">
            <span><IonIcon :icon="cloudOfflineOutline" /> <b>{{ app.counts.pending }}</b> operación(es) pendientes de sincronizar<template v-if="app.counts.problems"> · <b class="gf-expense">{{ app.counts.problems }}</b> con problemas</template></span>
            <IonButton size="small" fill="clear" router-link="/ajustes">Ver</IonButton>
          </div>
        </div>

        <div style="display: flex; gap: 8px; padding: 10px 12px; align-items: center">
          <IonSelect :value="app.period" interface="popover" aria-label="Período" @ionChange="onPeriod($event.detail.value)" style="flex: 1">
            <IonSelectOption value="today">Hoy</IonSelectOption>
            <IonSelectOption value="week">Esta semana</IonSelectOption>
            <IonSelectOption value="month">Este mes</IonSelectOption>
            <IonSelectOption value="prev_month">Mes anterior</IonSelectOption>
            <IonSelectOption value="year">Este año</IonSelectOption>
            <IonSelectOption value="custom">Período personalizado</IonSelectOption>
          </IonSelect>
        </div>
        <div v-if="app.period === 'custom'" style="display: flex; gap: 8px; padding: 0 12px 8px">
          <IonInput type="date" :value="app.custom.from" fill="outline" label="Desde" label-placement="stacked" @ionChange="app.setCustom($event.detail.value ?? '', app.custom.to)" />
          <IonInput type="date" :value="app.custom.to" fill="outline" label="Hasta" label-placement="stacked" @ionChange="app.setCustom(app.custom.from, $event.detail.value ?? '')" />
        </div>

        <div class="gf-grid">
          <div class="gf-card">
            <h4>Patrimonio</h4>
            <div v-for="[c, v] in entries(d.netWorth)" :key="c" class="gf-amount">{{ formatAmount(v) }} <span class="gf-muted">{{ c }}</span></div>
            <div v-if="!entries(d.netWorth).length" class="gf-amount">—</div>
            <div class="gf-muted">Activos − pasivos</div>
          </div>
          <div class="gf-card">
            <h4>Disponible real</h4>
            <div v-for="[c, v] in entries(d.available)" :key="c" class="gf-amount">{{ formatAmount(v) }} <span class="gf-muted">{{ c }}</span></div>
            <div v-if="!entries(d.available).length" class="gf-amount">—</div>
            <div v-for="[c, v] in entries(d.pendingAvailableDelta).filter(([, x]) => !isZero(x))" :key="'p' + c" class="gf-muted">{{ toUnits(v) > 0n ? '+' : '' }}{{ formatAmount(v) }} {{ c }} pend. de sincronizar</div>
          </div>
          <div class="gf-card">
            <h4>Ingresos confirmados</h4>
            <div v-for="[c, v] in entries(d.income)" :key="c" class="gf-amount gf-income">{{ formatAmount(v) }} <span class="gf-muted">{{ c }}</span></div>
            <div v-if="!entries(d.income).length" class="gf-amount">—</div>
            <div class="gf-muted">{{ variation(d.income, d.incomePrev) }}</div>
          </div>
          <div class="gf-card">
            <h4>Gastos confirmados</h4>
            <div v-for="[c, v] in entries(d.expense)" :key="c" class="gf-amount gf-expense">{{ formatAmount(v) }} <span class="gf-muted">{{ c }}</span></div>
            <div v-if="!entries(d.expense).length" class="gf-amount">—</div>
            <div class="gf-muted">{{ variation(d.expense, d.expensePrev) }}</div>
          </div>
        </div>
        <p class="gf-muted" style="padding: 6px 14px 0">Los ingresos previstos o pendientes no aumentan el disponible real. Cada moneda se muestra por separado.</p>

        <div class="gf-section">Acciones rápidas</div>
        <div class="gf-grid" style="grid-template-columns: repeat(3, 1fr)">
          <IonButton size="small" color="success" @click="modal = 'income'">Ingreso</IonButton>
          <IonButton size="small" color="danger" @click="modal = 'expense'">Gasto</IonButton>
          <IonButton size="small" color="medium" @click="modal = 'transfer'">Transferencia</IonButton>
          <IonButton size="small" fill="outline" @click="modal = 'account'">Nueva cuenta</IonButton>
          <IonButton size="small" fill="outline" disabled title="Llega con el módulo de recurrencias">Programar</IonButton>
          <IonButton size="small" fill="outline" disabled title="Llega con el módulo de deudas">Registrar deuda</IonButton>
        </div>

        <div class="gf-section">Ingresos frente a gastos</div>
        <div class="gf-panel gf-card">
          <div v-if="!flowBars.length" class="gf-empty">Sin movimientos confirmados en el período.</div>
          <div v-for="b in flowBars" :key="b.currency" style="margin-bottom: 8px">
            <div class="gf-muted">{{ b.currency }}</div>
            <div class="gf-row"><span class="gf-muted" style="width: 62px">Ingresos</span><div class="gf-bar" style="flex: 1"><span :style="{ width: b.iw + '%', background: 'var(--gf-income)' }" /></div><span class="gf-amount" style="font-size: 13px">{{ formatAmount(b.income) }}</span></div>
            <div class="gf-row"><span class="gf-muted" style="width: 62px">Gastos</span><div class="gf-bar" style="flex: 1"><span :style="{ width: b.ew + '%', background: 'var(--gf-expense)' }" /></div><span class="gf-amount" style="font-size: 13px">{{ formatAmount(b.expense) }}</span></div>
          </div>
        </div>

        <div class="gf-section">Gastos por categoría</div>
        <div class="gf-panel gf-card">
          <div v-if="!categoryBars.length" class="gf-empty">Sin gastos confirmados en el período.</div>
          <div v-for="c in categoryBars" :key="c.currency + c.category" style="margin-bottom: 6px">
            <div class="gf-row" style="padding: 2px 0"><span>{{ c.category }}</span><span class="gf-amount" style="font-size: 13px">{{ formatAmount(c.total) }} {{ c.currency }}</span></div>
            <div class="gf-bar"><span :style="{ width: c.pct + '%', background: 'var(--ion-color-primary)' }" /></div>
          </div>
        </div>

        <div class="gf-section">Actividad reciente</div>
        <div class="gf-panel gf-card">
          <IonSegment :value="filter" style="margin-bottom: 6px" @ionChange="filter = $event.detail.value as typeof filter">
            <IonSegmentButton value="all"><IonLabel>Todo</IonLabel></IonSegmentButton>
            <IonSegmentButton value="income"><IonLabel>Ingresos</IonLabel></IonSegmentButton>
            <IonSegmentButton value="expense"><IonLabel>Gastos</IonLabel></IonSegmentButton>
            <IonSegmentButton value="transfer"><IonLabel>Transf.</IonLabel></IonSegmentButton>
          </IonSegment>
          <div v-if="!recent.length" class="gf-empty">Todavía no hay movimientos.</div>
          <div v-for="e in recent" :key="e.id" class="gf-row">
            <div style="min-width: 0">
              <div>{{ e.description || TYPE_LABEL[e.type] }}</div>
              <div class="gf-muted">{{ dateLabel(e.occurred_at) }} · {{ e.account || '—' }}</div>
              <div><span class="gf-chip">{{ TYPE_LABEL[e.type] }}</span> <span class="gf-chip">{{ e.space_code === 'PER' ? 'Personal' : 'LM Studio' }}</span> <span class="gf-chip" :class="{ pending: e.status !== 'confirmed' }">{{ e.status === 'confirmed' ? 'Confirmado' : 'Pendiente de sincronizar' }}</span></div>
            </div>
            <div class="gf-amount" :class="{ 'gf-income': e.type === 'income', 'gf-expense': e.type === 'expense' }">{{ sign(e.type) }}{{ formatAmount(e.amount) }} <span class="gf-muted">{{ e.currency }}</span></div>
          </div>
        </div>

        <div class="gf-grid" style="grid-template-columns: 1fr">
          <div class="gf-card"><h4>Próximos cobros</h4><div class="gf-empty">Sueldos, servicios, ventas y cobranzas recurrentes aparecerán aquí cuando exista el módulo de recurrencias.</div></div>
          <div class="gf-card"><h4>Próximas obligaciones</h4><div class="gf-empty">Tarjetas, cuotas, préstamos e impuestos con vencimiento aparecerán aquí (los vencidos con alerta).</div></div>
        </div>

        <div class="gf-section">Cuentas y fondos</div>
        <div v-if="!groups.length" class="gf-panel gf-card"><div class="gf-empty">Aún no hay cuentas. Crea la primera con “Nueva cuenta”.</div></div>
        <div v-for="g in groups" :key="g.label" class="gf-panel gf-card">
          <h4>{{ g.label }}</h4>
          <div v-for="b in g.rows" :key="b.financialAccountId + b.currency" class="gf-row">
            <div style="min-width: 0">
              <div>{{ b.name }}<span v-if="app.context === 'general'" class="gf-chip" style="margin-left: 6px">{{ app.spaceCode(b.spaceId) }}</span></div>
              <div class="gf-muted">{{ b.alias ? b.alias + ' · ' : '' }}{{ b.currency }}</div>
            </div>
            <div style="text-align: right">
              <div class="gf-amount">{{ balanceLabel(b) }} {{ formatAmount(b.confirmed) }}</div>
              <div v-if="hasPending(b)" class="gf-muted">{{ toUnits(b.pendingDelta) > 0n ? '+' : '' }}{{ formatAmount(b.pendingDelta) }} pendiente</div>
            </div>
          </div>
        </div>
        <div style="height: 32px" />
      </template>
    </IonContent>

    <OperationModal :open="modal === 'income' || modal === 'expense' || modal === 'transfer'" :kind="modal === 'account' || modal === null ? 'income' : modal" @close="modal = null" />
    <AccountModal :open="modal === 'account'" @close="modal = null" />
  </IonPage>
</template>
