import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { makeUser, mockApi, renderPage } from '../../test/utils'
import { AttentionPage } from './AttentionPage'

const dentist = makeUser('dentist')
const patient = {
  id: 'p-1',
  first_name: 'Ana',
  last_name: 'Quispe',
  age_years: 36,
  clinical_record_number: 'HC-000001',
  has_current_consent: true,
  consent_outdated: false,
  allergies: ['Penicilina'],
}
const openAttention = {
  id: 'a-1',
  patient_id: 'p-1',
  dentist: { id: dentist.id, name: 'Dra. Pérez', cop: '12345' },
  status: 'abierta',
  is_first_attention: true,
  opened_at: '2026-10-06T15:00:00.000000Z',
  closed_at: null,
  signer: null,
  signed_at: null,
  note: null,
  diagnoses: [],
  addenda: [],
}
const catalog = [
  {
    code: 'CARIES',
    name: 'Lesión de caries dental',
    acronym: null,
    level: 'superficie',
    dentition: 'ambas',
    states: [{ code: 'CD', name: 'Lesión de caries dental a nivel de la dentina', color: 'rojo', acronym: 'CD' }],
  },
  {
    code: 'PULPOTOMIA',
    name: 'Pulpotomía',
    acronym: 'PP',
    level: 'pieza',
    dentition: 'temporal',
    states: [{ code: 'BUENO', name: 'Buen estado', color: 'azul', acronym: null }],
  },
]
const entry = {
  id: 'e-1',
  entry_type: 'inicial',
  tooth: 36,
  tooth_end: null,
  surfaces: ['O'],
  finding: { code: 'CARIES', name: 'Lesión de caries dental', acronym: null },
  state: { code: 'CD', name: 'Lesión de caries dental a nivel de la dentina', acronym: 'CD' },
  color: 'rojo',
  origin: 'manual',
  author: { id: 'u-otro', name: 'Dr. Ramos', cop: '67890' },
  recorded_at: '2026-10-06T15:10:00.000000Z',
}

function api(attention = openAttention, writes = {}) {
  return mockApi((config) => {
    const key = `${config.method.toUpperCase()} ${config.url}`
    if (writes[key]) return writes[key](config)
    if (key === 'GET /attentions/a-1') return { data: { data: attention } }
    if (key === 'GET /patients/p-1') return { data: { data: patient } }
    if (key === 'GET /finding-catalog') return { data: { data: catalog } }
    if (key === 'GET /patients/p-1/odontogram')
      return { data: { data: { at: null, default_dentition: 'permanente', entries: [] } } }
    if (key === 'GET /patients/p-1/teeth/36/history') return { data: { data: [entry] } }
    if (key === 'GET /cie10') {
      return { data: { data: [{ code: 'K02.1', description: 'Caries de la dentina', is_dental: true }] } }
    }
    return { status: 201, data: { data: {} } }
  })
}

const ROUTE = { route: '/c/clinica-demo/app/atenciones/a-1', path: '/c/:slug/app/atenciones/:uuid', user: dentist }
const body = (calls, key) => JSON.parse(calls.find((call) => `${call.method.toUpperCase()} ${call.url}` === key).data)

