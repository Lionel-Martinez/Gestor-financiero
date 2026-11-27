<script setup lang="ts">
import { IonButton, IonButtons, IonContent, IonHeader, IonIcon, IonInput, IonLabel, IonPage, IonRefresher, IonRefresherContent, IonSegment, IonSegmentButton, IonSelect, IonSelectOption, IonTitle, IonToolbar } from '@ionic/vue'
import { cloudOfflineOutline, settingsOutline, syncOutline } from 'ionicons/icons'
import { computed, ref } from 'vue'
import AccountModal from '@/components/AccountModal.vue'
import { variationPct, type AccountBalance } from '@/data/repo'
import type { PeriodKey } from '@/data/period'
import { formatAmount, isZero, sub, toUnits } from '@/domain/money'
import { useApp, type Context } from '@/stores/app'

const app = useApp()
const modal = ref<null | 'account'>(null)

const d = computed(() => app.dashboard)
const entries = (m: Record<string, string> | undefined) => Object.entries(m ?? {}).sort(([a], [b]) => (a === 'ARS' ? -1 : b === 'ARS' ? 1 : a.localeCompare(b)))

function variation(cur: Record<string, string> | undefined, prev: Record<string, string> | undefined): string | null {
  const keys = new Set([...Object.keys(cur ?? {}), ...Object.keys(prev ?? {})])
  if (keys.size !== 1) return null
  const k = [...keys][0]
  const v = variationPct(cur?.[k] ?? '0', prev?.[k] ?? '0')
  return v === null ? null : `${v > 0 ? '+' : ''}${String(v).replace('.', ',')} % vs. período anterior`
}

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

