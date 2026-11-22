/**
 * Importes como strings decimales exactos (escala 4, igual que DECIMAL(19,4)) operados con BigInt.
 * Nunca se usa number/float para dinero. Port del Decimal.php del backend.
 */
export const SCALE = 4
const FACTOR = 10n ** BigInt(SCALE)

export class MoneyError extends Error {}

/** Convierte "1234.5" a unidades enteras (12345000n). Rechaza más de 4 decimales y notación científica. */
export function toUnits(value: string): bigint {
  const m = /^(-)?(\d+)(?:\.(\d+))?$/.exec(value.trim())
  if (!m) throw new MoneyError(`Importe inválido: '${value}'`)
  let frac = m[3] ?? ''
  if (frac.length > SCALE) {
    if (/[1-9]/.test(frac.slice(SCALE))) throw new MoneyError(`'${value}' excede los ${SCALE} decimales`)
    frac = frac.slice(0, SCALE)
  }
  const units = BigInt(m[2] + frac.padEnd(SCALE, '0'))
  return m[1] && units !== 0n ? -units : units
}

export function fromUnits(units: bigint): string {
  const neg = units < 0n
  const abs = neg ? -units : units
  const int = abs / FACTOR
  const frac = (abs % FACTOR).toString().padStart(SCALE, '0')
  return `${neg ? '-' : ''}${int}.${frac}`
}

export const norm = (v: string): string => fromUnits(toUnits(v))
export const add = (a: string, b: string): string => fromUnits(toUnits(a) + toUnits(b))
export const sub = (a: string, b: string): string => fromUnits(toUnits(a) - toUnits(b))
export const cmp = (a: string, b: string): -1 | 0 | 1 => {
  const x = toUnits(a)
  const y = toUnits(b)
  return x < y ? -1 : x > y ? 1 : 0
}
export const isZero = (v: string): boolean => toUnits(v) === 0n
export const isNegative = (v: string): boolean => toUnits(v) < 0n

/** Formato es-AR para mostrar: 1.234,50 (solo presentación; el dato sigue siendo el string exacto). */
export function formatAmount(value: string, decimals = 2): string {
  const units = toUnits(value)
  const neg = units < 0n
  const abs = neg ? -units : units
  // Redondeo half-up a `decimals`
  const drop = 10n ** BigInt(SCALE - decimals)
  const rounded = (abs + drop / 2n) / drop
  const unit = 10n ** BigInt(decimals)
  const int = (rounded / unit).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.')
  const frac = (rounded % unit).toString().padStart(decimals, '0')
  return `${neg && rounded !== 0n ? '-' : ''}${int}${decimals > 0 ? ',' + frac : ''}`
}

export function sum(values: string[]): string {
  return fromUnits(values.reduce((acc, v) => acc + toUnits(v), 0n))
}
