import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { makeUser, mockApi, renderPage, testQueryClient } from '../../test/utils'
import { AppointmentConfirmPage } from '../auth/AppointmentConfirmPage'
import { AgendaPage } from './AgendaPage'
import { AppointmentDetailPage } from './AppointmentDetailPage'
import { AppointmentNewPage } from './AppointmentNewPage'

// Lunes 02/11/2026 a las 09:00 en Lima: solo se simula la fecha, los temporizadores siguen reales.
beforeEach(() => vi.useFakeTimers({ toFake: ['Date'], now: new Date('2026-11-02T14:00:00Z') }))
afterEach(() => {
  vi.useRealTimers()
  vi.restoreAllMocks()
})

const key = (config) => `${config.method.toUpperCase()} ${config.url}`
const body = (calls, wanted) => JSON.parse(calls.filter((call) => key(call) === wanted).at(-1).data)

function appointment(overrides = {}) {
  return {
    id: 'a-1',
    patient: { id: 'p-1', name: 'Lucía Sintética' },
    dentist: { id: 'u-ana', name: 'Dra. Ana Torres' },
    type: { id: 't-1', name: 'Consulta', color: '#000000' },
    starts_at: '2026-11-02T15:00:00+00:00',
    ends_at: '2026-11-02T15:30:00+00:00',
    status: 'programada',
    origin: 'clinica',
    confirmed_at: null,
    confirmation_channel: null,
    reconfirmation_pending: false,
    checked_in_at: null,
    cancelled_at: null,
    cancel_reason: null,
    cancel_detail: null,
    ...overrides,
  }
}

const PATIENT = {
  id: 'p-1',
  first_name: 'Lucía',
  last_name: 'Sintética',
  document_number: '45678912',
  age_years: 36,
  clinical_record_number: '40000001',
  has_current_consent: true,
  allergies: [],
}

describe('AgendaPage', () => {
  it('lists the appointments of the day with their status and filters by dentist', async () => {
    const calls = mockApi((config) => ({
      data: {
        data: [
          appointment(),
          appointment({
            id: 'a-2',
            patient: { id: 'p-2', name: 'Mario Prueba' },
            dentist: { id: 'u-luis', name: 'Dr. Luis Paz' },
            status: 'confirmada',
            starts_at: '2026-11-02T16:00:00+00:00',
            ends_at: '2026-11-02T16:30:00+00:00',
          }),
        ].filter((item) => !config.params?.dentist_id || item.dentist.id === config.params.dentist_id),
      },
    }))
    renderPage(<AgendaPage />, { user: makeUser('receptionist') })

    const table = await screen.findByRole('table')
    expect(within(table).getByRole('link', { name: 'Lucía Sintética' })).toBeInTheDocument()
    expect(within(table).getByText('10:00 – 10:30')).toBeInTheDocument()
    expect(within(table).getByText('Confirmada')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Nueva cita' })).toBeInTheDocument()
    expect(calls[0].params).toEqual({ from: '2026-11-02', to: '2026-11-02' })

    await userEvent.selectOptions(screen.getByLabelText('Odontólogo'), 'u-luis')
    await waitFor(() =>
      expect(calls.at(-1).params).toEqual({ from: '2026-11-02', to: '2026-11-02', dentist_id: 'u-luis' }),
    )
    expect(within(screen.getByRole('table')).queryByText('Lucía Sintética')).not.toBeInTheDocument()
    // El odontólogo elegido sigue en el filtro aunque el día no tenga citas de otros.
    expect(screen.getByLabelText('Odontólogo')).toHaveValue('u-luis')
  })

  it('shows the week from Monday and the waiting room with the wait time', async () => {
    const calls = mockApi((config) =>
      config.url === '/waiting-room'
        ? { data: { data: [appointment({ status: 'en_atencion', checked_in_at: '2026-11-02T13:45:00+00:00' })] } }
        : { data: { data: [appointment()] } },
    )
    renderPage(<AgendaPage />, { user: makeUser('dentist') })

    expect(screen.queryByRole('link', { name: 'Nueva cita' })).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('tab', { name: 'Semana' }))
    await waitFor(() => expect(calls.at(-1).params).toEqual({ from: '2026-11-02', to: '2026-11-08' }))
    expect(await screen.findByRole('heading', { name: 'Domingo 08/11/2026' })).toBeInTheDocument()

    await userEvent.click(screen.getByRole('tab', { name: 'Sala de espera' }))
    expect(await screen.findByText('15 min')).toBeInTheDocument()
  })
})

