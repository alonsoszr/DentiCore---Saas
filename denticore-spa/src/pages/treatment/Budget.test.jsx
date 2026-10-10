import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { makeUser, mockApi, renderPage } from '../../test/utils'
import { BudgetDetailPage } from './BudgetDetailPage'
import { BudgetNewPage } from './BudgetNewPage'
import { ConsentTemplatesPage } from './ConsentTemplatesPage'
import { PlanBudgets } from './PlanBudgets'
import { PlanConsentsPage } from './PlanConsentsPage'
import { ProceduresPanel } from './ProceduresPanel'

afterEach(() => vi.restoreAllMocks())

const PATIENT = {
  id: 'p-1',
  first_name: 'Ana',
  last_name: 'Núñez',
  age_years: 36,
  clinical_record_number: '40000001',
  has_current_consent: true,
  allergies: ['Penicilina'],
}
const PROCEDURE = {
  id: 'proc-1',
  code: 'RES-01',
  name: 'Restauración con resina',
  price: '150.00',
  requires_tooth: true,
  requires_surface: false,
  requires_informed_consent: false,
  is_active: true,
}
const EXTRACTION = {
  ...PROCEDURE,
  id: 'proc-2',
  code: 'EXO-01',
  name: 'Extracción simple',
  price: '120.00',
  requires_informed_consent: true,
}

function item(overrides = {}) {
  return {
    id: 'item-1',
    position: 1,
    procedure: { id: 'proc-1', code: 'RES-01', name: 'Restauración con resina' },
    tooth: 36,
    surfaces: ['O'],
    quantity: 1,
    performed_quantity: 0,
    status: 'propuesto',
    ...overrides,
  }
}

function plan(overrides = {}) {
  return {
    id: 'plan-1',
    patient_id: 'p-1',
    title: 'Plan de restauraciones',
    status: 'propuesto',
    created_by: { id: 'u-dentist', name: 'Dra. Pérez' },
    items: [item(), item({ id: 'item-2', position: 2, tooth: 46 })],
    ...overrides,
  }
}

function budget(overrides = {}) {
  return {
    id: 'b-1',
    number: null,
    status: 'borrador',
    plan_id: 'plan-1',
    patient_id: 'p-1',
    corrects_budget_id: null,
    prices_include_igv: true,
    subtotal: '150.00',
    discount_total: '0.00',
    base_amount: '127.12',
    igv_amount: '22.88',
    total: '150.00',
    issued_at: null,
    expires_at: null,
    dentist: null,
    decision: null,
    lines: [
      {
        id: 'line-1',
        plan_item_id: 'item-1',
        description: 'Restauración con resina',
        tooth: 36,
        surfaces: ['O'],
        unit_price: '150.00',
        quantity: 1,
        discount_pct: '0.00',
        discount_reason: null,
        discount_approved: false,
        subtotal: '150.00',
      },
    ],
    ...overrides,
  }
}

const ISSUED = budget({
  number: 'P-000001',
  status: 'emitido',
  issued_at: '2026-10-09T15:00:00Z',
  expires_at: '2026-11-08T15:00:00Z',
  dentist: { id: 'u-dentist', name: 'Dra. Pérez', cop: '12345' },
})

const key = (config) => `${config.method.toUpperCase()} ${config.url}`
const body = (calls, wanted) => JSON.parse(calls.find((call) => key(call) === wanted).data)
const formOf = (calls, wanted) => Object.fromEntries(calls.find((call) => key(call) === wanted).data.entries())

function api(handler) {
  return mockApi((config) => {
    if (config.url === '/patients/p-1') return { data: { data: PATIENT } }
    if (config.url === '/procedures') return { data: { data: [PROCEDURE, EXTRACTION] } }
    return handler(config)
  })
}

const BUDGET_ROUTE = { route: '/c/clinica-demo/app/presupuestos/b-1', path: '/c/:slug/app/presupuestos/:uuid' }

describe('BudgetNewPage', () => {
  it('creates a draft with the chosen proposed items (CUS-35, RN-36)', async () => {
    const calls = api((config) => {
      if (key(config) === 'GET /treatment-plans/plan-1') return { data: { data: plan() } }
      return { status: 201, data: { data: budget() } }
    })
    renderPage(<BudgetNewPage />, {
      user: makeUser('receptionist'),
      route: '/c/clinica-demo/app/planes/plan-1/presupuesto',
      path: '/c/:slug/app/planes/:uuid/presupuesto',
    })

    await userEvent.click(await screen.findByRole('checkbox', { name: 'Incluir Restauración con resina (ítem 2)' }))
    await userEvent.click(screen.getByRole('button', { name: 'Crear borrador con 1 ítem' }))

    expect(await screen.findByTestId('location')).toHaveTextContent('/c/clinica-demo/app/presupuestos/b-1')
    expect(body(calls, 'POST /treatment-plans/plan-1/budgets')).toEqual({ plan_item_ids: ['item-1'] })
  })
})

