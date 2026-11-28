<script setup lang="ts">
import { IonButton, IonButtons, IonContent, IonHeader, IonInput, IonItem, IonLabel, IonModal, IonSegment, IonSegmentButton, IonSelect, IonSelectOption, IonTitle, IonToolbar } from '@ionic/vue'
import { computed, ref, watch } from 'vue'
import { recordCardPurchase, recordIncomeOrExpense, recordTransfer } from '@/data/repo'
import { schedule } from '@/domain/installments'
import { formatAmount } from '@/domain/money'
import { useApp } from '@/stores/app'
import { occurredAtFor, parseAmountInput, todayLocal } from './amount'

const props = defineProps<{ open: boolean; kind: 'income' | 'expense' | 'transfer' }>()
const emit = defineEmits<{ close: [] }>()
const app = useApp()

const kind = ref<'income' | 'expense' | 'transfer'>(props.kind)
const spaceId = ref('')
const accountId = ref('')
const toSpaceId = ref('')
const toAccountId = ref('')
const currency = ref('')
const categoryId = ref('')
const amount = ref('')
const day = ref(todayLocal())
const description = ref('')
const installments = ref('1')
const INSTALLMENT_OPTIONS = ['1', '2', '3', '6', '9', '12', '18', '24']

const title = computed(() => ({ income: 'Nuevo ingreso', expense: 'Nuevo gasto', transfer: 'Transferencia' })[kind.value])
const fixedContext = computed(() => app.context !== 'general')

// Las tarjetas de crédito son pasivos: solo se usan en gastos (compras con cuotas); se pagan desde Tarjetas.
const accountsOf = (space: string) => app.accounts.filter((a) => a.space_id === space && a.is_active && (a.kind !== 'credit_card' || kind.value === 'expense'))
const selectedCard = computed(() => {
  const a = app.accounts.find((x) => x.id === accountId.value)
  return kind.value === 'expense' && a?.kind === 'credit_card' ? a : null
})
const plan = computed(() => {
  const c = selectedCard.value
  const a = parseAmountInput(amount.value)
  if (!c || c.closing_day == null || c.due_day == null || !a) return []
  try { return schedule(day.value, c.closing_day, c.due_day, a, Number(installments.value)) } catch { return [] }
})
const monthLabel = (ym: string) => new Date(Number(ym.slice(0, 4)), Number(ym.slice(5, 7)) - 1, 1).toLocaleDateString('es-AR', { month: 'long', year: 'numeric' })
const dateLabel = (iso: string) => `${iso.slice(8, 10)}/${iso.slice(5, 7)}`
const currenciesOf = (accId: string) => app.accountCurrencies.filter((c) => c.financial_account_id === accId).map((c) => c.currency)
const categories = computed(() => app.categories.filter((c) => (c.space_id === null || c.space_id === spaceId.value) && (c.kind === 'both' || c.kind === kind.value)))
const commonCurrencies = computed(() => currenciesOf(accountId.value).filter((c) => currenciesOf(toAccountId.value).includes(c)))
const currencyOptions = computed(() => (kind.value === 'transfer' ? commonCurrencies.value : currenciesOf(accountId.value)))

watch(() => props.open, (o) => {
  if (!o) return
  kind.value = props.kind
  // En Personal o LM Studio el contexto se hereda; en General hay que elegir el destino (spec §4.8).
  spaceId.value = fixedContext.value ? app.activeSpaceIds[0] : ''
  toSpaceId.value = spaceId.value
  accountId.value = toAccountId.value = currency.value = categoryId.value = amount.value = description.value = ''
  day.value = todayLocal()
  installments.value = '1'
})
watch(spaceId, () => { accountId.value = ''; categoryId.value = '' })
watch(kind, () => { categoryId.value = ''; toAccountId.value = ''; currency.value = currencyOptions.value[0] ?? '' })
watch(toSpaceId, () => { toAccountId.value = '' })
watch([accountId, toAccountId], () => { currency.value = currencyOptions.value[0] ?? ''; installments.value = '1' })

async function submit() {
  const a = parseAmountInput(amount.value)
  if (!a) { app.message = 'Ingresa un importe válido (hasta 4 decimales).'; return }
  if (!accountId.value || !currency.value) { app.message = 'Elige la cuenta y la moneda.'; return }
  const occurredAt = occurredAtFor(day.value)
  let ok: unknown
  if (kind.value === 'transfer') {
    if (!toAccountId.value) { app.message = 'Elige la cuenta de destino.'; return }
    ok = await app.write((db) => recordTransfer(db, { fromSpaceId: spaceId.value, fromAccountId: accountId.value, toSpaceId: toSpaceId.value, toAccountId: toAccountId.value, currency: currency.value, amount: a, occurredAt, description: description.value }))
  } else {
    if (!categoryId.value) { app.message = 'Elige una categoría.'; return }
    if (selectedCard.value) {
      ok = await app.write((db) => recordCardPurchase(db, { spaceId: spaceId.value, financialAccountId: accountId.value, currency: currency.value, amount: a, installments: Number(installments.value), categoryId: categoryId.value, occurredAt, purchaseDate: day.value, description: description.value }))
      if (ok) emit('close')
      return
    }
    ok = await app.write((db) => recordIncomeOrExpense(db, { kind: kind.value as 'income' | 'expense', spaceId: spaceId.value, financialAccountId: accountId.value, currency: currency.value, amount: a, categoryId: categoryId.value, occurredAt, description: description.value }))
  }
  if (ok) emit('close')
}
</script>

