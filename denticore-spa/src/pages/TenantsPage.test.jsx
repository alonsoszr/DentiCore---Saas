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
        plan: { id: 'p-1', code: 'basic', name: 'Basic' },
        active_dentists: 2,
        status: 'activa',
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
    await userEvent.type(screen.getByLabelText('Nombre comercial'), 'Sonrisa Ñaña')
    expect(screen.getByLabelText('Código de acceso')).toHaveValue('sonrisa-nana')

    await userEvent.type(screen.getByLabelText('Razón social'), 'Sonrisa Ñaña S.A.C.')
    await userEvent.type(screen.getByLabelText('RUC'), '20600000013')
    await userEvent.type(screen.getByLabelText('Dirección'), 'Av. Arequipa 1234, Lima')
    await userEvent.selectOptions(screen.getByLabelText('Plan'), 'pro')
    await userEvent.type(screen.getByLabelText('Nombre'), 'Rosa Admin')
    await userEvent.type(screen.getByLabelText('Correo electrónico'), 'rosa@sonrisa.test')
    expect(screen.queryByLabelText('Contraseña')).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Guardar' }))

    expect(await screen.findByText(/Clínica «Sonrisa Ñaña» registrada/)).toBeInTheDocument()
    expect(screen.getByText(/Se envió la/)).toBeInTheDocument()
    const post = calls.find((call) => call.method === 'post')
    expect(post.url).toBe('/platform/tenants')
    expect(JSON.parse(post.data)).toEqual({
      name: 'Sonrisa Ñaña',
      legal_name: 'Sonrisa Ñaña S.A.C.',
      ruc: '20600000013',
      slug: 'sonrisa-nana',
      address: 'Av. Arequipa 1234, Lima',
      subscription_plan: 'pro',
      admin: { name: 'Rosa Admin', email: 'rosa@sonrisa.test' },
    })
  })

  it('searches by name, legal name or RUC and links to the detail (RF-018)', async () => {
    const calls = mockApi(() => ({
      data: { data: [{ id: 't-1', name: 'Clínica Demo', slug: 'clinica-demo', status: 'activa', plan: null }] },
    }))
    renderPage(<TenantsPage />, ROUTE)

    expect(await screen.findByRole('link', { name: 'Clínica Demo' })).toHaveAttribute('href', '/admin/clinicas/t-1')
    await userEvent.type(screen.getByLabelText('Buscar clínica'), 'demo')
    await userEvent.click(screen.getByRole('button', { name: 'Buscar' }))

    await screen.findByRole('link', { name: 'Clínica Demo' })
    expect(calls.at(-1).params).toEqual({ q: 'demo' })
  })

  it('shows the RUC error next to its field and keeps the typed value', async () => {
    mockApi((config) =>
      config.method === 'post'
        ? { status: 422, data: { errors: { ruc: ['El RUC no es válido.'] } } }
        : { data: { data: [] } },
    )
    renderPage(<TenantsPage />, ROUTE)

    await userEvent.click(await screen.findByRole('button', { name: 'Registrar clínica' }))
    await userEvent.type(screen.getByLabelText('RUC'), '20600000014')
    await userEvent.click(screen.getByRole('button', { name: 'Guardar' }))

    expect(await screen.findByText('El RUC no es válido.')).toBeInTheDocument()
    expect(screen.getByLabelText('RUC')).toHaveValue('20600000014')
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
    await userEvent.type(screen.getByLabelText('Nombre comercial'), 'Otra')
    expect(screen.getByLabelText('Código de acceso')).toHaveValue('propio')

    await userEvent.click(screen.getByRole('button', { name: 'Guardar' }))
    expect(await screen.findByText('El código de acceso ya está en uso.')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Cancelar' }))
    expect(screen.queryByRole('button', { name: 'Guardar' })).not.toBeInTheDocument()
  })
})
