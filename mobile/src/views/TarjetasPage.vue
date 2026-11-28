<script setup lang="ts">
import { alertController, IonButton, IonContent, IonHeader, IonLabel, IonPage, IonSegment, IonSegmentButton, IonTitle, IonToolbar } from '@ionic/vue'
import { computed } from 'vue'
import { recordCardPayment, setCardDays, type Statement } from '@/data/repo'
import { formatAmount, fromUnits, toUnits } from '@/domain/money'
import { useApp, type Context } from '@/stores/app'

const app = useApp()
const today = () => new Date().toISOString().slice(0, 10)
const monthLabel = (ym: string) => new Date(Number(ym.slice(0, 4)), Number(ym.slice(5, 7)) - 1, 1).toLocaleDateString('es-AR', { month: 'long', year: 'numeric' })
const dateLabel = (iso: string) => `${iso.slice(8, 10)}/${iso.slice(5, 7)}/${iso.slice(0, 4)}`
const pad = (n: number) => String(n).padStart(2, '0')

const cards = computed(() => app.accounts.filter((a) => a.kind === 'credit_card' && a.is_active && app.activeSpaceIds.includes(a.space_id)))
const debts = computed(() => {
  const m: Record<string, bigint> = {}
  for (const b of app.balances.filter((x) => x.kind === 'credit_card')) m[b.currency] = (m[b.currency] ?? 0n) + toUnits(b.confirmed)
  return Object.entries(m).filter(([, v]) => v !== 0n).map(([c, v]) => [c, fromUnits(v)] as const)
})
const upcoming = computed(() => app.statements.filter((s) => toUnits(s.open) > 0n))
const paid = computed(() => app.statements.filter((s) => toUnits(s.open) === 0n).reverse())

/** Un resumen se puede pagar cuando ya cerró (el día de cierre del mes del resumen ya pasó). */
function closeDate(s: Statement): string | null {
  const c = app.accounts.find((a) => a.id === s.financialAccountId)
  return c?.closing_day == null ? null : `${s.statementMonth}-${pad(c.closing_day)}`
}
const isClosed = (s: Statement) => { const d = closeDate(s); return d !== null && d <= today() }

async function pay(s: Statement) {
  const funds = app.accounts.filter((a) => a.space_id === s.spaceId && a.kind !== 'credit_card' && a.is_active && app.accountCurrencies.some((c) => c.financial_account_id === a.id && c.currency === s.currency))
  if (!funds.length) { app.message = `No hay una cuenta en ${s.currency} desde la que pagar.`; return }
  const alert = await alertController.create({
    header: `Pagar ${monthLabel(s.statementMonth)}`,
    message: `${formatAmount(s.open)} ${s.currency} desde:`,
    inputs: funds.map((f, i) => ({ type: 'radio' as const, label: f.name, value: f.id, checked: i === 0 })),
    buttons: [
      { text: 'Cancelar', role: 'cancel' },
      { text: 'Pagar resumen', handler: (from: string) => { void app.write((db) => recordCardPayment(db, { spaceId: s.spaceId, financialAccountId: s.financialAccountId, fromAccountId: from, currency: s.currency, statementMonth: s.statementMonth, occurredAt: new Date().toISOString() })) } },
    ],
  })
  await alert.present()
}

async function editDays(cardId: string, closing: number | null, due: number | null) {
  const alert = await alertController.create({
    header: 'Cierre y vencimiento',
    message: 'Días del mes, de 1 a 28. Las compras ya cargadas conservan su cronograma.',
    inputs: [
      { name: 'closing', type: 'number', placeholder: 'Día de cierre', value: closing ?? '', min: 1, max: 28 },
      { name: 'due', type: 'number', placeholder: 'Día de vencimiento', value: due ?? '', min: 1, max: 28 },
    ],
    buttons: [
      { text: 'Cancelar', role: 'cancel' },
      { text: 'Guardar', handler: (v: { closing: string; due: string }) => { void app.write((db) => setCardDays(db, cardId, Number(v.closing), Number(v.due))) } },
    ],
  })
  await alert.present()
}
</script>

