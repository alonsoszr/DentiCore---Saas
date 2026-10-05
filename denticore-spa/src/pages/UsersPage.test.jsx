import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { makeUser, mockApi, renderPage } from '../test/utils'
import { UsersPage } from './UsersPage'

const admin = makeUser('clinic_admin')
const ROUTE = { user: admin, route: '/c/clinica-demo/app/usuarios', path: '/c/:slug/app/usuarios' }

describe('UsersPage', () => {
  it('lists users and creates a new one', async () => {
    const calls = mockApi((config) => {
      if (config.method === 'post') return { status: 201, data: { data: { id: 'u-9', name: 'Luis' } } }
      return {
        data: {
          data: [{ ...admin }, { id: 'u-2', name: 'Dra. Paz', email: 'paz@x.test', role: 'dentist', is_active: false }],
        },
      }
    })
    renderPage(<UsersPage />, ROUTE)

    expect(await screen.findByText('(tú)')).toBeInTheDocument()
    expect(screen.getByText('Inactivo')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Nuevo usuario' }))
    await userEvent.type(screen.getByLabelText('Nombre'), 'Luis')
    await userEvent.type(screen.getByLabelText('Correo electrónico'), 'luis@x.test')
    await userEvent.type(screen.getByLabelText('Contraseña'), 'secreta123')
    await userEvent.selectOptions(screen.getByLabelText('Rol'), 'receptionist')
    await userEvent.click(screen.getByRole('button', { name: 'Guardar' }))

    expect(await screen.findByText('Se creó la cuenta de Luis.')).toBeInTheDocument()
    const post = calls.find((call) => call.method === 'post')
    expect(JSON.parse(post.data)).toMatchObject({ name: 'Luis', role: 'receptionist', is_active: true })
  })

  it('asks for the COP number only for dentists and sends it', async () => {
    const calls = mockApi((config) => {
      if (config.method === 'post') return { status: 201, data: { data: { id: 'u-9', name: 'Dra. Ríos' } } }
      return { data: { data: [{ ...admin }] } }
    })
    renderPage(<UsersPage />, ROUTE)

    await userEvent.click(await screen.findByRole('button', { name: 'Nuevo usuario' }))
    await userEvent.selectOptions(screen.getByLabelText('Rol'), 'receptionist')
    expect(screen.queryByLabelText('Número de COP')).not.toBeInTheDocument()

    await userEvent.selectOptions(screen.getByLabelText('Rol'), 'dentist')
    await userEvent.type(screen.getByLabelText('Nombre'), 'Dra. Ríos')
    await userEvent.type(screen.getByLabelText('Correo electrónico'), 'rios@x.test')
    await userEvent.type(screen.getByLabelText('Contraseña'), 'secreta123')
    await userEvent.type(screen.getByLabelText('Número de COP'), '12345')
    await userEvent.click(screen.getByRole('button', { name: 'Guardar' }))

    expect(await screen.findByText('Se creó la cuenta de Dra. Ríos.')).toBeInTheDocument()
    const post = calls.find((call) => call.method === 'post')
    expect(JSON.parse(post.data)).toMatchObject({ role: 'dentist', cop_number: '12345' })
  })

  it('edits a user without sending an empty password', async () => {
    const calls = mockApi((config) => {
      if (config.method === 'patch') return { data: { data: { id: 'u-2', name: 'Dra. Paz' } } }
      return {
        data: { data: [{ id: 'u-2', name: 'Dra. Paz', email: 'paz@x.test', role: 'dentist', is_active: true }] },
      }
    })
    renderPage(<UsersPage />, ROUTE)

    const row = (await screen.findByText('Dra. Paz')).closest('tr')
    await userEvent.click(within(row).getByRole('button', { name: 'Editar' }))
    await userEvent.click(screen.getByLabelText(/Cuenta activa/))
    await userEvent.click(screen.getByRole('button', { name: 'Guardar' }))

    expect(await screen.findByText('Se actualizó a Dra. Paz.')).toBeInTheDocument()
    const patch = calls.find((call) => call.method === 'patch')
    expect(patch.url).toBe('/users/u-2')
    expect(JSON.parse(patch.data)).toEqual({ name: 'Dra. Paz', email: 'paz@x.test', role: 'dentist', is_active: false })
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
