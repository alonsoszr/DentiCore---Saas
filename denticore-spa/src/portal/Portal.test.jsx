import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import PortalArea from '../areas/PortalArea'
import { makeUser, mockApi, renderPage } from '../test/utils'

const user = makeUser('patient', { name: 'Lucía Sintética' })
const HOLDER = {
  id: 'p-1',
  first_name: 'Lucía',
  last_name: 'Sintética',
  age_years: 36,
  is_minor: false,
  relationship: 'titular',
}
const MINOR_HOLDER = { ...HOLDER, age_years: 15, is_minor: true }

function budget(overrides = {}) {
  return {
    id: 'b-1',
    number: 'P-000007',
    status: 'emitido',
    patient_id: 'p-1',
    total: '150.00',
    discount_total: '0.00',
    igv_amount: '22.88',
    prices_include_igv: true,
    issued_at: '2026-10-06T15:00:00Z',
    expires_at: '2026-11-05T04:59:59Z',
    dentist: { id: 'u-1', name: 'Dra. Ana Torres', cop: '12345' },
    lines: [
      {
        id: 'l-1',
        description: 'Restauración con resina',
        tooth: 36,
        surfaces: ['O'],
        discount_pct: '0.00',
        subtotal: '150.00',
      },
    ],
    decision: null,
    ...overrides,
  }
}

function api({ subjects = [HOLDER], budgets = [budget()], appointments = [], decide } = {}) {
  return mockApi((config) => {
    const key = `${config.method.toUpperCase()} ${config.url}`
    if (key === 'GET /portal/subjects') return { data: { data: subjects } }
    if (key === 'GET /portal/patients/p-1/budgets') return { data: { data: budgets } }
    if (key === 'GET /portal/patients/p-1/appointments') return { data: { data: appointments } }
    if (key === 'GET /portal/budgets/b-1') return { data: { data: budgets[0] } }
    if (key === 'GET /portal/budgets/b-1/pdf')
      return { data: { data: { status: 'listo', url: 'https://archivos.test/p.pdf' } } }
    if (key === 'POST /portal/budgets/b-1/decision') return decide(JSON.parse(config.data))
    return { status: 404, data: {} }
  })
}

const at = (path) => ({ route: `/c/clinica-demo/portal${path}`, path: '/c/:slug/portal/*', user })

