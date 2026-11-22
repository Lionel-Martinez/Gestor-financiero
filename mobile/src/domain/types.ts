export type AccountType = 'asset' | 'liability' | 'equity' | 'income' | 'expense'
export type EntryKind = 'ordinary' | 'opening' | 'fx' | 'adjustment' | 'reversal'
export type CategoryKind = 'income' | 'expense' | 'both'
export type FinancialKind = 'bank' | 'wallet' | 'virtual' | 'cash' | 'usd_cash' | 'digital_usd' | 'credit_card' | 'other'

export interface LedgerAccountInfo {
  space_id: string
  type: AccountType
  is_group: boolean
  is_active: boolean
  currency: string | null
}
export interface CategoryInfo {
  space_id: string | null
  kind: CategoryKind
  is_active: boolean
}
export interface LineDraft {
  ledger_account_id: string
  debit: string
  credit: string
  category_id: string | null
}
export interface EntryHeader {
  space_id: string
  currency: string
  kind: EntryKind
  occurred_at: string
  description: string
}
export interface Posting {
  entry: EntryHeader
  lines: LineDraft[]
}
