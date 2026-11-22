<script setup lang="ts">
import { IonButton, IonButtons, IonContent, IonHeader, IonInput, IonItem, IonLabel, IonModal, IonSelect, IonSelectOption, IonTitle, IonToolbar } from '@ionic/vue'
import { computed, ref, watch } from 'vue'
import { recordIncomeOrExpense, recordTransfer } from '@/data/repo'
import { useApp } from '@/stores/app'
import { occurredAtFor, parseAmountInput, todayLocal } from './amount'

const props = defineProps<{ open: boolean; kind: 'income' | 'expense' | 'transfer' }>()
const emit = defineEmits<{ close: [] }>()
const app = useApp()

const spaceId = ref('')
const accountId = ref('')
const toSpaceId = ref('')
const toAccountId = ref('')
const currency = ref('')
const categoryId = ref('')
const amount = ref('')
const day = ref(todayLocal())
const description = ref('')

const title = computed(() => ({ income: 'Nuevo ingreso', expense: 'Nuevo gasto', transfer: 'Transferencia' })[props.kind])
const fixedContext = computed(() => app.context !== 'general')

// Las tarjetas de crédito son pasivos: no se usan como cuenta de fondos en este formulario (compras en cuotas: roadmap).
const accountsOf = (space: string) => app.accounts.filter((a) => a.space_id === space && a.kind !== 'credit_card' && a.is_active)
const currenciesOf = (accId: string) => app.accountCurrencies.filter((c) => c.financial_account_id === accId).map((c) => c.currency)
const categories = computed(() => app.categories.filter((c) => (c.space_id === null || c.space_id === spaceId.value) && (c.kind === 'both' || c.kind === props.kind)))
const commonCurrencies = computed(() => currenciesOf(accountId.value).filter((c) => currenciesOf(toAccountId.value).includes(c)))
const currencyOptions = computed(() => (props.kind === 'transfer' ? commonCurrencies.value : currenciesOf(accountId.value)))

watch(() => props.open, (o) => {
  if (!o) return
  // En Personal o LM Studio el contexto se hereda; en General hay que elegir el destino (spec §4.8).
  spaceId.value = fixedContext.value ? app.activeSpaceIds[0] : ''
  toSpaceId.value = spaceId.value
  accountId.value = toAccountId.value = currency.value = categoryId.value = amount.value = description.value = ''
  day.value = todayLocal()
})
watch(spaceId, () => { accountId.value = ''; categoryId.value = '' })
watch(toSpaceId, () => { toAccountId.value = '' })
watch([accountId, toAccountId], () => { currency.value = currencyOptions.value[0] ?? '' })

async function submit() {
  const a = parseAmountInput(amount.value)
  if (!a) { app.message = 'Ingresa un importe válido (hasta 4 decimales).'; return }
  if (!accountId.value || !currency.value) { app.message = 'Elige la cuenta y la moneda.'; return }
  const occurredAt = occurredAtFor(day.value)
  let ok: unknown
  if (props.kind === 'transfer') {
    if (!toAccountId.value) { app.message = 'Elige la cuenta de destino.'; return }
    ok = await app.write((db) => recordTransfer(db, { fromSpaceId: spaceId.value, fromAccountId: accountId.value, toSpaceId: toSpaceId.value, toAccountId: toAccountId.value, currency: currency.value, amount: a, occurredAt, description: description.value }))
  } else {
    if (!categoryId.value) { app.message = 'Elige una categoría.'; return }
    ok = await app.write((db) => recordIncomeOrExpense(db, { kind: props.kind as 'income' | 'expense', spaceId: spaceId.value, financialAccountId: accountId.value, currency: currency.value, amount: a, categoryId: categoryId.value, occurredAt, description: description.value }))
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
      <IonItem><IonInput v-model="day" type="date" label="Fecha" label-placement="stacked" /></IonItem>
      <IonItem><IonInput v-model="description" label="Descripción (opcional)" label-placement="stacked" /></IonItem>

      <IonButton expand="block" class="ion-margin-top" @click="submit">Guardar</IonButton>
      <p class="gf-muted">Se guarda en el dispositivo y se sincroniza cuando haya conexión. El servidor la confirma y le asigna su número contable.</p>
    </IonContent>
  </IonModal>
</template>
