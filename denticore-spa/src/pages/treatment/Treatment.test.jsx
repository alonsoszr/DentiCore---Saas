import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { makeUser, mockApi, renderPage } from '../../test/utils'
import { CatalogPage } from './CatalogPage'
import { itemErrors, itemPayload, itemsFromFindings, progressText } from './labels'
import { PatientPlansPage } from './PatientPlansPage'
import { PendingFindingsPage } from './PendingFindingsPage'
import { PlanDetailPage } from './PlanDetailPage'

afterEach(() => vi.restoreAllMocks())

const PATIENT = { id: 'p-1', first_name: 'Ana', last_name: 'Núñez' }
const PATIENT_ROUTE = { route: '/c/clinica-demo/app/pacientes/p-1/x', path: '/c/:slug/app/pacientes/:uuid/x' }
const PROCEDURE = {
  id: 'proc-1',
  code: 'RES-01',
  name: 'Restauración con resina',
  category: 'Operatoria',
  price: '150.00',
  requires_tooth: true,
  requires_surface: true,
  requires_informed_consent: false,
  resulting_finding: { code: 'RESTAURACION', name: 'Restauración definitiva', acronym: null },
  resulting_state: { code: 'R_BUENO', name: 'Buen estado', color: 'azul', acronym: 'R' },
  is_active: true,
}
const FINDING = {
  id: 'e-1',
  tooth: 36,
  surfaces: ['O', 'M'],
  finding: { code: 'CARIES', name: 'Caries', acronym: null },
  state: { code: 'ACTIVA', name: 'Activa', acronym: 'CA' },
  color: 'rojo',
  recorded_at: '2026-10-06T15:00:00Z',
}
const PROGRESS = { items_total: 2, items_performed: 1, performed_amount: '150.00', accepted_amount: '300.00' }

function plan(overrides = {}) {
  return {
    id: 'plan-1',
    patient_id: 'p-1',
    title: 'Plan de restauraciones',
    status: 'borrador',
    origin: 'manual',
    created_by: { id: 'u-dentist', name: 'Dra. Pérez' },
    items: [
      {
        id: 'item-1',
        position: 1,
        procedure: { id: 'proc-1', code: 'RES-01', name: 'Restauración con resina' },
        tooth: 36,
        surfaces: ['O'],
        quantity: 1,
        performed_quantity: 0,
        session_number: null,
        observations: null,
        status: 'propuesto',
        discard_reason: null,
        origin: 'manual',
        finding_ids: ['e-1'],
      },
    ],
    progress: { items_total: 1, items_performed: 0, performed_amount: '0.00', accepted_amount: null },
    cancel_reason: null,
    cancelled_at: null,
    completed_at: null,
    created_at: '2026-10-06T15:00:00Z',
    ...overrides,
  }
}

const PLAN_ROUTE = { route: '/c/clinica-demo/app/planes/plan-1', path: '/c/:slug/app/planes/:uuid' }
const PATIENT_RECORD = {
  ...PATIENT,
  age_years: 36,
  clinical_record_number: '40000001',
  has_current_consent: true,
  allergies: ['Penicilina'],
}

/** Respuestas comunes del detalle del plan: la ficha del paciente y el catálogo. */
function planApi(handler) {
  return mockApi((config) => {
    if (config.url === '/patients/p-1') return { data: { data: PATIENT_RECORD } }
    if (config.url === '/procedures') return { data: { data: [PROCEDURE] } }
    if (config.url === '/patients/p-1/budgets') return { data: { data: [] } }
    return handler(config)
  })
}

