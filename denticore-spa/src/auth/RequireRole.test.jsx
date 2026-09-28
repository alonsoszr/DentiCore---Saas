import { screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { makeUser, renderPage } from '../test/utils'
import { RequireRole } from './RequireRole'

// T-166 (SDD §6.3.10; DD-02)
describe('RequireRole', () => {
  it('redirects a receptionist away from dentist only routes', () => {
    renderPage(
      <RequireRole allow={['dentist']} forbiddenPath="/c/clinica-demo/app/sin-acceso">
        <p>Odontograma</p>
      </RequireRole>,
      {
        user: makeUser('receptionist'),
        route: '/c/clinica-demo/app/atenciones/1',
        path: '/c/:slug/app/atenciones/:id',
      },
    )

    expect(screen.queryByText('Odontograma')).not.toBeInTheDocument()
    expect(screen.getByTestId('location')).toHaveTextContent('/c/clinica-demo/app/sin-acceso')
  })

  it('renders the route for an allowed role', () => {
    renderPage(
      <RequireRole allow={['dentist']} forbiddenPath="/sin-acceso">
        <p>Odontograma</p>
      </RequireRole>,
      { user: makeUser('dentist'), route: '/x', path: '/x' },
    )

    expect(screen.getByText('Odontograma')).toBeInTheDocument()
  })
})
