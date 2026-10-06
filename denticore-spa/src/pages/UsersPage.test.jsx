import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { makeUser, mockApi, renderPage } from '../test/utils'
import { UsersPage } from './UsersPage'

const admin = makeUser('clinic_admin')
const ROUTE = { user: admin, route: '/c/clinica-demo/app/usuarios', path: '/c/:slug/app/usuarios' }

describe('UsersPage', () => {
  afterEach(() => vi.restoreAllMocks())

  it('lists users and invites a new one without password', async () => {
    const calls = mockApi((config) => {
      if (config.method === 'post') {
        return { status: 201, data: { data: { id: 'u-9', name: 'Luis', email: 'luis@x.test' } } }
      }
      return {
        data: {
          data: [
            { ...admin, status: 'activo' },
            { id: 'u-2', name: 'Dra. Paz', email: 'paz@x.test', role: 'dentist', status: 'inactivo' },
          ],
        },
      }
    })
    renderPage(<UsersPage />, ROUTE)

    expect(await screen.findByText('(tú)')).toBeInTheDocument()
    expect(screen.getByText('Inactivo')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Nuevo usuario' }))
    expect(screen.queryByLabelText('Contraseña')).not.toBeInTheDocument()
    await userEvent.type(screen.getByLabelText('Nombre'), 'Luis')
    await userEvent.type(screen.getByLabelText('Correo electrónico'), 'luis@x.test')
    await userEvent.selectOptions(screen.getByLabelText('Rol'), 'receptionist')
    await userEvent.click(screen.getByRole('button', { name: 'Guardar' }))

    expect(await screen.findByText('Se envió la invitación a luis@x.test (vence en 72 horas).')).toBeInTheDocument()
    const post = calls.find((call) => call.method === 'post')
    expect(JSON.parse(post.data)).toEqual({ name: 'Luis', email: 'luis@x.test', role: 'receptionist' })
  })

  it('asks for the COP number only for dentists and sends it', async () => {
    const calls = mockApi((config) => {
      if (config.method === 'post') {
        return { status: 201, data: { data: { id: 'u-9', name: 'Dra. Ríos', email: 'rios@x.test' } } }
      }
      return { data: { data: [{ ...admin, status: 'activo' }] } }
    })
    renderPage(<UsersPage />, ROUTE)

    await userEvent.click(await screen.findByRole('button', { name: 'Nuevo usuario' }))
    await userEvent.selectOptions(screen.getByLabelText('Rol'), 'receptionist')
    expect(screen.queryByLabelText('Número de COP')).not.toBeInTheDocument()

    await userEvent.selectOptions(screen.getByLabelText('Rol'), 'dentist')
    await userEvent.type(screen.getByLabelText('Nombre'), 'Dra. Ríos')
    await userEvent.type(screen.getByLabelText('Correo electrónico'), 'rios@x.test')
    await userEvent.type(screen.getByLabelText('Número de COP'), '12345')
    await userEvent.type(screen.getByLabelText('Especialidad'), 'Ortodoncia')
    await userEvent.click(screen.getByRole('button', { name: 'Guardar' }))

    expect(await screen.findByText(/Se envió la invitación a rios@x.test/)).toBeInTheDocument()
    const post = calls.find((call) => call.method === 'post')
    expect(JSON.parse(post.data)).toMatchObject({ role: 'dentist', cop_number: '12345', specialty: 'Ortodoncia' })
  })

  it('deactivates a user after confirmation and reactivates another', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    const calls = mockApi((config) => {
      if (config.method === 'post') return { data: { data: {} } }
      return {
        data: {
          data: [
            { ...admin, status: 'activo' },
            { id: 'u-2', name: 'Rosa', email: 'rosa@x.test', role: 'receptionist', status: 'activo' },
            { id: 'u-3', name: 'Luis', email: 'luis@x.test', role: 'receptionist', status: 'inactivo' },
          ],
        },
      }
    })
    renderPage(<UsersPage />, ROUTE)

    const rosa = (await screen.findByText('Rosa')).closest('tr')
    await userEvent.click(within(rosa).getByRole('button', { name: 'Desactivar' }))
    expect(window.confirm).toHaveBeenCalledWith(expect.stringContaining('Se cerrarán todas sus sesiones'))
    expect(await screen.findByText('Se desactivó a Rosa y se cerraron sus sesiones.')).toBeInTheDocument()

    const luis = screen.getByText('Luis').closest('tr')
    await userEvent.click(within(luis).getByRole('button', { name: 'Reactivar' }))
    expect(await screen.findByText('Se reactivó a Luis.')).toBeInTheDocument()

    expect(calls.filter((call) => call.method === 'post').map((call) => call.url)).toEqual([
      '/users/u-2/deactivate',
      '/users/u-3/reactivate',
    ])
  })

  it('shows field errors and closes the form on cancel', async () => {
    mockApi((config) =>
      config.method === 'post'
        ? { status: 422, data: { errors: { email: ['El correo electrónico ya está en uso.'] } } }
        : { data: { data: [] } },
    )
    renderPage(<UsersPage />, ROUTE)

    await userEvent.click(await screen.findByRole('button', { name: 'Nuevo usuario' }))
    await userEvent.click(screen.getByRole('button', { name: 'Guardar' }))
    expect(await screen.findByText('El correo electrónico ya está en uso.')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Cancelar' }))
    expect(screen.queryByRole('button', { name: 'Guardar' })).not.toBeInTheDocument()
  })
})
