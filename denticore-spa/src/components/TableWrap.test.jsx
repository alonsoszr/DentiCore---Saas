import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { TableWrap } from './TableWrap'

// RNF-052 y WCAG 2.1.1: la tabla que se desplaza en horizontal se puede recorrer con el teclado y
// tiene nombre, tomado del encabezado visible o de `label`.
describe('TableWrap (RNF-052)', () => {
  it('takes its name from the visible heading and receives the keyboard focus', () => {
    render(
      <>
        <h2 id="items-title">Ítems del plan</h2>
        <TableWrap labelledBy="items-title">
          <table />
        </TableWrap>
      </>,
    )

    const group = screen.getByRole('group', { name: 'Ítems del plan' })
    expect(group).toHaveAttribute('tabindex', '0')
    expect(group).toHaveClass('table-wrap')
  })

  it('uses the label when the table has no heading of its own', () => {
    render(
      <TableWrap label="Citas del lunes 12/10/2026">
        <table />
      </TableWrap>,
    )

    expect(screen.getByRole('group', { name: 'Citas del lunes 12/10/2026' })).toBeInTheDocument()
  })
})
