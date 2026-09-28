import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { makeUser, renderPage } from '../test/utils'
import { LoginPage } from './LoginPage'

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

    expect(await screen.findByText('Credenciales inválidas.')).toBeInTheDocument()
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
})