describe('BudgetDetailPage', () => {
  it('applies a discount and shows the RN-31 cap for non admins', async () => {
    const calls = api((config) => {
      if (key(config) === 'GET /budgets/b-1') return { data: { data: budget() } }
      return {
        status: 403,
        data: {
          detail:
            'El descuento supera el tope de la clínica (10.00 %): solo el Administrador de Clínica puede aplicarlo.',
        },
      }
    })
    renderPage(<BudgetDetailPage />, { ...BUDGET_ROUTE, user: makeUser('dentist') })

    await userEvent.click(await screen.findByRole('button', { name: 'Descuento de Restauración con resina' }))
    await userEvent.clear(screen.getByLabelText('Descuento (%)'))
    await userEvent.type(screen.getByLabelText('Descuento (%)'), '20')
    await userEvent.type(screen.getByLabelText('Motivo del descuento'), 'Paciente frecuente')
    await userEvent.click(screen.getByRole('button', { name: 'Aplicar descuento' }))

    expect(await screen.findByText(/solo el Administrador de Clínica/)).toBeInTheDocument()
    expect(body(calls, 'PATCH /budgets/b-1/lines/line-1')).toEqual({
      discount_pct: '20',
      discount_reason: 'Paciente frecuente',
    })
  })

  it('issues only after confirming and shows inactive procedures in their line (RN-32, RNF-063)', async () => {
    const calls = api((config) => {
      if (key(config) === 'GET /budgets/b-1') return { data: { data: budget() } }
      return {
        status: 422,
        data: {
          detail: 'El presupuesto tiene procedimientos inactivos: no se emitió ninguna parte.',
          errors: { 'lines.line-1': ['El procedimiento «Restauración con resina» está inactivo en el catálogo.'] },
        },
      }
    })
    renderPage(<BudgetDetailPage />, { ...BUDGET_ROUTE, user: makeUser('dentist') })

    await userEvent.click(await screen.findByRole('button', { name: 'Emitir presupuesto' }))
    expect(calls.some((call) => key(call) === 'POST /budgets/b-1/issue')).toBe(false)
    const dialog = screen.getByRole('dialog', { name: '¿Emitir el presupuesto?' })
    expect(dialog).toHaveTextContent('no se puede modificar')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Confirmar emisión' }))

    expect(await screen.findByText(/está inactivo en el catálogo/)).toBeInTheDocument()
    expect(screen.getByText(/no se emitió ninguna parte/)).toBeInTheDocument()
  })

  it('records a presencial rejection after confirming (CUS-37)', async () => {
    const calls = api((config) => {
      if (key(config) === 'GET /budgets/b-1') return { data: { data: ISSUED } }
      if (key(config) === 'GET /budgets/b-1/pdf')
        return { data: { data: { status: 'listo', url: 'https://s3.test/b.pdf' } } }
      return {
        data: {
          data: {
            ...ISSUED,
            status: 'rechazado',
            decision: {
              channel: 'presencial',
              by: { id: 'u-receptionist', name: 'Usuario receptionist' },
              signer: 'titular',
              decided_at: '2026-10-09T16:00:00Z',
              rejection_reason: 'precio',
              rejection_detail: null,
            },
          },
        },
      }
    })
    renderPage(<BudgetDetailPage />, { ...BUDGET_ROUTE, user: makeUser('receptionist') })

    expect(await screen.findByRole('link', { name: /Descargar PDF/ })).toHaveAttribute('href', 'https://s3.test/b.pdf')
    await userEvent.click(screen.getByRole('radio', { name: 'Rechaza el presupuesto' }))
    await userEvent.type(screen.getByLabelText('Documento de quien decide'), '40000001')
    await userEvent.selectOptions(screen.getByLabelText('Motivo del rechazo (opcional)'), 'precio')
    await userEvent.click(screen.getByRole('button', { name: 'Registrar rechazo' }))
    expect(calls.some((call) => key(call) === 'POST /budgets/b-1/decision')).toBe(false)
    await userEvent.click(screen.getByRole('button', { name: 'Confirmar rechazo' }))

    expect(await screen.findByRole('heading', { name: 'Decisión registrada' })).toBeInTheDocument()
    expect(formOf(calls, 'POST /budgets/b-1/decision')).toEqual({
      decision: 'rechazado',
      signer: 'titular',
      signer_document_number: '40000001',
      rejection_reason: 'precio',
    })
  })

  it('shows the document mismatch beside its field and reloads the budget after a 409 (RN-35)', async () => {
    let decided = 0
    const calls = api((config) => {
      if (key(config) === 'GET /budgets/b-1') {
        return { data: { data: decided > 1 ? { ...ISSUED, status: 'vencido' } : ISSUED } }
      }
      if (key(config) === 'GET /budgets/b-1/pdf') return { data: { data: { status: 'listo', url: null } } }
      decided += 1
      return decided === 1
        ? {
            status: 422,
            data: { errors: { signer_document_number: ['El documento no coincide con el del titular.'] } },
          }
        : { status: 409, data: { detail: 'El presupuesto venció: no admite decisión.' } }
    })
    renderPage(<BudgetDetailPage />, { ...BUDGET_ROUTE, user: makeUser('receptionist') })

    await userEvent.type(await screen.findByLabelText('Documento de quien decide'), '99999999')
    await userEvent.click(screen.getByRole('button', { name: 'Registrar aceptación' }))
    await userEvent.click(screen.getByRole('button', { name: 'Confirmar aceptación' }))
    expect(await screen.findByText('El documento no coincide con el del titular.')).toBeInTheDocument()
    expect(screen.getByLabelText('Documento de quien decide')).toHaveAttribute('aria-invalid', 'true')

    await userEvent.click(screen.getByRole('button', { name: 'Registrar aceptación' }))
    await userEvent.click(screen.getByRole('button', { name: 'Confirmar aceptación' }))
    expect(await screen.findByText('Vencido')).toBeInTheDocument()
    expect(screen.getByText('El presupuesto venció: no admite decisión.')).toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Registrar la decisión del paciente' })).not.toBeInTheDocument()
    expect(calls.filter((call) => key(call) === 'GET /budgets/b-1').length).toBeGreaterThan(1)
  })

  it('deletes a draft only after confirming and goes back to the plan', async () => {
    const calls = api((config) => {
      if (key(config) === 'GET /budgets/b-1') return { data: { data: budget() } }
      return { status: 204, data: null }
    })
    renderPage(<BudgetDetailPage />, { ...BUDGET_ROUTE, user: makeUser('clinic_admin') })

    await userEvent.click(await screen.findByRole('button', { name: 'Eliminar borrador' }))
    expect(calls.some((call) => key(call) === 'DELETE /budgets/b-1')).toBe(false)
    await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Eliminar borrador' }))

    expect(await screen.findByTestId('location')).toHaveTextContent('/c/clinica-demo/app/planes/plan-1')
  })

  it('lets the dentist correct and regenerate a failed PDF, but not decide', async () => {
    const calls = api((config) => {
      if (key(config) === 'GET /budgets/b-1') return { data: { data: ISSUED } }
      if (key(config) === 'GET /budgets/b-1/pdf') return { data: { data: { status: 'fallido', url: null } } }
      if (key(config) === 'POST /budgets/b-1/corrections') return { status: 201, data: { data: budget({ id: 'b-2' }) } }
      return { status: 202, data: null }
    })
    renderPage(<BudgetDetailPage />, { ...BUDGET_ROUTE, user: makeUser('dentist') })

    await userEvent.click(await screen.findByRole('button', { name: 'Regenerar PDF' }))
    await waitFor(() => expect(calls.some((call) => key(call) === 'POST /budgets/b-1/pdf/regenerate')).toBe(true))
    expect(screen.queryByRole('heading', { name: 'Registrar la decisión del paciente' })).not.toBeInTheDocument()

    // La corrección abre el borrador nuevo en la misma pantalla.
    await userEvent.click(screen.getByRole('button', { name: 'Corregir' }))
    await waitFor(() => expect(calls.some((call) => key(call) === 'GET /budgets/b-2')).toBe(true))
  })
})

