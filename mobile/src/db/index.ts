import { Capacitor } from '@capacitor/core'
import type { SqlDriver } from './driver'
import { migrate, SCHEMA_VERSION } from './schema'

let instance: Promise<SqlDriver> | null = null

const LS_KEY = 'gf_sqljs_db'

function toBase64(bytes: Uint8Array): string {
  let s = ''
  for (let i = 0; i < bytes.length; i += 0x8000) s += String.fromCharCode(...bytes.subarray(i, i + 0x8000))
  return btoa(s)
}
function fromBase64(b64: string): Uint8Array {
  return Uint8Array.from(atob(b64), (c) => c.charCodeAt(0))
}

/** Abre (una sola vez) la base local y aplica migraciones. En nativo usa SQLite; en navegador, sql.js. */
export function getDb(): Promise<SqlDriver> {
  instance ??= (async () => {
    let driver: SqlDriver
    if (Capacitor.isNativePlatform()) {
      const { CapacitorDriver } = await import('./capacitor')
      driver = await CapacitorDriver.open()
    } else {
      const { SqlJsDriver } = await import('./sqljs')
      const wasmUrl = (await import('sql.js/dist/sql-wasm.wasm?url')).default
      const saved = localStorage.getItem(LS_KEY)
      driver = await SqlJsDriver.open({
        wasmUrl,
        data: saved ? fromBase64(saved) : undefined,
        persist: (bytes) => localStorage.setItem(LS_KEY, toBase64(bytes)),
      })
    }
    await initSchema(driver)
    return driver
  })()
  return instance
}

export async function initSchema(db: SqlDriver): Promise<void> {
  await db.exec('CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT)')
  const rows = await db.all<{ value: string }>("SELECT value FROM meta WHERE key = 'schema_version'")
  const current = rows.length ? Number(rows[0].value) : 0
  if (current >= SCHEMA_VERSION) return
  await db.transaction(async () => {
    const v = await migrate((sql) => db.run(sql), current)
    await db.run("INSERT INTO meta(key, value) VALUES('schema_version', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value", [String(v)])
  })
}
