import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, expect, it, vi } from 'vitest'
import { makeUser, mockApi, renderPage } from '../../test/utils'
import { ConsentRevocation } from './ConsentRevocation'
import { ClinicalRecordCopy } from './ClinicalRecordCopy'
import { ArcoRequests } from './ArcoRequests'
import { ArcoInboxPage } from './ArcoInboxPage'
import { PatientEditPage } from '../patients/PatientEditPage'

afterEach(() => vi.restoreAllMocks())

const ROUTE = { route: '/c/clinica-demo/app/cumplimiento/arco', path: '/c/:slug/app/*' }
const PATIENT = { id: 'p-1', first_name: 'Ana', last_name: 'Núñez', archive_status: 'activo' }
const CONSENT = { id: 'c-1', status: 'vigente', purpose_care: true, purpose_notifications: true, revoked_purposes: [] }
const REQUEST = {
  id: 'a-1',
  type: 'cancelacion',
  status: 'recibida',
  patient: { id: 'p-1', name: 'Ana Núñez' },
  description: 'Solicito cancelar mis datos opcionales.',
  requested_by: 'titular',
  received_at: '2026-10-20T15:00:00Z',
  due_date: '2026-11-03',
  remaining_business_days: 10,
}

it('confirms revocation and sends the selected API purpose only after confirming (CUS-18, RNF-063)', async () => {
  const calls = mockApi((config) =>
    config.method === 'post' ? { data: { data: { ...CONSENT, status: 'revocado' } } } : { data: { data: [CONSENT] } },
  )
  const user = userEvent.setup()
  renderPage(<ConsentRevocation patientId="p-1" />, { ...ROUTE, user: makeUser('receptionist') })
  await user.click(await screen.findByLabelText('Revocar (a) Atención odontológica'))
  await user.click(screen.getByRole('button', { name: 'Revocar finalidades' }))
  const dialog = screen.getByRole('dialog')
  expect(dialog).toHaveTextContent('no se podrán registrar nuevos datos clínicos')
  expect(calls.filter((call) => call.method === 'post')).toHaveLength(0)
  await user.click(within(dialog).getByRole('button', { name: 'Confirmar revocación' }))
  await screen.findByText('Finalidades revocadas.')
  expect(JSON.parse(calls.find((call) => call.method === 'post').data)).toEqual({
    purposes: ['atencion'],
    reason: null,
  })
})

it('cancels a revocation without effects and excludes already revoked purposes', async () => {
  const calls = mockApi(() => ({ data: { data: [{ ...CONSENT, revoked_purposes: ['notificaciones'] }] } }))
  const user = userEvent.setup()
  renderPage(<ConsentRevocation patientId="p-1" />, { ...ROUTE, user: makeUser('clinic_admin') })
  await user.click(await screen.findByLabelText('Revocar (a) Atención odontológica'))
  expect(screen.queryByLabelText('Revocar (b) Notificaciones')).not.toBeInTheDocument()
  await user.click(screen.getByRole('button', { name: 'Revocar finalidades' }))
  await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Cancelar' }))
  expect(calls.some((call) => call.method === 'post')).toBe(false)
})

it.each([false, true])(
  'requests and reads a clinical record copy through the correct staff/portal API (%s)',
  async (portal) => {
    const calls = mockApi((config) => ({
      data: {
        data:
          config.method === 'post'
            ? { id: 'doc-1', status: 'pendiente', url: null }
            : { id: 'doc-1', status: 'listo', url: 'https://archivos.test/copia-firmada' },
      },
    }))
    const user = userEvent.setup()
    renderPage(<ClinicalRecordCopy patientId="p-1" portal={portal} />, {
      ...ROUTE,
      user: makeUser(portal ? 'patient' : 'dentist'),
    })
    await user.click(screen.getByRole('button', { name: 'Solicitar copia de HC' }))
    expect(await screen.findByRole('link', { name: 'Descargar copia' })).toHaveAttribute(
      'href',
      'https://archivos.test/copia-firmada',
    )
    expect(calls.find((call) => call.method === 'post').url).toBe(
      `${portal ? '/portal' : ''}/patients/p-1/clinical-record-copies`,
    )
    expect(calls.some((call) => call.url === `${portal ? '/portal' : ''}/documents/doc-1`)).toBe(true)
  },
)

