import type { SqlDriver } from '@/db/driver'
import { schedule } from '@/domain/installments'
import { cmp, fromUnits, isZero, sub, toUnits } from '@/domain/money'
import * as P from '@/domain/posting'
import type { AccountType, CategoryInfo, FinancialKind, LedgerAccountInfo, Posting } from '@/domain/types'
import { uuid } from '@/domain/uuid'
import { validateEntry } from '@/domain/validator'

export class DomainError extends Error {
  constructor(public errors: string[]) {
    super(errors.join(' | '))
  }
}

const nowIso = (): string => new Date().toISOString()

// ----------------------------------------------------------------- referencias

export interface Space { id: string; code: string; name: string }
export interface FinancialAccountRow {
  id: string; space_id: string; name: string; alias: string | null; kind: FinancialKind
  is_active: number; is_archived: number; version: number; pending: number
  closing_day: number | null; due_day: number | null
}
export interface AccountCurrencyRow { id: string; space_id: string; financial_account_id: string; currency: string; ledger_account_id: string }
export interface CategoryRow { id: string; space_id: string | null; parent_id: string | null; name: string; kind: string; group_key: string | null; is_active: number }

export const listSpaces = (db: SqlDriver) => db.all<Space>('SELECT id, code, name FROM spaces ORDER BY code DESC')
export const listCategories = (db: SqlDriver) =>
  db.all<CategoryRow>('SELECT id, space_id, parent_id, name, kind, group_key, is_active FROM categories WHERE is_active = 1 ORDER BY name')
export const listFinancialAccounts = (db: SqlDriver) =>
  db.all<FinancialAccountRow>('SELECT * FROM financial_accounts WHERE is_archived = 0 ORDER BY name')
export const listAccountCurrencies = (db: SqlDriver) => db.all<AccountCurrencyRow>('SELECT * FROM account_currencies')
export const listCurrencies = (db: SqlDriver) => db.all<{ code: string; name: string }>('SELECT code, name FROM currencies ORDER BY code')

export async function getMeta(db: SqlDriver, key: string): Promise<string | null> {
  const r = await db.all<{ value: string }>('SELECT value FROM meta WHERE key = ?', [key])
  return r.length ? r[0].value : null
}
export const setMeta = (db: SqlDriver, key: string, value: string) =>
  db.run('INSERT INTO meta(key, value) VALUES(?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value', [key, value])

async function ledgerMap(db: SqlDriver): Promise<Record<string, LedgerAccountInfo>> {
  const rows = await db.all<{ id: string; space_id: string; type: AccountType; is_group: number; is_active: number; currency: string | null }>('SELECT id, space_id, type, is_group, is_active, currency FROM ledger_accounts')
  return Object.fromEntries(rows.map((r) => [r.id, { space_id: r.space_id, type: r.type, is_group: !!r.is_group, is_active: !!r.is_active, currency: r.currency }]))
}
async function categoryMap(db: SqlDriver): Promise<Record<string, CategoryInfo>> {
  const rows = await db.all<{ id: string; space_id: string | null; kind: CategoryInfo['kind']; is_active: number }>('SELECT id, space_id, kind, is_active FROM categories')
  return Object.fromEntries(rows.map((r) => [r.id, { space_id: r.space_id, kind: r.kind, is_active: !!r.is_active }]))
}

async function systemAccount(db: SqlDriver, spaceId: string, role: string): Promise<string> {
  const r = await db.all<{ id: string }>('SELECT id FROM ledger_accounts WHERE space_id = ? AND system_role = ? LIMIT 1', [spaceId, role])
  if (!r.length) throw new DomainError(['Faltan datos del servidor: sincroniza al menos una vez con conexión antes de operar.'])
  return r[0].id
}

async function fundsLedger(db: SqlDriver, spaceId: string, financialAccountId: string, currency: string): Promise<string> {
  const r = await db.all<{ ledger_account_id: string }>(
    'SELECT ledger_account_id FROM account_currencies WHERE space_id = ? AND financial_account_id = ? AND currency = ?',
    [spaceId, financialAccountId, currency],
  )
  if (!r.length) throw new DomainError([`La cuenta no opera en ${currency}.`])
  return r[0].ledger_account_id
}

// ----------------------------------------------------------------- escritura local + cola

interface OutboxOp { entity: string; action: string; payload: Record<string, unknown>; baseVersion?: number | null }

