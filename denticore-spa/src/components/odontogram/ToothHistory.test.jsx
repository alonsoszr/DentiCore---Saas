import { render, screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { ToothHistory } from './ToothHistory'

const author = { id: 'u-1', name: 'Dra. Pérez', cop: '12345' }
const caries = { code: 'CARIES', name: 'Lesión de caries dental', acronym: null }

const history = [
  {
    id: 'e-1',
    entry_type: 'inicial',
    tooth: 36,
    surfaces: ['O', 'M'],
    finding: caries,
    state: { code: 'CD', name: 'Lesión de caries dental a nivel de la dentina', acronym: 'CD' },
    color: 'rojo',
    origin: 'manual',
    note: 'Lesión cavitada',
    corrects_entry_id: null,
    correction_kind: null,
    correction_reason: null,
    corrected_by_id: 'e-2',
    author,
    recorded_at: '2026-10-06T15:00:00.000000Z',
  },
  {
    id: 'e-2',
    entry_type: 'correccion',
    tooth: 36,
    surfaces: ['O'],
    finding: caries,
    state: { code: 'CE', name: 'Lesión de caries dental a nivel del esmalte', acronym: 'CE' },
    color: 'rojo',
    origin: 'ia',
    note: null,
    corrects_entry_id: 'e-1',
    correction_kind: 'reemplazo',
    correction_reason: 'La lesión no alcanza la dentina',
    corrected_by_id: null,
    author: { id: 'u-2', name: 'Dr. Ramos', cop: '67890' },
    recorded_at: '2026-10-06T15:20:00.000000Z',
  },
]

describe('ToothHistory', () => {
  it('lists the entries of the tooth in chronological order with the corrections marked', () => {
    render(<ToothHistory tooth={36} entries={history} />)

    expect(screen.getByRole('heading', { name: 'Historial de la pieza 36' })).toBeInTheDocument()
    const [original, correction] = screen.getAllByRole('listitem')

    expect(within(original).getByText('Corregida')).toBeInTheDocument()
    expect(
      within(original).getByText('Lesión de caries dental · Lesión de caries dental a nivel de la dentina'),
    ).toHaveClass('line-through')
    expect(within(original).getByText('Motivo de la corrección: La lesión no alcanza la dentina')).toBeInTheDocument()
    expect(within(original).getByRole('link', { name: 'Ver la corrección' })).toHaveAttribute('href', '#entry-e-2')
    expect(within(original).getByText('CD')).toHaveClass('text-odontogram-red')
    expect(within(original).getByText('Superficies: oclusal, mesial')).toBeInTheDocument()
    expect(within(original).getByText('Inicial · Manual')).toBeInTheDocument()
    expect(within(original).getByText('Dra. Pérez · COP 12345')).toBeInTheDocument()
    expect(within(original).getByText('06/10/2026 10:00')).toBeInTheDocument()

    expect(correction).toHaveAttribute('id', 'entry-e-2')
    expect(within(correction).getByText('Corrección (reemplazo) · IA')).toBeInTheDocument()
    expect(within(correction).getByText('IA')).toHaveClass('text-ai-origin')
    expect(within(correction).getByText('Motivo: La lesión no alcanza la dentina')).toBeInTheDocument()
  })

  it('says when the tooth has no entries', () => {
    render(<ToothHistory tooth={11} entries={[]} />)

    expect(screen.getByText('La pieza 11 no tiene registros.')).toBeInTheDocument()
  })
})
