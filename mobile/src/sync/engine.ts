import { Preferences } from '@capacitor/preferences'
import type { SqlDriver } from '@/db/driver'
import { getMeta, markOperation, pendingOutbox, setMeta } from '@/data/repo'
import { uuid } from '@/domain/uuid'

export class NetworkError extends Error {}
export class AuthError extends Error {}

const TOKEN_KEY = 'gf_token'

export async function deviceId(db: SqlDriver): Promise<string> {
  let id = await getMeta(db, 'device_id')
  if (!id) {
    id = uuid()
    await setMeta(db, 'device_id', id)
  }
  return id
}

async function call<T>(db: SqlDriver, method: 'GET' | 'POST', path: string, body?: unknown, auth = true): Promise<T> {
  const base = (await getMeta(db, 'server_url')) ?? ''
  if (!base) throw new NetworkError('Configura la URL del servidor en Ajustes.')
  const headers: Record<string, string> = { Accept: 'application/json' }
  if (body !== undefined) headers['Content-Type'] = 'application/json'
  if (auth) {
    const token = (await Preferences.get({ key: TOKEN_KEY })).value
    if (!token) throw new AuthError('No hay sesión iniciada.')
    headers.Authorization = `Bearer ${token}`
  }

  const ctrl = new AbortController()
  const timer = setTimeout(() => ctrl.abort(), 30_000)
  let res: Response
  try {
    res = await fetch(`${base.replace(/\/$/, '')}/api/v1/${path}`, { method, headers, body: body === undefined ? undefined : JSON.stringify(body), signal: ctrl.signal })
  } catch {
    throw new NetworkError('Sin conexión con el servidor.')
  } finally {
    clearTimeout(timer)
  }
  if (res.status === 401) {
    if (auth) throw new AuthError('La sesión expiró o fue revocada.')
  }
  const json = (await res.json().catch(() => ({}))) as T & { message?: string }
  if (!res.ok) throw Object.assign(new Error(json.message ?? `Error ${res.status}`), { status: res.status, body: json })
  return json
}

export interface LoginResult { user: { name: string; email: string; reference_currency: string; reference_rate_type: string; two_factor: boolean } }

export async function login(db: SqlDriver, serverUrl: string, email: string, password: string, totp?: string): Promise<LoginResult> {
  await setMeta(db, 'server_url', serverUrl.trim())
  const res = await call<{ token: string } & LoginResult>(db, 'POST', 'auth/login', {
    email, password, totp_code: totp || undefined,
    device_id: await deviceId(db), device_name: 'Dispositivo móvil', platform: 'other',
  }, false)
  await Preferences.set({ key: TOKEN_KEY, value: res.token })
  await setMeta(db, 'user', JSON.stringify(res.user))
  return res
}

export async function logout(db: SqlDriver): Promise<void> {
  try { await call(db, 'POST', 'auth/logout', {}) } catch { /* sin conexión: se cierra la sesión local igualmente */ }
  await Preferences.remove({ key: TOKEN_KEY })
}

export const hasSession = async (): Promise<boolean> => !!(await Preferences.get({ key: TOKEN_KEY })).value

interface PushResult { id: string; status: 'applied' | 'rejected' | 'conflict' | 'pending_review'; error?: string | null; result?: unknown }

export interface SyncSummary { pushed: number; rejected: number; conflicts: number; pulled: number }

/** Envía la cola y luego trae cambios. Reintentos seguros: cada operación es idempotente por su UUID. */
export async function syncNow(db: SqlDriver): Promise<SyncSummary> {
  const summary: SyncSummary = { pushed: 0, rejected: 0, conflicts: 0, pulled: 0 }
  try {
    for (;;) {
      const batch = await pendingOutbox(db, 50)
      if (!batch.length) break
      const res = await call<{ results: PushResult[] }>(db, 'POST', 'sync/push', {
        operations: batch.map((o) => ({ id: o.id, entity: o.entity, action: o.action, payload: JSON.parse(o.payload), base_version: o.base_version, client_created_at: o.client_created_at })),
      })
      for (const r of res.results) {
        if (r.status === 'applied') { await markOperation(db, r.id, 'applied', null, r.result); summary.pushed++ }
        else if (r.status === 'conflict') { await markOperation(db, r.id, 'conflict', r.error ?? 'Conflicto de versión'); summary.conflicts++ }
        else { await markOperation(db, r.id, 'rejected', r.error ?? 'Rechazada'); summary.rejected++ }
      }
      // Si el servidor no devolvió resultado para alguna, se reintenta en la próxima sincronización.
      if (res.results.length < batch.length) break
    }

    let cursor = await getMeta(db, 'sync_cursor')
    for (;;) {
      const data = await call<PullResponse>(db, 'GET', `sync/pull${cursor ? `?since=${encodeURIComponent(cursor)}` : ''}`)
      summary.pulled += await applyPull(db, data)
      cursor = data.next_cursor
      await setMeta(db, 'sync_cursor', cursor)
      if (!data.has_more) break
    }
    await db.run("INSERT INTO sync_log(at, direction, ok, detail) VALUES(?, 'sync', 1, ?)", [new Date().toISOString(), JSON.stringify(summary)])
    await db.run("DELETE FROM outbox WHERE status = 'applied' AND seq NOT IN (SELECT seq FROM outbox WHERE status = 'applied' ORDER BY seq DESC LIMIT 100)")
    return summary
  } catch (e) {
    await db.run("INSERT INTO sync_log(at, direction, ok, detail) VALUES(?, 'sync', 0, ?)", [new Date().toISOString(), (e as Error).message])
    throw e
  }
}

