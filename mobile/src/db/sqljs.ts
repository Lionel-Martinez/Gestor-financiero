import initSqlJs, { type Database } from 'sql.js'
import { type SqlDriver, type SqlValue, TxLock } from './driver'

const bind = (p: readonly unknown[]) => p as SqlValue[]

/** Driver sobre sql.js: navegador (vista previa de desarrollo, con persistencia en localStorage) y tests. */
export class SqlJsDriver implements SqlDriver {
  private lock = new TxLock()

  constructor(
    private db: Database,
    private persist?: (bytes: Uint8Array) => void,
  ) {}

  static async open(opts: { wasmUrl?: string; data?: Uint8Array; persist?: (b: Uint8Array) => void } = {}): Promise<SqlJsDriver> {
    const url = opts.wasmUrl
    const SQL = await initSqlJs(url ? ({ locateFile: () => url } as unknown as Parameters<typeof initSqlJs>[0]) : undefined)
    return new SqlJsDriver(new SQL.Database(opts.data), opts.persist)
  }

  async exec(sql: string): Promise<void> {
    this.db.exec(sql)
  }

  async run(sql: string, params: readonly unknown[] = []): Promise<void> {
    this.db.run(sql, bind(params))
  }

  async all<T = Record<string, SqlValue>>(sql: string, params: readonly unknown[] = []): Promise<T[]> {
    const stmt = this.db.prepare(sql)
    try {
      stmt.bind(bind(params))
      const rows: T[] = []
      while (stmt.step()) rows.push(stmt.getAsObject() as T)
      return rows
    } finally {
      stmt.free()
    }
  }

  transaction<T>(fn: () => Promise<T>): Promise<T> {
    if (this.lock.depth > 0) return fn() // anidada: participa de la transacción externa
    return this.lock.run(async () => {
      this.lock.depth++
      this.db.run('BEGIN')
      try {
        const result = await fn()
        this.db.run('COMMIT')
        this.persist?.(this.db.export())
        return result
      } catch (e) {
        this.db.run('ROLLBACK')
        throw e
      } finally {
        this.lock.depth--
      }
    })
  }
}