async function enqueue(db: SqlDriver, op: OutboxOp, id = uuid()): Promise<string> {
  await db.run(
    'INSERT INTO outbox(id, entity, action, payload, base_version, client_created_at) VALUES(?, ?, ?, ?, ?, ?)',
    [id, op.entity, op.action, JSON.stringify(op.payload), op.baseVersion ?? null, nowIso()],
  )
  return id
}

/** Inserta un asiento provisional (pendiente de sincronizar). Los borradores/pendientes no cuentan como saldo confirmado. */
async function insertProvisional(db: SqlDriver, posting: Posting, entryId: string, syncOpId: string, operationId: string): Promise<void> {
  const e = posting.entry
  await db.run(
    `INSERT INTO entries(id, space_id, operation_id, number, status, kind, currency, occurred_at, accounting_date, description, sync_op_id)
     VALUES(?, ?, ?, NULL, 'pending', ?, ?, ?, NULL, ?, ?)`,
    [entryId, e.space_id, operationId, e.kind, e.currency, e.occurred_at, e.description, syncOpId],
  )
  for (const [i, l] of posting.lines.entries()) {
    await db.run(
      'INSERT INTO lines(id, entry_id, space_id, line_no, ledger_account_id, debit, credit, category_id) VALUES(?, ?, ?, ?, ?, ?, ?, ?)',
      [uuid(), entryId, e.space_id, i + 1, l.ledger_account_id, l.debit, l.credit, l.category_id],
    )
  }
}

async function validateOrThrow(db: SqlDriver, posting: Posting): Promise<void> {
  const errors = validateEntry(posting.entry, posting.lines, await ledgerMap(db), await categoryMap(db))
  if (errors.length) throw new DomainError(errors)
}

export interface NewAccountInput {
  spaceId: string
  name: string
  alias?: string
  kind: FinancialKind
  currencies: { currency: string; openingBalance: string }[]
  /** Solo tarjetas de crédito: días de cierre y de vencimiento (1 a 28). */
  closingDay?: number
  dueDay?: number
}

function checkCardDays(closing?: number | null, due?: number | null): void {
  for (const d of [closing, due]) if (d != null && (!Number.isInteger(d) || d < 1 || d > 28)) throw new DomainError(['El día de cierre y el de vencimiento deben estar entre 1 y 28.'])
}

export async function createAccount(db: SqlDriver, input: NewAccountInput): Promise<string> {
  if (!input.name.trim()) throw new DomainError(['El nombre es obligatorio.'])
  if (!input.currencies.length) throw new DomainError(['La cuenta necesita al menos una moneda.'])
  const isCard = input.kind === 'credit_card'
  if (!isCard && (input.closingDay != null || input.dueDay != null)) throw new DomainError(['El cierre y el vencimiento solo aplican a tarjetas de crédito.'])
  checkCardDays(input.closingDay, input.dueDay)
  const id = uuid()
  const opId = uuid()

  return db.transaction(async () => {
    await db.run('INSERT INTO financial_accounts(id, space_id, name, alias, kind, closing_day, due_day, pending) VALUES(?, ?, ?, ?, ?, ?, ?, 1)', [id, input.spaceId, input.name.trim(), input.alias?.trim() || null, input.kind, input.closingDay ?? null, input.dueDay ?? null])

    const currencies: Record<string, unknown>[] = []
    for (const c of input.currencies) {
      const ledgerId = uuid()
      const faCurrencyId = uuid()
      await db.run(
        `INSERT INTO ledger_accounts(id, space_id, code, name, type, currency) VALUES(?, ?, ?, ?, ?, ?)`,
        [ledgerId, input.spaceId, `FA-${id.slice(0, 8)}-${c.currency}`, `${input.name.trim()} (${c.currency})`, isCard ? 'liability' : 'asset', c.currency],
      )
      await db.run('INSERT INTO account_currencies(id, space_id, financial_account_id, currency, ledger_account_id) VALUES(?, ?, ?, ?, ?)', [faCurrencyId, input.spaceId, id, c.currency, ledgerId])

      const entry: Record<string, unknown> = { currency: c.currency, ledger_account_id: ledgerId, fa_currency_id: faCurrencyId, opening_balance: c.openingBalance || '0' }
      if (!isZero(c.openingBalance || '0')) {
        const entryId = uuid()
        const posting = P.opening(input.spaceId, c.currency, ledgerId, isCard ? 'liability' : 'asset', await systemAccount(db, input.spaceId, 'equity_opening'), c.openingBalance, nowIso())
        await validateOrThrow(db, posting)
        await insertProvisional(db, posting, entryId, opId, opId)
        entry.opening_entry_id = entryId
      }
      currencies.push(entry)
    }

    await enqueue(
      db,
      { entity: 'financial_account', action: 'create', payload: { id, space_id: input.spaceId, name: input.name.trim(), alias: input.alias?.trim() || null, kind: input.kind, closing_day: input.closingDay ?? null, due_day: input.dueDay ?? null, occurred_at: nowIso(), currencies } },
      opId,
    )
    return id
  })
}