describe('PortalArea', () => {
  it('shows the next appointment and the budgets to decide on the home page', async () => {
    api({
      appointments: [
        {
          id: 'a-1',
          status: 'programada',
          starts_at: '2099-01-05T14:00:00Z',
          dentist: { name: 'Dra. Ana Torres' },
          type: { name: 'Control' },
          reconfirmation_pending: false,
        },
      ],
    })
    renderPage(<PortalArea />, at(''))

    expect(await screen.findByRole('heading', { name: 'Hola, Lucía Sintética' })).toBeInTheDocument()
    const tabs = screen.getByRole('navigation', { name: 'Secciones del portal' })
    expect(within(tabs).getByRole('link', { name: 'Inicio' })).toHaveAttribute('href', '/c/clinica-demo/portal')
    expect(await screen.findByText('Control con Dra. Ana Torres')).toBeInTheDocument()
    expect(screen.getByText('Programada').querySelector('svg')).not.toBeNull()
    expect(await screen.findByRole('link', { name: 'Presupuesto N° P-000007' })).toHaveAttribute(
      'href',
      '/c/clinica-demo/portal/presupuestos/b-1',
    )
  })

  it('explains an account without a linked record', async () => {
    api({ subjects: [] })
    renderPage(<PortalArea />, at(''))

    expect(await screen.findByText(/Tu cuenta aún no está vinculada/)).toBeInTheDocument()
  })

  it('lists the budgets with their status, total and expiry', async () => {
    api()
    renderPage(<PortalArea />, at('/presupuestos'))

    const card = (await screen.findByRole('link', { name: 'Presupuesto N° P-000007' })).closest('li')
    expect(within(card).getByText('Emitido')).toBeInTheDocument()
    expect(card).toHaveTextContent(/Total: S\/\s150\.00/)
    expect(within(card).getByText(/Vence el/)).toBeInTheDocument()
  })

  it('accepts the budget only after ticking the full acceptance box (CUS-37)', async () => {
    const calls = api({ decide: () => ({ data: { data: budget({ status: 'aceptado' }) } }) })
    renderPage(<PortalArea />, at('/presupuestos/b-1'))

    expect(await screen.findByRole('link', { name: /^Descargar PDF/ })).toHaveAttribute(
      'href',
      'https://archivos.test/p.pdf',
    )
    await userEvent.click(screen.getByRole('button', { name: 'Aceptar presupuesto' }))
    // El foco entra a la casilla y vuelve a «Aceptar presupuesto» con «Volver».
    expect(screen.getByRole('checkbox')).toHaveFocus()
    await userEvent.click(screen.getByRole('button', { name: 'Volver' }))
    expect(screen.getByRole('button', { name: 'Aceptar presupuesto' })).toHaveFocus()
    await userEvent.click(screen.getByRole('button', { name: 'Aceptar presupuesto' }))
    const confirm = screen.getByRole('button', { name: 'Confirmar aceptación' })
    expect(confirm).toBeDisabled()
    await userEvent.click(
      screen.getByRole('checkbox', {
        name: /^He leído el presupuesto N° P-000007 por S\/\s150\.00 y lo acepto en su totalidad$/,
      }),
    )
    await userEvent.click(confirm)

    const notice = (await screen.findByText('Presupuesto aceptado')).closest('[tabindex="-1"]')
    expect(notice).toHaveFocus()
    expect(notice).toHaveTextContent(/Saldo por pagar: S\/\s150\.00\./)
    const post = calls.find((call) => call.method === 'post')
    expect(JSON.parse(post.data)).toEqual({ decision: 'aceptado' })
  })

  it('rejects the budget with an optional reason after confirming', async () => {
    const calls = api({ decide: () => ({ data: { data: budget({ status: 'rechazado' }) } }) })
    renderPage(<PortalArea />, at('/presupuestos/b-1'))

    await userEvent.click(await screen.findByRole('button', { name: 'Rechazar' }))
    const dialog = screen.getByRole('dialog', { name: '¿Rechazar el presupuesto N° P-000007?' })
    await userEvent.selectOptions(within(dialog).getByLabelText('Motivo (opcional)'), 'precio')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Rechazar presupuesto' }))

    expect(await screen.findByText('Presupuesto rechazado')).toBeInTheDocument()
    expect(JSON.parse(calls.find((call) => call.method === 'post').data)).toEqual({
      decision: 'rechazado',
      rejection_reason: 'precio',
      rejection_detail: null,
    })
  })

  it('shows why the decision failed (RN-35)', async () => {
    api({
      decide: () => ({ status: 409, data: { rule: 'RN-35', detail: 'El presupuesto venció el 05/11/2026.' } }),
    })
    renderPage(<PortalArea />, at('/presupuestos/b-1'))

    await userEvent.click(await screen.findByRole('button', { name: 'Aceptar presupuesto' }))
    await userEvent.click(screen.getByRole('checkbox'))
    await userEvent.click(screen.getByRole('button', { name: 'Confirmar aceptación' }))

    expect(await screen.findByText('El presupuesto venció el 05/11/2026.')).toBeInTheDocument()
  })

  it('leaves the decision to the legal representative of a minor holder (RN-12)', async () => {
    api({ subjects: [MINOR_HOLDER] })
    renderPage(<PortalArea />, at('/presupuestos/b-1'))

    expect(await screen.findByText('La decisión corresponde al representante legal.')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Aceptar presupuesto' })).not.toBeInTheDocument()
  })
})
