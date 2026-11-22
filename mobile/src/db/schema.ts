/**
 * Esquema local (SQLite). Los importes se guardan como TEXT decimal exacto (nunca REAL): las sumas se hacen en BigInt.
 * Los UUID se generan en el dispositivo. `status` de un asiento local: confirmed | pending | rejected.
 */
export const SCHEMA_VERSION = 1

export const MIGRATIONS: string[][] = [
  [
    `CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT)`,
    `CREATE TABLE IF NOT EXISTS spaces (id TEXT PRIMARY KEY, code TEXT NOT NULL, name TEXT NOT NULL)`,
    `CREATE TABLE IF NOT EXISTS currencies (code TEXT PRIMARY KEY, name TEXT NOT NULL, minor_units INTEGER NOT NULL DEFAULT 2, kind TEXT NOT NULL DEFAULT 'fiat')`,
    `CREATE TABLE IF NOT EXISTS financial_accounts (
       id TEXT PRIMARY KEY, space_id TEXT NOT NULL, name TEXT NOT NULL, alias TEXT, kind TEXT NOT NULL,
       is_active INTEGER NOT NULL DEFAULT 1, is_archived INTEGER NOT NULL DEFAULT 0, version INTEGER NOT NULL DEFAULT 1,
       pending INTEGER NOT NULL DEFAULT 0)`,
    `CREATE TABLE IF NOT EXISTS account_currencies (
       id TEXT PRIMARY KEY, space_id TEXT NOT NULL, financial_account_id TEXT NOT NULL, currency TEXT NOT NULL, ledger_account_id TEXT NOT NULL)`,
    `CREATE TABLE IF NOT EXISTS ledger_accounts (
       id TEXT PRIMARY KEY, space_id TEXT NOT NULL, code TEXT NOT NULL, name TEXT NOT NULL, type TEXT NOT NULL,
       system_role TEXT, is_group INTEGER NOT NULL DEFAULT 0, currency TEXT, is_active INTEGER NOT NULL DEFAULT 1)`,
    `CREATE TABLE IF NOT EXISTS categories (
       id TEXT PRIMARY KEY, space_id TEXT, parent_id TEXT, name TEXT NOT NULL, kind TEXT NOT NULL, group_key TEXT,
       is_active INTEGER NOT NULL DEFAULT 1, version INTEGER NOT NULL DEFAULT 1, pending INTEGER NOT NULL DEFAULT 0)`,
    `CREATE TABLE IF NOT EXISTS entries (
       id TEXT PRIMARY KEY, space_id TEXT NOT NULL, operation_id TEXT, number TEXT, status TEXT NOT NULL,
       kind TEXT NOT NULL, currency TEXT NOT NULL, occurred_at TEXT NOT NULL, accounting_date TEXT,
       description TEXT NOT NULL DEFAULT '', sync_op_id TEXT)`,
    `CREATE INDEX IF NOT EXISTS idx_entries_space ON entries(space_id, status, occurred_at)`,
    `CREATE INDEX IF NOT EXISTS idx_entries_op ON entries(sync_op_id)`,
    `CREATE TABLE IF NOT EXISTS lines (
       id TEXT PRIMARY KEY, entry_id TEXT NOT NULL, space_id TEXT NOT NULL, line_no INTEGER NOT NULL,
       ledger_account_id TEXT NOT NULL, debit TEXT NOT NULL, credit TEXT NOT NULL, category_id TEXT)`,
    `CREATE INDEX IF NOT EXISTS idx_lines_entry ON lines(entry_id)`,
    // Cola de sincronización: persistente, ordenada y a prueba de cierres inesperados.
    `CREATE TABLE IF NOT EXISTS outbox (
       seq INTEGER PRIMARY KEY AUTOINCREMENT, id TEXT NOT NULL UNIQUE, entity TEXT NOT NULL, action TEXT NOT NULL,
       payload TEXT NOT NULL, base_version INTEGER, client_created_at TEXT NOT NULL,
       status TEXT NOT NULL DEFAULT 'pending', error TEXT, attempts INTEGER NOT NULL DEFAULT 0, result TEXT)`,
    `CREATE INDEX IF NOT EXISTS idx_outbox_status ON outbox(status, seq)`,
    `CREATE TABLE IF NOT EXISTS sync_log (
       id INTEGER PRIMARY KEY AUTOINCREMENT, at TEXT NOT NULL, direction TEXT NOT NULL, ok INTEGER NOT NULL, detail TEXT)`,
  ],
]

export async function migrate(run: (sql: string) => Promise<void>, current: number): Promise<number> {
  for (let v = current; v < MIGRATIONS.length; v++) {
    for (const stmt of MIGRATIONS[v]) await run(stmt)
  }
  return MIGRATIONS.length
}
