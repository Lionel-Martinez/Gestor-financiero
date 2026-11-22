import { beforeEach, describe, expect, it } from 'vitest'
import { computeDashboard, createAccount, discardOperation, DomainError, listBalances, markOperation, outboxCounts, recordIncomeOrExpense, recordTransfer } from '../src/data/repo'
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
