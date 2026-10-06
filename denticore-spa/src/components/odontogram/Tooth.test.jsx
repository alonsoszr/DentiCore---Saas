import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { Tooth } from './Tooth'

/** Entrada del odontograma como la devuelve la API (OdontogramEntryResource). */
function entry(overrides = {}) {
  return {
    id: crypto.randomUUID(),
    entry_type: 'evolucion',
    tooth: 36,
    tooth_end: null,
    surfaces: ['O'],
    finding: { code: 'CARIES', name: 'Lesión de caries dental', acronym: null },
    state: { code: 'CD', name: 'Lesión de caries dental a nivel de la dentina', acronym: 'CD' },
    color: 'rojo',
    origin: 'manual',
    ...overrides,
  }
}

function renderTooth(props) {
  return render(
    <svg>
      <Tooth tooth={36} entries={[]} {...props} />
    </svg>,
  )
}

describe('Tooth', () => {
  it('shows the acronym next to every colored finding', () => {
    renderTooth({
      entries: [
        entry(),
        entry({
          surfaces: [],
          finding: { code: 'IMPLANTE', name: 'Implante dental', acronym: 'IMP' },
          state: { code: 'BUENO', name: 'Buen estado', acronym: null },
          color: 'azul',
        }),
        entry({
          surfaces: [],
          finding: { code: 'MOVILIDAD', name: 'Movilidad patológica', acronym: null },
          state: { code: 'M2', name: 'Movilidad de grado 2', acronym: 'M2' },
          color: 'rojo',
        }),
      ],
    })

    const box = screen.getByTestId('acronyms-36')
    const acronyms = within(box)
      .getAllByText(/./)
      .map((node) => [node.textContent, node.getAttribute('class')])

    expect(acronyms).toEqual([
      ['CD', expect.stringContaining('fill-odontogram-red')],
      ['IMP', expect.stringContaining('fill-odontogram-blue')],
      ['M2', expect.stringContaining('fill-odontogram-red')],
    ])
  })

  it('paints the finding surfaces with its color only', () => {
    renderTooth({ entries: [entry({ surfaces: ['O', 'M'] })] })

    expect(screen.getByTestId('surface-36-O')).toHaveAttribute('class', expect.stringContaining('fill-odontogram-red'))
    expect(screen.getByTestId('surface-36-M')).toHaveAttribute('class', expect.stringContaining('fill-odontogram-red'))
    expect(screen.getByTestId('surface-36-D')).toHaveAttribute('class', expect.stringContaining('fill-transparent'))
  })

  it('names findings without an official acronym through their symbol', () => {
    renderTooth({
      entries: [
        entry({
          surfaces: [],
          finding: { code: 'FRACTURA', name: 'Fractura dental', acronym: null },
          state: { code: 'PRESENTE', name: 'Presente', acronym: null },
        }),
      ],
    })

    expect(screen.getByRole('button', { name: /Pieza 36: Fractura dental/ })).toBeInTheDocument()
    expect(screen.getByTestId('glyph-36-FRACTURA')).toHaveAttribute(
      'class',
      expect.stringContaining('stroke-odontogram-red'),
    )
    expect(within(screen.getByTestId('acronyms-36')).queryAllByText(/./)).toHaveLength(0)
  })

  it('selects the tooth and toggles its surfaces without calling the server', async () => {
    const onSelect = vi.fn()
    const onToggleSurface = vi.fn()
    renderTooth({ selected: true, selectedSurfaces: ['O'], onSelect, onToggleSurface })

    await userEvent.click(screen.getByRole('button', { name: /Pieza 36/ }))
    await userEvent.click(screen.getByTestId('surface-36-M'))

    expect(onSelect).toHaveBeenCalledWith(36)
    expect(onToggleSurface).toHaveBeenCalledWith('M')
    expect(screen.getByRole('button', { name: /Pieza 36/ })).toHaveAttribute('aria-pressed', 'true')
    expect(screen.getByTestId('surface-36-O')).toHaveAttribute('data-selected', 'true')
  })
})
