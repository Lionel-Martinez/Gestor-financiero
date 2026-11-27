import { describe, expect, it } from 'vitest'
import { add, cmp, formatAmount, norm, sub, sum, toUnits } from '../src/domain/money'
import { schedule } from '../src/domain/installments'
import * as P from '../src/domain/posting'
import type { CategoryInfo, LedgerAccountInfo } from '../src/domain/types'
import { validateEntry } from '../src/domain/validator'

const PER = 'per'
const accounts: Record<string, LedgerAccountInfo> = {
  cash: { space_id: PER, type: 'asset', is_group: false, is_active: true, currency: 'ARS' },
  usd: { space_id: PER, type: 'asset', is_group: false, is_active: true, currency: 'USD' },
  inc: { space_id: PER, type: 'income', is_group: false, is_active: true, currency: null },
  exp: { space_id: PER, type: 'expense', is_group: false, is_active: true, currency: null },
  lms_cash: { space_id: 'lms', type: 'asset', is_group: false, is_active: true, currency: 'ARS' },
}
const categories: Record<string, CategoryInfo> = {
  food: { space_id: null, kind: 'expense', is_active: true },
  salary: { space_id: null, kind: 'income', is_active: true },
  lms_only: { space_id: 'lms', kind: 'expense', is_active: true },
}

describe('money', () => {
  it('normaliza y rechaza lo inválido', () => {
    expect(norm('10')).toBe('10.0000')
    expect(norm('10.5')).toBe('10.5000')
    expect(norm('-0')).toBe('0.0000')
    expect(() => norm('10.00001')).toThrow()
    expect(() => norm('1e3')).toThrow()
    expect(() => norm('abc')).toThrow()
  })
  it('suma sin error de coma flotante', () => {
    expect(add('0.1', '0.2')).toBe('0.3000')
    expect(add('999999999999999.9999', '0.0002')).toBe('1000000000000000.0001')
    expect(sub('5', '10')).toBe('-5.0000')
    expect(sum(['0.1', '0.2', '0.3'])).toBe('0.6000')
  })
  it('compara', () => {
    expect(cmp('1.0', '1.0000')).toBe(0)
    expect(cmp('-3', '-2')).toBe(-1)
    expect(toUnits('2')).toBe(20000n)
  })
  it('formatea es-AR con redondeo half-up', () => {
    expect(formatAmount('1234567.8')).toBe('1.234.567,80')
    expect(formatAmount('-0.005')).toBe('-0,01')
    expect(formatAmount('-0.0001')).toBe('0,00')
    expect(formatAmount('999.9950')).toBe('1.000,00')
  })
})

describe('validator (port del servidor)', () => {
  const run = (lines: Parameters<typeof validateEntry>[1], currency = 'ARS', kind: 'ordinary' | 'fx' = 'ordinary') =>
    validateEntry({ space_id: PER, currency, kind }, lines, accounts, categories)

  it('acepta gasto e ingreso válidos', () => {
    expect(run([{ ledger_account_id: 'exp', debit: '100', credit: '0', category_id: 'food' }, { ledger_account_id: 'cash', debit: '0', credit: '100', category_id: null }])).toEqual([])
    expect(run([{ ledger_account_id: 'cash', debit: '5000.50', credit: '0', category_id: null }, { ledger_account_id: 'inc', debit: '0', credit: '5000.50', category_id: 'salary' }])).toEqual([])
  })
  it('rechaza desequilibrio', () => {
    const e = run([{ ledger_account_id: 'exp', debit: '100', credit: '0', category_id: 'food' }, { ledger_account_id: 'cash', debit: '0', credit: '99.9999', category_id: null }])
    expect(e).toHaveLength(1)
    expect(e[0]).toContain('no está equilibrado')
  })
  it('no mezcla monedas ni espacios', () => {
    expect(run([{ ledger_account_id: 'usd', debit: '10', credit: '0', category_id: null }, { ledger_account_id: 'inc', debit: '0', credit: '10', category_id: 'salary' }])[0]).toContain('moneda')
    expect(run([{ ledger_account_id: 'lms_cash', debit: '10', credit: '0', category_id: null }, { ledger_account_id: 'inc', debit: '0', credit: '10', category_id: 'salary' }])[0]).toContain('otro espacio')
  })
  it('exige categoría salvo fx, y rechaza categorías ajenas o de otro tipo', () => {
    const noCat = [{ ledger_account_id: 'exp', debit: '10', credit: '0', category_id: null }, { ledger_account_id: 'cash', debit: '0', credit: '10', category_id: null }]
    expect(run(noCat)).not.toEqual([])
    expect(run(noCat, 'ARS', 'fx')).toEqual([])
    const withCat = (c: string) => [{ ledger_account_id: 'exp', debit: '10', credit: '0', category_id: c }, { ledger_account_id: 'cash', debit: '0', credit: '10', category_id: null }]
    expect(run(withCat('lms_only'))).not.toEqual([])
    expect(run(withCat('salary'))).not.toEqual([])
  })
})

