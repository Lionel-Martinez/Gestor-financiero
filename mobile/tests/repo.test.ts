import { beforeEach, describe, expect, it } from 'vitest'
import { computeAnnual, computeDashboard, createAccount, discardOperation, DomainError, listBalances, listStatements, markOperation, outboxCounts, pendingOutbox, recordCardPayment, recordCardPurchase, recordIncomeOrExpense, recordTransfer, setCardDays } from '../src/data/repo'
import type { SqlDriver } from '../src/db/driver'
import { initSchema } from '../src/db'
import { SqlJsDriver } from '../src/db/sqljs'
import { applyPull, type PullResponse } from '../src/sync/engine'

const PER = '00000000-0000-4000-8000-0000000000a1'
const LMS = '00000000-0000-4000-8000-0000000000a2'
const FOOD = '00000000-0000-4000-8000-0000000000c1'
const SALARY = '00000000-0000-4000-8000-0000000000c2'
const NOW = new Date().toISOString()
const MONTH = { from: NOW.slice(0, 8) + '01', to: NOW.slice(0, 8) + '31', pf: '2000-01-01', pt: '2000-01-31' }

let db: SqlDriver

/** Simula la primera sincronización: espacios, cuentas de sistema y categorías globales. */
async function seedFromServer(): Promise<void> {
  const sys = (space: string, role: string, type: string, code: string) => ({ id: `${space}-${role}`, space_id: space, code, name: role, type, system_role: role, is_group: 0, currency: null, is_active: 1 })
  const pull: PullResponse = {
    server_time: NOW, next_cursor: NOW, has_more: false,
    spaces: [{ id: PER, code: 'PER', name: 'Personal' }, { id: LMS, code: 'LMS', name: 'LM Studio' }],
    currencies: [{ code: 'ARS', name: 'Peso', minor_units: 2, kind: 'fiat' }, { code: 'USD', name: 'Dólar', minor_units: 2, kind: 'fiat' }],
    financial_accounts: [], financial_account_currencies: [],
    ledger_accounts: [PER, LMS].flatMap((s) => [
      sys(s, 'equity_opening', 'equity', 'PAT-INI'), sys(s, 'income_general', 'income', 'ING'),
      sys(s, 'expense_general', 'expense', 'GAS'), sys(s, 'interspace_clearing', 'asset', 'TIE'),
    ]),
    categories: [
      { id: FOOD, space_id: null, parent_id: null, name: 'Alimentación', kind: 'expense', group_key: 'alimentacion', is_active: 1, version: 1 },
      { id: SALARY, space_id: null, parent_id: null, name: 'Sueldo', kind: 'income', group_key: 'sueldo', is_active: 1, version: 1 },
    ],
    entries: [], tombstones: [],
  }
  await applyPull(db, pull)
}

/** Simula que el servidor confirmó lo pendiente: devuelve los asientos como confirmados con número. */
async function confirmAllPendingAsServer(): Promise<void> {
  const entries = await db.all<Record<string, string>>("SELECT * FROM entries WHERE status = 'pending'")
  const out: PullResponse['entries'] = []
  let n = 0
  for (const e of entries) {
    const lines = await db.all<Record<string, unknown>>('SELECT * FROM lines WHERE entry_id = ?', [e.id])
    out.push({ ...e, number: `X-2026-${String(++n).padStart(6, '0')}`, accounting_date: e.occurred_at.slice(0, 10), occurred_at: e.occurred_at.replace('T', ' ').replace('Z', ''), lines })
  }
  await applyPull(db, { server_time: NOW, next_cursor: NOW, has_more: false, spaces: [], currencies: [], financial_accounts: [], financial_account_currencies: [], ledger_accounts: [], categories: [], entries: out, tombstones: [] })
}

beforeEach(async () => {
  db = await SqlJsDriver.open()
  await initSchema(db)
  await seedFromServer()
})