<template>
  <IonModal :is-open="open" @didDismiss="emit('close')">
    <IonHeader>
      <IonToolbar>
        <IonTitle>{{ title }}</IonTitle>
        <IonButtons slot="end"><IonButton @click="emit('close')">Cancelar</IonButton></IonButtons>
      </IonToolbar>
    </IonHeader>
    <IonContent class="ion-padding">
      <IonSegment :value="kind" style="margin-bottom: 8px" @ionChange="kind = $event.detail.value as typeof kind">
        <IonSegmentButton value="expense"><IonLabel>Gasto</IonLabel></IonSegmentButton>
        <IonSegmentButton value="income"><IonLabel>Ingreso</IonLabel></IonSegmentButton>
        <IonSegmentButton value="transfer"><IonLabel>Transf.</IonLabel></IonSegmentButton>
      </IonSegment>
      <IonItem v-if="!fixedContext">
        <IonSelect v-model="spaceId" :label="kind === 'transfer' ? 'Espacio de origen' : 'Espacio'" label-placement="stacked" placeholder="Elegir">
          <IonSelectOption v-for="s in app.spaces" :key="s.id" :value="s.id">{{ s.name }}</IonSelectOption>
        </IonSelect>
      </IonItem>
      <IonItem>
        <IonSelect v-model="accountId" :label="kind === 'transfer' ? 'Cuenta de origen' : 'Cuenta'" label-placement="stacked" placeholder="Elegir" :disabled="!spaceId">
          <IonSelectOption v-for="a in accountsOf(spaceId)" :key="a.id" :value="a.id">{{ a.name }}</IonSelectOption>
        </IonSelect>
      </IonItem>

      <template v-if="kind === 'transfer'">
        <IonItem v-if="!fixedContext">
          <IonSelect v-model="toSpaceId" label="Espacio de destino" label-placement="stacked" placeholder="Elegir">
            <IonSelectOption v-for="s in app.spaces" :key="s.id" :value="s.id">{{ s.name }}</IonSelectOption>
          </IonSelect>
        </IonItem>
        <IonItem>
          <IonSelect v-model="toAccountId" label="Cuenta de destino" label-placement="stacked" placeholder="Elegir" :disabled="!toSpaceId">
            <IonSelectOption v-for="a in accountsOf(toSpaceId)" :key="a.id" :value="a.id">{{ a.name }}</IonSelectOption>
          </IonSelect>
        </IonItem>
        <IonItem v-if="fixedContext">
          <IonLabel class="gf-muted ion-text-wrap">Para mover dinero entre Personal y LM Studio, usa el contexto General.</IonLabel>
        </IonItem>
      </template>

      <IonItem>
        <IonSelect v-model="currency" label="Moneda" label-placement="stacked" placeholder="Elegir" :disabled="!currencyOptions.length">
          <IonSelectOption v-for="c in currencyOptions" :key="c" :value="c">{{ c }}</IonSelectOption>
        </IonSelect>
      </IonItem>
      <IonItem>
        <IonInput v-model="amount" label="Importe" label-placement="stacked" inputmode="decimal" placeholder="0,00" />
      </IonItem>
      <IonItem v-if="kind !== 'transfer'">
        <IonSelect v-model="categoryId" label="Categoría" label-placement="stacked" placeholder="Elegir" :disabled="!spaceId">
          <IonSelectOption v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</IonSelectOption>
        </IonSelect>
      </IonItem>
      <IonItem><IonInput v-model="day" type="date" :label="selectedCard ? 'Fecha de compra' : 'Fecha'" label-placement="stacked" /></IonItem>
      <template v-if="selectedCard">
        <IonItem>
          <IonSelect v-model="installments" label="Cuotas (sin interés)" label-placement="stacked">
            <IonSelectOption v-for="n in INSTALLMENT_OPTIONS" :key="n" :value="n">{{ n === '1' ? '1 pago' : n + ' cuotas' }}</IonSelectOption>
          </IonSelect>
        </IonItem>
        <IonItem v-if="selectedCard.closing_day == null || selectedCard.due_day == null">
          <IonLabel class="gf-muted ion-text-wrap">Esta tarjeta no tiene día de cierre y de vencimiento. Configúralos en la pestaña Tarjetas antes de cargar compras.</IonLabel>
        </IonItem>
        <div v-if="plan.length" class="gf-card" style="margin-top: 8px">
          <h4>Cronograma</h4>
          <div v-for="c in plan" :key="c.no" class="gf-row">
            <div><div>Cuota {{ c.no }}</div><div class="gf-muted">Resumen de {{ monthLabel(c.statementMonth) }} · vence {{ dateLabel(c.dueDate) }}</div></div>
            <span class="gf-amount">{{ formatAmount(c.amount) }}</span>
          </div>
          <p class="gf-muted">El gasto se registra completo hoy. Las cuotas solo indican cuánto pagar en cada resumen.</p>
        </div>
      </template>
      <IonItem><IonInput v-model="description" label="Descripción (opcional)" label-placement="stacked" /></IonItem>

      <IonButton expand="block" class="ion-margin-top" @click="submit">Guardar</IonButton>
      <p class="gf-muted">Se guarda en el dispositivo y se sincroniza cuando haya conexión. El servidor la confirma y le asigna su número contable.</p>
    </IonContent>
  </IonModal>
</template>