describe('AppointmentNewPage', () => {
  it('books a free slot of the chosen day for the patient', async () => {
    const calls = mockApi((config) => {
      if (key(config) === 'GET /appointment-types') {
        return {
          data: {
            data: [{ id: 't-1', name: 'Consulta', default_duration_min: 30, color: '#000000', is_active: true }],
          },
        }
      }
      if (key(config) === 'GET /patients') return { data: { data: [PATIENT] } }
      if (key(config) === 'GET /availability') {
        return {
          data: {
            data: [
              {
                dentist: { id: 'u-ana', name: 'Dra. Ana Torres' },
                duration_min: 30,
                slots: ['2026-11-02T15:00:00+00:00'],
              },
            ],
          },
        }
      }
      return { status: 201, data: { data: appointment() } }
    })
    // La agenda del día quedó en caché sin la cita nueva.
    const queryClient = testQueryClient()
    const agendaKey = ['appointments', 'clinica-demo', '2026-11-02', '2026-11-02', null]
    queryClient.setQueryData(agendaKey, [])
    renderPage(<AppointmentNewPage />, {
      user: makeUser('receptionist'),
      route: '/c/clinica-demo/app/agenda/nueva',
      path: '/c/:slug/app/agenda/nueva',
      queryClient,
    })

    await userEvent.type(screen.getByLabelText('Buscar paciente'), 'Lucía')
    await userEvent.click(screen.getByRole('button', { name: 'Buscar' }))
    await userEvent.click(await screen.findByRole('button', { name: /Sintética, Lucía/ }))
    await userEvent.selectOptions(await screen.findByLabelText('Tipo de cita'), 't-1')
    await userEvent.click(await screen.findByRole('button', { name: '10:00' }))
    await userEvent.click(screen.getByRole('button', { name: /Reservar con Dra. Ana Torres/ }))

    expect(await screen.findByTestId('location')).toHaveTextContent('/c/clinica-demo/app/agenda/a-1')
    expect(calls.find((call) => key(call) === 'GET /availability').params).toEqual({
      appointment_type_id: 't-1',
      patient_id: 'p-1',
      from: '2026-11-02',
      to: '2026-11-02',
    })
    expect(body(calls, 'POST /appointments')).toEqual({
      patient_id: 'p-1',
      dentist_id: 'u-ana',
      appointment_type_id: 't-1',
      starts_at: '2026-11-02T15:00:00+00:00',
    })
    expect(queryClient.getQueryData(agendaKey)).toBeUndefined()
  })
})

describe('AppointmentNewPage errors', () => {
  const types = {
    data: { data: [{ id: 't-1', name: 'Consulta', default_duration_min: 30, color: '#000000', is_active: true }] },
  }
  const choosePatientAndType = async () => {
    await userEvent.type(screen.getByLabelText('Buscar paciente'), 'Lucía')
    await userEvent.click(screen.getByRole('button', { name: 'Buscar' }))
    await userEvent.click(await screen.findByRole('button', { name: /Sintética, Lucía/ }))
    await userEvent.selectOptions(await screen.findByLabelText('Tipo de cita'), 't-1')
  }
  const render = () =>
    renderPage(<AppointmentNewPage />, {
      user: makeUser('receptionist'),
      route: '/c/clinica-demo/app/agenda/nueva',
      path: '/c/:slug/app/agenda/nueva',
    })

  it('clears the chosen slot and shows the availability again when it was taken (FE-1)', async () => {
    let availabilityCalls = 0
    mockApi((config) => {
      if (key(config) === 'GET /appointment-types') return types
      if (key(config) === 'GET /patients') return { data: { data: [PATIENT] } }
      if (key(config) === 'GET /availability') {
        availabilityCalls += 1
        return {
          data: {
            data: [
              {
                dentist: { id: 'u-ana', name: 'Dra. Ana Torres' },
                duration_min: 30,
                slots: ['2026-11-02T15:00:00+00:00'],
              },
              {
                dentist: { id: 'u-luis', name: 'Dr. Luis Paz' },
                duration_min: 30,
                slots: ['2026-11-02T16:00:00+00:00'],
              },
            ],
          },
        }
      }
      return { status: 409, data: { rule: 'RN-46', detail: 'El horario ya no está disponible.' } }
    })
    render()
    await choosePatientAndType()

    // CUS-47 paso 1: se puede elegir un odontólogo o «Cualquiera».
    await userEvent.selectOptions(await screen.findByLabelText('Odontólogo'), 'u-luis')
    expect(screen.queryByRole('button', { name: '10:00' })).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: '11:00' }))
    await userEvent.click(screen.getByRole('button', { name: /Reservar con Dr. Luis Paz/ }))

    expect(await screen.findByText('El horario ya no está disponible.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Reservar cita' })).toBeDisabled()
    await waitFor(() => expect(availabilityCalls).toBe(2))
  })

  it('validates the adjusted duration before asking for the availability', async () => {
    const calls = mockApi((config) => {
      if (key(config) === 'GET /appointment-types') return types
      if (key(config) === 'GET /patients') return { data: { data: [PATIENT] } }
      return { status: 422, data: { errors: { from: ['La fecha debe ser hoy o posterior.'] } } }
    })
    render()
    await choosePatientAndType()
    await userEvent.type(screen.getByLabelText('Duración (minutos, opcional)'), '12')

    expect(screen.getByText('Indica de 10 a 240 minutos, en múltiplos de 5.')).toBeInTheDocument()
    await userEvent.clear(screen.getByLabelText('Duración (minutos, opcional)'))
    expect(await screen.findByText('La fecha debe ser hoy o posterior.')).toBeInTheDocument()
    expect(
      calls.filter((call) => key(call) === 'GET /availability').every((call) => call.params.duration_min !== 12),
    ).toBe(true)
  })
})