it('shows a pending document without a fake download link', async () => {
  mockApi(() => ({ data: { data: { id: 'doc-1', status: 'pendiente', url: null } } }))
  const user = userEvent.setup()
  renderPage(<ClinicalRecordCopy patientId="p-1" />, { ...ROUTE, user: makeUser('dentist') })
  await user.click(screen.getByRole('button', { name: 'Solicitar copia de HC' }))
  expect(await screen.findByText('En preparación.')).toBeInTheDocument()
  expect(screen.queryByRole('link', { name: 'Descargar copia' })).not.toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'Actualizar estado' })).toBeEnabled()
})

it('registers a staff ARCO request with identity confirmation and displays its deadline (CUS-63)', async () => {
  const calls = mockApi((config) => (config.method === 'post' ? { data: { data: REQUEST } } : { data: { data: [] } }))
  const user = userEvent.setup()
  renderPage(<ArcoRequests patientId="p-1" />, { ...ROUTE, user: makeUser('receptionist') })
  await user.selectOptions(screen.getByLabelText('Tipo de solicitud'), 'cancelacion')
  await user.type(screen.getByLabelText('Descripción'), REQUEST.description)
  await user.type(screen.getByLabelText('Documento de confirmación'), '40000001')
  await user.click(screen.getByRole('button', { name: 'Registrar solicitud' }))
  expect(await screen.findByText(/Solicitud registrada/)).toHaveTextContent('03/11/2026')
  expect(JSON.parse(calls.find((call) => call.method === 'post').data)).toMatchObject({
    type: 'cancelacion',
    requested_by: 'titular',
    confirmation_document_number: '40000001',
  })
})

it('uses only the session subject and type/description when registering from the portal', async () => {
  const calls = mockApi((config) => (config.method === 'post' ? { data: { data: REQUEST } } : { data: { data: [] } }))
  const user = userEvent.setup()
  renderPage(<ArcoRequests patientId="p-1" portal />, { ...ROUTE, user: makeUser('patient') })
  expect(screen.queryByLabelText('Documento de confirmación')).not.toBeInTheDocument()
  await user.type(screen.getByLabelText('Descripción'), REQUEST.description)
  await user.click(screen.getByRole('button', { name: 'Registrar solicitud' }))
  await screen.findByText(/Solicitud registrada/)
  const call = calls.find((item) => item.method === 'post')
  expect(call.url).toBe('/portal/patients/p-1/arco-requests')
  expect(JSON.parse(call.data)).toEqual({ type: 'acceso', description: REQUEST.description })
})

it('preserves a registration form and shows 422 errors beside the field', async () => {
  mockApi((config) =>
    config.method === 'post'
      ? { status: 422, data: { detail: 'Revise la solicitud.', errors: { description: ['Detalle insuficiente.'] } } }
      : { data: { data: [] } },
  )
  const user = userEvent.setup()
  renderPage(<ArcoRequests patientId="p-1" portal />, { ...ROUTE, user: makeUser('patient') })
  await user.type(screen.getByLabelText('Descripción'), 'Texto de solicitud')
  await user.click(screen.getByRole('button', { name: 'Registrar solicitud' }))
  expect(await screen.findByText('Detalle insuficiente.')).toBeInTheDocument()
  expect(screen.getByLabelText('Descripción')).toHaveValue('Texto de solicitud')
})

it('does not request the inbox for an administrator who is not data officer (CUS-64)', () => {
  const calls = mockApi(() => ({ data: { data: [] } }))
  renderPage(<ArcoInboxPage />, { ...ROUTE, user: makeUser('clinic_admin', { is_data_officer: false }) })
  expect(screen.queryByRole('heading', { name: 'Solicitudes ARCO' })).not.toBeInTheDocument()
  expect(calls).toHaveLength(0)
})

