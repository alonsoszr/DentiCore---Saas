import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { NotificationBell } from '../../components/NotificationBell'
import { makeUser, mockApi, renderPage } from '../../test/utils'
import { AppointmentTypesPage } from './AppointmentTypesPage'
import { SchedulePage } from './SchedulePage'

afterEach(() => vi.restoreAllMocks())

const key = (config) => `${config.method.toUpperCase()} ${config.url}`
const body = (calls, wanted) => JSON.parse(calls.filter((call) => key(call) === wanted).at(-1).data)
const ROUTE = { route: '/c/clinica-demo/app/agenda/horarios', path: '/c/:slug/app/agenda/horarios' }
const DENTIST = { id: 'u-ana', name: 'Dra. Ana Torres', role: 'dentist', status: 'activo' }
const APPOINTMENT = {
  id: 'a-1',
  patient: { id: 'p-1', name: 'Lucía Sintética' },
  dentist: { id: 'u-ana', name: 'Dra. Ana Torres' },
  type: { id: 't-1', name: 'Control', color: '#000000' },
  starts_at: '2026-11-02T20:00:00+00:00',
  ends_at: '2026-11-02T20:30:00+00:00',
  status: 'programada',
  reconfirmation_pending: false,
}

describe('NotificationBell', () => {
  it('shows the unread count and opens a notification marking it as read', async () => {
    const calls = mockApi((config) =>
      key(config) === 'GET /notifications'
        ? {
            data: {
              data: [
                {
                  id: 'n-1',
                  event: 'presupuesto_aceptado',
                  params: { number: 'P-000003', total: '150.00' },
                  links: { budget: 'http://localhost:5173/c/clinica-demo/app/presupuestos/b-1' },
                  read_at: null,
                  created_at: '2026-11-02T15:00:00+00:00',
                },
              ],
              meta: { unread_count: 1 },
            },
          }
        : { status: 204, data: null },
    )
    renderPage(<NotificationBell />, {
      user: makeUser('receptionist'),
      route: '/c/clinica-demo/app',
      path: '/c/:slug/app',
    })

    await userEvent.click(await screen.findByRole('button', { name: 'Notificaciones: 1 sin leer' }))
    const panel = screen.getByRole('region', { name: 'Notificaciones' })
    expect(within(panel).getByText(/Se aceptó el presupuesto P-000003/)).toBeInTheDocument()

    await userEvent.click(within(panel).getByRole('button', { name: /Se aceptó el presupuesto/ }))
    expect(await screen.findByTestId('location')).toHaveTextContent('/c/clinica-demo/app/presupuestos/b-1')
    expect(calls.some((call) => key(call) === 'POST /notifications/n-1/read')).toBe(true)
  })

  it('marks all notifications as read', async () => {
    const calls = mockApi((config) =>
      key(config) === 'GET /notifications'
        ? { data: { data: [], meta: { unread_count: 3 } } }
        : { status: 204, data: null },
    )
    renderPage(<NotificationBell />, { user: makeUser('dentist'), route: '/c/clinica-demo/app' })

    await userEvent.click(await screen.findByRole('button', { name: 'Notificaciones: 3 sin leer' }))
    await userEvent.click(screen.getByRole('button', { name: 'Marcar todas como leídas' }))
    await waitFor(() => expect(calls.some((call) => key(call) === 'POST /notifications/read-all')).toBe(true))
  })
})

describe('NotificationBell focus', () => {
  it('closes the panel with Escape and returns the focus to the bell', async () => {
    mockApi(() => ({ data: { data: [], meta: { unread_count: 0 } } }))
    renderPage(<NotificationBell />, { user: makeUser('dentist'), route: '/c/clinica-demo/app', path: '/c/:slug/app' })

    const bell = await screen.findByRole('button', { name: 'Notificaciones' })
    await userEvent.click(bell)
    expect(await screen.findByText('No tienes notificaciones.')).toBeInTheDocument()
    await userEvent.keyboard('{Escape}')

    expect(screen.queryByRole('region', { name: 'Notificaciones' })).not.toBeInTheDocument()
    expect(bell).toHaveFocus()
  })
})

