import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { makeUser, mockApi, renderPage } from '../test/utils'
import { TenantDetailPage } from './TenantDetailPage'

const ROUTE = { user: makeUser('super_admin'), route: '/admin/clinicas/t-1', path: '/admin/clinicas/:uuid' }

const TENANT = {
  id: 't-1',
  name: 'Clínica Demo',
  legal_name: 'Clínica Demo S.A.C.',
  ruc: '20600000013',
  slug: 'clinica-demo',
  address: 'Av. Arequipa 1234, Lima',
  plan: { id: 'p-2', code: 'pro', name: 'Pro', max_dentists: 10 },
  status: 'activa',
  status_reason: null,
  active_dentists: 3,
  admin: { id: 'u-1', name: 'Carla', email: 'admin@clinica-demo.test', status: 'pendiente_activacion' },
  created_at: '2026-09-21T15:00:00Z',
}

const PLANS = [
  { id: 'p-1', code: 'basic', name: 'Basic', max_dentists: 2 },
  { id: 'p-2', code: 'pro', name: 'Pro', max_dentists: 10 },
]

function api(overrides = {}) {
  return mockApi((config) => {
    const key = `${config.method} ${config.url}`
    if (overrides[key]) return overrides[key](config)
    if (key === 'get /platform/plans') return { data: { data: PLANS } }
    return { data: { data: TENANT } }
  })
}

afterEach(() => vi.restoreAllMocks())

describe('TenantDetailPage', () => {
  it('shows the clinic, its plan and its first administrator', async () => {
    api()
    renderPage(<TenantDetailPage />, ROUTE)

    expect(await screen.findByRole('heading', { name: 'Clínica Demo' })).toBeInTheDocument()
    expect(screen.getByText('20600000013')).toBeInTheDocument()
    expect(screen.getByText('admin@clinica-demo.test')).toBeInTheDocument()
    expect(screen.getByText('3 de 10')).toBeInTheDocument()
  })

  it('pairs each label with its value and names the plan in Spanish', async () => {
    api({ 'get /platform/tenants/t-1': () => ({ data: { data: { ...TENANT, plan: PLANS[0] } } }) })
    renderPage(<TenantDetailPage />, ROUTE)

    // Cada rótulo y su valor van juntos en su propio grupo: la grilla no puede cruzarlos.
    const ruc = (await screen.findByText('RUC')).parentElement
    expect(ruc.tagName).toBe('DIV')
    expect(within(ruc).getByText('20600000013')).toBeInTheDocument()
    expect(within(screen.getByText('Plan').parentElement).getByText('Básico')).toBeInTheDocument()
    expect(within(screen.getAllByText('Estado')[0].parentElement).getByText('Activa')).toBeInTheDocument()
  })

  it('asks for confirmation with the effects before suspending and sends the reason', async () => {
    const calls = api({
      'post /platform/tenants/t-1/suspend': () => ({ data: { data: { ...TENANT, status: 'suspendida' } } }),
    })
    renderPage(<TenantDetailPage />, ROUTE)

    await userEvent.type(await screen.findByLabelText('Motivo de la suspensión'), 'Falta de pago')
    await userEvent.click(screen.getByRole('button', { name: 'Suspender clínica' }))
    // RNF-063 con el diálogo del sistema de diseño, no con window.confirm.
    let dialog = screen.getByRole('dialog', { name: '¿Suspender «Clínica Demo»?' })
    expect(within(dialog).getByText(/solo podrá consultar/)).toBeInTheDocument()
    await userEvent.click(within(dialog).getByRole('button', { name: 'Cancelar' }))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(calls.some((call) => call.method === 'post')).toBe(false)

    await userEvent.click(screen.getByRole('button', { name: 'Suspender clínica' }))
    dialog = screen.getByRole('dialog', { name: '¿Suspender «Clínica Demo»?' })
    await userEvent.click(within(dialog).getByRole('button', { name: 'Suspender clínica' }))
    expect(await screen.findByText('Clínica suspendida.')).toBeInTheDocument()
    const post = calls.find((call) => call.method === 'post')
    expect(JSON.parse(post.data)).toEqual({ reason: 'Falta de pago' })
  })

  it('reactivates a suspended clinic after confirmation', async () => {
    const calls = mockApi((config) => {
      if (config.url === '/platform/plans') return { data: { data: PLANS } }
      if (config.method === 'post') return { data: { data: { ...TENANT, status: 'activa' } } }
      return { data: { data: { ...TENANT, status: 'suspendida', status_reason: 'Falta de pago' } } }
    })
    renderPage(<TenantDetailPage />, ROUTE)

    expect(await screen.findByText('Falta de pago')).toBeInTheDocument()
    await userEvent.type(screen.getByLabelText('Motivo de la reactivación'), 'Pago regularizado')
    await userEvent.click(screen.getByRole('button', { name: 'Reactivar clínica' }))
    const dialog = screen.getByRole('dialog', { name: '¿Reactivar «Clínica Demo»?' })
    await userEvent.click(within(dialog).getByRole('button', { name: 'Reactivar clínica' }))

    expect(await screen.findByText('Clínica reactivada.')).toBeInTheDocument()
    expect(calls.find((call) => call.method === 'post').url).toBe('/platform/tenants/t-1/reactivate')
  })

  it('shows how many dentists must be deactivated when the plan change is rejected (RF-022)', async () => {
    api({
      'put /platform/tenants/t-1/plan': () => ({
        status: 422,
        data: {
          rule: 'RN-08',
          dentists_to_deactivate: 1,
          errors: { subscription_plan: ['Desactive 1 odontólogo(s) antes de cambiar al plan Basic.'] },
        },
      }),
    })
    renderPage(<TenantDetailPage />, ROUTE)

    await userEvent.selectOptions(await screen.findByLabelText('Nuevo plan'), 'basic')
    await userEvent.click(screen.getByRole('button', { name: 'Cambiar plan' }))

    expect(await screen.findByText('Desactive 1 odontólogo(s) antes de cambiar al plan Basic.')).toBeInTheDocument()
  })

  it('resends the invitation of a pending administrator', async () => {
    const calls = api({ 'post /platform/tenants/t-1/admin-invitation': () => ({ status: 204 }) })
    renderPage(<TenantDetailPage />, ROUTE)

    await userEvent.click(await screen.findByRole('button', { name: 'Reenviar invitación' }))

    expect(await screen.findByText(/Se reenvió la invitación/)).toBeInTheDocument()
    expect(calls.some((call) => call.url === '/platform/tenants/t-1/admin-invitation')).toBe(true)
  })
})