it('starts a request, confirms the block and resolves it only after explicit confirmation (CUS-64)', async () => {
  let request = REQUEST
  let blocked = false
  const calls = mockApi((config) => {
    if (config.url === '/patients/p-1')
      return { data: { data: { ...PATIENT, archive_status: blocked ? 'bloqueado' : 'activo' } } }
    if (config.method === 'post') {
      if (config.url.endsWith('/start')) request = { ...request, status: 'en_tramite' }
      if (config.url.endsWith('/cancellation')) blocked = true
      if (config.url.endsWith('/resolve'))
        request = { ...request, status: 'atendida', response: JSON.parse(config.data).response }
      return { data: { data: request } }
    }
    return { data: { data: config.url === '/arco-requests' ? [request] : request } }
  })
  const user = userEvent.setup()
  renderPage(<ArcoInboxPage />, { ...ROUTE, user: makeUser('clinic_admin', { is_data_officer: true }) })
  await user.click(await screen.findByRole('button', { name: 'Ver solicitud' }))
  await user.click(await screen.findByRole('button', { name: 'Iniciar atención' }))
  await user.click(await screen.findByRole('button', { name: 'Aplicar cancelación ARCO' }))
  expect(screen.getByRole('dialog')).toHaveTextContent('La historia clínica se conserva')
  expect(blocked).toBe(false)
  await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Bloquear paciente' }))
  await screen.findByText('Paciente bloqueado. La historia clínica se conserva.')
  await user.type(
    screen.getByLabelText('Respuesta al solicitante'),
    'Se atendió su solicitud conforme a la retención legal.',
  )
  await user.click(screen.getByRole('button', { name: 'Resolver solicitud' }))
  expect(calls.some((call) => call.url.endsWith('/resolve'))).toBe(false)
  await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Confirmar resolución' }))
  await waitFor(() =>
    expect(calls.filter((call) => call.method === 'post').map((call) => call.url)).toEqual([
      '/arco-requests/a-1/start',
      '/arco-requests/a-1/cancellation',
      '/arco-requests/a-1/resolve',
    ]),
  )
})

it('associates purpose errors with the selected revocation checkbox, focuses it and preserves data', async () => {
  mockApi((config) =>
    config.method === 'post'
      ? { status: 422, data: { errors: { purposes: ['Revisa las finalidades seleccionadas.'] } } }
      : { data: { data: [CONSENT] } },
  )
  const user = userEvent.setup()
  renderPage(<ConsentRevocation patientId="p-1" />, { ...ROUTE, user: makeUser('clinic_admin') })
  const checkbox = await screen.findByLabelText('Revocar (a) Atención odontológica')
  await user.click(checkbox)
  await user.type(screen.getByLabelText('Motivo (opcional)'), 'Conservar este motivo')
  await user.click(screen.getByRole('button', { name: 'Revocar finalidades' }))
  await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Confirmar revocación' }))
  await screen.findByText('Revisa las finalidades seleccionadas.')
  expect(checkbox).toHaveAttribute('aria-invalid', 'true')
  expect(checkbox).toHaveAccessibleDescription('Revisa las finalidades seleccionadas.')
  await waitFor(() => expect(checkbox).toHaveFocus())
  expect(checkbox).toBeChecked()
  expect(screen.getByLabelText('Motivo (opcional)')).toHaveValue('Conservar este motivo')
})

it('associates an opposition purpose error and preserves response and selection after 422', async () => {
  const request = { ...REQUEST, type: 'oposicion', status: 'en_tramite' }
  mockApi((config) =>
    config.method === 'post'
      ? { status: 422, data: { errors: { purposes: ['Selecciona finalidades opcionales vigentes.'] } } }
      : { data: { data: config.url === '/arco-requests' ? [request] : request } },
  )
  const user = userEvent.setup()
  renderPage(<ArcoInboxPage />, { ...ROUTE, user: makeUser('clinic_admin', { is_data_officer: true }) })
  await user.click(await screen.findByRole('button', { name: 'Ver solicitud' }))
  const checkbox = await screen.findByLabelText('(b) Notificaciones')
  await user.click(checkbox)
  await user.type(screen.getByLabelText('Respuesta al solicitante'), 'Respuesta que debe conservarse.')
  await user.click(screen.getByRole('button', { name: 'Resolver solicitud' }))
  await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Confirmar resolución' }))
  await screen.findByText('Selecciona finalidades opcionales vigentes.')
  expect(checkbox).toHaveAccessibleDescription('Selecciona finalidades opcionales vigentes.')
  await waitFor(() => expect(checkbox).toHaveFocus())
  expect(checkbox).toBeChecked()
  expect(screen.getByLabelText('Respuesta al solicitante')).toHaveValue('Respuesta que debe conservarse.')
})

it('retries a failed history without discarding the request form', async () => {
  let reads = 0
  mockApi(() => (++reads === 1 ? { status: 500 } : { data: { data: [] } }))
  const user = userEvent.setup()
  renderPage(<ArcoRequests patientId="p-1" portal />, { ...ROUTE, user: makeUser('patient') })
  await user.type(screen.getByLabelText('Descripción'), 'Descripción que se debe conservar.')
  await user.click(await screen.findByRole('button', { name: 'Reintentar historial' }))
  await screen.findByText('No hay solicitudes registradas.')
  expect(screen.getByLabelText('Descripción')).toHaveValue('Descripción que se debe conservar.')
})