describe('treatment labels', () => {
  it('shows the progress of the plan in soles (RF-130)', () => {
    // Intl separa «S/» con un espacio duro; se normaliza como en format.test.js.
    expect(progressText(PROGRESS).replace(/\s/g, ' ')).toBe('1 de 2 ítems realizados · S/ 150.00 de S/ 300.00')
    expect(progressText({ ...PROGRESS, accepted_amount: null })).toBe('1 de 2 ítems realizados')
  })

  it('maps the errors of item N and builds the item payload', () => {
    expect(itemErrors({ 'items.1.tooth': 'Pieza inválida', 'items.0.surfaces': 'x', title: 'y' }, 1)).toEqual({
      tooth: 'Pieza inválida',
    })
    const [item] = itemsFromFindings([{ id: 'e-1', tooth: 36, surfaces: ['O'], label: 'Caries (CA)' }])
    expect(itemPayload({ ...item, procedure_id: 'proc-1' })).toEqual({
      procedure_id: 'proc-1',
      tooth: 36,
      surfaces: ['O'],
      quantity: 1,
      session_number: null,
      observations: null,
      finding_ids: ['e-1'],
    })
  })
})

describe('CatalogPage', () => {
  it('lists the catalog with prices in soles and creates a procedure (CUS-32)', async () => {
    const calls = mockApi((config) => {
      if (config.url === '/finding-catalog') return { data: { data: [] } }
      if (config.method === 'post') return { status: 201, data: { data: PROCEDURE } }
      return { data: { data: [PROCEDURE] } }
    })
    renderPage(<CatalogPage />, {
      user: makeUser('clinic_admin'),
      route: '/c/clinica-demo/app/catalogo',
      path: '/c/:slug/app/catalogo',
    })

    expect(await screen.findByText('S/ 150.00')).toBeInTheDocument()
    expect(screen.getByText('pieza, superficie')).toBeInTheDocument()
    expect(screen.getByText('Activo')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Nuevo procedimiento' }))
    await userEvent.type(screen.getByLabelText('Código'), 'PRF-01')
    await userEvent.type(screen.getByLabelText('Nombre'), 'Profilaxis')
    await userEvent.type(screen.getByLabelText('Precio (S/)'), '80')
    await userEvent.click(screen.getByLabelText('Pieza'))
    await userEvent.click(screen.getByRole('button', { name: 'Guardar procedimiento' }))

    await waitFor(() => expect(calls.some((call) => call.method === 'post')).toBe(true))
    expect(JSON.parse(calls.find((call) => call.method === 'post').data)).toMatchObject({
      code: 'PRF-01',
      name: 'Profilaxis',
      price: '80',
      requires_tooth: false,
      requires_surface: false,
      resulting_finding_code: null,
    })
  })

  it('explains in the dialog that a used procedure can only be deactivated (RF-109)', async () => {
    mockApi((config) => {
      if (config.method === 'delete')
        return {
          status: 409,
          data: { detail: 'El procedimiento se usó en planes o presupuestos: no se puede eliminar, solo desactivar.' },
        }
      if (config.url === '/finding-catalog') return { data: { data: [] } }
      return { data: { data: [PROCEDURE] } }
    })
    renderPage(<CatalogPage />, {
      user: makeUser('clinic_admin'),
      route: '/c/clinica-demo/app/catalogo',
      path: '/c/:slug/app/catalogo',
    })

    await userEvent.click(await screen.findByRole('button', { name: 'Eliminar' }))
    const dialog = screen.getByRole('dialog')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Eliminar procedimiento' }))

    expect(await within(dialog).findByRole('alert')).toHaveTextContent('solo desactivar')
  })
})

describe('PendingFindingsPage', () => {
  it('records a no-treat decision with its reason and shows a short reason next to the field (RF-113)', async () => {
    const calls = mockApi((config) => {
      if (config.method === 'post') {
        const reason = JSON.parse(config.data).reason
        return reason.length < 10
          ? { status: 422, data: { errors: { reason: ['El campo motivo debe tener al menos 10 caracteres.'] } } }
          : { status: 201, data: { data: { id: 'd-1' } } }
      }
      return { data: { data: [FINDING] } }
    })
    renderPage(<PendingFindingsPage patient={PATIENT} />, { user: makeUser('dentist'), ...PATIENT_ROUTE })

    expect(await screen.findByText('36 (O, M)')).toBeInTheDocument()
    expect(screen.getByText('Caries (CA)')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'No tratar' }))
    const dialog = screen.getByRole('dialog')
    await userEvent.type(within(dialog).getByLabelText('Motivo'), 'Corto')
    expect(within(dialog).getByRole('button', { name: 'Registrar decisión' })).toBeDisabled()

    await userEvent.type(within(dialog).getByLabelText('Motivo'), ' de verdad: prefiere esperar')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Registrar decisión' }))
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(calls.filter((call) => call.method === 'post').at(-1).url).toBe('/odontogram-entries/e-1/no-treat')
  })

  it('takes the selected findings to a new plan (RF-111)', async () => {
    mockApi(() => ({ data: { data: [FINDING] } }))
    renderPage(<PendingFindingsPage patient={PATIENT} />, { user: makeUser('dentist'), ...PATIENT_ROUTE })

    const create = await screen.findByRole('button', { name: 'Crear ítems del plan (0)' })
    expect(create).toBeDisabled()
    await userEvent.click(await screen.findByRole('checkbox', { name: 'Seleccionar Caries (CA) en la pieza 36' }))
    await userEvent.click(screen.getByRole('button', { name: 'Crear ítems del plan (1)' }))

    expect(screen.getByTestId('location')).toHaveTextContent('/c/clinica-demo/app/pacientes/p-1/planes')
  })
})

