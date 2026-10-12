import { describe, expect, it } from 'vitest'
import {
  addDays,
  ageFrom,
  formatCivilDate,
  formatCurrency,
  formatDate,
  formatDateTime,
  formatTime,
  isoWeekday,
  localDate,
  zonedDateTimeToIso,
} from './format'

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

describe('clinic time zone helpers', () => {
  it('converts between instants and local dates and times of the clinic', () => {
    expect(formatTime('2026-11-02T14:00:00Z')).toBe('09:00')
    expect(localDate('2026-11-03T03:30:00Z')).toBe('2026-11-02')
    expect(zonedDateTimeToIso('2026-11-02', '09:00')).toBe('2026-11-02T14:00:00.000Z')
    expect(zonedDateTimeToIso('2026-11-02', '23:30', 'Asia/Tokyo')).toBe('2026-11-02T14:30:00.000Z')
  })

  it('moves civil dates and gets the ISO weekday', () => {
    expect(addDays('2026-10-31', 2)).toBe('2026-11-02')
    expect(isoWeekday('2026-11-02')).toBe(1)
    expect(isoWeekday('2026-11-08')).toBe(7)
  })
})