export async function createCategory(db: SqlDriver, input: { spaceId: string; name: string; kind: 'income' | 'expense' | 'both'; parentId?: string }): Promise<string> {
  if (!input.name.trim()) throw new DomainError(['El nombre es obligatorio.'])
  const id = uuid()
  await db.transaction(async () => {
    await db.run('INSERT INTO categories(id, space_id, parent_id, name, kind, pending) VALUES(?, ?, ?, ?, ?, 1)', [id, input.spaceId, input.parentId ?? null, input.name.trim(), input.kind])
    await enqueue(db, { entity: 'category', action: 'create', payload: { id, space_id: input.spaceId, name: input.name.trim(), kind: input.kind, parent_id: input.parentId ?? null } })
  })
  return id
}

export interface MoneyMoveInput {
  kind: 'income' | 'expense'
  spaceId: string
  financialAccountId: string
  currency: string
  amount: string
  categoryId: string
  occurredAt: string
  description?: string
}

export async function recordIncomeOrExpense(db: SqlDriver, i: MoneyMoveInput): Promise<string> {
  const entryId = uuid()
  const opId = uuid()
  return db.transaction(async () => {
    const funds = await fundsLedger(db, i.spaceId, i.financialAccountId, i.currency)
    const posting = i.kind === 'income'
      ? P.income(i.spaceId, i.currency, funds, await systemAccount(db, i.spaceId, 'income_general'), i.amount, i.categoryId, i.occurredAt, i.description)
      : P.expense(i.spaceId, i.currency, funds, await systemAccount(db, i.spaceId, 'expense_general'), i.amount, i.categoryId, i.occurredAt, i.description)
    await validateOrThrow(db, posting)
    await insertProvisional(db, posting, entryId, opId, opId)
    await enqueue(db, {
      entity: 'operation', action: i.kind,
      payload: { entry_id: entryId, space_id: i.spaceId, financial_account_id: i.financialAccountId, currency: i.currency, amount: i.amount, category_id: i.categoryId, occurred_at: i.occurredAt, description: i.description ?? '' },
    }, opId)
    return opId
  })
}

export interface TransferInput {
  fromSpaceId: string
  fromAccountId: string
  toSpaceId: string
  toAccountId: string
  currency: string
  amount: string
  occurredAt: string
  description?: string
}

/** Transferencia entre cuentas propias, o entre espacios (Personal ↔ LM Studio) si los espacios difieren. */
export async function recordTransfer(db: SqlDriver, i: TransferInput): Promise<string> {
  const opId = uuid()
  return db.transaction(async () => {
    const from = await fundsLedger(db, i.fromSpaceId, i.fromAccountId, i.currency)
    const to = await fundsLedger(db, i.toSpaceId, i.toAccountId, i.currency)

    if (i.fromSpaceId === i.toSpaceId) {
      const entryId = uuid()
      const posting = P.ownTransfer(i.fromSpaceId, i.currency, from, to, i.amount, i.occurredAt, i.description)
      await validateOrThrow(db, posting)
      await insertProvisional(db, posting, entryId, opId, opId)
      await enqueue(db, {
        entity: 'operation', action: 'transfer',
        payload: { entry_id: entryId, space_id: i.fromSpaceId, from_financial_account_id: i.fromAccountId, to_financial_account_id: i.toAccountId, currency: i.currency, amount: i.amount, occurred_at: i.occurredAt, description: i.description ?? '' },
      }, opId)
      return opId
    }

    const t = P.interSpaceTransfer(
      i.fromSpaceId, from, await systemAccount(db, i.fromSpaceId, 'interspace_clearing'),
      i.toSpaceId, to, await systemAccount(db, i.toSpaceId, 'interspace_clearing'),
      i.currency, i.amount, i.occurredAt, i.description,
    )
    await validateOrThrow(db, t.out)
    await validateOrThrow(db, t.in)
    const outId = uuid()
    const inId = uuid()
    const operationId = uuid()
    await insertProvisional(db, t.out, outId, opId, operationId)
    await insertProvisional(db, t.in, inId, opId, operationId)
    await enqueue(db, {
      entity: 'operation', action: 'interspace_transfer',
      payload: { entry_id_out: outId, entry_id_in: inId, from_space_id: i.fromSpaceId, from_financial_account_id: i.fromAccountId, to_space_id: i.toSpaceId, to_financial_account_id: i.toAccountId, currency: i.currency, amount: i.amount, occurred_at: i.occurredAt, description: i.description ?? '' },
    }, opId)
    return opId
  })
}