it('retries representatives without clearing the selected request type or description', async () => {
  let reads = 0
  mockApi((config) =>
    config.url.endsWith('/representatives')
      ? ++reads === 1
        ? { status: 500 }
        : { data: { data: [{ id: 'r-1', first_name: 'Rosa', last_name: 'Prueba', is_current: true }] } }
      : { data: { data: [] } },
  )
  const user = userEvent.setup()
  renderPage(<ArcoRequests patientId="p-1" />, { ...ROUTE, user: makeUser('receptionist') })
  await user.selectOptions(screen.getByLabelText('Tipo de solicitud'), 'rectificacion')
  await user.selectOptions(screen.getByLabelText('Solicitante'), 'representante')
  await user.type(screen.getByLabelText('Descripción'), 'Conservar el detalle del trámite.')
  await user.click(await screen.findByRole('button', { name: 'Reintentar representantes' }))
  expect(await screen.findByRole('option', { name: 'Rosa Prueba' })).toBeInTheDocument()
  expect(screen.getByLabelText('Tipo de solicitud')).toHaveValue('rectificacion')
  expect(screen.getByLabelText('Descripción')).toHaveValue('Conservar el detalle del trámite.')
})

it.each(['solicitud', 'paciente'])('retries a failed %s read from the selected request', async (target) => {
  let failed = false
  const request = { ...REQUEST, status: 'en_tramite' }
  mockApi((config) => {
    const path = target === 'solicitud' ? '/arco-requests/a-1' : '/patients/p-1'
    if (config.url === path && !failed) {
      failed = true
      return { status: 500 }
    }
    return {
      data: { data: config.url === '/arco-requests' ? [request] : config.url === '/patients/p-1' ? PATIENT : request },
    }
  })
  const user = userEvent.setup()
  renderPage(<ArcoInboxPage />, { ...ROUTE, user: makeUser('clinic_admin', { is_data_officer: true }) })
  await user.click(await screen.findByRole('button', { name: 'Ver solicitud' }))
  await user.click(await screen.findByRole('button', { name: `Reintentar ${target}` }))
  expect(await screen.findByRole('button', { name: 'Aplicar cancelación ARCO' })).toBeEnabled()
})

it('preserves the officer response when a 409 requires refreshing the request', async () => {
  const request = { ...REQUEST, type: 'acceso', status: 'en_tramite' }
  mockApi((config) =>
    config.method === 'post'
      ? { status: 409, data: { detail: 'Actualiza la solicitud antes de resolver.' } }
      : { data: { data: config.url === '/arco-requests' ? [request] : request } },
  )
  const user = userEvent.setup()
  renderPage(<ArcoInboxPage />, { ...ROUTE, user: makeUser('clinic_admin', { is_data_officer: true }) })
  await user.click(await screen.findByRole('button', { name: 'Ver solicitud' }))
  await user.type(await screen.findByLabelText('Respuesta al solicitante'), 'Esta respuesta se debe conservar.')
  await user.click(screen.getByRole('button', { name: 'Resolver solicitud' }))
  await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Confirmar resolución' }))
  await screen.findByText('Actualiza la solicitud antes de resolver.')
  expect(screen.getByLabelText('Respuesta al solicitante')).toHaveValue('Esta respuesta se debe conservar.')
})

it('links an identity correction to the ARCO request without changing ordinary patient updates', async () => {
  const calls = mockApi(() => ({ data: { data: PATIENT } }))
  const user = userEvent.setup()
  renderPage(<PatientEditPage patient={PATIENT} arcoRequestId="a-1" />, {
    ...ROUTE,
    user: makeUser('clinic_admin', { is_data_officer: true }),
  })
  await user.clear(screen.getByLabelText('Nombres'))
  await user.type(screen.getByLabelText('Nombres'), 'Ana María')
  await user.click(screen.getByRole('button', { name: 'Guardar cambios' }))
  await screen.findByText('Datos actualizados.')
  expect(JSON.parse(calls.find((call) => call.method === 'patch').data)).toEqual({
    first_name: 'Ana María',
    arco_request_id: 'a-1',
  })
})
