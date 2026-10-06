import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { makeUser, mockApi, renderPage } from '../test/utils'
import { LoginPage } from './LoginPage'

const CLINIC_ROUTE = { route: '/c/clinica-demo/login', path: '/c/:slug/login' }
const PLATFORM_ROUTE = { route: '/login', path: '/login' }

// Sin perfil público de la clínica por defecto (GET /public/clinics/{slug} → 404, PEND-08).
beforeEach(() => {
  mockApi(() => ({ status: 404, data: {} }))
})

function failWith(status, headers = {}) {
  return vi.fn(async () => {
    throw { response: { status, data: {}, headers } }
  })
}

async function submit(email, password) {
  if (email) await userEvent.type(screen.getByLabelText('Correo electrónico'), email)
  if (password) await userEvent.type(screen.getByLabelText('Contraseña'), password)
  await userEvent.click(screen.getByRole('button', { name: 'Ingresar' }))
}

describe('LoginPage', () => {
  it('logs into the clinic of the URL and goes to the staff area', async () => {
    const login = vi.fn(async () => ({ user: makeUser('receptionist') }))
    renderPage(<LoginPage />, { route: '/c/clinica-demo/login', path: '/c/:slug/login', auth: { login } })

    await userEvent.type(screen.getByLabelText('Correo electrónico'), 'recepcion@clinica-demo.test')
    await userEvent.type(screen.getByLabelText('Contraseña'), 'secreta')
    await userEvent.click(screen.getByRole('button', { name: 'Ingresar' }))

    expect(login).toHaveBeenCalledWith({
      tenantSlug: 'clinica-demo',
      email: 'recepcion@clinica-demo.test',
      password: 'secreta',
    })
    expect(await screen.findByTestId('location')).toHaveTextContent('/c/clinica-demo/app/pacientes')
  })

  it.each([
    ['verification (A3)', { requires_2fa: true, user: null }, '/c/clinica-demo/login/2fa'],
    ['setup (A4)', { requires_2fa_setup: true, user: null }, '/c/clinica-demo/app/seguridad/2fa'],
  ])('continues with the second factor %s', async (_, result, path) => {
    const login = vi.fn(async () => result)
    renderPage(<LoginPage />, { route: '/c/clinica-demo/login', path: '/c/:slug/login', auth: { login } })

    await userEvent.type(screen.getByLabelText('Correo electrónico'), 'admin@clinica-demo.test')
    await userEvent.type(screen.getByLabelText('Contraseña'), 'secreta')
    await userEvent.click(screen.getByRole('button', { name: 'Ingresar' }))

    expect(await screen.findByTestId('location')).toHaveTextContent(path)
  })

  it('shows the notice that arrives from activation or an expired session', () => {
    renderPage(<LoginPage />, {
      route: { pathname: '/c/clinica-demo/login', state: { notice: 'Tu cuenta está activa. Inicia sesión.' } },
      path: '/c/:slug/login',
    })

    expect(screen.getByText('Tu cuenta está activa. Inicia sesión.')).toBeInTheDocument()
  })

  it('shows the validation error next to the field', async () => {
    const error = { response: { status: 422, data: { errors: { email: ['Las credenciales son incorrectas.'] } } } }
    const login = vi.fn(async () => {
      throw error
    })
    renderPage(<LoginPage />, { route: '/login', path: '/login', auth: { login } })

    await userEvent.type(screen.getByLabelText('Correo electrónico'), 'admin@denticore.test')
    await userEvent.type(screen.getByLabelText('Contraseña'), 'mala')
    await userEvent.click(screen.getByRole('button', { name: 'Ingresar' }))

    expect(await screen.findByText('Las credenciales son incorrectas.')).toBeInTheDocument()
    expect(login).toHaveBeenCalledWith(expect.objectContaining({ tenantSlug: undefined }))
  })

  it('shows invalid credentials for a 401', async () => {
    const login = vi.fn(async () => {
      throw { response: { status: 401, data: {} } }
    })
    renderPage(<LoginPage />, { route: '/c/clinica-demo/login', path: '/c/:slug/login', auth: { login } })

    await userEvent.type(screen.getByLabelText('Correo electrónico'), 'x@y.test')
    await userEvent.type(screen.getByLabelText('Contraseña'), 'mala')
    await userEvent.click(screen.getByRole('button', { name: 'Ingresar' }))

    // Título exacto de la ficha auth-login (sin punto final).
    expect(await screen.findByText('Credenciales inválidas')).toBeInTheDocument()
  })

  it('takes the platform login to the login of a clinic by its code', async () => {
    renderPage(<LoginPage />, { route: '/login', path: '/login' })

    await userEvent.type(screen.getByLabelText(/código de acceso/), ' Clinica-Demo ')
    await userEvent.click(screen.getByRole('button', { name: 'Ir al acceso de la clínica' }))

    expect(screen.getByTestId('location')).toHaveTextContent('/c/clinica-demo/login')
  })

  it('redirects a user with a session to their home', () => {
    renderPage(<LoginPage />, { user: makeUser('super_admin'), route: '/login', path: '/login' })

    expect(screen.getByTestId('location')).toHaveTextContent('/admin/clinicas')
  })

  describe('clinic login (A1)', () => {
    it('shows the clinic name from its public profile', async () => {
      mockApi((config) =>
        config.url === '/public/clinics/clinica-demo'
          ? { data: { data: { name: 'Clínica Dental Demo' } } }
          : { status: 404, data: {} },
      )
      renderPage(<LoginPage />, CLINIC_ROUTE)

      expect((await screen.findAllByText('Clínica Dental Demo')).length).toBeGreaterThan(0)
      expect(screen.getByRole('heading', { level: 1, name: 'Inicia sesión' })).toBeInTheDocument()
      expect(screen.getByText('Accede con tu correo y contraseña.')).toBeInTheDocument()
    })

    it('uses the clinic code of the URL while the public profile is not available', async () => {
      renderPage(<LoginPage />, CLINIC_ROUTE)

      expect((await screen.findAllByText('clinica-demo')).length).toBeGreaterThan(0)
    })

    it('shows the recovery link, the footer and the link to change the clinic', () => {
      renderPage(<LoginPage />, CLINIC_ROUTE)

      expect(screen.getByRole('link', { name: '¿Olvidaste tu contraseña?' })).toHaveAttribute(
        'href',
        '/c/clinica-demo/restablecer',
      )
      expect(screen.getByText('¿Problemas para ingresar? Contacta al administrador de tu clínica.')).toBeInTheDocument()
      expect(screen.getByText('Las cuentas del personal se crean solo por invitación.')).toBeInTheDocument()
      expect(screen.queryByRole('link', { name: /Contacta/ })).not.toBeInTheDocument()
      expect(screen.getByRole('link', { name: 'Cambiar de clínica' })).toHaveAttribute('href', '/login')
      expect(screen.getByPlaceholderText('nombre@correo.com')).toBeInTheDocument()
    })
  })

  describe('platform login (A2)', () => {
    it('shows the platform header and footer without the recovery link', () => {
      renderPage(<LoginPage />, PLATFORM_ROUTE)

      expect(screen.getByText('DentiCore · Administración de la plataforma')).toBeInTheDocument()
      expect(screen.getByText('Acceso restringido')).toBeInTheDocument()
      expect(screen.getByText('¿Problemas para ingresar? Contacta al equipo de DentiCore.')).toBeInTheDocument()
      expect(screen.getByText('Acceso exclusivo para administradores de la plataforma.')).toBeInTheDocument()
      expect(screen.queryByRole('link', { name: '¿Olvidaste tu contraseña?' })).not.toBeInTheDocument()
    })
  })

  describe('client validation', () => {
    it('requires both fields and does not call the API', async () => {
      const login = vi.fn()
      renderPage(<LoginPage />, { ...CLINIC_ROUTE, auth: { login } })

      await submit()

      expect(screen.getByText('Ingresa tu correo electrónico.')).toBeInTheDocument()
      expect(screen.getByText('Ingresa tu contraseña.')).toBeInTheDocument()
      // Los errores se anuncian con role="alert" (ficha auth-login › Criterios).
      expect(screen.getAllByRole('alert')).toHaveLength(2)
      expect(screen.getByLabelText('Correo electrónico')).toHaveFocus()
      expect(screen.getByLabelText('Correo electrónico')).toHaveAttribute('aria-invalid', 'true')
      expect(login).not.toHaveBeenCalled()
    })

    it('checks the email format and the 128-character limit of the password', async () => {
      const login = vi.fn()
      renderPage(<LoginPage />, { ...CLINIC_ROUTE, auth: { login } })

      await userEvent.type(screen.getByLabelText('Correo electrónico'), 'recepcion')
      await userEvent.click(screen.getByLabelText('Contraseña'))
      await userEvent.paste('x'.repeat(129))
      await userEvent.click(screen.getByRole('button', { name: 'Ingresar' }))

      expect(screen.getByText('Ingresa un correo electrónico válido.')).toBeInTheDocument()
      expect(screen.getByText('La contraseña no puede superar 128 caracteres.')).toBeInTheDocument()
      expect(login).not.toHaveBeenCalled()
    })

    it('does not apply the 10-character creation policy', async () => {
      const login = vi.fn(async () => ({ user: makeUser('receptionist') }))
      renderPage(<LoginPage />, { ...CLINIC_ROUTE, auth: { login } })

      await submit('recepcion@clinica-demo.test', 'a')

      expect(login).toHaveBeenCalledWith(expect.objectContaining({ password: 'a' }))
    })
  })

  describe('states', () => {
    it('disables the form while signing in', async () => {
      const login = vi.fn(() => new Promise(() => {}))
      renderPage(<LoginPage />, { ...CLINIC_ROUTE, auth: { login } })

      await submit('recepcion@clinica-demo.test', 'secreta')

      expect(screen.getByRole('button', { name: 'Ingresando…' })).toBeDisabled()
      expect(screen.getByLabelText('Correo electrónico')).toBeDisabled()
      expect(screen.getByLabelText('Contraseña')).toBeDisabled()
    })

    it('shows the same message for an unknown email and a wrong password (CA-06.3)', async () => {
      renderPage(<LoginPage />, { ...CLINIC_ROUTE, auth: { login: failWith(401) } })

      await submit('nadie@clinica-demo.test', 'secreta')
      const first = (await screen.findByRole('alert')).textContent

      await userEvent.clear(screen.getByLabelText('Correo electrónico'))
      await submit('recepcion@clinica-demo.test', 'incorrecta')
      const second = (await screen.findByRole('alert')).textContent

      expect(first).toBe('Credenciales inválidas')
      expect(second).toBe(first)
    })

    it('clears the password, keeps the email and focuses the password after a 401', async () => {
      renderPage(<LoginPage />, { ...CLINIC_ROUTE, auth: { login: failWith(401) } })

      await submit('recepcion@clinica-demo.test', 'incorrecta')

      await screen.findByText('Credenciales inválidas')
      expect(screen.getByLabelText('Correo electrónico')).toHaveValue('recepcion@clinica-demo.test')
      expect(screen.getByLabelText('Contraseña')).toHaveValue('')
      await waitFor(() => expect(screen.getByLabelText('Contraseña')).toHaveFocus())
    })

    it('warns about too many attempts with the minutes from Retry-After', async () => {
      renderPage(<LoginPage />, { ...CLINIC_ROUTE, auth: { login: failWith(429, { 'retry-after': '125' }) } })

      await submit('recepcion@clinica-demo.test', 'secreta')

      expect(await screen.findByText('Demasiados intentos. Vuelve a intentarlo en 3 minutos.')).toBeInTheDocument()
      expect(screen.getByRole('button', { name: 'Ingresar' })).toBeDisabled()
    })

    it('enables the submit again when Retry-After has passed', async () => {
      renderPage(<LoginPage />, { ...CLINIC_ROUTE, auth: { login: failWith(429, { 'retry-after': '1' }) } })

      await submit('recepcion@clinica-demo.test', 'secreta')

      expect(await screen.findByText('Demasiados intentos. Vuelve a intentarlo en 1 minuto.')).toBeInTheDocument()
      expect(screen.getByRole('button', { name: 'Ingresar' })).toBeDisabled()
      await waitFor(() => expect(screen.getByRole('button', { name: 'Ingresar' })).toBeEnabled(), { timeout: 2500 })
      expect(screen.queryByRole('alert')).not.toBeInTheDocument()
    })
  })

  it('shows and hides the password', async () => {
    renderPage(<LoginPage />, CLINIC_ROUTE)
    const password = screen.getByLabelText('Contraseña')

    expect(password).toHaveAttribute('type', 'password')
    await userEvent.click(screen.getByRole('button', { name: 'Mostrar contraseña' }))
    expect(password).toHaveAttribute('type', 'text')
    await userEvent.click(screen.getByRole('button', { name: 'Ocultar contraseña' }))
    expect(password).toHaveAttribute('type', 'password')
  })
})