// ----------------------------------------------------------------- tarjetas de crédito (D-5)

/** Fecha local (YYYY-MM-DD) de un instante ISO: el día en que el usuario hizo la compra. */
const localDate = (iso: string): string => {
  const d = new Date(iso)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

async function cardOf(db: SqlDriver, spaceId: string, id: string): Promise<FinancialAccountRow> {
  const r = (await db.all<FinancialAccountRow>('SELECT * FROM financial_accounts WHERE id = ? AND space_id = ?', [id, spaceId]))[0]
  if (!r || r.kind !== 'credit_card') throw new DomainError(['La cuenta no es una tarjeta de crédito de este espacio.'])
  return r
}

/** Define o corrige el cierre y el vencimiento de una tarjeta ya creada. */
export async function setCardDays(db: SqlDriver, accountId: string, closingDay: number, dueDay: number): Promise<void> {
  checkCardDays(closingDay, dueDay)
  if (closingDay == null || dueDay == null) throw new DomainError(['Indica el día de cierre y el de vencimiento.'])
  await db.transaction(async () => {
    const a = (await db.all<FinancialAccountRow>('SELECT * FROM financial_accounts WHERE id = ?', [accountId]))[0]
    if (!a || a.kind !== 'credit_card') throw new DomainError(['La cuenta no es una tarjeta de crédito.'])
    await db.run('UPDATE financial_accounts SET closing_day = ?, due_day = ? WHERE id = ?', [closingDay, dueDay, accountId])
    await enqueue(db, { entity: 'financial_account', action: 'update', payload: { id: accountId, changes: { closing_day: closingDay, due_day: dueDay } }, baseVersion: a.version })
  })
}

export interface CardPurchaseInput {
  spaceId: string
  financialAccountId: string
  currency: string
  amount: string
  installments: number
  categoryId: string
  occurredAt: string
  /** Día local de la compra (YYYY-MM-DD); por defecto, el de occurredAt. */
  purchaseDate?: string
  description?: string
}

/** Compra con tarjeta en 1 a 60 cuotas sin interés: un asiento por el precio completo y el cronograma de cuotas. */
export async function recordCardPurchase(db: SqlDriver, i: CardPurchaseInput): Promise<string> {
  const entryId = uuid()
  const opId = uuid()
  return db.transaction(async () => {
    const card = await cardOf(db, i.spaceId, i.financialAccountId)
    if (card.closing_day == null || card.due_day == null) throw new DomainError(['Configura el día de cierre y el de vencimiento de la tarjeta antes de cargar compras.'])
    const date = i.purchaseDate ?? localDate(i.occurredAt)
    const plan = schedule(date, card.closing_day, card.due_day, i.amount, i.installments)

    const funds = await fundsLedger(db, i.spaceId, i.financialAccountId, i.currency)
    const posting = P.expense(i.spaceId, i.currency, funds, await systemAccount(db, i.spaceId, 'expense_general'), i.amount, i.categoryId, i.occurredAt, i.description)
    await validateOrThrow(db, posting)
    await insertProvisional(db, posting, entryId, opId, opId)

    const ids = plan.map(() => uuid())
    for (const [k, c] of plan.entries()) {
      await db.run(
        `INSERT INTO card_installments(id, space_id, financial_account_id, entry_id, installment_no, installments_total, currency, amount, statement_month, due_date, status, pending)
         VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', 1)`,
        [ids[k], i.spaceId, i.financialAccountId, entryId, c.no, plan.length, i.currency, c.amount, c.statementMonth, c.dueDate],
      )
    }
    await enqueue(db, {
      entity: 'operation', action: 'card_purchase',
      payload: { entry_id: entryId, space_id: i.spaceId, financial_account_id: i.financialAccountId, currency: i.currency, amount: i.amount, installments: plan.length, installment_ids: ids, category_id: i.categoryId, occurred_at: i.occurredAt, purchase_date: date, description: i.description ?? '' },
    }, opId)
    return opId
  })
}

export interface CardPaymentInput {
  spaceId: string
  financialAccountId: string
  fromAccountId: string
  currency: string
  statementMonth: string
  occurredAt: string
}

/** Paga el resumen completo (todas sus cuotas abiertas) desde una cuenta de fondos. */
export async function recordCardPayment(db: SqlDriver, i: CardPaymentInput): Promise<string> {
  const entryId = uuid()
  const opId = uuid()
  return db.transaction(async () => {
    await cardOf(db, i.spaceId, i.financialAccountId)
    const due = await db.all<{ id: string; amount: string }>(
      "SELECT id, amount FROM card_installments WHERE financial_account_id = ? AND currency = ? AND statement_month = ? AND status = 'open'",
      [i.financialAccountId, i.currency, i.statementMonth],
    )
    if (!due.length) throw new DomainError(['Ese resumen no tiene cuotas pendientes de pago.'])
    const total = fromUnits(due.reduce((a, r) => a + toUnits(r.amount), 0n))

    const from = await fundsLedger(db, i.spaceId, i.fromAccountId, i.currency)
    const to = await fundsLedger(db, i.spaceId, i.financialAccountId, i.currency)
    const posting = P.ownTransfer(i.spaceId, i.currency, from, to, total, i.occurredAt, `Pago de resumen ${i.statementMonth}`)
    await validateOrThrow(db, posting)
    await insertProvisional(db, posting, entryId, opId, opId)
    for (const r of due) await db.run("UPDATE card_installments SET status = 'paid', paid_entry_id = ?, pending = 1 WHERE id = ?", [entryId, r.id])
    await enqueue(db, {
      entity: 'operation', action: 'card_payment',
      payload: { entry_id: entryId, space_id: i.spaceId, financial_account_id: i.financialAccountId, from_financial_account_id: i.fromAccountId, currency: i.currency, statement_month: i.statementMonth, occurred_at: i.occurredAt },
    }, opId)
    return opId
  })
}

export interface Statement {
  financialAccountId: string
  cardName: string
  spaceId: string
  currency: string
  statementMonth: string
  dueDate: string
  /** Lo que falta pagar de ese resumen. */
  open: string
  paid: string
  installments: number
  /** Hay operaciones de esa tarjeta que aún no confirmó el servidor. */
  pending: boolean
}

/** Resúmenes por tarjeta, moneda y mes (el más próximo primero). Las cuotas canceladas no cuentan. */
export async function listStatements(db: SqlDriver, spaceIds: string[]): Promise<Statement[]> {
  if (!spaceIds.length) return []
  const ph = spaceIds.map(() => '?').join(',')
  const rows = await db.all<{ financial_account_id: string; name: string; space_id: string; currency: string; statement_month: string; due_date: string; amount: string; status: string; pending: number }>(
    `SELECT c.financial_account_id, a.name, c.space_id, c.currency, c.statement_month, c.due_date, c.amount, c.status, c.pending
       FROM card_installments c JOIN financial_accounts a ON a.id = c.financial_account_id
      WHERE c.space_id IN (${ph}) AND c.status IN ('open','paid')`,
    spaceIds,
  )
  const map = new Map<string, { s: Statement; o: bigint; p: bigint }>()
  for (const r of rows) {
    const key = `${r.financial_account_id}|${r.currency}|${r.statement_month}`
    const cur = map.get(key) ?? { s: { financialAccountId: r.financial_account_id, cardName: r.name, spaceId: r.space_id, currency: r.currency, statementMonth: r.statement_month, dueDate: r.due_date, open: '0.0000', paid: '0.0000', installments: 0, pending: false }, o: 0n, p: 0n }
    if (r.status === 'open') cur.o += toUnits(r.amount); else cur.p += toUnits(r.amount)
    cur.s.installments++
    if (r.due_date > cur.s.dueDate) cur.s.dueDate = r.due_date
    if (r.pending) cur.s.pending = true
    map.set(key, cur)
  }
  return [...map.values()].map(({ s, o, p }) => ({ ...s, open: fromUnits(o), paid: fromUnits(p) })).sort((a, b) => a.statementMonth.localeCompare(b.statementMonth) || a.cardName.localeCompare(b.cardName))
}

// ----------------------------------------------------------------- cola

export interface OutboxRow { seq: number; id: string; entity: string; action: string; payload: string; base_version: number | null; client_created_at: string; status: string; error: string | null; attempts: number }

export const pendingOutbox = (db: SqlDriver, limit = 50) =>
  db.all<OutboxRow>("SELECT * FROM outbox WHERE status = 'pending' ORDER BY seq LIMIT ?", [limit])
export const outboxProblems = (db: SqlDriver) => db.all<OutboxRow>("SELECT * FROM outbox WHERE status IN ('rejected','conflict') ORDER BY seq")
export async function outboxCounts(db: SqlDriver): Promise<{ pending: number; problems: number }> {
  const r = await db.all<{ status: string; n: number }>("SELECT status, COUNT(*) AS n FROM outbox WHERE status IN ('pending','rejected','conflict') GROUP BY status")
  const n = (s: string[]) => r.filter((x) => s.includes(x.status)).reduce((a, x) => a + Number(x.n), 0)
  return { pending: n(['pending']), problems: n(['rejected', 'conflict']) }
}

/** Si una operación de tarjeta no se confirma: sus cuotas desaparecen (compra) o vuelven a quedar abiertas (pago). */
async function undoInstallments(db: SqlDriver, opId: string): Promise<void> {
  const mine = "(SELECT id FROM entries WHERE sync_op_id = ? AND status <> 'confirmed')"
  await db.run(`UPDATE card_installments SET status = 'open', paid_entry_id = NULL WHERE paid_entry_id IN ${mine}`, [opId])
  await db.run(`UPDATE card_installments SET status = 'cancelled' WHERE entry_id IN ${mine} AND pending = 1`, [opId])
}

/** Marca una operación como rechazada/en conflicto; sus asientos provisionales dejan de contar en los saldos. */
export async function markOperation(db: SqlDriver, opId: string, status: 'applied' | 'rejected' | 'conflict', error: string | null, result?: unknown): Promise<void> {
  await db.transaction(async () => {
    await db.run('UPDATE outbox SET status = ?, error = ?, result = ?, attempts = attempts + 1 WHERE id = ?', [status, error, result === undefined ? null : JSON.stringify(result), opId])
    if (status === 'rejected' || status === 'conflict') {
      await undoInstallments(db, opId)
      await db.run("UPDATE entries SET status = 'rejected' WHERE sync_op_id = ? AND status = 'pending'", [opId])
    }
  })
}

/** Descarta una operación rechazada/en conflicto y todo lo provisional que había generado (asientos, cuenta, categoría). */
export async function discardOperation(db: SqlDriver, opId: string): Promise<void> {
  await db.transaction(async () => {
    const op = (await db.all<OutboxRow>('SELECT * FROM outbox WHERE id = ?', [opId]))[0]
    await undoInstallments(db, opId)
    await db.run("DELETE FROM card_installments WHERE status = 'cancelled' AND pending = 1 AND entry_id IN (SELECT id FROM entries WHERE sync_op_id = ? AND status <> 'confirmed')", [opId])
    await db.run("DELETE FROM lines WHERE entry_id IN (SELECT id FROM entries WHERE sync_op_id = ? AND status <> 'confirmed')", [opId])
    await db.run("DELETE FROM entries WHERE sync_op_id = ? AND status <> 'confirmed'", [opId])
    if (op && op.action === 'create') {
      const payload = JSON.parse(op.payload) as { id?: string }
      if (op.entity === 'financial_account' && payload.id) {
        await db.run('DELETE FROM ledger_accounts WHERE id IN (SELECT ledger_account_id FROM account_currencies WHERE financial_account_id = ?)', [payload.id])
        await db.run('DELETE FROM account_currencies WHERE financial_account_id = ?', [payload.id])
        await db.run('DELETE FROM financial_accounts WHERE id = ? AND pending = 1', [payload.id])
      }
      if (op.entity === 'category' && payload.id) await db.run('DELETE FROM categories WHERE id = ? AND pending = 1', [payload.id])
    }
    await db.run('DELETE FROM outbox WHERE id = ?', [opId])
  })
}

// ----------------------------------------------------------------- saldos y dashboard

export interface AccountBalance {
  financialAccountId: string
  name: string
  alias: string | null
  kind: FinancialKind
  spaceId: string
  currency: string
  confirmed: string
  pendingDelta: string
}

interface LineRow {
  debit: string; credit: string; ledger_account_id: string; category_id: string | null
  status: string; currency: string; kind: string; occurred_at: string; accounting_date: string | null
  space_id: string; type: AccountType
}

async function lineRows(db: SqlDriver, spaceIds: string[]): Promise<LineRow[]> {
  if (!spaceIds.length) return []
  const ph = spaceIds.map(() => '?').join(',')
  return db.all<LineRow>(
    `SELECT l.debit, l.credit, l.ledger_account_id, l.category_id, e.status, e.currency, e.kind, e.occurred_at, e.accounting_date, l.space_id, a.type
       FROM lines l JOIN entries e ON e.id = l.entry_id JOIN ledger_accounts a ON a.id = l.ledger_account_id
      WHERE l.space_id IN (${ph}) AND e.status IN ('confirmed','pending')`,
    spaceIds,
  )
}

export async function listBalances(db: SqlDriver, spaceIds: string[]): Promise<AccountBalance[]> {
  if (!spaceIds.length) return []
  const rows = await lineRows(db, spaceIds)
  const accounts = (await listFinancialAccounts(db)).filter((a) => spaceIds.includes(a.space_id))
  const links = await listAccountCurrencies(db)
  const out: AccountBalance[] = []

  for (const a of accounts) {
    for (const link of links.filter((l) => l.financial_account_id === a.id)) {
      let confirmed = 0n
      let pending = 0n
      for (const r of rows) {
        if (r.ledger_account_id !== link.ledger_account_id) continue
        // Activos: debe − haber. Pasivos (tarjetas): haber − debe, para mostrar la deuda como positiva.
        const signed = (toUnits(r.debit) - toUnits(r.credit)) * (a.kind === 'credit_card' ? -1n : 1n)
        if (r.status === 'confirmed') confirmed += signed
        else pending += signed
      }
      out.push({ financialAccountId: a.id, name: a.name, alias: a.alias, kind: a.kind, spaceId: a.space_id, currency: link.currency, confirmed: fromUnits(confirmed), pendingDelta: fromUnits(pending) })
    }
  }
  return out
}

export interface DashboardData {
  netWorth: Record<string, string>
  income: Record<string, string>
  expense: Record<string, string>
  available: Record<string, string>
  pendingAvailableDelta: Record<string, string>
  incomePrev: Record<string, string>
  expensePrev: Record<string, string>
  expenseByCategory: { category: string; currency: string; total: string }[]
  recent: RecentEntry[]
  pendingCount: number
}
export interface RecentEntry {
  id: string; number: string | null; kind: string; currency: string; occurred_at: string; description: string; status: string
  space_code: string; amount: string; type: 'income' | 'expense' | 'transfer' | 'conversion'; account: string
}

const dateOf = (r: { status: string; accounting_date: string | null; occurred_at: string }) => (r.status === 'confirmed' && r.accounting_date ? r.accounting_date : r.occurred_at).slice(0, 10)

function bump(map: Record<string, bigint>, key: string, by: bigint) {
  map[key] = (map[key] ?? 0n) + by
}
const strMap = (m: Record<string, bigint>): Record<string, string> => Object.fromEntries(Object.entries(m).map(([k, v]) => [k, fromUnits(v)]))

export async function computeDashboard(db: SqlDriver, spaceIds: string[], from: string, to: string, prevFrom: string, prevTo: string): Promise<DashboardData> {
  const rows = await lineRows(db, spaceIds)
  const accounts = await listFinancialAccounts(db)
  const links = await listAccountCurrencies(db)
  const liquid = new Set<string>()
  for (const l of links) {
    const fa = accounts.find((a) => a.id === l.financial_account_id)
    if (fa && fa.kind !== 'credit_card') liquid.add(l.ledger_account_id)
  }
  const cats = Object.fromEntries((await listCategories(db)).map((c) => [c.id, c.name]))

  const net: Record<string, bigint> = {}
  const available: Record<string, bigint> = {}
  const pendAvail: Record<string, bigint> = {}
  const inc: Record<string, bigint> = {}
  const exp: Record<string, bigint> = {}
  const incP: Record<string, bigint> = {}
  const expP: Record<string, bigint> = {}
  const byCat: Record<string, bigint> = {}

  for (const r of rows) {
    const d = toUnits(r.debit)
    const c = toUnits(r.credit)
    const confirmed = r.status === 'confirmed'
    if (confirmed) {
      if (r.type === 'asset') bump(net, r.currency, d - c)
      if (r.type === 'liability') bump(net, r.currency, c - d)
      if (liquid.has(r.ledger_account_id)) bump(available, r.currency, d - c)
    } else if (liquid.has(r.ledger_account_id)) {
      bump(pendAvail, r.currency, d - c)
    }
    // Solo lo confirmado cuenta como ingreso/gasto "confirmado" (spec §4.3).
    if (!confirmed) continue
    const day = dateOf(r)
    const inCurrent = day >= from && day <= to
    const inPrev = day >= prevFrom && day <= prevTo
    if (r.type === 'income') {
      if (inCurrent) bump(inc, r.currency, c - d)
      if (inPrev) bump(incP, r.currency, c - d)
    }
    if (r.type === 'expense') {
      if (inCurrent) {
        bump(exp, r.currency, d - c)
        bump(byCat, `${r.currency}|${r.category_id ? cats[r.category_id] ?? 'Sin categoría' : 'Sin categoría'}`, d - c)
      }
      if (inPrev) bump(expP, r.currency, d - c)
    }
  }

  const ph = spaceIds.map(() => '?').join(',')
  const recentWithAmount = await recentEntries(db, spaceIds, 5)

  const pendingCount = (await db.all<{ n: number }>("SELECT COUNT(*) AS n FROM entries WHERE status = 'pending' AND space_id IN (" + ph + ')', spaceIds))[0]?.n ?? 0

  return {
    netWorth: strMap(net), income: strMap(inc), expense: strMap(exp), available: strMap(available), pendingAvailableDelta: strMap(pendAvail),
    incomePrev: strMap(incP), expensePrev: strMap(expP),
    expenseByCategory: Object.entries(byCat).map(([k, v]) => { const [currency, category] = k.split('|'); return { category, currency, total: fromUnits(v) } }).sort((a, b) => cmp(b.total, a.total)),
    recent: recentWithAmount, pendingCount: Number(pendingCount),
  }
}

/** Últimos movimientos (sin asientos de apertura), del más reciente al más antiguo. */
export async function recentEntries(db: SqlDriver, spaceIds: string[], limit: number): Promise<RecentEntry[]> {
  if (!spaceIds.length) return []
  const ph = spaceIds.map(() => '?').join(',')
  const recent = await db.all<Omit<RecentEntry, 'amount' | 'type' | 'account'>>(
    `SELECT e.id, e.number, e.kind, e.currency, e.occurred_at, e.description, e.status, s.code AS space_code
       FROM entries e JOIN spaces s ON s.id = e.space_id
      WHERE e.space_id IN (${ph}) AND e.status IN ('confirmed','pending') AND e.kind <> 'opening'
      ORDER BY e.occurred_at DESC LIMIT ${Math.max(1, Math.floor(limit))}`,
    spaceIds,
  )
  const out: RecentEntry[] = []
  for (const e of recent) {
    const ls = await db.all<{ debit: string; type: string; system_role: string | null; name: string }>(
      'SELECT l.debit, a.type, a.system_role, a.name FROM lines l JOIN ledger_accounts a ON a.id = l.ledger_account_id WHERE l.entry_id = ? ORDER BY l.line_no',
      [e.id],
    )
    const type: RecentEntry['type'] = ls.some((l) => l.type === 'income') ? 'income' : ls.some((l) => l.type === 'expense') && e.kind !== 'fx' ? 'expense' : e.kind === 'fx' ? 'conversion' : 'transfer'
    const account = ls.filter((l) => l.system_role === null && (l.type === 'asset' || l.type === 'liability')).map((l) => l.name).join(' → ')
    out.push({ ...e, type, account, amount: fromUnits(ls.reduce((a, l) => a + toUnits(l.debit), 0n)) })
  }
  return out
}

export interface AnnualRow { year: string; income: Record<string, string>; expense: Record<string, string>; result: Record<string, string> }

/** Ingresos y gastos confirmados por año contable y moneda (nunca se mezclan monedas). Años más recientes primero. */
export async function computeAnnual(db: SqlDriver, spaceIds: string[]): Promise<AnnualRow[]> {
  const rows = await lineRows(db, spaceIds)
  const inc: Record<string, Record<string, bigint>> = {}
  const exp: Record<string, Record<string, bigint>> = {}
  for (const r of rows) {
    if (r.status !== 'confirmed') continue
    const year = dateOf(r).slice(0, 4)
    const d = toUnits(r.debit)
    const c = toUnits(r.credit)
    if (r.type === 'income') bump((inc[year] ??= {}), r.currency, c - d)
    if (r.type === 'expense') bump((exp[year] ??= {}), r.currency, d - c)
  }
  const years = [...new Set([...Object.keys(inc), ...Object.keys(exp)])].sort().reverse()
  return years.map((year) => {
    const i = inc[year] ?? {}
    const e = exp[year] ?? {}
    const res: Record<string, bigint> = {}
    for (const k of new Set([...Object.keys(i), ...Object.keys(e)])) res[k] = (i[k] ?? 0n) - (e[k] ?? 0n)
    return { year, income: strMap(i), expense: strMap(e), result: strMap(res) }
  })
}

/** Variación porcentual informativa (solo presentación; no se persiste ni se usa para contabilizar). */
export function variationPct(current: string, previous: string): number | null {
  if (isZero(previous)) return null
  return Math.round((Number(sub(current, previous)) / Number(previous)) * 10000) / 100
}