const recent = computed(() => d.value?.recent ?? [])
const TYPE_LABEL = { income: 'Ingreso', expense: 'Gasto', transfer: 'Transferencia', conversion: 'Conversión' } as const
const result = computed(() => {
  const cur = new Set([...Object.keys(d.value?.income ?? {}), ...Object.keys(d.value?.expense ?? {})])
  return [...cur].sort().map((c) => ({ currency: c, value: sub(d.value?.income[c] ?? '0', d.value?.expense[c] ?? '0') }))
})
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
          <IonSegmentButton value="PER"><IonLabel>Personal</IonLabel></IonSegmentButton>
          <IonSegmentButton value="LMS"><IonLabel>LM Studio</IonLabel></IonSegmentButton>
          <IonSegmentButton value="general"><IonLabel>General</IonLabel></IonSegmentButton>
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
            <span><IonIcon :icon="cloudOfflineOutline" /> <b>{{ app.counts.pending }}</b> operación(es) pendientes de sincronizar<template v-if="app.counts.problems"> · <b>{{ app.counts.problems }}</b> con problemas</template></span>
            <IonButton size="small" fill="clear" router-link="/ajustes">Ver</IonButton>
          </div>
        </div>

        <div class="gf-panel gf-card" style="padding-top: 14px; padding-bottom: 14px">
          <div class="gf-muted">Patrimonio neto</div>
          <div v-for="[c, v] in entries(d.netWorth)" :key="c" style="font-size: 26px; font-weight: 700; font-variant-numeric: tabular-nums">{{ formatAmount(v) }} <span class="gf-muted" style="font-size: 14px">{{ c }}</span></div>
          <div v-if="!entries(d.netWorth).length" style="font-size: 26px; font-weight: 700">—</div>
          <div class="gf-muted">Activos − pasivos<template v-if="variation(d.income, d.incomePrev)"> · ingresos {{ variation(d.income, d.incomePrev) }}</template></div>
        </div>

        <div style="display: flex; padding: 0 16px; align-items: center">
          <div class="gf-section" style="padding-left: 0; flex: 1">Período</div>
          <IonSelect :value="app.period" interface="popover" aria-label="Período" @ionChange="onPeriod($event.detail.value)" style="max-width: 190px; min-height: 40px">
            <IonSelectOption value="today">Hoy</IonSelectOption>
            <IonSelectOption value="week">Esta semana</IonSelectOption>
            <IonSelectOption value="month">Este mes</IonSelectOption>
            <IonSelectOption value="prev_month">Mes anterior</IonSelectOption>
            <IonSelectOption value="year">Este año</IonSelectOption>
            <IonSelectOption value="custom">Personalizado</IonSelectOption>
          </IonSelect>
        </div>
        <div v-if="app.period === 'custom'" class="gf-panel gf-card" style="display: flex; gap: 8px">
          <IonInput type="date" :value="app.custom.from" fill="outline" label="Desde" label-placement="stacked" @ionChange="app.setCustom($event.detail.value ?? '', app.custom.to)" />
          <IonInput type="date" :value="app.custom.to" fill="outline" label="Hasta" label-placement="stacked" @ionChange="app.setCustom(app.custom.from, $event.detail.value ?? '')" />
        </div>
        <div class="gf-panel gf-card">
          <div v-for="[c, v] in entries(d.available)" :key="'a' + c" class="gf-row"><div><div>Disponible real</div><div class="gf-muted">Solo lo confirmado · {{ c }}</div></div><span class="gf-amount">{{ formatAmount(v) }}</span></div>
          <div v-for="[c, v] in entries(d.income)" :key="'i' + c" class="gf-row"><div><div>Ingresos</div><div class="gf-muted">Confirmados · {{ c }}</div></div><span class="gf-amount gf-income">+ {{ formatAmount(v) }}</span></div>
          <div v-for="[c, v] in entries(d.expense)" :key="'e' + c" class="gf-row"><div><div>Gastos</div><div class="gf-muted">Confirmados · {{ c }}</div></div><span class="gf-amount">{{ formatAmount(v) }}</span></div>
          <div v-for="r in result" :key="'r' + r.currency" class="gf-row"><div><div>Resultado</div><div class="gf-muted">Ingresos − gastos · {{ r.currency }}</div></div><span class="gf-amount">{{ toUnits(r.value) > 0n ? '+ ' : '' }}{{ formatAmount(r.value) }}</span></div>
          <div v-for="[c, v] in entries(d.pendingAvailableDelta).filter(([, x]) => !isZero(x))" :key="'p' + c" class="gf-muted" style="padding: 6px 0">{{ toUnits(v) > 0n ? '+' : '' }}{{ formatAmount(v) }} {{ c }} pendientes de sincronizar (no suman al disponible real)</div>
          <div v-if="!entries(d.available).length && !entries(d.income).length && !entries(d.expense).length" class="gf-empty">Sin movimientos confirmados en el período.</div>
        </div>

        <div class="gf-section">Cuentas</div>
        <div v-if="!groups.length" class="gf-panel gf-card"><div class="gf-empty">Aún no hay cuentas.</div></div>
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
        <div class="gf-panel gf-card"><IonButton size="small" fill="clear" style="margin: 0 -8px" @click="modal = 'account'">+ Nueva cuenta</IonButton></div>

        <div class="gf-section">Gastos por categoría</div>
        <div class="gf-panel gf-card">
          <div v-if="!categoryBars.length" class="gf-empty">Sin gastos confirmados en el período.</div>
          <div v-for="c in categoryBars" :key="c.currency + c.category" style="margin-bottom: 8px">
            <div class="gf-row" style="padding: 2px 0; border: 0"><span>{{ c.category }}</span><span class="gf-amount" style="font-size: 13px">{{ formatAmount(c.total) }} {{ c.currency }}</span></div>
            <div class="gf-bar"><span :style="{ width: c.pct + '%', background: 'var(--ion-color-primary)' }" /></div>
          </div>
        </div>

        <div style="display: flex; padding-right: 8px; align-items: center"><div class="gf-section" style="flex: 1">Últimos movimientos</div><IonButton size="small" fill="clear" router-link="/movimientos">Ver todos</IonButton></div>
        <div class="gf-panel gf-card">
          <div v-if="!recent.length" class="gf-empty">Todavía no hay movimientos.</div>
          <div v-for="e in recent" :key="e.id" class="gf-row">
            <div style="min-width: 0">
              <div>{{ e.description || TYPE_LABEL[e.type] }}</div>
              <div class="gf-muted">{{ dateLabel(e.occurred_at) }} · {{ e.account || '—' }}<template v-if="app.context === 'general'"> · {{ e.space_code === 'PER' ? 'Personal' : 'LM Studio' }}</template></div>
              <span class="gf-chip" :class="e.status === 'confirmed' ? 'ok' : 'pending'">{{ e.status === 'confirmed' ? 'Confirmado' : 'Pendiente de sincronizar' }}</span>
            </div>
            <div class="gf-amount" :class="{ 'gf-income': e.type === 'income' }">{{ sign(e.type) }}{{ formatAmount(e.amount) }} <span class="gf-muted">{{ e.currency }}</span></div>
          </div>
        </div>

        <div class="gf-section">Próximos cobros y obligaciones</div>
        <div class="gf-panel gf-card"><div class="gf-empty">Sueldos, servicios, tarjetas, cuotas y vencimientos aparecerán aquí cuando existan los módulos de recurrencias y tarjetas.</div></div>
        <div style="height: 24px" />
      </template>
    </IonContent>

    <AccountModal :open="modal === 'account'" @close="modal = null" />
  </IonPage>
</template>