describe('PlanBudgets', () => {
  it('lists only the budgets of the plan and links a new one (RF-121)', async () => {
    api(() => ({
      data: { data: [ISSUED, { ...ISSUED, id: 'b-9', number: 'P-000009', plan_id: 'otro-plan' }] },
    }))
    renderPage(<PlanBudgets plan={plan()} needsConsent />, { user: makeUser('dentist') })

    expect(await screen.findByRole('link', { name: 'P-000001' })).toBeInTheDocument()
    expect(screen.queryByText('P-000009')).not.toBeInTheDocument()
    expect(screen.getByText('Emitido')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Nuevo presupuesto' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Consentimientos informados' })).toBeInTheDocument()
  })
})

describe('ConsentTemplatesPage', () => {
  const TEMPLATE = {
    id: 't-1',
    title: 'Extracción dental',
    is_active: true,
    current_version: 1,
    body: 'Yo, {{paciente}}, autorizo {{procedimiento}}.',
    procedures: ['proc-2'],
  }

  it('creates a template and shows the error of a procedure (CUS-82, RF-073)', async () => {
    const calls = api((config) => {
      if (key(config) === 'GET /informed-consent-templates') return { data: { data: [TEMPLATE] } }
      return {
        status: 422,
        data: { errors: { 'procedures.0': ['El procedimiento «Extracción simple» ya tiene la plantilla activa.'] } },
      }
    })
    renderPage(<ConsentTemplatesPage />, { user: makeUser('clinic_admin') })

    const row = (await screen.findByText('Extracción dental')).closest('tr')
    expect(within(row).getByText('Activa')).toBeInTheDocument()
    expect(within(row).getByText('Extracción simple')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Nueva plantilla' }))
    await userEvent.type(screen.getByLabelText('Título'), 'Extracción compleja')
    await userEvent.type(screen.getByLabelText('Texto del consentimiento'), 'Autorizo el procedimiento.')
    await userEvent.click(screen.getByRole('checkbox', { name: 'EXO-01 · Extracción simple' }))
    await userEvent.click(screen.getByRole('button', { name: 'Crear plantilla' }))

    expect(await screen.findByText(/ya tiene la plantilla activa/)).toBeInTheDocument()
    expect(body(calls, 'POST /informed-consent-templates')).toEqual({
      title: 'Extracción compleja',
      body: 'Autorizo el procedimiento.',
      procedures: ['proc-2'],
    })
  })

  it('deactivates a template only after confirming', async () => {
    const calls = api(() => ({ data: { data: [TEMPLATE] } }))
    renderPage(<ConsentTemplatesPage />, { user: makeUser('clinic_admin') })

    await userEvent.click(await screen.findByRole('button', { name: 'Desactivar Extracción dental' }))
    const dialog = screen.getByRole('dialog')
    expect(dialog).toHaveTextContent('Los consentimientos ya firmados no cambian.')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Desactivar plantilla' }))

    await waitFor(() =>
      expect(calls.some((call) => key(call) === 'POST /informed-consent-templates/t-1/deactivate')).toBe(true),
    )
  })
})

describe('PlanConsentsPage', () => {
  const CONSENT = {
    id: 'ic-1',
    template_version: 1,
    signer: 'titular',
    channel: 'dispositivo',
    signed_at: '2026-10-09T16:00:00Z',
    status: 'vigente',
  }

  it('previews the text, signs with the document and revokes with a reason (CUS-83)', async () => {
    const extraction = item({ id: 'item-3', procedure: { id: 'proc-2', code: 'EXO-01', name: 'Extracción simple' } })
    const calls = api((config) => {
      if (key(config) === 'GET /treatment-plans/plan-1')
        return { data: { data: plan({ items: [item(), extraction] }) } }
      if (key(config) === 'GET /plan-items/item-3/informed-consents/preview') {
        return {
          data: {
            data: {
              template_version: 1,
              text: 'Yo, Ana Núñez, autorizo Extracción simple.',
              representative: null,
              informed_by: { id: 'u-dentist', name: 'Dra. Pérez' },
            },
          },
        }
      }
      if (key(config) === 'POST /plan-items/item-3/informed-consents') return { status: 201, data: { data: CONSENT } }
      return { data: { data: { ...CONSENT, status: 'revocado' } } }
    })
    renderPage(<PlanConsentsPage />, {
      user: makeUser('receptionist'),
      route: '/c/clinica-demo/app/planes/plan-1/consentimientos',
      path: '/c/:slug/app/planes/:uuid/consentimientos',
    })

    const row = (await screen.findByText('Extracción simple')).closest('tr')
    expect(screen.queryByText('Restauración con resina')).not.toBeInTheDocument()
    await userEvent.click(within(row).getByRole('button', { name: 'Firmar: consentimiento de Extracción simple' }))
    expect(screen.getByText('Odontólogo que informa: Dra. Pérez.')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Ver texto a firmar' }))

    expect(await screen.findByText('Yo, Ana Núñez, autorizo Extracción simple.')).toBeInTheDocument()
    const preview = calls.find((call) => key(call) === 'GET /plan-items/item-3/informed-consents/preview')
    expect(preview.params).toEqual({ riesgos: '', alternativas: '', informed_by: 'u-dentist' })
    await userEvent.type(screen.getByLabelText('Documento de quien firma'), '40000001')
    await userEvent.click(screen.getByRole('button', { name: 'Registrar firma' }))

    expect(await within(row).findByText('Firmado')).toBeInTheDocument()
    expect(formOf(calls, 'POST /plan-items/item-3/informed-consents')).toEqual({
      channel: 'dispositivo',
      confirmation_document_number: '40000001',
      informed_by: 'u-dentist',
    })

    await userEvent.click(within(row).getByRole('button', { name: 'Revocar el consentimiento de Extracción simple' }))
    const dialog = screen.getByRole('dialog')
    const confirm = within(dialog).getByRole('button', { name: 'Revocar consentimiento' })
    expect(confirm).toBeDisabled()
    await userEvent.type(within(dialog).getByLabelText('Motivo de la revocación'), 'El paciente lo pensará')
    await userEvent.click(confirm)

    expect(await within(row).findByText('Revocado')).toBeInTheDocument()
    expect(body(calls, 'POST /informed-consents/ic-1/revoke')).toEqual({ reason: 'El paciente lo pensará' })
    // Revocado, se puede firmar un consentimiento nuevo (RF-073).
    expect(
      within(row).getByRole('button', { name: 'Firmar uno nuevo: consentimiento de Extracción simple' }),
    ).toBeInTheDocument()
  })
})

describe('ProceduresPanel', () => {
  const ATTENTION = { id: 'a-1', patient_id: 'p-1' }
  const PERFORMED = { quantity: 1, odontogram_entry_id: 'e-9' }

  it('registers an accepted item after confirming with the allergies in view (CUS-39)', async () => {
    const calls = api((config) => {
      if (key(config) === 'GET /patients/p-1/treatment-plans') {
        return {
          data: {
            data: [
              plan({ status: 'aceptado', items: [item({ status: 'aceptado', quantity: 2, performed_quantity: 1 })] }),
              plan({ id: 'plan-2', status: 'propuesto', items: [item({ id: 'item-9' })] }),
            ],
          },
        }
      }
      return { status: 201, data: { data: PERFORMED } }
    })
    renderPage(<ProceduresPanel attention={ATTENTION} patient={PATIENT} />, { user: makeUser('dentist') })

    const row = (await screen.findByText('1 de 2')).closest('tr')
    expect(screen.getAllByRole('button', { name: /^Registrar / })).toHaveLength(1)
    await userEvent.click(within(row).getByRole('button', { name: 'Registrar Restauración con resina' }))
    expect(screen.getByLabelText('Cantidad (pendiente: 1)')).toHaveValue(1)
    await userEvent.click(screen.getByRole('button', { name: 'Registrar procedimiento' }))

    const dialog = screen.getByRole('dialog')
    expect(within(dialog).getByText('Penicilina')).toBeInTheDocument()
    await userEvent.click(within(dialog).getByRole('button', { name: 'Confirmar registro' }))

    expect(await screen.findByText('Su evolución se agregó al odontograma.')).toBeInTheDocument()
    expect(body(calls, 'POST /plan-items/item-1/performed-procedures')).toEqual({
      attention_id: 'a-1',
      quantity: 1,
      observations: null,
    })
  })

  it('registers an urgent procedure with who accepts it (CUS-39 FA-1)', async () => {
    const calls = api((config) => {
      if (key(config) === 'GET /patients/p-1/treatment-plans') return { data: { data: [] } }
      return { status: 201, data: { data: { ...PERFORMED, odontogram_entry_id: null } } }
    })
    renderPage(<ProceduresPanel attention={ATTENTION} patient={PATIENT} />, { user: makeUser('dentist') })

    expect(await screen.findByText('El paciente no tiene ítems aceptados pendientes de realizar.')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Procedimiento de urgencia' }))
    expect(screen.queryByLabelText('Sesión (opcional)')).not.toBeInTheDocument()
    await userEvent.selectOptions(await screen.findByLabelText('Procedimiento'), 'proc-1')
    await userEvent.type(screen.getByLabelText('Pieza'), '36')
    await userEvent.type(screen.getByLabelText('Documento de quien acepta'), '40000001')
    await userEvent.click(screen.getByRole('button', { name: 'Registrar urgencia' }))
    await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Confirmar urgencia' }))

    expect(await screen.findByText(/el odontograma no cambia/)).toBeInTheDocument()
    expect(body(calls, 'POST /attentions/a-1/urgent-procedures')).toEqual({
      procedure_id: 'proc-1',
      tooth: 36,
      surfaces: [],
      quantity: 1,
      observations: null,
      signer: 'titular',
      signer_document_number: '40000001',
    })
  })
})
