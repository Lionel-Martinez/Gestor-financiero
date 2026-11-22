/** Acepta "1234.5" o "1234,5" (sin separador de miles). Devuelve null si no es un importe válido de hasta 4 decimales. */
export function parseAmountInput(raw: string): string | null {
  const s = raw.trim().replace(',', '.')
  return /^\d+(\.\d{1,4})?$/.test(s) ? s : null
}

/** Fecha elegida (YYYY-MM-DD) → instante ISO UTC. Hoy conserva la hora actual; otro día, mediodía UTC. */
export function occurredAtFor(day: string, now: Date = new Date()): string {
  const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
  return day === today ? now.toISOString() : `${day}T12:00:00.000Z`
}

export const todayLocal = (d: Date = new Date()): string =>
  `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