describe('AppointmentTypesPage', () => {
  it('creates a type and shows the duration error beside its field', async () => {
    const calls = mockApi((config) => {
      if (key(config) === 'GET /appointment-types') return { data: { data: [] } }
      return { status: 422, data: { errors: { default_duration_min: ['La duración debe ser múltiplo de 5.'] } } }
    })
    renderPage(<AppointmentTypesPage />, { user: makeUser('clinic_admin') })

    await userEvent.click(await screen.findByRole('button', { name: 'Nuevo tipo de cita' }))
    await userEvent.type(screen.getByLabelText('Nombre'), 'Control')
    await userEvent.clear(screen.getByLabelText('Duración (minutos)'))
    await userEvent.type(screen.getByLabelText('Duración (minutos)'), '12')
    await userEvent.click(screen.getByRole('button', { name: 'Crear tipo de cita' }))

    expect(await screen.findByText('La duración debe ser múltiplo de 5.')).toBeInTheDocument()
    expect(body(calls, 'POST /appointment-types')).toMatchObject({
      name: 'Control',
      default_duration_min: 12,
      is_active: true,
    })
  })
})

describe('SchedulePage', () => {
  it('confirms a new schedule and decides on the appointments it leaves out (RN-82)', async () => {
    let attempts = 0
    const calls = mockApi((config) => {
      if (key(config) === 'GET /users') return { data: { data: [DENTIST] } }
      if (key(config) === 'GET /dentists/u-ana/work-schedules') {
        return {
          data: {
            data: [
              {
                id: 's-1',
                weekday: 1,
                start_time: '09:00',
                end_time: '18:00',
                valid_from: '2026-01-01',
                valid_until: null,
              },
            ],
          },
        }
      }
      if (key(config) === 'GET /schedule-blocks') return { data: { data: [] } }
      if (key(config) === 'GET /appointments/a-1') return { data: { data: APPOINTMENT } }
      attempts += 1
      return attempts === 1
        ? {
            status: 422,
            data: {
              rule: 'RN-82',
              detail: 'Hay 1 cita activa afectada.',
              errors: { 'decisions.a-1': ['La cita necesita una decisión.'] },
            },
          }
        : { data: { data: [] } }
    })
    renderPage(<SchedulePage />, { ...ROUTE, user: makeUser('clinic_admin') })

    expect(await screen.findByText('09:00 – 18:00')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Cambiar horario' }))
    await userEvent.clear(screen.getByLabelText('Hasta'))
    await userEvent.type(screen.getByLabelText('Hasta'), '13:00')
    await userEvent.click(screen.getByRole('button', { name: 'Guardar horario' }))
    expect(calls.some((call) => key(call) === 'PUT /dentists/u-ana/work-schedules')).toBe(false)
    await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Guardar horario' }))

    const decisions = await screen.findByRole('dialog', { name: 'Citas fuera del horario nuevo' })
    const confirm = within(decisions).getByRole('button', { name: 'Aplicar horario y decisiones' })
    expect(confirm).toBeDisabled()
    await userEvent.click(within(decisions).getByRole('radio', { name: 'Cancelar' }))
    await userEvent.click(confirm)

    await waitFor(() => expect(attempts).toBe(2))
    expect(body(calls, 'PUT /dentists/u-ana/work-schedules')).toMatchObject({
      slots: [{ weekday: 1, start_time: '09:00', end_time: '13:00' }],
      decisions: [{ appointment_id: 'a-1', action: 'cancelar' }],
    })
  })

  it('shows the end time error beside its field', async () => {
    mockApi((config) => {
      if (key(config) === 'GET /users') return { data: { data: [DENTIST] } }
      if (key(config) === 'GET /dentists/u-ana/work-schedules') return { data: { data: [] } }
      if (key(config) === 'GET /schedule-blocks') return { data: { data: [] } }
      return {
        status: 422,
        data: { errors: { 'slots.0.end_time': ['La hora de fin debe ser posterior a la de inicio.'] } },
      }
    })
    renderPage(<SchedulePage />, { ...ROUTE, user: makeUser('clinic_admin') })

    await userEvent.click(await screen.findByRole('button', { name: 'Cambiar horario' }))
    await userEvent.click(screen.getByRole('button', { name: 'Agregar franja' }))
    await userEvent.click(screen.getByRole('button', { name: 'Guardar horario' }))
    await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Guardar horario' }))

    expect(await screen.findByText('La hora de fin debe ser posterior a la de inicio.')).toBeInTheDocument()
    expect(screen.getByLabelText('Hasta')).toHaveAttribute('aria-invalid', 'true')
    expect(screen.getByLabelText('Desde', { selector: 'input' })).not.toHaveAttribute('aria-invalid')
  })

  it('lets a dentist block their agenda and warns when the block affects appointments', async () => {
    const dentist = makeUser('dentist', { id: 'u-ana', name: 'Dra. Ana Torres' })
    const calls = mockApi((config) => {
      if (key(config) === 'GET /dentists/u-ana/work-schedules') return { data: { data: [] } }
      if (key(config) === 'GET /schedule-blocks') return { data: { data: [] } }
      if (key(config) === 'POST /schedule-blocks/impact') return { data: { data: [APPOINTMENT] } }
      return { status: 201, data: { data: {} } }
    })
    renderPage(<SchedulePage />, { ...ROUTE, user: dentist })

    expect(await screen.findByText('El odontólogo aún no tiene horario laboral.')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Cambiar horario' })).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Nuevo bloqueo' }))
    expect(screen.queryByText('Toda la clínica')).not.toBeInTheDocument()
    await userEvent.type(screen.getByLabelText('Motivo'), 'Trámite personal')
    await userEvent.click(screen.getByRole('button', { name: 'Crear bloqueo' }))

    expect(await screen.findByText('El bloqueo afecta citas activas')).toBeInTheDocument()
    expect(body(calls, 'POST /schedule-blocks/impact')).toMatchObject({ dentist_id: 'u-ana' })
    expect(calls.some((call) => key(call) === 'POST /schedule-blocks')).toBe(false)
  })

  it('creates a clinic-wide block without affected appointments directly', async () => {
    const calls = mockApi((config) => {
      if (key(config) === 'GET /users') return { data: { data: [DENTIST] } }
      if (key(config) === 'GET /dentists/u-ana/work-schedules') return { data: { data: [] } }
      if (key(config) === 'GET /schedule-blocks') return { data: { data: [] } }
      if (key(config) === 'POST /schedule-blocks/impact') return { data: { data: [] } }
      return { status: 201, data: { data: {} } }
    })
    renderPage(<SchedulePage />, { ...ROUTE, user: makeUser('clinic_admin') })

    await userEvent.click(await screen.findByRole('button', { name: 'Nuevo bloqueo' }))
    await userEvent.click(screen.getByRole('radio', { name: 'Toda la clínica' }))
    await userEvent.selectOptions(screen.getByLabelText('Tipo'), 'feriado')
    await userEvent.type(screen.getByLabelText('Motivo'), 'Feriado nacional')
    await userEvent.click(screen.getByRole('button', { name: 'Crear bloqueo' }))

    await waitFor(() => expect(calls.some((call) => key(call) === 'POST /schedule-blocks')).toBe(true))
    const created = body(calls, 'POST /schedule-blocks')
    expect(created).toMatchObject({ kind: 'feriado', reason: 'Feriado nacional' })
    expect(created.dentist_id).toBeUndefined()
  })
})