<template>
  <IonPage>
    <IonHeader>
      <IonToolbar><IonTitle>Tarjetas</IonTitle></IonToolbar>
      <IonToolbar>
        <IonSegment :value="app.context" @ionChange="app.setContext($event.detail.value as Context)">
          <IonSegmentButton value="general"><IonLabel>General</IonLabel></IonSegmentButton>
          <IonSegmentButton value="PER"><IonLabel>Personal</IonLabel></IonSegmentButton>
          <IonSegmentButton value="LMS"><IonLabel>LM Studio</IonLabel></IonSegmentButton>
        </IonSegment>
      </IonToolbar>
    </IonHeader>
    <IonContent>
      <div class="gf-panel gf-card" style="padding-top: 14px; padding-bottom: 14px">
        <div class="gf-muted">Deuda total en tarjetas</div>
        <div v-for="[c, v] in debts" :key="c" style="font-size: 26px; font-weight: 700; font-variant-numeric: tabular-nums">{{ formatAmount(v) }} <span class="gf-muted" style="font-size: 14px">{{ c }}</span></div>
        <div v-if="!debts.length" style="font-size: 26px; font-weight: 700">—</div>
        <div class="gf-muted">Incluye las cuotas de resúmenes futuros. Solo lo confirmado por el servidor.</div>
      </div>

      <div class="gf-section">Próximos resúmenes</div>
      <div v-if="!upcoming.length" class="gf-panel gf-card"><div class="gf-empty">No hay cuotas pendientes. Cargá un gasto con tarjeta desde el botón Nuevo.</div></div>
      <div v-for="s in upcoming" :key="s.financialAccountId + s.currency + s.statementMonth" class="gf-panel gf-card">
        <div class="gf-row" style="border: 0">
          <div style="min-width: 0">
            <div style="text-transform: capitalize">{{ monthLabel(s.statementMonth) }}</div>
            <div class="gf-muted">{{ s.cardName }} · vence {{ dateLabel(s.dueDate) }}</div>
            <span v-if="s.pending" class="gf-chip pending">Pendiente de sincronizar</span>
          </div>
          <div style="text-align: right">
            <div class="gf-amount">{{ formatAmount(s.open) }} <span class="gf-muted">{{ s.currency }}</span></div>
            <IonButton v-if="isClosed(s)" size="small" fill="outline" @click="pay(s)">Pagar resumen</IonButton>
            <div v-else class="gf-muted">{{ closeDate(s) ? 'Cierra el ' + dateLabel(closeDate(s)!) : 'Sin día de cierre' }}</div>
          </div>
        </div>
      </div>

      <div v-if="paid.length" class="gf-section">Pagados</div>
      <div v-for="s in paid" :key="'p' + s.financialAccountId + s.currency + s.statementMonth" class="gf-panel gf-card">
        <div class="gf-row" style="border: 0">
          <div><div style="text-transform: capitalize">{{ monthLabel(s.statementMonth) }}</div><div class="gf-muted">{{ s.cardName }} · venció {{ dateLabel(s.dueDate) }}</div></div>
          <div class="gf-amount">{{ formatAmount(s.paid) }} <span class="gf-muted">{{ s.currency }}</span></div>
        </div>
      </div>

      <div class="gf-section">Mis tarjetas</div>
      <div v-if="!cards.length" class="gf-panel gf-card"><div class="gf-empty">Aún no hay tarjetas. Crealas desde Inicio, en Nueva cuenta, con tipo Tarjeta de crédito.</div></div>
      <div v-for="c in cards" :key="c.id" class="gf-panel gf-card">
        <div class="gf-row" style="border: 0">
          <div>
            <div>{{ c.name }}</div>
            <div class="gf-muted">{{ c.closing_day != null && c.due_day != null ? `Cierra el ${c.closing_day} · vence el ${c.due_day}` : 'Sin cierre ni vencimiento: configurá estos días para cargar compras' }}</div>
          </div>
          <IonButton size="small" fill="clear" @click="editDays(c.id, c.closing_day, c.due_day)">{{ c.closing_day != null ? 'Editar' : 'Configurar' }}</IonButton>
        </div>
      </div>
      <p class="gf-muted" style="padding: 4px 16px">Pagar un resumen paga todas sus cuotas desde la cuenta que elijas. No se pagan cuotas sueltas.</p>
      <div style="height: 24px" />
    </IonContent>
  </IonPage>
</template>
