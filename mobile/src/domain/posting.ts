import { isNegative, isZero, norm } from './money'
import type { LineDraft, Posting } from './types'

/** Port de Posting.php para las operaciones que la app crea offline. */
const ZERO = '0.0000'

function positive(amount: string): string {
  const a = norm(amount)
  if (isNegative(a) || isZero(a)) throw new Error('El importe debe ser mayor que cero.')
  return a
}
const line = (id: string, debit: string, credit: string, categoryId: string | null = null): LineDraft => ({
  ledger_account_id: id,
  debit,
  credit,
  category_id: categoryId,
})
const build = (spaceId: string, currency: string, kind: Posting['entry']['kind'], occurredAt: string, description: string, lines: LineDraft[]): Posting => ({
  entry: { space_id: spaceId, currency, kind, occurred_at: occurredAt, description },
  lines,
})

export function income(spaceId: string, currency: string, funds: string, incomeAccount: string, amount: string, categoryId: string, occurredAt: string, description = ''): Posting {
  const a = positive(amount)
  return build(spaceId, currency, 'ordinary', occurredAt, description, [line(funds, a, ZERO), line(incomeAccount, ZERO, a, categoryId)])
}

export function expense(spaceId: string, currency: string, funds: string, expenseAccount: string, amount: string, categoryId: string, occurredAt: string, description = ''): Posting {
  const a = positive(amount)
  return build(spaceId, currency, 'ordinary', occurredAt, description, [line(expenseAccount, a, ZERO, categoryId), line(funds, ZERO, a)])
}

export function ownTransfer(spaceId: string, currency: string, from: string, to: string, amount: string, occurredAt: string, description = ''): Posting {
  if (from === to) throw new Error('Origen y destino de la transferencia no pueden ser la misma cuenta.')
  const a = positive(amount)
  return build(spaceId, currency, 'ordinary', occurredAt, description, [line(to, a, ZERO), line(from, ZERO, a)])
}

export function interSpaceTransfer(
  fromSpace: string, fromFunds: string, fromClearing: string,
  toSpace: string, toFunds: string, toClearing: string,
  currency: string, amount: string, occurredAt: string, description = '',
): { out: Posting; in: Posting } {
  if (fromSpace === toSpace) throw new Error('Una transferencia entre espacios requiere espacios distintos.')
  const a = positive(amount)
  return {
    out: build(fromSpace, currency, 'ordinary', occurredAt, description, [line(fromClearing, a, ZERO), line(fromFunds, ZERO, a)]),
    in: build(toSpace, currency, 'ordinary', occurredAt, description, [line(toFunds, a, ZERO), line(toClearing, ZERO, a)]),
  }
}

export function opening(spaceId: string, currency: string, accountId: string, accountType: 'asset' | 'liability', equityAccount: string, amount: string, occurredAt: string): Posting {
  const a = positive(amount)
  const lines = accountType === 'asset' ? [line(accountId, a, ZERO), line(equityAccount, ZERO, a)] : [line(equityAccount, a, ZERO), line(accountId, ZERO, a)]
  return build(spaceId, currency, 'opening', occurredAt, 'Saldo inicial', lines)
}
