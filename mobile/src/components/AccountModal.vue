<script setup lang="ts">
import { IonButton, IonButtons, IonContent, IonHeader, IonInput, IonItem, IonModal, IonSelect, IonSelectOption, IonTitle, IonToolbar } from '@ionic/vue'
import { computed, ref, watch } from 'vue'
import { createAccount } from '@/data/repo'
import type { FinancialKind } from '@/domain/types'
import { useApp } from '@/stores/app'
import { parseAmountInput } from './amount'

const props = defineProps<{ open: boolean }>()
const emit = defineEmits<{ close: [] }>()
const app = useApp()

const KINDS: { value: FinancialKind; label: string }[] = [
  { value: 'bank', label: 'Banco' }, { value: 'wallet', label: 'Billetera virtual' }, { value: 'virtual', label: 'Cuenta virtual (PayPal, Wise…)' },
  { value: 'cash', label: 'Efectivo' }, { value: 'usd_cash', label: 'Dólares físicos' }, { value: 'digital_usd', label: 'Dólares digitales (USDT, USDC…)' },
  { value: 'credit_card', label: 'Tarjeta de crédito (pasivo)' }, { value: 'other', label: 'Otro' },
]

const spaceId = ref('')
const name = ref('')
const alias = ref('')
const kind = ref<FinancialKind>('bank')
const rows = ref<{ currency: string; opening: string }[]>([{ currency: 'ARS', opening: '' }])
const closingDay = ref('')
const dueDay = ref('')
const isCard = computed(() => kind.value === 'credit_card')

watch(() => props.open, (o) => {
  if (!o) return
  spaceId.value = app.context === 'general' ? '' : app.activeSpaceIds[0]
  name.value = alias.value = closingDay.value = dueDay.value = ''
  kind.value = 'bank'
  rows.value = [{ currency: 'ARS', opening: '' }]
})

async function submit() {
  if (!spaceId.value) { app.message = 'Elige el espacio de la cuenta.'; return }
  const currencies: { currency: string; openingBalance: string }[] = []
  for (const r of rows.value) {
    const opening = r.opening.trim() === '' ? '0' : parseAmountInput(r.opening)
    if (opening === null) { app.message = `Saldo inicial inválido en ${r.currency}.`; return }
    currencies.push({ currency: r.currency, openingBalance: opening })
  }
  if (new Set(currencies.map((c) => c.currency)).size !== currencies.length) { app.message = 'No repitas la misma moneda en una cuenta.'; return }
  let closing: number | undefined
  let due: number | undefined
  if (isCard.value) {
    closing = Number(closingDay.value)
    due = Number(dueDay.value)
    if (![closing, due].every((d) => Number.isInteger(d) && d >= 1 && d <= 28)) { app.message = 'Indica el día de cierre y el de vencimiento de la tarjeta (de 1 a 28).'; return }
  }
  const ok = await app.write((db) => createAccount(db, { spaceId: spaceId.value, name: name.value, alias: alias.value, kind: kind.value, closingDay: closing, dueDay: due, currencies }))
  if (ok) emit('close')
}
</script>

<template>
  <IonModal :is-open="open" @didDismiss="emit('close')">
    <IonHeader>
      <IonToolbar>
        <IonTitle>Nueva cuenta</IonTitle>
        <IonButtons slot="end"><IonButton @click="emit('close')">Cancelar</IonButton></IonButtons>
      </IonToolbar>
    </IonHeader>
    <IonContent class="ion-padding">
      <IonItem v-if="app.context === 'general'">
        <IonSelect v-model="spaceId" label="Espacio" label-placement="stacked" placeholder="Elegir">
          <IonSelectOption v-for="s in app.spaces" :key="s.id" :value="s.id">{{ s.name }}</IonSelectOption>
        </IonSelect>
      </IonItem>
      <IonItem><IonInput v-model="name" label="Nombre" label-placement="stacked" placeholder="Ej.: Banco Macro" /></IonItem>
      <IonItem><IonInput v-model="alias" label="Alias (opcional)" label-placement="stacked" /></IonItem>
      <IonItem>
        <IonSelect v-model="kind" label="Tipo" label-placement="stacked">
          <IonSelectOption v-for="k in KINDS" :key="k.value" :value="k.value">{{ k.label }}</IonSelectOption>
        </IonSelect>
      </IonItem>

      <template v-if="isCard">
        <p class="gf-section" style="padding-left: 0">Resumen de la tarjeta</p>
        <IonItem><IonInput v-model="closingDay" type="number" inputmode="numeric" min="1" max="28" label="Día de cierre" label-placement="stacked" placeholder="Ej.: 20" helper-text="Las compras hasta ese día entran en el resumen del mes." /></IonItem>
        <IonItem><IonInput v-model="dueDay" type="number" inputmode="numeric" min="1" max="28" label="Día de vencimiento" label-placement="stacked" placeholder="Ej.: 5" helper-text="Si es anterior al cierre, vence el mes siguiente." /></IonItem>
      </template>

      <p class="gf-section" style="padding-left: 0">Monedas y {{ isCard ? 'deuda inicial' : 'saldo inicial' }}</p>
      <div v-for="(r, i) in rows" :key="i" class="gf-row">
        <IonSelect v-model="r.currency" aria-label="Moneda" style="max-width: 110px">
          <IonSelectOption v-for="c in app.currencies" :key="c.code" :value="c.code">{{ c.code }}</IonSelectOption>
        </IonSelect>
        <IonInput v-model="r.opening" inputmode="decimal" :placeholder="isCard ? 'Deuda actual' : 'Saldo actual'" fill="outline" />
        <IonButton v-if="rows.length > 1" fill="clear" color="danger" @click="rows.splice(i, 1)">Quitar</IonButton>
      </div>
      <IonButton fill="clear" @click="rows.push({ currency: 'USD', opening: '' })">+ Agregar otra moneda</IonButton>
      <p class="gf-muted">El saldo inicial genera un asiento de apertura contra patrimonio inicial: no se cuenta como ingreso. Cada moneda mantiene su saldo independiente.</p>

      <IonButton expand="block" class="ion-margin-top" @click="submit">Crear cuenta</IonButton>
    </IonContent>
  </IonModal>
</template>