describe('PatientPlansPage', () => {
  it('lists the plans with their progress and hides creation from the reception', async () => {
    mockApi(() => ({ data: { data: [plan({ status: 'en_ejecucion', progress: PROGRESS })] } }))
    renderPage(<PatientPlansPage patient={PATIENT} />, { user: makeUser('receptionist'), ...PATIENT_ROUTE })

    expect(await screen.findByRole('link', { name: 'Plan de restauraciones' })).toHaveAttribute(
      'href',
      '/c/clinica-demo/app/planes/plan-1',
    )
    expect(screen.getByText('En ejecución')).toBeInTheDocument()
    expect(screen.getByText('1 de 2 ítems realizados · S/ 150.00 de S/ 300.00')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Nuevo plan' })).not.toBeInTheDocument()
  })

  it('creates a draft plan from the selected findings (RF-110, RF-111)', async () => {
    const calls = mockApi((config) => {
      if (config.method === 'post') return { status: 201, data: { data: plan({ id: 'plan-9' }) } }
      if (config.url === '/procedures') return { data: { data: [PROCEDURE] } }
      return { data: { data: [] } }
    })
    renderPage(<PatientPlansPage patient={PATIENT} />, {
      user: makeUser('dentist'),
      path: PATIENT_ROUTE.path,
      route: {
        pathname: PATIENT_ROUTE.route,
        state: { fromFindings: [{ id: 'e-1', tooth: 36, surfaces: ['O', 'M'], label: 'Caries (CA)' }] },
      },
    })

    expect(await screen.findByText(/atiende Caries \(CA\)/)).toBeInTheDocument()
    await userEvent.type(screen.getByLabelText('Título del plan'), 'Plan de restauraciones')
    await userEvent.selectOptions(screen.getByLabelText('Procedimiento'), 'proc-1')
    await userEvent.click(screen.getByRole('button', { name: 'Crear plan en borrador' }))

    expect(await screen.findByTestId('location')).toHaveTextContent('/c/clinica-demo/app/planes/plan-9')
    expect(JSON.parse(calls.find((call) => call.method === 'post').data)).toEqual({
      title: 'Plan de restauraciones',
      items: [
        {
          procedure_id: 'proc-1',
          tooth: 36,
          surfaces: ['O', 'M'],
          quantity: 1,
          session_number: null,
          observations: null,
          finding_ids: ['e-1'],
        },
      ],
    })
  })
})

describe('PlanDetailPage', () => {
  it('lets the dentist propose a draft plan and shows the API conflict (RF-114)', async () => {
    const calls = planApi((config) => {
      if (config.url.endsWith('/propose'))
        return { status: 422, data: { detail: 'Agregue al menos un ítem al plan antes de proponerlo.' } }
      return { data: { data: plan() } }
    })
    renderPage(<PlanDetailPage />, { user: makeUser('dentist'), ...PLAN_ROUTE })

    expect(await screen.findByRole('heading', { name: 'Plan de restauraciones' })).toBeInTheDocument()
    expect(screen.getByText('Atiende 1 hallazgo')).toBeInTheDocument()
    // DESIGN.md › Allergy warning: las alergias se ven mientras se elabora el plan.
    expect(await screen.findByText('Penicilina')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Proponer al paciente' }))

    expect(await screen.findByText('Agregue al menos un ítem al plan antes de proponerlo.')).toBeInTheDocument()
    expect(calls.some((call) => call.url === '/treatment-plans/plan-1/propose')).toBe(true)
  })

  it('shows only the reading of the plan to the reception', async () => {
    planApi(() => ({ data: { data: plan() } }))
    renderPage(<PlanDetailPage />, { user: makeUser('receptionist'), ...PLAN_ROUTE })

    expect(await screen.findByRole('heading', { name: 'Plan de restauraciones' })).toBeInTheDocument()
    for (const name of ['Proponer al paciente', 'Cancelar plan', 'Agregar ítems', 'Editar título', 'Descartar']) {
      expect(screen.queryByRole('button', { name })).not.toBeInTheDocument()
    }
  })

  it('previews what was performed and cancels the plan with a reason (RF-129)', async () => {
    const calls = planApi((config) => {
      if (config.url.endsWith('/cancellation-preview'))
        return {
          data: { data: { items_total: 3, items_performed: 1, performed_amount: '183.33', accepted_amount: '330.00' } },
        }
      if (config.url.endsWith('/cancel')) {
        return JSON.parse(config.data).reason === ''
          ? { status: 422, data: { errors: { reason: ['El campo motivo es obligatorio.'] } } }
          : { data: { data: plan({ status: 'cancelado' }) } }
      }
      return { data: { data: plan({ status: 'en_ejecucion' }) } }
    })
    renderPage(<PlanDetailPage />, { user: makeUser('clinic_admin'), ...PLAN_ROUTE })

    await userEvent.click(await screen.findByRole('button', { name: 'Cancelar plan' }))
    const dialog = await screen.findByRole('dialog', { name: 'Cancelar el plan' })
    expect(within(dialog).getByText('S/ 183.33')).toBeInTheDocument()
    expect(within(dialog).getByText('S/ 330.00')).toBeInTheDocument()
    expect(within(dialog).getByText('1 de 3')).toBeInTheDocument()

    // Sin motivo no se puede confirmar (RF-129); el botón es destructivo y distinto de «Cancelar».
    const confirm = within(dialog).getByRole('button', { name: 'Confirmar cancelación' })
    expect(confirm).toBeDisabled()
    expect(confirm).toHaveClass('btn-danger')

    await userEvent.type(within(dialog).getByLabelText('Motivo'), 'El paciente se mudó de ciudad')
    await userEvent.click(confirm)
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(JSON.parse(calls.filter((call) => call.url.endsWith('/cancel')).at(-1).data)).toEqual({
      reason: 'El paciente se mudó de ciudad',
    })
  })

  it('discards an item with a reason', async () => {
    const calls = planApi((config) => {
      if (config.url.endsWith('/discard')) return { data: { data: {} } }
      return { data: { data: plan({ status: 'propuesto' }) } }
    })
    renderPage(<PlanDetailPage />, { user: makeUser('dentist'), ...PLAN_ROUTE })

    await userEvent.click(await screen.findByRole('button', { name: 'Descartar' }))
    const dialog = screen.getByRole('dialog', { name: 'Descartar el ítem 1' })
    await userEvent.type(within(dialog).getByLabelText('Motivo'), 'Se tratará en otra clínica')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Descartar ítem' }))

    await waitFor(() => expect(calls.some((call) => call.url === '/plan-items/item-1/discard')).toBe(true))
    expect(screen.getByRole('button', { name: 'Volver a editar' })).toBeInTheDocument()
  })
})
