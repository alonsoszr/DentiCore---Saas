import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { describe, expect, it, vi } from 'vitest'
import { Odontogram } from './Odontogram'

function Harness({ entries = [], dentition = 'permanente', onSelectTooth = () => {} }) {
  const [tooth, setTooth] = useState(null)
  const [surfaces, setSurfaces] = useState([])

  return (
    <Odontogram
      entries={entries}
      dentition={dentition}
      selectedTooth={tooth}
      selectedSurfaces={surfaces}
      onSelectTooth={(next) => {
        setTooth(next)
        setSurfaces([])
        onSelectTooth(next)
      }}
      onToggleSurface={(surface) =>
        setSurfaces((current) =>
          current.includes(surface) ? current.filter((s) => s !== surface) : [...current, surface],
        )
      }
    />
  )
}

describe('Odontogram', () => {
  it('draws the teeth of the dentition with the legend always visible', () => {
    const { rerender } = render(<Harness dentition="permanente" />)
    expect(screen.getAllByRole('button', { name: /^Pieza \d\d/ })).toHaveLength(32)

    rerender(<Harness dentition="mixta" />)
    expect(screen.getAllByRole('button', { name: /^Pieza \d\d/ })).toHaveLength(52)
    expect(
      screen.getByText(
        'Rojo: patología o mal estado · Azul: buen estado o tratamiento realizado · Siglas según NTS N° 188',
      ),
    ).toBeInTheDocument()
  })

  it('selects a tooth by its number and a surface by its letter from the keyboard', async () => {
    const onSelectTooth = vi.fn()
    render(<Harness onSelectTooth={onSelectTooth} />)

    await userEvent.click(screen.getByRole('button', { name: /^Pieza 18/ }))
    await userEvent.keyboard('36')
    expect(onSelectTooth).toHaveBeenLastCalledWith(36)
    expect(screen.getByRole('button', { name: /^Pieza 36/ })).toHaveFocus()
    expect(screen.getByRole('status')).toHaveTextContent('Pieza 36 seleccionada')

    // Oclusal y mesial aplican al molar; incisal no (RN-18).
    await userEvent.keyboard('omi')
    expect(screen.getByTestId('surface-36-O')).toHaveAttribute('data-selected', 'true')
    expect(screen.getByTestId('surface-36-M')).toHaveAttribute('data-selected', 'true')
    expect(screen.queryByTestId('surface-36-I')).not.toBeInTheDocument()
    expect(screen.getByRole('status')).toHaveTextContent('La superficie incisal no aplica a la pieza 36')

    // Un número que no existe en la dentición se ignora.
    await userEvent.keyboard('19')
    expect(onSelectTooth).toHaveBeenLastCalledWith(36)
  })

  it('moves the focus across the row with the arrow keys', async () => {
    render(<Harness />)

    await userEvent.click(screen.getByRole('button', { name: /^Pieza 11/ }))
    await userEvent.keyboard('{ArrowRight}')
    expect(screen.getByRole('button', { name: /^Pieza 21/ })).toHaveFocus()
    await userEvent.keyboard('{ArrowDown}')
    expect(screen.getByRole('button', { name: /^Pieza 31/ })).toHaveFocus()
    await userEvent.keyboard('{ArrowLeft}{Enter}')
    expect(screen.getByRole('button', { name: /^Pieza 41/ })).toHaveAttribute('aria-pressed', 'true')
  })

  it('draws span findings between their end teeth with the acronym of the norm', () => {
    render(
      <Harness
        entries={[
          {
            id: 'e-1',
            entry_type: 'evolucion',
            tooth: 13,
            tooth_end: 23,
            surfaces: [],
            finding: { code: 'PROTESIS_FIJA', name: 'Prótesis dental parcial fija', acronym: null },
            state: { code: 'MALO', name: 'Mal estado', acronym: null },
            color: 'rojo',
            origin: 'manual',
          },
          {
            id: 'e-2',
            entry_type: 'evolucion',
            tooth: 11,
            tooth_end: 21,
            surfaces: [],
            finding: { code: 'SUPERNUMERARIA', name: 'Pieza dentaria supernumeraria', acronym: 'S' },
            state: { code: 'PRESENTE', name: 'Presente', acronym: null },
            color: 'azul',
            origin: 'manual',
          },
        ]}
      />,
    )

    expect(
      screen.getByRole('img', { name: 'Prótesis dental parcial fija, mal estado: piezas 13 a 23' }),
    ).toHaveAttribute('class', expect.stringContaining('stroke-odontogram-red'))
    expect(
      screen.getByRole('img', { name: 'Pieza dentaria supernumeraria, presente: piezas 11 a 21' }),
    ).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /^Pieza 13: Prótesis dental parcial fija/ })).toBeInTheDocument()
  })
})
