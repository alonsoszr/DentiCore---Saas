import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { apiClient } from '../../api/client'
import { fieldErrors, generalError } from '../../api/errors'
import { useClinic } from '../../auth/useClinic'
import { ConfirmDialog } from '../../components/ConfirmDialog'
import { Field } from '../../components/Field'
import { PageHeader } from '../../components/PageHeader'
import { addDays, formatDate, formatDateTime, localDate } from '../../ui/format'
import { useTimeZone } from '../../ui/useTimeZone'
import { RiskLevelBadge } from './RiskLevelBadge'

const ACTIONS = {
  plan_preventivo: 'Plan preventivo',
  cita_control: 'Cita de control',
  justificacion: 'Justificación',
}

const OPEN_PLAN = ['borrador', 'propuesto', 'aceptado', 'en_ejecucion']

/**
 * Diálogo de reconocimiento (CUS-58; RN-65, RF-171): la acción es obligatoria. El plan o la cita
 * se eligen entre los vigentes del paciente; la justificación tiene 20 caracteres o más.
 */
function AcknowledgeDialog({ alert, onClose }) {
  const { slug } = useClinic()
  const timeZone = useTimeZone()
  const queryClient = useQueryClient()
  const [action, setAction] = useState('')
  const [planId, setPlanId] = useState('')
  const [appointmentId, setAppointmentId] = useState('')
  const [justification, setJustification] = useState('')
  const patientId = alert.patient.id
  const today = localDate(new Date(), timeZone)

  const plans = useQuery({
    queryKey: ['treatment-plans', slug, patientId],
    queryFn: async () => (await apiClient.get(`/patients/${patientId}/treatment-plans`)).data.data,
    enabled: action === 'plan_preventivo',
  })
  const appointments = useQuery({
    queryKey: ['appointments', slug, 'patient', patientId, today],
    queryFn: async () =>
      (
        await apiClient.get('/appointments', {
          params: { patient_id: patientId, from: today, to: addDays(today, 30) },
        })
      ).data.data,
    enabled: action === 'cita_control',
  })

  const acknowledge = useMutation({
    mutationFn: async () =>
      (
        await apiClient.post(`/risk-alerts/${alert.id}/acknowledge`, {
          action,
          ...(action === 'plan_preventivo' ? { treatment_plan_id: planId } : {}),
          ...(action === 'cita_control' ? { appointment_id: appointmentId } : {}),
          ...(action === 'justificacion' ? { justification: justification.trim() } : {}),
        })
      ).data.data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['risk-alerts', slug] })
      onClose()
    },
  })

  const errors = fieldErrors(acknowledge.error)
  const openPlans = (plans.data ?? []).filter((plan) => OPEN_PLAN.includes(plan.status))
  const activeAppointments = (appointments.data ?? []).filter((appointment) =>
    ['programada', 'confirmada', 'en_atencion'].includes(appointment.status),
  )
  const ready =
    (action === 'plan_preventivo' && planId) ||
    (action === 'cita_control' && appointmentId) ||
    (action === 'justificacion' && justification.trim().length >= 20)

  return (
    <ConfirmDialog
      title={`Reconocer la alerta de ${alert.patient.name}`}
      confirmLabel="Reconocer alerta"
      onConfirm={() => acknowledge.mutate()}
      onCancel={onClose}
      busy={acknowledge.isPending}
      error={generalError(acknowledge.error)}
      confirmDisabled={!ready}
    >
      <p>Registre la acción que tomó frente al riesgo alto. Queda en la bitácora y no se puede deshacer.</p>
      <fieldset className="m-0 mb-4 border-0 p-0">
        <legend className="text-label-md mb-2">Acción</legend>
        {Object.entries(ACTIONS).map(([value, label]) => (
          <label key={value} className="flex items-center gap-2 py-1">
            <input
              type="radio"
              name="alert-action"
              value={value}
              checked={action === value}
              onChange={() => setAction(value)}
            />
            {label}
          </label>
        ))}
        {errors.action && (
          <span className="error" role="alert">
            {errors.action}
          </span>
        )}
      </fieldset>

      {action === 'plan_preventivo' && (
        <Field
          label="Plan del paciente"
          name="alert-plan"
          error={errors.treatment_plan_id}
          hint={plans.isSuccess && openPlans.length === 0 ? 'El paciente no tiene planes vigentes.' : undefined}
        >
          <select id="alert-plan" value={planId} onChange={(event) => setPlanId(event.target.value)}>
            <option value="">Seleccione…</option>
            {openPlans.map((plan) => (
              <option key={plan.id} value={plan.id}>
                {plan.title}
              </option>
            ))}
          </select>
        </Field>
      )}

      {action === 'cita_control' && (
        <Field
          label="Cita de control"
          name="alert-appointment"
          error={errors.appointment_id}
          hint={
            appointments.isSuccess && activeAppointments.length === 0
              ? 'El paciente no tiene citas en los próximos 30 días.'
              : undefined
          }
        >
          <select
            id="alert-appointment"
            value={appointmentId}
            onChange={(event) => setAppointmentId(event.target.value)}
          >
            <option value="">Seleccione…</option>
            {activeAppointments.map((appointment) => (
              <option key={appointment.id} value={appointment.id}>
                {formatDateTime(appointment.starts_at, timeZone)} · {appointment.type.name}
              </option>
            ))}
          </select>
        </Field>
      )}

      {action === 'justificacion' && (
        <Field
          label="Texto de la justificación"
          name="alert-justification"
          error={errors.justification}
          hint="Al menos 20 caracteres."
        >
          <textarea
            id="alert-justification"
            rows={4}
            maxLength={1000}
            value={justification}
            onChange={(event) => setJustification(event.target.value)}
          />
        </Field>
      )}
    </ConfirmDialog>
  )
}

