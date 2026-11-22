import { add, cmp, isNegative, isZero, MoneyError, norm } from './money'
import type { CategoryInfo, EntryKind, LedgerAccountInfo, LineDraft } from './types'

const UNCATEGORIZED_ALLOWED: EntryKind[] = ['fx', 'adjustment', 'opening']

/**
 * Port en TypeScript de EntryValidator.php: la app valida offline las mismas reglas que el servidor
 * (doble partida, una moneda, espacios, categorías). El servidor siempre vuelve a validar.
 */
export function validateEntry(
  entry: { space_id: string; currency: string; kind: EntryKind },
  lines: LineDraft[],
  accounts: Record<string, LedgerAccountInfo>,
  categories: Record<string, CategoryInfo>,
): string[] {
  const errors: string[] = []
  if (lines.length < 2) errors.push('El asiento necesita al menos dos apuntes.')

  let debit = '0.0000'
  let credit = '0.0000'

  lines.forEach((line, i) => {
    const n = i + 1
    let d: string
    let c: string
    try {
      d = norm(line.debit)
      c = norm(line.credit)
    } catch (e) {
      errors.push(`Apunte ${n}: ${(e as MoneyError).message}`)
      return
    }
    if (isNegative(d) || isNegative(c)) errors.push(`Apunte ${n}: los importes no pueden ser negativos.`)
    if (!isZero(d) === !isZero(c)) errors.push(`Apunte ${n}: debe tener importe en el debe o en el haber, no en ambos ni en ninguno.`)
    debit = add(debit, d)
    credit = add(credit, c)

    const account = accounts[line.ledger_account_id]
    if (!account) {
      errors.push(`Apunte ${n}: la cuenta contable no existe.`)
      return
    }
    if (account.space_id !== entry.space_id) errors.push(`Apunte ${n}: la cuenta contable pertenece a otro espacio.`)
    if (account.is_group) errors.push(`Apunte ${n}: las cuentas agrupadoras no reciben apuntes directos.`)
    if (!account.is_active) errors.push(`Apunte ${n}: la cuenta contable está inactiva.`)
    if (account.currency !== null && account.currency !== entry.currency)
      errors.push(`Apunte ${n}: la moneda de la cuenta (${account.currency}) no coincide con la del asiento (${entry.currency}).`)

    errors.push(...categoryErrors(n, entry, account.type, line.category_id, categories))
  })

  if (cmp(debit, credit) !== 0) errors.push(`El asiento no está equilibrado (debe ${debit} ≠ haber ${credit}).`)
  if (isZero(debit)) errors.push('El asiento no puede tener importe cero.')
  return errors
}

function categoryErrors(
  n: number,
  entry: { space_id: string; kind: EntryKind },
  accountType: string,
  categoryId: string | null,
  categories: Record<string, CategoryInfo>,
): string[] {
  const isPnl = accountType === 'income' || accountType === 'expense'
  if (!isPnl) return categoryId !== null ? [`Apunte ${n}: activos, pasivos y patrimonio no aceptan categorías de ingreso/gasto.`] : []
  if (categoryId === null) return UNCATEGORIZED_ALLOWED.includes(entry.kind) ? [] : [`Apunte ${n}: los ingresos y gastos requieren una categoría.`]

  const cat = categories[categoryId]
  if (!cat) return [`Apunte ${n}: la categoría no existe.`]
  const errors: string[] = []
  if (cat.space_id !== null && cat.space_id !== entry.space_id) errors.push(`Apunte ${n}: la categoría pertenece a otro espacio.`)
  if (!cat.is_active) errors.push(`Apunte ${n}: la categoría está desactivada.`)
  if (cat.kind !== 'both' && cat.kind !== accountType) errors.push(`Apunte ${n}: la categoría es de tipo ${cat.kind} y la cuenta es de ${accountType}.`)
  return errors
}
