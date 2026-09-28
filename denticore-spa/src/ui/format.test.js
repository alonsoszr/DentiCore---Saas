import { describe, expect, it } from 'vitest'
import { ageFrom, formatCivilDate, formatCurrency, formatDate, formatDateTime } from './format'

// T-167 (SDD §6.3.10; RF-009, RNF-189)
describe('formats', () => {
  it('formats currency as S/ 1,234.56 and dates as dd/mm/aaaa in America/Lima', () => {
    // Intl separa el símbolo con un espacio de no separación (no parte la línea).
    expect(formatCurrency('1234.56').replace(/\s/g, ' ')).toBe('S/ 1,234.56')
    // 04:30 UTC del 6 de octubre todavía es 5 de octubre en Lima (UTC-5).
    expect(formatDate('2026-10-06T04:30:00Z')).toBe('05/10/2026')
    expect(formatDateTime('2026-10-05T19:05:00Z')).toBe('05/10/2026 14:05')
  })

  it('uses 24 hour time', () => {
    expect(formatDateTime('2026-10-06T03:00:00Z')).toBe('05/10/2026 22:00')
  })

  it('formats civil dates without time zone shifts', () => {
    expect(formatCivilDate('1990-01-01')).toBe('01/01/1990')
  })

  it('shows a dash for empty values', () => {
    expect(formatCurrency(null)).toBe('—')
    expect(formatDate(null)).toBe('—')
    expect(formatDateTime('')).toBe('—')
    expect(formatCivilDate(undefined)).toBe('—')
  })

  it('computes the age reached at a given day', () => {
    const today = new Date(2026, 9, 5)

    expect(ageFrom('2000-10-05', today)).toBe(26)
    expect(ageFrom('2000-10-06', today)).toBe(25)
    expect(ageFrom(null, today)).toBeNull()
  })
})