describe('posting', () => {
  const balanced = (p: { lines: { debit: string; credit: string }[] }) =>
    expect(sum(p.lines.map((l) => l.debit))).toBe(sum(p.lines.map((l) => l.credit)))

  it('ingreso, gasto y transferencia propia quedan equilibrados', () => {
    balanced(P.income(PER, 'ARS', 'cash', 'inc', '1500.25', 'salary', 'x'))
    balanced(P.expense(PER, 'ARS', 'cash', 'exp', '300', 'food', 'x'))
    const t = P.ownTransfer(PER, 'ARS', 'a', 'b', '50', 'x')
    balanced(t)
    expect(t.lines.every((l) => l.category_id === null)).toBe(true)
    expect(() => P.ownTransfer(PER, 'ARS', 'a', 'a', '50', 'x')).toThrow()
  })
  it('transferencia entre espacios: un asiento por espacio, sin ingresos ni gastos', () => {
    const t = P.interSpaceTransfer('per', 'p_cash', 'p_tie', 'lms', 'l_cash', 'l_tie', 'ARS', '1000', 'x')
    balanced(t.out)
    balanced(t.in)
    expect(t.out.entry.space_id).toBe('per')
    expect(t.in.entry.space_id).toBe('lms')
    expect(t.out.lines.map((l) => l.ledger_account_id).sort()).toEqual(['p_cash', 'p_tie'])
    expect(() => P.interSpaceTransfer('x', 'a', 'b', 'x', 'c', 'd', 'ARS', '1', 'x')).toThrow()
  })
  it('apertura usa patrimonio inicial y nunca es ingreso', () => {
    const a = P.opening(PER, 'ARS', 'bank', 'asset', 'eq', '5000', 'x')
    expect(a.entry.kind).toBe('opening')
    expect(a.lines[0]).toMatchObject({ ledger_account_id: 'bank', debit: '5000.0000' })
    const l = P.opening(PER, 'ARS', 'card', 'liability', 'eq', '800', 'x')
    expect(l.lines[0].ledger_account_id).toBe('eq')
    expect(l.lines[1]).toMatchObject({ ledger_account_id: 'card', credit: '800.0000' })
  })
  it('rechaza importes no positivos', () => {
    expect(() => P.expense(PER, 'ARS', 'a', 'b', '0', 'food', 'x')).toThrow()
    expect(() => P.expense(PER, 'ARS', 'a', 'b', '-5', 'food', 'x')).toThrow()
  })
})


describe('cuotas de tarjeta (sin interés)', () => {
  it('mismo cronograma que el servidor', () => {
    const a = schedule('2026-11-10', 20, 5, '30000', 3)
    expect(a.map((x) => x.statementMonth)).toEqual(['2026-11', '2026-12', '2027-01'])
    expect(a.map((x) => x.dueDate)).toEqual(['2026-12-05', '2027-01-05', '2027-02-05'])
    expect(schedule('2026-11-20', 20, 5, '100', 1)[0].statementMonth).toBe('2026-11')
    expect(schedule('2026-11-21', 20, 5, '100', 1)[0].statementMonth).toBe('2026-12')
    expect(schedule('2026-12-25', 20, 5, '100', 1)[0].statementMonth).toBe('2027-01')
    expect(schedule('2026-11-10', 10, 25, '100', 1)[0].dueDate).toBe('2026-11-25')
  })
  it('el resto va a la última cuota y la suma es exacta', () => {
    const s = schedule('2026-11-10', 20, 5, '100', 3)
    expect(s.map((x) => x.amount)).toEqual(['33.3333', '33.3333', '33.3334'])
    expect(sum(s.map((x) => x.amount))).toBe('100.0000')
  })
  it('rechaza datos inválidos', () => {
    const bad: [string, number, number, string, number][] = [['2026-02-30', 20, 5, '10', 1], ['2026-11-10', 29, 5, '10', 1], ['2026-11-10', 20, 0, '10', 1], ['2026-11-10', 20, 5, '10', 0], ['2026-11-10', 20, 5, '10', 61], ['2026-11-10', 20, 5, '0', 1], ['2026-11-10', 20, 5, '-5', 2]]
    for (const [a, b, c, d, e] of bad) expect(() => schedule(a, b, c, d, e)).toThrow()
  })
})
