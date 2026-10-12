import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { CLINIC, makeUser, mockApi, renderPage } from '../../test/utils'
import { AlertsPage } from './AlertsPage'

const dentist = makeUser('dentist', { tenant: { ...CLINIC, plan: { includes_risk: true } } })
const key = (config) => `${config.method.toUpperCase()} ${config.url}`

function alert(overrides = {}) {
  return {
    id: 'al-1',
    status: 'abierta',
    patient: { id: 'p-1', name: 'Lucía Sintética' },
    prediction: { id: 'r-1', level: 'alto', confidence: '0.7200', predicted_at: '2026-11-02T15:00:00+00:00' },
    action: null,
    treatment_plan_id: null,
    appointment_id: null,
    justification: null,
    acknowledged_at: null,
    created_at: '2026-11-02T15:00:00+00:00',
    ...overrides,
  }
}

describe('AlertsPage', () => {
  it('acknowledges an open alert with a justification of at least 20 characters', async () => {
    let acknowledged = false
    const calls = mockApi((config) => {
      if (key(config) === 'GET /risk-alerts')
        return {
          data: {
            data: [
              acknowledged
                ? alert({ status: 'reconocida', action: 'justificacion', acknowledged_at: '2026-11-02T16:00:00+00:00' })
                : alert(),
            ],
          },
        }
      if (key(config) === 'POST /risk-alerts/al-1/acknowledge') {
        acknowledged = true
        return { data: { data: alert({ status: 'reconocida' }) } }
      }
      return { status: 404 }
    })
    renderPage(<AlertsPage />, { user: dentist })

    expect(await screen.findByText('Riesgo alto')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Reconocer la alerta de Lucía Sintética' }))
    const dialog = screen.getByRole('dialog', { name: 'Reconocer la alerta de Lucía Sintética' })
    const confirm = within(dialog).getByRole('button', { name: 'Reconocer alerta' })
    expect(confirm).toBeDisabled()

    await userEvent.click(within(dialog).getByRole('radio', { name: 'Justificación' }))
    await userEvent.type(within(dialog).getByLabelText('Texto de la justificación'), 'Muy corta')
    expect(confirm).toBeDisabled()
    await userEvent.type(within(dialog).getByLabelText('Texto de la justificación'), ' y ya con más de veinte')
    await userEvent.click(confirm)

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(JSON.parse(calls.find((call) => key(call) === 'POST /risk-alerts/al-1/acknowledge').data)).toEqual({
      action: 'justificacion',
      justification: 'Muy corta y ya con más de veinte',
    })
    expect(await screen.findByText('Reconocida')).toBeInTheDocument()
    expect(screen.getByRole('status')).toHaveTextContent('Alerta de Lucía Sintética reconocida.')
    expect(screen.getByRole('region', { name: 'Bandeja de alertas' })).toHaveFocus()
  })

  it('offers the current plans of the patient for a preventive plan', async () => {
    const calls = mockApi((config) => {
      if (key(config) === 'GET /risk-alerts') return { data: { data: [alert()] } }
      if (key(config) === 'GET /patients/p-1/treatment-plans')
        return {
          data: {
            data: [
              { id: 'tp-1', title: 'Plan preventivo', status: 'borrador' },
              { id: 'tp-2', title: 'Plan cancelado', status: 'cancelado' },
            ],
          },
        }
      if (key(config) === 'POST /risk-alerts/al-1/acknowledge')
        return { data: { data: alert({ status: 'reconocida' }) } }
      return { status: 404 }
    })
    renderPage(<AlertsPage />, { user: dentist })

    await userEvent.click(await screen.findByRole('button', { name: 'Reconocer la alerta de Lucía Sintética' }))
    await userEvent.click(screen.getByRole('radio', { name: 'Plan preventivo' }))
    const select = await screen.findByLabelText('Plan del paciente')
    await waitFor(() => expect(within(select).getAllByRole('option')).toHaveLength(2))
    await userEvent.selectOptions(select, 'tp-1')
    await userEvent.click(screen.getByRole('button', { name: 'Reconocer alerta' }))

    await waitFor(() =>
      expect(JSON.parse(calls.find((call) => key(call) === 'POST /risk-alerts/al-1/acknowledge').data)).toEqual({
        action: 'plan_preventivo',
        treatment_plan_id: 'tp-1',
      }),
    )
  })

  it('links a control appointment and reports an alert already acknowledged', async () => {
    const calls = mockApi((config) => {
      if (key(config) === 'GET /risk-alerts') return { data: { data: [alert()] } }
      if (key(config) === 'GET /appointments')
        return {
          data: {
            data: [
              { id: 'ap-1', status: 'programada', starts_at: '2026-11-10T15:00:00+00:00', type: { name: 'Control' } },
              { id: 'ap-2', status: 'cancelada', starts_at: '2026-11-11T15:00:00+00:00', type: { name: 'Control' } },
            ],
          },
        }
      if (key(config) === 'POST /risk-alerts/al-1/acknowledge')
        return { status: 409, data: { rule: 'RN-65', detail: 'La alerta ya fue reconocida.' } }
      return { status: 404 }
    })
    renderPage(<AlertsPage />, { user: dentist })

    await userEvent.click(await screen.findByRole('button', { name: 'Reconocer la alerta de Lucía Sintética' }))
    await userEvent.click(screen.getByRole('radio', { name: 'Cita de control' }))
    const select = await screen.findByLabelText('Cita del paciente')
    await waitFor(() => expect(within(select).getAllByRole('option')).toHaveLength(2))
    await userEvent.selectOptions(select, 'ap-1')
    await userEvent.click(screen.getByRole('button', { name: 'Reconocer alerta' }))

    expect(await screen.findByText('La alerta ya fue reconocida.')).toBeInTheDocument()
    expect(calls.find((call) => key(call) === 'GET /appointments').params).toMatchObject({ patient_id: 'p-1' })
    expect(calls.filter((call) => key(call) === 'GET /risk-alerts').length).toBeGreaterThan(1)
  })

  it('announces an error loading the alerts', async () => {
    mockApi(() => ({ status: 500 }))
    renderPage(<AlertsPage />, { user: dentist })

    expect(await screen.findByRole('alert')).toHaveTextContent('Ocurrió un error inesperado')
  })

  it('shows an empty state without alerts', async () => {
    mockApi(() => ({ data: { data: [] } }))
    renderPage(<AlertsPage />, { user: dentist })

    expect(await screen.findByText('No tienes alertas de riesgo.')).toBeInTheDocument()
  })
})
