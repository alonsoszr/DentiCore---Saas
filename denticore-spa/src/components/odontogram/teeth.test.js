import { describe, expect, it } from 'vitest'
import { ALL_TEETH, ROWS, isUpper, mesialIsRight, rootCount, rowsFor, surfacesFor } from './teeth'

describe('teeth (anexo gráfico NTS 188)', () => {
  it('lays out the 52 teeth in the four rows of the annex', () => {
    expect(ALL_TEETH).toHaveLength(52)
    expect(new Set(ALL_TEETH).size).toBe(52)
    expect(ROWS.map((row) => row.teeth.join(' '))).toEqual([
      '18 17 16 15 14 13 12 11 21 22 23 24 25 26 27 28',
      '55 54 53 52 51 61 62 63 64 65',
      '85 84 83 82 81 71 72 73 74 75',
      '48 47 46 45 44 43 42 41 31 32 33 34 35 36 37 38',
    ])
  })

  it('shows the rows of the selected dentition', () => {
    expect(rowsFor('permanente').map((row) => row.id)).toEqual(['upper-permanent', 'lower-permanent'])
    expect(rowsFor('temporal').map((row) => row.id)).toEqual(['upper-temporal', 'lower-temporal'])
    expect(rowsFor('mixta')).toHaveLength(4)
  })

  it('offers the surfaces of RN-18 for each tooth', () => {
    expect(surfacesFor(11)).toEqual(['V', 'P', 'M', 'D', 'I'])
    expect(surfacesFor(36)).toEqual(['V', 'L', 'M', 'D', 'O'])
    expect(surfacesFor(55)).toEqual(['V', 'P', 'M', 'D', 'O'])
    expect(surfacesFor(83)).toEqual(['V', 'L', 'M', 'D', 'I'])
  })

  it('draws the roots and the mesial side as in the annex', () => {
    expect([18, 16, 14, 15, 13, 36, 45, 55, 53, 75].map(rootCount)).toEqual([3, 3, 2, 1, 1, 2, 1, 3, 1, 2])
    expect([16, 46, 55, 85].every(mesialIsRight)).toBe(true)
    expect([26, 36, 65, 75].some(mesialIsRight)).toBe(false)
    expect([11, 28, 55, 65].every(isUpper)).toBe(true)
    expect([31, 48, 71, 85].some(isUpper)).toBe(false)
  })
})
