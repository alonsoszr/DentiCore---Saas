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
const TYPE = { id: 't-1', name: 'Control', duration_min: 30 }

function appointment(overrides = {}) {
  return {
    id: 'a-1',
    status: 'programada',
    starts_at: '2099-01-05T14:00:00Z',
    dentist: { id: 'u-ana', name: 'Dra. Ana Torres' },
    type: { id: 't-1', name: 'Control' },
    reconfirmation_pending: false,
    confirmed_at: null,
    confirmation_channel: null,
    ...overrides,
  }
}

const SLOTS = [
  { dentist: { id: 'u-ana', name: 'Dra. Ana Torres' }, duration_min: 30, slots: ['2099-01-06T15:00:00+00:00'] },
]

function api({ subjects = [HOLDER], appointments = [appointment()], writes = {}, consents = [], history = [] } = {}) {
  return mockApi((config) => {
    const key = `${config.method.toUpperCase()} ${config.url}`
    if (writes[key]) return writes[key](config.data ? JSON.parse(config.data) : {})
    if (key === 'GET /portal/subjects') return { data: { data: subjects } }
    if (key === 'GET /portal/patients/p-1/appointments') return { data: { data: appointments } }
    if (key === 'GET /portal/availability') {
      return { data: { data: config.params?.appointment_type_id ? SLOTS : [], types: [TYPE] } }
    }
    if (key === 'GET /portal/patients/p-1/consents') return { data: { data: consents } }
    if (key === 'GET /portal/patients/p-1/consents/preview') {
      return {
        data: {
          data: {
            template_version: 2,
            text: 'Texto sintético del consentimiento.',
            granted_by: 'titular',
            representative: null,
            available_purposes: ['purpose_care', 'purpose_notifications', 'purpose_surveys'],
          },
        },
      }
    }
    if (key === 'GET /portal/patients/p-1/teeth/36/history') return { data: { data: history } }
    return { status: 404, data: {} }
  })
}

const at = (path) => ({ route: `/c/clinica-demo/portal${path}`, path: '/c/:slug/portal/*', user })
const post = (calls, url) => calls.find((call) => call.method === 'post' && call.url === url)

describe('portal appointments', () => {
  it('shows the four tabs and lists the upcoming and past appointments', async () => {
    api({
      appointments: [appointment(), appointment({ id: 'a-0', status: 'atendida', starts_at: '2020-01-05T14:00:00Z' })],
    })
    renderPage(<PortalArea />, at('/citas'))

    const tabs = screen.getByRole('navigation', { name: 'Secciones del portal' })
    expect(
      within(tabs)
        .getAllByRole('link')
        .map((link) => link.textContent),
    ).toEqual(['Inicio', 'Citas', 'Presupuestos', 'Mi salud'])
    const upcoming = await screen.findByRole('region', { name: 'Próximas citas' })
    expect(within(upcoming).getByText('Control con Dra. Ana Torres')).toBeInTheDocument()
    expect(within(screen.getByRole('region', { name: 'Historial de citas' })).getByText('Atendida')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Reservar cita' })).toHaveAttribute(
      'href',
      '/c/clinica-demo/portal/citas/nueva',
    )
  })

  it('confirms an appointment from the portal (CUS-49)', async () => {
    const calls = api({
      writes: {
        'POST /portal/appointments/a-1/confirm': () => ({
          data: {
            data: appointment({
              status: 'confirmada',
              confirmed_at: '2026-10-16T15:00:00Z',
              confirmation_channel: 'portal',
            }),
          },
        }),
      },
    })
    renderPage(<PortalArea />, at('/citas/a-1'))

    await userEvent.click(await screen.findByRole('button', { name: 'Confirmar asistencia' }))

    expect(await screen.findByText('Cita confirmada.')).toBeInTheDocument()
    expect(post(calls, '/portal/appointments/a-1/confirm')).toBeDefined()
    expect(screen.queryByRole('button', { name: 'Confirmar asistencia' })).not.toBeInTheDocument()
  })

  it('cancels after confirming and shows the RN-49 limit', async () => {
    const calls = api({
      writes: {
        'POST /portal/appointments/a-1/cancel': () => ({
          status: 422,
          data: {
            rule: 'RN-49',
            detail:
              'Desde el portal puedes reprogramar o cancelar hasta 24 horas antes de la cita. Comunícate con la clínica.',
          },
        }),
      },
    })
    renderPage(<PortalArea />, at('/citas/a-1'))

    await userEvent.click(await screen.findByRole('button', { name: 'Cancelar cita' }))
    const dialog = screen.getByRole('dialog', { name: '¿Cancelar la cita?' })
    expect(within(dialog).getByRole('button', { name: 'Cancelar cita' })).toHaveClass('btn-danger')
    await userEvent.type(within(dialog).getByLabelText('Detalle (opcional)'), 'Viaje')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Cancelar cita' }))

    expect(await within(dialog).findByText(/hasta 24 horas antes/)).toBeInTheDocument()
    expect(JSON.parse(post(calls, '/portal/appointments/a-1/cancel').data)).toEqual({ cancel_detail: 'Viaje' })
    await userEvent.click(within(dialog).getByRole('button', { name: 'Volver' }))
    expect(screen.getByRole('button', { name: 'Cancelar cita' })).toHaveFocus()
  })

  it('reschedules with the same dentist and type from the portal availability', async () => {
    const calls = api({
      writes: {
        'POST /portal/appointments/a-1/reschedule': () => ({
          data: { data: appointment({ starts_at: '2099-01-06T15:00:00Z' }) },
        }),
      },
    })
    renderPage(<PortalArea />, at('/citas/a-1'))

    await userEvent.click(await screen.findByRole('button', { name: 'Reprogramar' }))
    expect(screen.getByLabelText('Fecha')).toHaveFocus()
    await userEvent.click(await screen.findByRole('button', { name: '10:00' }))
    await userEvent.click(screen.getByRole('button', { name: /^Reprogramar al/ }))

    expect(await screen.findByText('Cita reprogramada.')).toBeInTheDocument()
    const availability = calls.find((call) => call.url === '/portal/availability' && call.params?.appointment_type_id)
    expect(availability.params).toMatchObject({ appointment_type_id: 't-1', dentist_id: 'u-ana', patient_id: 'p-1' })
    expect(JSON.parse(post(calls, '/portal/appointments/a-1/reschedule').data)).toEqual({
      starts_at: '2099-01-06T15:00:00+00:00',
    })
  })

  it('books a portal appointment type and opens the new appointment (CUS-47)', async () => {
    const booked = appointment({ id: 'a-9', starts_at: '2099-01-06T15:00:00Z' })
    const list = [appointment()]
    const calls = api({
      appointments: list,
      writes: {
        'POST /portal/patients/p-1/appointments': () => {
          list.push(booked)
          return { status: 201, data: { data: booked } }
        },
      },
    })
    renderPage(<PortalArea />, at('/citas/nueva'))

    await userEvent.selectOptions(await screen.findByLabelText('Tipo de cita'), 't-1')
    await userEvent.click(await screen.findByRole('button', { name: '10:00' }))
    await userEvent.click(screen.getByRole('button', { name: /^Reservar con Dra. Ana Torres/ }))

    // Abre la cita nueva con el aviso, que recibe el foco cuando la cita termina de cargar.
    expect(await screen.findByRole('heading', { level: 1, name: 'Cita del 06/01/2099 10:00' })).toBeInTheDocument()
    expect(screen.getByText('Cita reservada.').closest('[tabindex="-1"]')).toHaveFocus()
    expect(JSON.parse(post(calls, '/portal/patients/p-1/appointments').data)).toEqual({
      appointment_type_id: 't-1',
      dentist_id: 'u-ana',
      starts_at: '2099-01-06T15:00:00+00:00',
    })
  })
})

