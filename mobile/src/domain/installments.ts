import { fromUnits, MoneyError, toUnits } from './money'

export const MAX_INSTALLMENTS = 60

export interface Installment { no: number; amount: string; statementMonth: string; dueDate: string }

const pad = (n: number, w = 2) => String(n).padStart(w, '0')

/**
 * Cronograma de cuotas sin interés (decisión D-5). Debe dar exactamente lo mismo que App\Domain\Cards\Installments en el servidor:
 * una compra hasta el día de cierre entra en el resumen de ese mes, la cuota k va k-1 meses después y el resto de la división
 * se suma a la última cuota.
 */
export function schedule(purchaseDate: string, closingDay: number, dueDay: number, amount: string, count: number): Installment[] {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(purchaseDate)
  if (!m) throw new MoneyError('Fecha de compra inválida.')
  const [y, mo, d] = [Number(m[1]), Number(m[2]), Number(m[3])]
  const check = new Date(Date.UTC(y, mo - 1, d))
  if (check.getUTCFullYear() !== y || check.getUTCMonth() !== mo - 1 || check.getUTCDate() !== d) throw new MoneyError('Fecha de compra inválida.')
  for (const x of [closingDay, dueDay]) if (!Number.isInteger(x) || x < 1 || x > 28) throw new MoneyError('El día de cierre y el de vencimiento deben estar entre 1 y 28.')
  if (!Number.isInteger(count) || count < 1 || count > MAX_INSTALLMENTS) throw new MoneyError(`La cantidad de cuotas debe estar entre 1 y ${MAX_INSTALLMENTS}.`)
  const units = toUnits(amount)
  if (units <= 0n) throw new MoneyError('El importe debe ser mayor que cero.')

  const first = y * 12 + (mo - 1) + (d <= closingDay ? 0 : 1)
  const dueExtra = dueDay <= closingDay ? 1 : 0
  const n = BigInt(count)
  const base = units / n
  const rest = units - base * n

  const out: Installment[] = []
  for (let k = 0; k < count; k++) {
    const s = first + k
    const due = s + dueExtra
    out.push({
      no: k + 1,
      amount: fromUnits(base + (k === count - 1 ? rest : 0n)),
      statementMonth: `${pad(Math.floor(s / 12), 4)}-${pad((s % 12) + 1)}`,
      dueDate: `${pad(Math.floor(due / 12), 4)}-${pad((due % 12) + 1)}-${pad(dueDay)}`,
    })
  }
  return out
}
