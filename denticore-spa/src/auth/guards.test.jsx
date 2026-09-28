import { screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { makeUser, renderPage } from '../test/utils'
import { RequireAuth, RequireFeature, RequireTwoFactor } from './guards'

describe('RequireAuth', () => {
  it('sends a visitor without session to the login of the clinic in the URL', () => {
    renderPage(
      <RequireAuth>
        <p>Privado</p>
      </RequireAuth>,
      { route: '/c/clinica-demo/app/pacientes', path: '/c/:slug/app/pacientes' },
    )

    expect(screen.getByTestId('location')).toHaveTextContent('/c/clinica-demo/login')
  })

  it('sends a user to the home of their own clinic when the URL names another one', () => {
    renderPage(
      <RequireAuth>
        <p>Privado</p>
      </RequireAuth>,
      { user: makeUser('dentist'), route: '/c/otra-clinica/app/pacientes', path: '/c/:slug/app/pacientes' },
    )

    // La redirección llega a /c/clinica-demo/app/pacientes, que sí es su clínica.
    expect(screen.getByText('Privado')).toBeInTheDocument()
  })

  it('renders nothing while the session is loading', () => {
    const { container } = renderPage(
      <RequireAuth>
        <p>Privado</p>
      </RequireAuth>,
      { route: '/admin', path: '/admin', auth: { isLoading: true } },
    )

    expect(container).toBeEmptyDOMElement()
  })

  it('renders the page for the user of the clinic', () => {
    renderPage(
      <RequireAuth>
        <p>Privado</p>
      </RequireAuth>,
      { user: makeUser('dentist'), route: '/c/clinica-demo/app', path: '/c/:slug/app' },
    )

    expect(screen.getByText('Privado')).toBeInTheDocument()
  })
})

describe('RequireTwoFactor', () => {
  it('sends a pending second factor to the verification screen', () => {
    renderPage(
      <RequireTwoFactor>
        <p>Privado</p>
      </RequireTwoFactor>,
      { user: makeUser('dentist'), route: '/c/clinica-demo/app', path: '/c/:slug/app', auth: { twoFactor: 'pending' } },
    )

    expect(screen.getByTestId('location')).toHaveTextContent('/c/clinica-demo/login/2fa')
  })

  it('restricts an admin without 2FA to the setup screen of the area', () => {
    renderPage(
      <RequireTwoFactor>
        <p>Privado</p>
      </RequireTwoFactor>,
      {
        user: makeUser('clinic_admin'),
        route: '/c/clinica-demo/app/usuarios',
        path: '/c/:slug/app/usuarios',
        auth: { twoFactor: 'setup' },
      },
    )

    expect(screen.getByTestId('location')).toHaveTextContent('/c/clinica-demo/app/seguridad/2fa')
  })

  it('lets the request through when no second factor is pending', () => {
    renderPage(
      <RequireTwoFactor>
        <p>Privado</p>
      </RequireTwoFactor>,
      { user: makeUser('dentist'), route: '/x', path: '/x' },
    )

    expect(screen.getByText('Privado')).toBeInTheDocument()
  })
})

describe('RequireFeature', () => {
  it('hides an area that the clinic plan does not include', () => {
    renderPage(
      <RequireFeature feature="risk" fallbackPath="/c/clinica-demo/app">
        <p>Riesgo</p>
      </RequireFeature>,
      { user: makeUser('dentist'), route: '/riesgo', path: '/riesgo' },
    )

    expect(screen.queryByText('Riesgo')).not.toBeInTheDocument()
    expect(screen.getByTestId('location')).toHaveTextContent('/c/clinica-demo/app')
  })

  it('shows the area when the plan includes it', () => {
    const user = makeUser('dentist', { tenant: { slug: 'clinica-demo', features: { risk: true } } })
    renderPage(
      <RequireFeature feature="risk" fallbackPath="/c/clinica-demo/app">
        <p>Riesgo</p>
      </RequireFeature>,
      { user, route: '/riesgo', path: '/riesgo' },
    )

    expect(screen.getByText('Riesgo')).toBeInTheDocument()
  })
})
