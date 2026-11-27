import { defineStore } from 'pinia'
import * as repo from '@/data/repo'
import { type PeriodKey, rangeFor } from '@/data/period'
import { getDb } from '@/db'
import type { SqlDriver } from '@/db/driver'
import { AuthError, hasSession, login as apiLogin, logout as apiLogout, NetworkError, syncNow } from '@/sync/engine'

export type Context = 'general' | 'PER' | 'LMS'
export type ThemeMode = 'system' | 'light' | 'dark'

interface State {
  ready: boolean
  db: SqlDriver | null
  spaces: repo.Space[]
  accounts: repo.FinancialAccountRow[]
  accountCurrencies: repo.AccountCurrencyRow[]
  categories: repo.CategoryRow[]
  currencies: { code: string; name: string }[]
  balances: repo.AccountBalance[]
  dashboard: repo.DashboardData | null
  movements: repo.RecentEntry[]
  annual: repo.AnnualRow[]
  newOp: null | 'income' | 'expense' | 'transfer'
  context: Context
  period: PeriodKey
  custom: { from: string; to: string }
  counts: { pending: number; problems: number }
  problems: repo.OutboxRow[]
  loggedIn: boolean
  syncing: boolean
  lastSync: string | null
  message: string | null
  theme: ThemeMode
  serverUrl: string
}

export const useApp = defineStore('app', {
  state: (): State => ({
    ready: false, db: null, spaces: [], accounts: [], accountCurrencies: [], categories: [], currencies: [], balances: [], dashboard: null, movements: [], annual: [], newOp: null,
    context: 'general', period: 'month', custom: { from: '', to: '' }, counts: { pending: 0, problems: 0 }, problems: [],
    loggedIn: false, syncing: false, lastSync: null, message: null, theme: 'light', serverUrl: '',
  }),

  getters: {
    /** Espacios visibles según el selector de contexto. General consolida ambos sin crear un tercer espacio. */
    activeSpaceIds(s): string[] {
      return s.context === 'general' ? s.spaces.map((x) => x.id) : s.spaces.filter((x) => x.code === s.context).map((x) => x.id)
    },
    spaceCode: (s) => (id: string) => s.spaces.find((x) => x.id === id)?.code ?? '',
  },

  actions: {
    async init() {
      if (this.ready) return
      this.db = await getDb()
      this.theme = ((await repo.getMeta(this.db, 'theme')) as ThemeMode | null) ?? 'light'
      this.serverUrl = (await repo.getMeta(this.db, 'server_url')) ?? ''
      this.lastSync = await repo.getMeta(this.db, 'last_sync')
      this.loggedIn = await hasSession()
      this.applyTheme()
      this.ready = true
      await this.refresh()
      if (this.loggedIn) void this.sync(true)
    },

    applyTheme() {
      const dark = this.theme === 'dark' || (this.theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches)
      document.documentElement.classList.toggle('ion-palette-dark', dark)
    },
    async setTheme(t: ThemeMode) {
      this.theme = t
      this.applyTheme()
      if (this.db) await repo.setMeta(this.db, 'theme', t)
    },

    async refresh() {
      const db = this.db
      if (!db) return
      this.spaces = await repo.listSpaces(db)
      this.accounts = await repo.listFinancialAccounts(db)
      this.accountCurrencies = await repo.listAccountCurrencies(db)
      this.categories = await repo.listCategories(db)
      this.currencies = await repo.listCurrencies(db)
      this.counts = await repo.outboxCounts(db)
      this.problems = await repo.outboxProblems(db)
      const ids = this.activeSpaceIds
      this.balances = await repo.listBalances(db, ids)
      const r = rangeFor(this.period, new Date(), this.period === 'custom' ? this.custom : undefined)
      this.dashboard = await repo.computeDashboard(db, ids, r.from, r.to, r.prevFrom, r.prevTo)
      this.movements = await repo.recentEntries(db, ids, 300)
      this.annual = await repo.computeAnnual(db, ids)
    },

    async setContext(c: Context) { this.context = c; await this.refresh() },
    async setPeriod(p: PeriodKey) {
      if (p === 'custom' && !this.custom.from) {
        const r = rangeFor('month')
        this.custom = { from: r.from, to: r.to }
      }
      this.period = p
      await this.refresh()
    },
    async setCustom(from: string, to: string) { this.custom = { from, to }; this.period = 'custom'; await this.refresh() },

    /** Ejecuta una escritura local y sincroniza en segundo plano si hay sesión. Los errores de dominio se muestran al usuario. */
    async write<T>(fn: (db: SqlDriver) => Promise<T>): Promise<T | undefined> {
      if (!this.db) return undefined
      try {
        const res = await fn(this.db)
        await this.refresh()
        if (this.loggedIn) void this.sync(true)
        return res
      } catch (e) {
        if (e instanceof repo.DomainError) { this.message = e.errors.join('\n'); return undefined }
        throw e
      }
    },

    async sync(silent = false) {
      if (!this.db || this.syncing) return
      this.syncing = true
      try {
        const s = await syncNow(this.db)
        this.lastSync = new Date().toISOString()
        await repo.setMeta(this.db, 'last_sync', this.lastSync)
        if (!silent) this.message = `Sincronizado: ${s.pushed} enviadas, ${s.pulled} registros recibidos` + (s.rejected + s.conflicts ? `, ${s.rejected + s.conflicts} con problemas` : '')
      } catch (e) {
        if (e instanceof AuthError) { this.loggedIn = false; if (!silent) this.message = (e as Error).message }
        else if (e instanceof NetworkError) { if (!silent) this.message = 'Sin conexión: las operaciones quedan guardadas en el dispositivo.' }
        else if (!silent) this.message = (e as Error).message
      } finally {
        this.syncing = false
        await this.refresh()
      }
    },

    async login(url: string, email: string, password: string, totp?: string): Promise<boolean> {
      if (!this.db) return false
      try {
        await apiLogin(this.db, url, email, password, totp)
        this.serverUrl = url
        this.loggedIn = true
        await this.sync(false)
        return true
      } catch (e) {
        const err = e as Error & { body?: { two_factor_required?: boolean } }
        this.message = err.body?.two_factor_required ? 'Ingresa el código de tu app autenticadora (TOTP) o un código de recuperación.' : err.message
        return false
      }
    },

    async logout() {
      if (!this.db) return
      await apiLogout(this.db)
      this.loggedIn = false
    },
  },
})
