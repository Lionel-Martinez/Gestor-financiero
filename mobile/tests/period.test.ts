import { describe, expect, it } from 'vitest'
import { rangeFor } from '../src/data/period'

const d = new Date(2026, 10, 21) // sábado 21-nov-2026

describe('períodos', () => {
  it('hoy', () => expect(rangeFor('today', d)).toEqual({ from: '2026-11-21', to: '2026-11-21', prevFrom: '2026-11-20', prevTo: '2026-11-20' }))
  it('esta semana (lunes a domingo)', () => expect(rangeFor('week', d)).toEqual({ from: '2026-11-16', to: '2026-11-22', prevFrom: '2026-11-09', prevTo: '2026-11-15' }))
  it('este mes y mes anterior', () => {
    expect(rangeFor('month', d)).toEqual({ from: '2026-11-01', to: '2026-11-30', prevFrom: '2026-10-02', prevTo: '2026-10-31' })
    expect(rangeFor('prev_month', d)).toMatchObject({ from: '2026-10-01', to: '2026-10-31' })
  })
  it('este año', () => expect(rangeFor('year', d)).toMatchObject({ from: '2026-01-01', to: '2026-12-31', prevTo: '2025-12-31' }))
  it('personalizado y validación', () => {
    expect(rangeFor('custom', d, { from: '2026-03-01', to: '2026-03-10' })).toMatchObject({ prevFrom: '2026-02-19', prevTo: '2026-02-28' })
    expect(() => rangeFor('custom', d, { from: '2026-03-10', to: '2026-03-01' })).toThrow()
  })
})
