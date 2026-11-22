export type PeriodKey = 'today' | 'week' | 'month' | 'prev_month' | 'year' | 'custom'

export interface Range { from: string; to: string; prevFrom: string; prevTo: string }

const pad = (n: number) => String(n).padStart(2, '0')
export const ymd = (d: Date): string => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
const parse = (s: string): Date => { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d) }
const addDays = (d: Date, n: number): Date => new Date(d.getFullYear(), d.getMonth(), d.getDate() + n)

/** Rango del período y el período anterior equivalente (misma duración, inmediatamente antes). */
export function rangeFor(key: PeriodKey, today: Date = new Date(), custom?: { from: string; to: string }): Range {
  const t = new Date(today.getFullYear(), today.getMonth(), today.getDate())
  let from: Date
  let to: Date
  switch (key) {
    case 'today': from = to = t; break
    case 'week': { const dow = (t.getDay() + 6) % 7; from = addDays(t, -dow); to = addDays(from, 6); break }   // lunes a domingo
    case 'month': from = new Date(t.getFullYear(), t.getMonth(), 1); to = new Date(t.getFullYear(), t.getMonth() + 1, 0); break
    case 'prev_month': from = new Date(t.getFullYear(), t.getMonth() - 1, 1); to = new Date(t.getFullYear(), t.getMonth(), 0); break
    case 'year': from = new Date(t.getFullYear(), 0, 1); to = new Date(t.getFullYear(), 11, 31); break
    case 'custom': {
      if (!custom) throw new Error('Falta el rango personalizado.')
      from = parse(custom.from); to = parse(custom.to)
      if (to < from) throw new Error('La fecha final no puede ser anterior a la inicial.')
      break
    }
  }
  const days = Math.round((to.getTime() - from.getTime()) / 86_400_000) + 1
  const prevTo = addDays(from, -1)
  return { from: ymd(from), to: ymd(to), prevFrom: ymd(addDays(prevTo, -(days - 1))), prevTo: ymd(prevTo) }
}