describe('AppointmentDetailPage', () => {
  const route = { route: '/c/clinica-demo/app/agenda/a-1', path: '/c/:slug/app/agenda/:uuid' }

  it('confirms by phone and cancels only after confirming with a reason', async () => {
    const calls = mockApi((config) => {
      if (key(config) === 'GET /appointments/a-1') return { data: { data: appointment() } }
      if (config.url === '/patients/p-1') return { data: { data: PATIENT } }
      if (key(config) === 'POST /appointments/a-1/confirm') {
        return {
          data: {
            data: appointment({
              status: 'confirmada',
              confirmed_at: '2026-11-02T14:05:00+00:00',
              confirmation_channel: 'telefono',
            }),
          },
        }
      }
      return { data: { data: appointment({ status: 'cancelada', cancel_reason: 'paciente' }) } }
    })
    const queryClient = testQueryClient()
    const waitingKey = ['waiting-room', 'clinica-demo', null]
    queryClient.setQueryData(waitingKey, [])
    renderPage(<AppointmentDetailPage />, { ...route, user: makeUser('receptionist'), queryClient })

    await userEvent.click(await screen.findByRole('button', { name: 'Confirmar por teléfono' }))
    expect(await screen.findByText(/Confirmada por teléfono el 02\/11\/2026 09:05/)).toBeInTheDocument()

    // «Volver» cierra el diálogo sin efectos y devuelve el foco a «Cancelar cita».
    await userEvent.click(screen.getByRole('button', { name: 'Cancelar cita' }))
    await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Volver' }))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Cancelar cita' })).toHaveFocus()

    await userEvent.click(screen.getByRole('button', { name: 'Cancelar cita' }))
    const dialog = screen.getByRole('dialog', { name: '¿Cancelar la cita?' })
    const confirm = within(dialog).getByRole('button', { name: 'Cancelar cita' })
    expect(confirm).toBeDisabled()
    expect(confirm).toHaveClass('btn-danger')
    expect(within(dialog).getByRole('button', { name: 'Volver' })).toBeInTheDocument()
    await userEvent.selectOptions(within(dialog).getByLabelText('Motivo'), 'paciente')
    await userEvent.click(confirm)

    expect(await screen.findByText('A pedido del paciente')).toBeInTheDocument()
    expect(queryClient.getQueryData(waitingKey)).toBeUndefined()
    // Cada dato va en su propio grupo dt/dd.
    const details = document.querySelector('dl.details')
    expect([...details.children].every((child) => child.tagName === 'DIV')).toBe(true)
    expect(body(calls, 'POST /appointments/a-1/cancel')).toEqual({ cancel_reason: 'paciente', cancel_detail: null })
  })

  it('registers the arrival and takes the clinic administrator to the attention with the consent warning', async () => {
    mockApi((config) => {
      if (key(config) === 'GET /appointments/a-1') return { data: { data: appointment() } }
      if (config.url === '/patients/p-1') return { data: { data: PATIENT } }
      return {
        data: {
          data: appointment({ status: 'en_atencion', checked_in_at: '2026-11-02T14:00:00+00:00' }),
          attention: { id: 'at-1' },
          consent_warning: { rule: 'RN-10', detail: 'Falta el consentimiento.' },
        },
      }
    })
    renderPage(<AppointmentDetailPage />, { ...route, user: makeUser('clinic_admin') })

    await userEvent.click(await screen.findByRole('button', { name: 'Registrar llegada' }))
    expect(await screen.findByTestId('location')).toHaveTextContent('/c/clinica-demo/app/atenciones/at-1')
  })

  it('warns the receptionist when the patient has no consent and shows the check-in window error', async () => {
    let checkIns = 0
    mockApi((config) => {
      if (key(config) === 'GET /appointments/a-1') return { data: { data: appointment() } }
      checkIns += 1
      return checkIns === 1
        ? { status: 422, data: { rule: 'RN-50', detail: 'La llegada se registra el día de la cita.' } }
        : {
            data: {
              data: appointment({ status: 'en_atencion', checked_in_at: '2026-11-02T14:00:00+00:00' }),
              attention: { id: 'at-1' },
              consent_warning: { rule: 'RN-10', detail: 'Falta el consentimiento.' },
            },
          }
    })
    renderPage(<AppointmentDetailPage />, { ...route, user: makeUser('receptionist') })

    await userEvent.click(await screen.findByRole('button', { name: 'Registrar llegada' }))
    expect(await screen.findByText('La llegada se registra el día de la cita.')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Registrar llegada' }))
    expect(await screen.findByText('Llegada registrada: falta el consentimiento')).toBeInTheDocument()
    expect(screen.getByText(/Falta el consentimiento\./)).toBeInTheDocument()
  })

  it('shows the arrival to the receptionist and no actions to the dentist', async () => {
    mockApi((config) => {
      if (key(config) === 'GET /appointments/a-1') return { data: { data: appointment() } }
      if (config.url === '/patients/p-1') return { data: { data: PATIENT } }
      return {
        data: {
          data: appointment({ status: 'en_atencion', checked_in_at: '2026-11-02T14:00:00+00:00' }),
          attention: { id: 'at-1' },
          consent_warning: null,
        },
      }
    })
    const { unmount } = renderPage(<AppointmentDetailPage />, { ...route, user: makeUser('receptionist') })

    await userEvent.click(await screen.findByRole('button', { name: 'Registrar llegada' }))
    expect(await screen.findByText('Llegada registrada')).toBeInTheDocument()
    unmount()

    renderPage(<AppointmentDetailPage />, { ...route, user: makeUser('dentist') })
    expect(await screen.findByText('Dra. Ana Torres')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Registrar llegada' })).not.toBeInTheDocument()
  })
})

