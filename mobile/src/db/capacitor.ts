import { CapacitorSQLite, SQLiteConnection, type SQLiteDBConnection } from '@capacitor-community/sqlite'
import { type SqlDriver, type SqlValue, TxLock } from './driver'

/** Driver nativo (Android/iOS) sobre @capacitor-community/sqlite. */
export class CapacitorDriver implements SqlDriver {
  private lock = new TxLock()

  private constructor(private db: SQLiteDBConnection) {}

  static async open(name = 'gestor_financiero'): Promise<CapacitorDriver> {
    const sqlite = new SQLiteConnection(CapacitorSQLite)
    const consistent = (await sqlite.checkConnectionsConsistency()).result
    const exists = (await sqlite.isConnection(name, false)).result
    const db = consistent && exists ? await sqlite.retrieveConnection(name, false) : await sqlite.createConnection(name, false, 'no-encryption', 1, false)
    await db.open()
    await db.execute('PRAGMA foreign_keys = ON;', false)
    return new CapacitorDriver(db)
  }

  async exec(sql: string): Promise<void> {
    await this.db.execute(sql, false)
  }

  async run(sql: string, params: readonly unknown[] = []): Promise<void> {
    await this.db.run(sql, [...params], false)
  }

  async all<T = Record<string, SqlValue>>(sql: string, params: readonly unknown[] = []): Promise<T[]> {
    const res = await this.db.query(sql, [...params])
    return (res.values ?? []) as T[]
  }

  transaction<T>(fn: () => Promise<T>): Promise<T> {
    if (this.lock.depth > 0) return fn()
    return this.lock.run(async () => {
      this.lock.depth++
      await this.db.beginTransaction()
      try {
        const result = await fn()
        await this.db.commitTransaction()
        return result
      } catch (e) {
        await this.db.rollbackTransaction()
        throw e
      } finally {
        this.lock.depth--
      }
    })
  }
}
