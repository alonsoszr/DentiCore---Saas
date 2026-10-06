import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { makeUser, mockApi, renderPage } from '../../test/utils'
import { ClinicalRecordPage } from './ClinicalRecordPage'

const patient = { id: 'p-1', first_name: 'Ana', last_name: 'Quispe', has_current_consent: true, allergies: [] }
const dentist = makeUser('dentist')
const attention = {
  id: 'a-1',
  patient_id: 'p-1',
  dentist: { id: dentist.id, name: 'Dra. Pérez', cop: '12345' },
  status: 'cerrada',
  is_first_attention: true,
  opened_at: '2026-10-06T15:00:00.000000Z',
}
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
  author: { id: dentist.id, name: 'Dra. Pérez', cop: '12345' },
  recorded_at: '2026-10-06T15:10:00.000000Z',
}

function api({ open } = {}) {
  return mockApi((config) => {
    const { method, url } = config
    if (method === 'post' && url === '/patients/p-1/attentions') return open
    if (url === '/patients/p-1/clinical-record')
      return { data: { data: { attentions: [attention], initial_odontogram: null } } }
    if (url === '/patients/p-1/odontogram') {
      return {
        data: { data: { at: null, default_dentition: 'permanente', entries: [{ ...entry, entry_type: 'evolucion' }] } },
      }
    }
    if (url === '/patients/p-1/odontogram/initial') {
      return {
        data: {
          data: {
            status: 'cerrado',
            closed_at: '2026-10-06T16:00:00.000000Z',
            closed_by: 'cierre_atencion',
            entries: [entry],
          },
        },
      }
    }
    if (url === '/patients/p-1/teeth/36/history') return { data: { data: [entry] } }
    return { status: 404 }
  })
}

const ROUTE = { route: '/c/clinica-demo/app/pacientes/p-1/hc', path: '/c/:slug/app/pacientes/:uuid/hc' }

describe('ClinicalRecordPage', () => {
  it('opens a new attention for the dentist and keeps the consent warning', async () => {
    const calls = api({
      open: { status: 201, data: { data: { ...attention, id: 'a-2', status: 'abierta' }, consent_warning: null } },
    })
    renderPage(<ClinicalRecordPage patient={patient} />, { ...ROUTE, user: dentist })

    expect(await screen.findByRole('link', { name: 'Ver la atención del 06/10/2026 10:00' })).toHaveAttribute(
      'href',
      '/c/clinica-demo/app/atenciones/a-1',
    )
    await userEvent.click(screen.getByRole('button', { name: 'Atender' }))

    expect(await screen.findByTestId('location')).toHaveTextContent('/c/clinica-demo/app/atenciones/a-2')
    expect(calls.some((call) => call.method === 'post' && call.url === '/patients/p-1/attentions')).toBe(true)
  })

  it('goes to the attention already open with the same dentist (RF-082)', async () => {
    api({
      open: { status: 409, data: { rule: 'RF-082', detail: 'El paciente ya tiene una atención abierta con usted.' } },
    })
    renderPage(<ClinicalRecordPage patient={patient} />, { ...ROUTE, user: dentist })
    // La atención abierta del odontólogo está en la historia clínica.
    await screen.findByText('Cerrada')

    await userEvent.click(screen.getByRole('button', { name: 'Atender' }))

    expect(await screen.findByText('El paciente ya tiene una atención abierta con usted.')).toBeInTheDocument()
  })

  it('shows the current and the initial odontogram and the history of a tooth', async () => {
    api()
    renderPage(<ClinicalRecordPage patient={patient} />, { ...ROUTE, user: dentist })

    const odontogram = await screen.findByRole('group', { name: /Odontograma vigente/ })
    expect(within(odontogram).getByTestId('acronyms-36')).toHaveTextContent('CD')

    await userEvent.click(screen.getByRole('button', { name: 'Inicial' }))
    expect(await screen.findByText('Odontograma inicial cerrado el 06/10/2026 11:00.')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: /^Pieza 36/ }))
    const history = (await screen.findByRole('heading', { name: 'Historial de la pieza 36' })).closest('section')
    expect(within(history).getByText('Dra. Pérez · COP 12345')).toBeInTheDocument()
  })

  it('gives reception the record without opening attentions', async () => {
    api()
    renderPage(<ClinicalRecordPage patient={patient} />, { ...ROUTE, user: makeUser('receptionist') })

    expect(await screen.findByText('Cerrada')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Atender' })).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /Ver la atención/ })).not.toBeInTheDocument()
  })
})