describe('AppointmentConfirmPage', () => {
  const route = { route: '/c/clinica-demo/confirmar/tok-1', path: '/c/:slug/confirmar/:token' }

  it('confirms only after pressing the button and shows the appointment', async () => {
    const calls = mockApi((config) => {
      if (config.url === '/public/clinics/clinica-demo')
        return { data: { data: { name: 'Clínica Demo', slug: 'clinica-demo' } } }
      return {
        data: {
          data: {
            status: 'confirmada',
            starts_at: '2026-11-02T15:00:00+00:00',
            ends_at: '2026-11-02T15:30:00+00:00',
            timezone: 'America/Lima',
            dentist: 'Dra. Ana Torres',
            type: 'Consulta',
            clinic: 'Clínica Demo',
          },
        },
      }
    })
    renderPage(<AppointmentConfirmPage />, route)

    expect(calls.some((call) => key(call) === 'POST /public/appointment-confirmations/tok-1')).toBe(false)
    await userEvent.click(await screen.findByRole('button', { name: 'Confirmar mi cita' }))
    expect(await screen.findByRole('heading', { name: 'Tu cita está confirmada' })).toBeInTheDocument()
    expect(screen.getByText('02/11/2026 a las 10:00')).toBeInTheDocument()
  })

  it('explains an invalid or used link', async () => {
    mockApi((config) =>
      config.url === '/public/clinics/clinica-demo'
        ? { data: { data: { name: 'Clínica Demo', slug: 'clinica-demo' } } }
        : { status: 404, data: { detail: 'El recurso solicitado no existe.' } },
    )
    renderPage(<AppointmentConfirmPage />, route)

    await userEvent.click(await screen.findByRole('button', { name: 'Confirmar mi cita' }))
    expect(await screen.findByRole('heading', { name: 'Este enlace ya no es válido' })).toBeInTheDocument()
  })
})