describe('operaciones offline', () => {
  it('crea cuenta con saldo inicial como asiento de apertura pendiente y encola la operación', async () => {
    const id = await createAccount(db, { spaceId: PER, name: 'Banco', kind: 'bank', currencies: [{ currency: 'ARS', openingBalance: '10000' }] })
    expect(id).toBeTruthy()
    expect(await outboxCounts(db)).toEqual({ pending: 1, problems: 0 })

    const b = await listBalances(db, [PER])
    expect(b).toHaveLength(1)
    expect(b[0]).toMatchObject({ confirmed: '0.0000', pendingDelta: '10000.0000' })   // aún sin confirmar por el servidor

    const d = await computeDashboard(db, [PER], MONTH.from, MONTH.to, MONTH.pf, MONTH.pt)
    expect(d.available).toEqual({})                         // pendiente no es disponible real
    expect(d.pendingAvailableDelta).toEqual({ ARS: '10000.0000' })
    expect(d.income).toEqual({})                            // y el saldo inicial nunca es un ingreso
  })

  it('tras la confirmación del servidor, el gasto reduce el disponible y suma a gastos', async () => {
    const acc = await createAccount(db, { spaceId: PER, name: 'Banco', kind: 'bank', currencies: [{ currency: 'ARS', openingBalance: '10000' }] })
    await recordIncomeOrExpense(db, { kind: 'expense', spaceId: PER, financialAccountId: acc, currency: 'ARS', amount: '2500.50', categoryId: FOOD, occurredAt: NOW })
    await confirmAllPendingAsServer()

    const d = await computeDashboard(db, [PER], MONTH.from, MONTH.to, MONTH.pf, MONTH.pt)
    expect(d.available).toEqual({ ARS: '7499.5000' })
    expect(d.expense).toEqual({ ARS: '2500.5000' })
    expect(d.netWorth).toEqual({ ARS: '7499.5000' })
    expect(d.expenseByCategory[0]).toMatchObject({ category: 'Alimentación', currency: 'ARS', total: '2500.5000' })
    expect(d.pendingCount).toBe(0)
    expect(d.recent).toHaveLength(1)                       // el asiento de apertura no aparece en actividad reciente
    expect(d.recent[0].amount).toBe('2500.5000')
  })

  it('rechaza localmente lo que el servidor rechazaría', async () => {
    const acc = await createAccount(db, { spaceId: PER, name: 'Banco', kind: 'bank', currencies: [{ currency: 'ARS', openingBalance: '0' }] })
    await expect(recordIncomeOrExpense(db, { kind: 'expense', spaceId: PER, financialAccountId: acc, currency: 'ARS', amount: '10', categoryId: SALARY, occurredAt: NOW })).rejects.toBeInstanceOf(DomainError)   // categoría de ingreso en un gasto
    await expect(recordIncomeOrExpense(db, { kind: 'expense', spaceId: PER, financialAccountId: acc, currency: 'USD', amount: '10', categoryId: FOOD, occurredAt: NOW })).rejects.toBeInstanceOf(DomainError)   // la cuenta no opera en USD
    await expect(recordIncomeOrExpense(db, { kind: 'expense', spaceId: PER, financialAccountId: acc, currency: 'ARS', amount: '0', categoryId: FOOD, occurredAt: NOW })).rejects.toThrow()
    // Nada quedó a medias: solo la operación de crear la cuenta
    expect(await outboxCounts(db)).toEqual({ pending: 1, problems: 0 })
    expect(await db.all("SELECT 1 FROM entries WHERE status = 'pending'")).toHaveLength(0)
  })

  it('transferencia entre espacios no cambia el total de General ni cuenta como ingreso/gasto', async () => {
    const p = await createAccount(db, { spaceId: PER, name: 'Banco P', kind: 'bank', currencies: [{ currency: 'ARS', openingBalance: '5000' }] })
    const l = await createAccount(db, { spaceId: LMS, name: 'Banco L', kind: 'bank', currencies: [{ currency: 'ARS', openingBalance: '0' }] })
    await recordTransfer(db, { fromSpaceId: PER, fromAccountId: p, toSpaceId: LMS, toAccountId: l, currency: 'ARS', amount: '1500', occurredAt: NOW })
    await confirmAllPendingAsServer()

    const general = await computeDashboard(db, [PER, LMS], MONTH.from, MONTH.to, MONTH.pf, MONTH.pt)
    expect(general.available).toEqual({ ARS: '5000.0000' })
    expect(general.income).toEqual({})
    expect(general.expense).toEqual({})
    expect((await computeDashboard(db, [PER], MONTH.from, MONTH.to, MONTH.pf, MONTH.pt)).available).toEqual({ ARS: '3500.0000' })
    expect((await computeDashboard(db, [LMS], MONTH.from, MONTH.to, MONTH.pf, MONTH.pt)).available).toEqual({ ARS: '1500.0000' })
  })

  it('una operación rechazada deja de contar y se puede descartar con todo lo provisional', async () => {
    const acc = await createAccount(db, { spaceId: PER, name: 'Banco', kind: 'bank', currencies: [{ currency: 'ARS', openingBalance: '1000' }] })
    const [{ id: createOp }] = await db.all<{ id: string }>('SELECT id FROM outbox ORDER BY seq LIMIT 1')
    await markOperation(db, createOp, 'rejected', 'rechazada por el servidor')
    expect((await listBalances(db, [PER]))[0].pendingDelta).toBe('0.0000')      // el asiento rechazado no cuenta
    expect((await outboxCounts(db)).problems).toBe(1)

    await discardOperation(db, createOp)
    expect(await db.all('SELECT 1 FROM financial_accounts WHERE id = ?', [acc])).toHaveLength(0)
    expect(await db.all('SELECT 1 FROM entries')).toHaveLength(0)
    expect(await outboxCounts(db)).toEqual({ pending: 0, problems: 0 })
  })

  it('aplicar el mismo pull dos veces es idempotente y ordena fechas del servidor como ISO', async () => {
    const acc = await createAccount(db, { spaceId: PER, name: 'Banco', kind: 'bank', currencies: [{ currency: 'ARS', openingBalance: '100' }] })
    await recordIncomeOrExpense(db, { kind: 'income', spaceId: PER, financialAccountId: acc, currency: 'ARS', amount: '50', categoryId: SALARY, occurredAt: NOW })
    await confirmAllPendingAsServer()
    await confirmAllPendingAsServer()      // nada pendiente: no hace nada
    const before = await db.all('SELECT * FROM lines')
    await seedFromServer()
    expect(await db.all('SELECT * FROM lines')).toEqual(before)
    const [e] = await db.all<{ occurred_at: string }>("SELECT occurred_at FROM entries WHERE kind = 'ordinary'")
    expect(e.occurred_at).toMatch(/T.*Z$/)
  })
})