describe('portal health', () => {
  it('shows the consent status and the history of a chosen tooth without corrections', async () => {
    api({
      consents: [{ id: 'c-1', status: 'vigente', outdated: false }],
      history: [
        {
          id: 'e-1',
          entry_type: 'evolucion',
          tooth: 36,
          surfaces: ['O'],
          finding: { code: 'CARIES', name: 'Lesión de caries dental', acronym: null },
          state: { code: 'CD', name: 'Lesión de caries dental a nivel de la dentina', acronym: 'CD' },
          color: 'rojo',
          origin: 'manual',
          author: { id: 'u-1', name: 'Dra. Ana Torres', cop: '12345' },
          recorded_at: '2026-10-06T15:10:00Z',
        },
      ],
    })
    renderPage(<PortalArea />, at('/mi-historia'))

    expect(await screen.findByText('Consentimiento vigente')).toBeInTheDocument()
    await userEvent.selectOptions(screen.getByLabelText('Pieza'), '36')

    expect(await screen.findByRole('heading', { name: 'Historial de la pieza 36' })).toBeInTheDocument()
    expect(screen.getByText(/Dra. Ana Torres/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Corregir/ })).not.toBeInTheDocument()
  })

  it('grants the consent only after ticking «He leído y acepto» (CUS-17 FA-1)', async () => {
    const calls = api({
      writes: {
        'POST /portal/patients/p-1/consents': () => ({ status: 201, data: { data: { id: 'c-2', channel: 'portal' } } }),
      },
    })
    renderPage(<PortalArea />, at('/consentimiento'))

    await userEvent.click(await screen.findByRole('checkbox', { name: /\(a\) Atención odontológica/ }))
    await userEvent.click(screen.getByRole('button', { name: 'Otorgar consentimiento' }))
    expect(await screen.findByText('Marca «He leído y acepto» para otorgar el consentimiento.')).toBeInTheDocument()
    expect(post(calls, '/portal/patients/p-1/consents')).toBeUndefined()

    await userEvent.click(screen.getByRole('checkbox', { name: 'He leído y acepto' }))
    await userEvent.click(screen.getByRole('button', { name: 'Otorgar consentimiento' }))

    expect(await screen.findByText('Consentimiento registrado')).toBeInTheDocument()
    expect(JSON.parse(post(calls, '/portal/patients/p-1/consents').data)).toMatchObject({
      purpose_care: true,
      purpose_notifications: false,
    })
  })

  it('leaves the consent of a minor holder to the legal representative (RN-12)', async () => {
    api({ subjects: [MINOR_HOLDER] })
    renderPage(<PortalArea />, at('/consentimiento'))

    expect(await screen.findByText('La decisión corresponde al representante legal.')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Otorgar consentimiento' })).not.toBeInTheDocument()
  })
})