/**
 * Bandeja de alertas de riesgo alto del odontólogo (CUS-58; SDD §3.4: /app/alertas; RN-65).
 * Primero las abiertas; cada una se reconoce con una acción. El orden y conteo de RF-172 llegan
 * en MS-15.
 */
export function AlertsPage() {
  const { slug, appPath } = useClinic()
  const [selected, setSelected] = useState(null)
  const alerts = useQuery({
    queryKey: ['risk-alerts', slug],
    queryFn: async () => (await apiClient.get('/risk-alerts')).data.data,
  })

  return (
    <>
      <PageHeader title="Alertas de riesgo" />
      <section className="card" aria-labelledby="alerts-title">
        <h2 id="alerts-title">Alertas de riesgo alto</h2>
        {alerts.isLoading && (
          <div className="empty" role="status">
            Cargando…
          </div>
        )}
        {alerts.isError && <div className="alert alert-error">{generalError(alerts.error)}</div>}
        {alerts.isSuccess && alerts.data.length === 0 && <div className="empty">No tiene alertas de riesgo.</div>}
        {alerts.isSuccess && alerts.data.length > 0 && (
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th scope="col">Paciente</th>
                  <th scope="col">Nivel</th>
                  <th scope="col">Calculada</th>
                  <th scope="col">Estado</th>
                  <th scope="col">Acción</th>
                </tr>
              </thead>
              <tbody>
                {alerts.data.map((alert) => (
                  <tr key={alert.id}>
                    <td>
                      <Link to={appPath(`/pacientes/${alert.patient.id}/riesgo`)}>{alert.patient.name}</Link>
                    </td>
                    <td>
                      <RiskLevelBadge level={alert.prediction.level} />
                    </td>
                    <td className="tabular-nums">{formatDate(alert.prediction.predicted_at)}</td>
                    <td>
                      <span className={alert.status === 'abierta' ? 'badge badge-warning' : 'badge badge-muted'}>
                        {alert.status === 'abierta' ? 'Abierta' : 'Reconocida'}
                      </span>
                    </td>
                    <td>
                      {alert.status === 'abierta' ? (
                        <button
                          type="button"
                          className="btn btn-secondary"
                          onClick={() => setSelected(alert)}
                          aria-label={`Reconocer la alerta de ${alert.patient.name}`}
                        >
                          Reconocer
                        </button>
                      ) : (
                        `${ACTIONS[alert.action] ?? alert.action} · ${formatDate(alert.acknowledged_at)}`
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
      {selected && <AcknowledgeDialog alert={selected} onClose={() => setSelected(null)} />}
    </>
  )
}