export interface PullResponse {
  server_time: string
  next_cursor: string
  has_more: boolean
  spaces: { id: string; code: string; name: string }[]
  currencies: { code: string; name: string; minor_units: number; kind: string }[]
  financial_accounts: Record<string, unknown>[]
  financial_account_currencies: Record<string, unknown>[]
  ledger_accounts: Record<string, unknown>[]
  categories: Record<string, unknown>[]
  entries: (Record<string, unknown> & { lines: Record<string, unknown>[] })[]
  tombstones: { entity: string; entity_id: string; action: string }[]
}

/** El servidor devuelve UTC como 'YYYY-MM-DD HH:MM:SS.mmm'; localmente se usa ISO-8601 para que el orden sea consistente. */
const toIso = (s: string): string => (/[zZ]|[+-]\d\d:\d\d$/.test(s) ? s : `${s.replace(' ', 'T')}Z`)

/** Upsert por UUID: recibir un registro dos veces es inofensivo. Devuelve cuántos registros se aplicaron. */
export async function applyPull(db: SqlDriver, d: PullResponse): Promise<number> {
  let n = 0
  await db.transaction(async () => {
    for (const s of d.spaces) { await db.run('INSERT INTO spaces(id, code, name) VALUES(?, ?, ?) ON CONFLICT(id) DO UPDATE SET code = excluded.code, name = excluded.name', [s.id, s.code, s.name]); n++ }
    for (const c of d.currencies) await db.run('INSERT INTO currencies(code, name, minor_units, kind) VALUES(?, ?, ?, ?) ON CONFLICT(code) DO UPDATE SET name = excluded.name', [c.code, c.name, c.minor_units, c.kind])
    for (const a of d.financial_accounts) {
      await db.run(
        `INSERT INTO financial_accounts(id, space_id, name, alias, kind, is_active, is_archived, version, pending) VALUES(?, ?, ?, ?, ?, ?, ?, ?, 0)
         ON CONFLICT(id) DO UPDATE SET name = excluded.name, alias = excluded.alias, kind = excluded.kind, is_active = excluded.is_active, is_archived = excluded.is_archived, version = excluded.version, pending = 0`,
        [a.id, a.space_id, a.name, a.alias ?? null, a.kind, a.is_active ? 1 : 0, a.is_archived ? 1 : 0, a.version],
      ); n++
    }
    for (const l of d.ledger_accounts) {
      await db.run(
        `INSERT INTO ledger_accounts(id, space_id, code, name, type, system_role, is_group, currency, is_active) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON CONFLICT(id) DO UPDATE SET name = excluded.name, is_active = excluded.is_active`,
        [l.id, l.space_id, l.code, l.name, l.type, l.system_role ?? null, l.is_group ? 1 : 0, l.currency ?? null, l.is_active ? 1 : 0],
      ); n++
    }
    for (const c of d.financial_account_currencies) {
      await db.run(
        `INSERT INTO account_currencies(id, space_id, financial_account_id, currency, ledger_account_id) VALUES(?, ?, ?, ?, ?)
         ON CONFLICT(id) DO UPDATE SET currency = excluded.currency`,
        [c.id, c.space_id, c.financial_account_id, c.currency, c.ledger_account_id],
      )
    }
    for (const c of d.categories) {
      await db.run(
        `INSERT INTO categories(id, space_id, parent_id, name, kind, group_key, is_active, version, pending) VALUES(?, ?, ?, ?, ?, ?, ?, ?, 0)
         ON CONFLICT(id) DO UPDATE SET name = excluded.name, kind = excluded.kind, group_key = excluded.group_key, is_active = excluded.is_active, version = excluded.version, pending = 0`,
        [c.id, c.space_id ?? null, c.parent_id ?? null, c.name, c.kind, c.group_key ?? null, c.is_active ? 1 : 0, c.version],
      ); n++
    }
    for (const e of d.entries) {
      // El asiento confirmado del servidor reemplaza al provisional local (mismo UUID).
      await db.run('DELETE FROM lines WHERE entry_id = ?', [e.id as string])
      await db.run(
        `INSERT INTO entries(id, space_id, operation_id, number, status, kind, currency, occurred_at, accounting_date, description, sync_op_id)
         VALUES(?, ?, ?, ?, 'confirmed', ?, ?, ?, ?, ?, NULL)
         ON CONFLICT(id) DO UPDATE SET number = excluded.number, status = 'confirmed', kind = excluded.kind, occurred_at = excluded.occurred_at,
           accounting_date = excluded.accounting_date, description = excluded.description, sync_op_id = NULL`,
        [e.id, e.space_id, e.operation_id ?? null, e.number, e.kind, e.currency, toIso(String(e.occurred_at)), e.accounting_date ?? null, e.description ?? ''],
      )
      for (const l of e.lines) {
        await db.run('INSERT INTO lines(id, entry_id, space_id, line_no, ledger_account_id, debit, credit, category_id) VALUES(?, ?, ?, ?, ?, ?, ?, ?)',
          [l.id, l.entry_id, l.space_id, l.line_no, l.ledger_account_id, String(l.debit), String(l.credit), l.category_id ?? null])
      }
      n++
    }
    for (const t of d.tombstones) {
      if (t.entity === 'financial_account') await db.run('UPDATE financial_accounts SET is_active = 0, is_archived = ? WHERE id = ?', [t.action === 'archive' ? 1 : 0, t.entity_id])
      if (t.entity === 'category') await db.run('UPDATE categories SET is_active = 0 WHERE id = ?', [t.entity_id])
    }
  })
  return n
}
