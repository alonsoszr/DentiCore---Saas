import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { makeUser, mockApi, renderPage } from '../../test/utils'
import { ConsentPage } from './ConsentPage'
import { MedicalHistoryPage } from './MedicalHistoryPage'
import { PatientEditPage } from './PatientEditPage'
import { RepresentativesPage } from './RepresentativesPage'

const ROUTE = { route: '/c/clinica-demo/app/pacientes/p-1/x', path: '/c/:slug/app/pacientes/:uuid/x' }

const PATIENT = {
  id: 'p-1',
  first_name: 'Ana',
  last_name: 'Núñez',
  document_type: 'dni',
  document_number: '40000001',
  clinical_record_number: '40000001',
  birth_date: '1990-01-31',
  sex: 'femenino',
  phone: '999888777',
  email: null,
  address: null,
  is_minor: false,
  has_current_consent: true,
  medical_history: null,
}

afterEach(() => vi.restoreAllMocks())

describe('MedicalHistoryPage', () => {
  it('saves one item per line (RF-064)', async () => {
    const calls = mockApi(() => ({ data: { data: { ...PATIENT, allergies: ['Penicilina', 'Látex'] } } }))
    renderPage(<MedicalHistoryPage patient={PATIENT} />, { user: makeUser('dentist'), ...ROUTE })

    await userEvent.type(screen.getByLabelText('Alergias'), 'Penicilina{enter}{enter}Látex')
    await userEvent.type(screen.getByLabelText('Observaciones'), 'Control mensual')
    await userEvent.click(screen.getByRole('button', { name: 'Guardar antecedentes' }))

    expect(await screen.findByText('Antecedentes guardados.')).toBeInTheDocument()
    expect(JSON.parse(calls.find((call) => call.method === 'put').data)).toEqual({
      alergias: ['Penicilina', 'Látex'],
      enfermedades: [],
      medicamentos: [],
      observaciones: 'Control mensual',
    })
  })

  it('blocks the history without a current consent and links to it (RN-10)', () => {
    renderPage(<MedicalHistoryPage patient={{ ...PATIENT, has_current_consent: false }} />, {
      user: makeUser('dentist'),
      ...ROUTE,
    })

    expect(screen.getByText(/no tiene un consentimiento vigente/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Registrar consentimiento' })).toHaveAttribute(
      'href',
      '/c/clinica-demo/app/pacientes/p-1/consentimiento',
    )
    expect(screen.getByRole('button', { name: 'Guardar antecedentes' })).toBeDisabled()
  })
})

describe('ConsentPage', () => {
  const preview = {
    template_version: 1,
    text: 'CONSENTIMIENTO PARA EL TRATAMIENTO DE DATOS PERSONALES',
    granted_by: 'titular',
    representative: null,
    available_purposes: ['purpose_care', 'purpose_notifications', 'purpose_surveys'],
  }

  it('registers the consent confirmed with the document of the holder (CUS-17)', async () => {
    const calls = mockApi((config) => {
      if (config.url.endsWith('/preview')) return { data: { data: preview } }
      if (config.method === 'post') return { status: 201, data: { data: { id: 'c-1' } } }
      return { data: { data: [] } }
    })
    renderPage(<ConsentPage patient={PATIENT} />, { user: makeUser('receptionist'), ...ROUTE })

    await userEvent.click(await screen.findByLabelText('(a) Atención odontológica (obligatoria)'))
    await userEvent.type(screen.getByLabelText('Documento del titular (confirmación)'), '40000001')
    await userEvent.click(screen.getByRole('button', { name: 'Registrar consentimiento' }))

    expect(await screen.findByText('Consentimiento registrado.')).toBeInTheDocument()
    expect(JSON.parse(calls.find((call) => call.method === 'post').data)).toEqual({
      channel: 'presencial',
      purpose_care: true,
      purpose_notifications: false,
      purpose_surveys: false,
      confirmation_document_number: '40000001',
    })
  })

  it('shows the errors of the API next to the purposes and the document', async () => {
    mockApi((config) => {
      if (config.url.endsWith('/preview')) return { data: { data: preview } }
      if (config.method === 'post') {
        return {
          status: 422,
          data: {
            errors: {
              purpose_care: ['La finalidad (a) atención odontológica es obligatoria.'],
              confirmation_document_number: ['El documento no coincide con el del titular.'],
            },
          },
        }
      }
      return { data: { data: [] } }
    })
    renderPage(<ConsentPage patient={PATIENT} />, { user: makeUser('receptionist'), ...ROUTE })

    await userEvent.click(await screen.findByRole('button', { name: 'Registrar consentimiento' }))

    expect(await screen.findByText('La finalidad (a) atención odontológica es obligatoria.')).toBeInTheDocument()
    expect(screen.getByText('El documento no coincide con el del titular.')).toBeInTheDocument()
  })

  it('lists the consents and opens the certificate when it is ready (RF-066)', async () => {
    const open = vi.spyOn(window, 'open').mockImplementation(() => null)
    mockApi((config) => {
      if (config.url.endsWith('/preview')) return { data: { data: preview } }
      if (config.url === '/consents/c-1/certificate') {
        return { data: { data: { status: 'listo', url: 'https://archivos.test/constancia.pdf' } } }
      }
      return {
        data: {
          data: [
            {
              id: 'c-1',
              status: 'vigente',
              outdated: true,
              granted_by: 'titular',
              channel: 'presencial',
              granted_at: '2026-10-05T15:00:00Z',
              purpose_care: true,
            },
          ],
        },
      }
    })
    renderPage(<ConsentPage patient={PATIENT} />, { user: makeUser('receptionist'), ...ROUTE })

    expect(await screen.findByText('Vigente')).toBeInTheDocument()
    expect(screen.getByText(/versión anterior/)).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Constancia' }))

    expect(open).toHaveBeenCalledWith('https://archivos.test/constancia.pdf', '_blank', 'noopener')
  })
})

describe('RepresentativesPage', () => {
  it('adds a representative and ends a current one after confirmation (CUS-16)', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    const current = {
      id: 'r-1',
      first_name: 'Rosa',
      last_name: 'Mamani',
      relationship: 'madre',
      document_type: 'dni',
      document_number: '41234567',
      valid_from: '2026-01-01',
      is_current: true,
    }
    const calls = mockApi((config) =>
      config.method === 'post' ? { status: 201, data: { data: current } } : { data: { data: [current] } },
    )
    renderPage(<RepresentativesPage patient={{ ...PATIENT, is_minor: true }} />, {
      user: makeUser('receptionist'),
      ...ROUTE,
    })

    expect(await screen.findByText('Rosa Mamani')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Agregar representante' }))
    await userEvent.type(screen.getByLabelText('Número de documento'), '42345678')
    await userEvent.type(screen.getByLabelText('Nombres'), 'Luis')
    await userEvent.type(screen.getByLabelText('Apellidos'), 'Quispe')
    await userEvent.selectOptions(screen.getByLabelText('Parentesco'), 'padre')
    await userEvent.type(screen.getByLabelText('Teléfono'), '912345678')
    await userEvent.click(screen.getByRole('button', { name: 'Guardar representante' }))

    const add = calls.find((call) => call.method === 'post' && call.url.endsWith('/representatives'))
    expect(JSON.parse(add.data)).toMatchObject({ document_number: '42345678', relationship: 'padre', email: null })

    await userEvent.click(screen.getByRole('button', { name: 'Terminar' }))
    const end = calls.find((call) => call.url.endsWith('/r-1/end'))
    expect(JSON.parse(end.data)).toEqual({ reason: 'revocada' })
  })

  it('does not let the dentist add representatives', async () => {
    mockApi(() => ({ data: { data: [] } }))
    renderPage(<RepresentativesPage patient={PATIENT} />, { user: makeUser('dentist'), ...ROUTE })

    expect(await screen.findByText('Sin representantes registrados.')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Agregar representante' })).not.toBeInTheDocument()
  })
})

describe('PatientEditPage', () => {
  it('sends only the changed fields and the document as a pair (CUS-15)', async () => {
    const calls = mockApi(() => ({ data: { data: { ...PATIENT, phone: '911222333' } } }))
    renderPage(<PatientEditPage patient={PATIENT} />, { user: makeUser('receptionist'), ...ROUTE })

    await userEvent.clear(screen.getByLabelText('Teléfono'))
    await userEvent.type(screen.getByLabelText('Teléfono'), '911222333')
    await userEvent.click(screen.getByRole('button', { name: 'Guardar cambios' }))
    expect(await screen.findByText('Datos actualizados.')).toBeInTheDocument()
    expect(JSON.parse(calls.at(-1).data)).toEqual({ phone: '911222333' })

    await userEvent.clear(screen.getByLabelText('Número de documento'))
    await userEvent.type(screen.getByLabelText('Número de documento'), '40000002')
    await userEvent.click(screen.getByRole('button', { name: 'Guardar cambios' }))
    await screen.findByText('Datos actualizados.')
    expect(JSON.parse(calls.at(-1).data)).toMatchObject({ document_type: 'dni', document_number: '40000002' })
  })

  it('shows the duplicate document next to its field', async () => {
    mockApi(() => ({
      status: 422,
      data: { rule: 'RN-09', errors: { document_number: ['El documento ya está registrado en la clínica.'] } },
    }))
    renderPage(<PatientEditPage patient={PATIENT} />, { user: makeUser('receptionist'), ...ROUTE })

    await userEvent.clear(screen.getByLabelText('Número de documento'))
    await userEvent.type(screen.getByLabelText('Número de documento'), '45678912')
    await userEvent.click(screen.getByRole('button', { name: 'Guardar cambios' }))

    expect(await screen.findByText('El documento ya está registrado en la clínica.')).toBeInTheDocument()
    expect(screen.getByLabelText('Número de documento')).toHaveValue('45678912')
  })
})
