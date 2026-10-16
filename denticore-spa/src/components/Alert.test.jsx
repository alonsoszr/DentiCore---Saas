import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { Alert } from './Alert'

describe('Alert', () => {
  it('accepts lists in its content without invalid markup (ul inside p)', () => {
    render(
      <Alert tone="error" title="No se puede cerrar la atención">
        <ul>
          <li>Registre el motivo de consulta.</li>
        </ul>
      </Alert>,
    )

    const item = screen.getByText('Registre el motivo de consulta.')
    expect(item.closest('p')).toBeNull()
    expect(screen.getByRole('alert')).toHaveTextContent('No se puede cerrar la atención')
  })
})
