import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { makeUser, mockApi, renderPage } from '../test/utils'
import { TenantsPage } from './TenantsPage'

const ROUTE = { user: makeUser('super_admin'), route: '/admin/clinicas', path: '/admin/clinicas' }

describe('TenantsPage', () => {
  it('lists clinics and registers one with its first administrator', async () => {
    const tenants = [
      {
        id: 't-1',
        name: 'Clínica Demo',
        slug: 'clinica-demo',
        subscription_plan: 'basic',
        status: 'active',
        created_at: '2026-09-21T15:00:00Z',
      },
    ]
    const calls = mockApi((config) => {
      if (config.method === 'post') {
        return { status: 201, data: { data: { id: 't-2', name: 'Sonrisa Ñaña', slug: 'sonrisa-nana' } } }
      }
      return { data: { data: tenants } }
    })
    renderPage(<TenantsPage />, ROUTE)

    expect(await screen.findByText('Clínica Demo')).toBeInTheDocument()
    expect(screen.getByText('21/09/2026')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Registrar clínica' }))
    const [clinicName] = screen.getAllByLabelText('Nombre')
    await userEvent.type(clinicName, 'Sonrisa Ñaña')
    expect(screen.getByLabelText('Código de acceso')).toHaveValue('sonrisa-nana')

    await userEvent.selectOptions(screen.getByLabelText('Plan'), 'pro')
    await userEvent.type(screen.getAllByLabelText('Nombre')[1], 'Rosa Admin')
    await userEvent.type(screen.getAllByLabelText('Correo electrónico')[0], 'rosa@sonrisa.test')
    await userEvent.type(screen.getByLabelText('Contraseña'), 'secreta123')
    await userEvent.click(screen.getByRole('button', { name: 'Guardar' }))

    expect(await screen.findByText(/Clínica «Sonrisa Ñaña» registrada/)).toBeInTheDocument()
    const post = calls.find((call) => call.method === 'post')
    expect(JSON.parse(post.data)).toEqual({
      name: 'Sonrisa Ñaña',
      slug: 'sonrisa-nana',
      subscription_plan: 'pro',
      admin: { name: 'Rosa Admin', email: 'rosa@sonrisa.test', password: 'secreta123' },
    })
  })

  it('keeps a manually edited access code and shows field errors', async () => {
    mockApi((config) =>
      config.method === 'post'
        ? { status: 422, data: { errors: { slug: ['El código de acceso ya está en uso.'] } } }
        : { data: { data: [] } },
    )
    renderPage(<TenantsPage />, ROUTE)

    expect(await screen.findByText('Aún no hay clínicas registradas.')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Registrar clínica' }))
    await userEvent.type(screen.getByLabelText('Código de acceso'), 'propio')
    await userEvent.type(screen.getAllByLabelText('Nombre')[0], 'Otra')
    expect(screen.getByLabelText('Código de acceso')).toHaveValue('propio')

    await userEvent.click(screen.getByRole('button', { name: 'Guardar' }))
    expect(await screen.findByText('El código de acceso ya está en uso.')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Cancelar' }))
    expect(screen.queryByRole('button', { name: 'Guardar' })).not.toBeInTheDocument()
  })
})
