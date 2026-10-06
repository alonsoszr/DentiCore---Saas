import { screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { makeUser, mockApi, renderPage } from '../../test/utils'
import { PatientDetailPage } from './PatientDetailPage'

const PATH = '/c/:slug/app/pacientes/:uuid/*'

const PATIENT = {
  id: 'p-1',
  first_name: 'Ana',
  last_name: 'Núñez',
  document_type: 'dni',
  document_number: '40000001',
  clinical_record_number: '40000001',
  birth_date: '1990-01-31',
  age_years: 36,
  is_minor: false,
  phone: '999888777',
  email: null,
  user_uuid: 'u-1',
  has_current_consent: true,
  consent_outdated: false,
  allergies: ['Penicilina'],
  medical_history: { alergias: ['Penicilina'], enfermedades: [], medicamentos: [], observaciones: null },
}

function api(patient = PATIENT) {
  return mockApi((config) => {
    if (config.url === '/patients/p-1') return { data: { data: patient } }
    if (config.url.endsWith('/preview')) {
      return {
        data: {
          data: { template_version: 1, text: 'Texto', granted_by: 'titular', available_purposes: ['purpose_care'] },
        },
      }
    }
    return { data: { data: [] } }
  })
}

function route(sub = '') {
  return { route: `/c/clinica-demo/app/pacientes/p-1${sub}`, path: PATH }
}

describe('PatientDetailPage', () => {
  it('shows the record with its medical history', async () => {
    api()
    renderPage(<PatientDetailPage />, { user: makeUser('dentist'), ...route() })

    expect(await screen.findByRole('heading', { name: 'Ana Núñez' })).toBeInTheDocument()
    expect(screen.getByText('DNI 40000001')).toBeInTheDocument()
    expect(screen.getAllByText('Ninguna registrada')).toHaveLength(2)
    expect(screen.getByText('Vinculada')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Volver' })).toHaveAttribute('href', '/c/clinica-demo/app/pacientes')
    // El odontólogo no edita la identificación (CUS-15).
    expect(screen.queryByRole('link', { name: 'Editar identificación' })).not.toBeInTheDocument()
  })

  it.each(['', '/antecedentes', '/consentimiento', '/representantes', '/editar'])(
    'shows the patient header with the allergy warning on %s',
    async (sub) => {
      api()
      renderPage(<PatientDetailPage />, { user: makeUser('receptionist'), ...route(sub) })

      const header = await screen.findByRole('region', { name: 'Paciente' })
      expect(within(header).getByRole('heading', { name: 'Ana Núñez' })).toBeInTheDocument()
      expect(within(header).getByText('36 años')).toBeInTheDocument()
      expect(within(header).getByText('HC 40000001')).toBeInTheDocument()
      expect(within(header).getByText('Consentimiento vigente')).toBeInTheDocument()
      expect(within(header).getByText('Penicilina')).toBeInTheDocument()
    },
  )

  it('shows a record without medical history nor consent', async () => {
    api({ ...PATIENT, medical_history: null, allergies: [], has_current_consent: false, user_uuid: null })
    renderPage(<PatientDetailPage />, { user: makeUser('dentist'), ...route() })

    expect(await screen.findByText('No se registraron antecedentes.')).toBeInTheDocument()
    expect(screen.getByText('Sin cuenta vinculada')).toBeInTheDocument()
    expect(screen.getByText('Sin alergias registradas.')).toBeInTheDocument()
    expect(screen.getByText('Sin consentimiento vigente')).toBeInTheDocument()
  })

  it('shows not found for a record of another clinic', async () => {
    mockApi(() => ({ status: 404, data: {} }))
    renderPage(<PatientDetailPage />, { user: makeUser('dentist'), ...route() })

    expect(await screen.findByText('No se encontró el recurso solicitado.')).toBeInTheDocument()
  })
})
