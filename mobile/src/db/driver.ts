export type SqlValue = string | number | null

/** Contrato mínimo que comparten SQLite nativo (Capacitor) y sql.js (navegador / tests). */
export interface SqlDriver {
  exec(sql: string): Promise<void>
  run(sql: string, params?: readonly unknown[]): Promise<void>
  all<T = Record<string, SqlValue>>(sql: string, params?: readonly unknown[]): Promise<T[]>
  /** Ejecuta `fn` en una transacción atómica; si lanza, se revierte todo (recuperación ante cierres inesperados). */
  transaction<T>(fn: () => Promise<T>): Promise<T>
}

/** Serializa las transacciones para que no se mezclen sentencias de operaciones concurrentes. */
export class TxLock {
  private tail: Promise<unknown> = Promise.resolve()
  depth = 0
  run<T>(fn: () => Promise<T>): Promise<T> {
    const next = this.tail.then(fn, fn)
    this.tail = next.catch(() => undefined)
    return next
  }
}