describe('resumen anual', () => {
  it('agrupa por año contable y moneda, solo con lo confirmado', async () => {
    const acc = await createAccount(db, { spaceId: PER, name: 'Banco', kind: 'bank', currencies: [{ currency: 'ARS', openingBalance: '10000' }] })
    await recordIncomeOrExpense(db, { kind: 'income', spaceId: PER, financialAccountId: acc, currency: 'ARS', amount: '4000', categoryId: SALARY, occurredAt: NOW })
    await recordIncomeOrExpense(db, { kind: 'expense', spaceId: PER, financialAccountId: acc, currency: 'ARS', amount: '1500', categoryId: FOOD, occurredAt: NOW })
    expect(await computeAnnual(db, [PER])).toEqual([])                       // nada confirmado todavía
    await confirmAllPendingAsServer()
    const year = NOW.slice(0, 4)
    expect(await computeAnnual(db, [PER])).toEqual([{ year, income: { ARS: '4000.0000' }, expense: { ARS: '1500.0000' }, result: { ARS: '2500.0000' } }])   // la apertura no es ingreso
  })
})

describe('tarjetas de crédito y cuotas', () => {
  const D = '2026-11-10T15:00:00.000Z'
  async function setup() {
    const card = await createAccount(db, { spaceId: PER, name: 'Visa', kind: 'credit_card', closingDay: 20, dueDay: 5, currencies: [{ currency: 'ARS', openingBalance: '0' }] })
    const bank = await createAccount(db, { spaceId: PER, name: 'Banco', kind: 'bank', currencies: [{ currency: 'ARS', openingBalance: '50000' }] })
    return { card, bank }
  }
  const buy = (card: string, amount: string, n: number, date = '2026-11-10') =>
    recordCardPurchase(db, { spaceId: PER, financialAccountId: card, currency: 'ARS', amount, installments: n, categoryId: FOOD, occurredAt: `${date}T15:00:00.000Z`, purchaseDate: date })

  it('la compra en cuotas arma el cronograma local y encola la operación con los ids de las cuotas', async () => {
    const { card } = await setup()
    const opId = await buy(card, '30000', 3)
    const st = await listStatements(db, [PER])
    expect(st.map((x) => [x.statementMonth, x.open, x.installments, x.pending])).toEqual([['2026-11', '10000.0000', 1, true], ['2026-12', '10000.0000', 1, true], ['2027-01', '10000.0000', 1, true]])
    const op = (await pendingOutbox(db)).find((o) => o.id === opId)!
    expect(op.action).toBe('card_purchase')
    const payload = JSON.parse(op.payload) as { installment_ids: string[]; installments: number }
    expect(payload.installments).toBe(3)
    expect(new Set(payload.installment_ids).size).toBe(3)
  })

  it('pagar el resumen cancela solo esas cuotas y suma varias compras', async () => {
    const { card, bank } = await setup()
    await buy(card, '30000', 3)
    await buy(card, '4000', 1, '2026-11-15')
    await recordCardPayment(db, { spaceId: PER, financialAccountId: card, fromAccountId: bank, currency: 'ARS', statementMonth: '2026-11', occurredAt: '2026-12-04T15:00:00.000Z' })
    const st = await listStatements(db, [PER])
    const nov = st.find((x) => x.statementMonth === '2026-11')!
    expect([nov.open, nov.paid]).toEqual(['0.0000', '14000.0000'])
    expect(st.find((x) => x.statementMonth === '2026-12')!.open).toBe('10000.0000')
    await expect(recordCardPayment(db, { spaceId: PER, financialAccountId: card, fromAccountId: bank, currency: 'ARS', statementMonth: '2026-11', occurredAt: D })).rejects.toBeInstanceOf(DomainError)
  })

  it('si el servidor rechaza el pago, las cuotas vuelven a quedar abiertas; si rechaza la compra, desaparecen', async () => {
    const { card, bank } = await setup()
    const buyOp = await buy(card, '9000', 3)
    const payOp = await recordCardPayment(db, { spaceId: PER, financialAccountId: card, fromAccountId: bank, currency: 'ARS', statementMonth: '2026-11', occurredAt: '2026-12-04T15:00:00.000Z' })
    await markOperation(db, payOp, 'rejected', 'sin fondos')
    expect((await listStatements(db, [PER])).find((x) => x.statementMonth === '2026-11')!.open).toBe('3000.0000')
    await markOperation(db, buyOp, 'rejected', 'categoría inválida')
    expect(await listStatements(db, [PER])).toEqual([])
    await discardOperation(db, buyOp)
    expect(await db.all("SELECT id FROM card_installments")).toEqual([])
  })

  it('exige tarjeta con cierre y vencimiento, y valida los días', async () => {
    const bank = await createAccount(db, { spaceId: PER, name: 'Banco', kind: 'bank', currencies: [{ currency: 'ARS', openingBalance: '0' }] })
    await expect(buy(bank, '100', 2)).rejects.toBeInstanceOf(DomainError)                                           // no es tarjeta
    const bare = await createAccount(db, { spaceId: PER, name: 'Master', kind: 'credit_card', currencies: [{ currency: 'ARS', openingBalance: '0' }] })
    await expect(buy(bare, '100', 2)).rejects.toBeInstanceOf(DomainError)                                           // sin cierre ni vencimiento
    await expect(setCardDays(db, bare, 31, 5)).rejects.toBeInstanceOf(DomainError)
    await setCardDays(db, bare, 15, 28)
    await buy(bare, '100', 2)
    expect((await listStatements(db, [PER])).length).toBe(2)
    await expect(createAccount(db, { spaceId: PER, name: 'X', kind: 'bank', closingDay: 10, currencies: [{ currency: 'ARS', openingBalance: '0' }] })).rejects.toBeInstanceOf(DomainError)
  })

  it('la cuota que llega del servidor reemplaza a la provisional (mismo id)', async () => {
    const { card } = await setup()
    const opId = await buy(card, '6000', 2)
    const payload = JSON.parse((await pendingOutbox(db)).find((o) => o.id === opId)!.payload) as { installment_ids: string[]; entry_id: string }
    await applyPull(db, { server_time: NOW, next_cursor: NOW, has_more: false, spaces: [], currencies: [], financial_accounts: [], financial_account_currencies: [], ledger_accounts: [], categories: [], entries: [], tombstones: [],
      card_installments: [{ id: payload.installment_ids[0], space_id: PER, financial_account_id: card, entry_id: payload.entry_id, installment_no: 1, installments_total: 2, currency: 'ARS', amount: '3000.0000', statement_month: '2026-11', due_date: '2026-12-05', status: 'paid', paid_entry_id: 'abc' }] })
    const st = await listStatements(db, [PER])
    const nov = st.find((x) => x.statementMonth === '2026-11')!
    expect([nov.paid, nov.open, nov.pending]).toEqual(['3000.0000', '0.0000', false])
    expect(await db.all('SELECT id FROM card_installments')).toHaveLength(2)                                        // no se duplicó
  })
})