describe('AttentionPage', () => {
  it('shows the allergies before the clinical data and saves the note', async () => {
    const calls = api()
    renderPage(<AttentionPage />, ROUTE)

    const header = await screen.findByRole('region', { name: 'Paciente' })
    expect(within(header).getByText('Penicilina')).toBeInTheDocument()
    expect(header.compareDocumentPosition(screen.getByLabelText('Motivo de consulta'))).toBe(
      Node.DOCUMENT_POSITION_FOLLOWING,
    )

    await userEvent.type(screen.getByLabelText('Motivo de consulta'), 'Dolor al masticar')
    await userEvent.click(screen.getByRole('button', { name: 'Guardar nota' }))

    expect(await screen.findByText('Nota guardada.')).toBeInTheDocument()
    expect(body(calls, 'PUT /attentions/a-1/note')).toMatchObject({
      chief_complaint: 'Dolor al masticar',
      indications: '',
    })
  })

  it('warns that clinical records are blocked without a current consent', async () => {
    api()
    renderPage(<AttentionPage />, {
      ...ROUTE,
      route: {
        pathname: '/c/clinica-demo/app/atenciones/a-1',
        state: { consentWarning: { rule: 'RN-10', detail: 'Sin consentimiento.' } },
      },
    })

    expect(await screen.findByText('Sin consentimiento.')).toBeInTheDocument()
  })

  it('searches CIE-10 and adds a diagnosis', async () => {
    const calls = api()
    renderPage(<AttentionPage />, ROUTE)

    await userEvent.type(await screen.findByLabelText('Buscar diagnóstico CIE-10'), 'caries')
    await userEvent.click(screen.getByRole('button', { name: 'Buscar' }))
    await userEvent.selectOptions(await screen.findByLabelText('Diagnóstico'), 'K02.1')
    await userEvent.selectOptions(screen.getByLabelText('Tipo'), 'definitivo')
    await userEvent.click(screen.getByRole('button', { name: 'Agregar diagnóstico' }))

    expect(calls.find((call) => call.url === '/cie10').params).toEqual({ q: 'caries' })
    expect(body(calls, 'POST /attentions/a-1/diagnoses')).toEqual({ cie10_code: 'K02.1', type: 'definitivo' })
  })

  it('records a finding from the odontogram with the findings of the tooth dentition', async () => {
    const calls = api()
    renderPage(<AttentionPage />, ROUTE)

    await userEvent.click(await screen.findByRole('button', { name: /^Pieza 36/ }))
    expect(screen.getByRole('option', { name: 'Lesión de caries dental' })).toBeInTheDocument()
    expect(screen.queryByRole('option', { name: 'Pulpotomía' })).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('checkbox', { name: 'Oclusal' }))
    await userEvent.selectOptions(screen.getByLabelText('Hallazgo'), 'CARIES')
    await userEvent.selectOptions(screen.getByLabelText('Estado'), 'CD')
    await userEvent.click(screen.getByRole('button', { name: 'Registrar hallazgo' }))

    expect(await screen.findByText('Hallazgo registrado.')).toBeInTheDocument()
    expect(body(calls, 'POST /attentions/a-1/odontogram-entries')).toEqual({
      tooth: 36,
      tooth_end: null,
      surfaces: ['O'],
      finding_code: 'CARIES',
      state_code: 'CD',
      note: null,
    })
  })

  it('corrects an entry after confirming its reason', async () => {
    const calls = api()
    renderPage(<AttentionPage />, ROUTE)

    await userEvent.click(await screen.findByRole('button', { name: /^Pieza 36/ }))
    await userEvent.click(await screen.findByRole('button', { name: 'Corregir la entrada del 06/10/2026 10:10' }))

    const dialog = screen.getByRole('dialog', { name: 'Corregir la entrada' })
    expect(within(dialog).getByText('Corrige una entrada registrada por Dr. Ramos.')).toBeInTheDocument()
    expect(
      within(dialog).getByText('La entrada original se conserva sin cambios y queda marcada como corregida.'),
    ).toBeInTheDocument()
    await userEvent.click(within(dialog).getByRole('radio', { name: 'Anulación' }))
    await userEvent.type(within(dialog).getByLabelText('Motivo de la corrección'), 'Registrada en la pieza equivocada')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Confirmar corrección' }))

    expect(body(calls, 'POST /odontogram-entries/e-1/corrections')).toEqual({
      kind: 'anulacion',
      reason: 'Registrada en la pieza equivocada',
    })
    expect(await screen.findByText('Corrección registrada.')).toBeInTheDocument()
  })

  it('closes the attention only after confirming and shows what is missing (RN-77)', async () => {
    const calls = api(openAttention, {
      'POST /attentions/a-1/close': () => ({
        status: 422,
        data: {
          rule: 'RN-77',
          detail: 'Para cerrar la atención registre el motivo de consulta y al menos un diagnóstico CIE-10.',
          errors: { diagnoses: ['Registre al menos un diagnóstico CIE-10.'] },
        },
      }),
    })
    renderPage(<AttentionPage />, ROUTE)

    await userEvent.click(await screen.findByRole('button', { name: 'Cerrar atención' }))
    const dialog = screen.getByRole('dialog', { name: 'Cerrar la atención' })
    expect(within(dialog).getByText(/la nota y los diagnósticos quedan firmados/i)).toBeInTheDocument()
    await userEvent.click(within(dialog).getByRole('button', { name: 'Cancelar' }))
    expect(calls.some((call) => call.url === '/attentions/a-1/close')).toBe(false)

    await userEvent.click(screen.getByRole('button', { name: 'Cerrar atención' }))
    await userEvent.click(screen.getByRole('button', { name: 'Confirmar cierre' }))

    expect(await screen.findByText('Registre al menos un diagnóstico CIE-10.')).toBeInTheDocument()
  })

  it('shows a closed attention in read-only mode and records an addendum', async () => {
    const calls = api({
      ...openAttention,
      status: 'cerrada_incompleta',
      note: { chief_complaint: null, intraoral_exam: 'Lesión en 36', status: 'borrador' },
    })
    renderPage(<AttentionPage />, ROUTE)

    expect(await screen.findByText('Cerrada incompleta')).toBeInTheDocument()
    expect(screen.getByText('Lesión en 36')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Guardar nota' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Registrar hallazgo' })).not.toBeInTheDocument()

    await userEvent.type(screen.getByLabelText('Texto de la adenda'), 'Se completa la atención.')
    await userEvent.type(screen.getByLabelText('Motivo de consulta (si faltó)'), 'Dolor al masticar')
    await userEvent.type(screen.getByLabelText('Buscar diagnóstico CIE-10'), 'caries')
    await userEvent.click(screen.getByRole('button', { name: 'Buscar' }))
    await userEvent.selectOptions(await screen.findByLabelText('Diagnóstico'), 'K02.1')
    await userEvent.click(screen.getByRole('button', { name: 'Agregar diagnóstico' }))
    await userEvent.click(screen.getByRole('button', { name: 'Registrar adenda' }))

    expect(body(calls, 'POST /attentions/a-1/addenda')).toEqual({
      text: 'Se completa la atención.',
      chief_complaint: 'Dolor al masticar',
      diagnoses: [{ cie10_code: 'K02.1', type: 'presuntivo' }],
    })
    expect(await screen.findByText('Adenda registrada.')).toBeInTheDocument()
  })
})
