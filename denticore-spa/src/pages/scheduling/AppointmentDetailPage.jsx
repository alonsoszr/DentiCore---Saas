import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { apiClient } from '../../api/client'
import { fieldErrors, generalError } from '../../api/errors'
import { useAuth } from '../../auth/useAuth'
import { useClinic } from '../../auth/useClinic'
import { Alert } from '../../components/Alert'
import { ConfirmDialog } from '../../components/ConfirmDialog'
import { Field } from '../../components/Field'
import { formatDateTime, formatTime, localDate } from '../../ui/format'
import { useTimeZone } from '../../ui/useTimeZone'
import { useFocusOnMount } from '../treatment/useTreatment'
import { CANCEL_REASONS, CONFIRMATION_CHANNELS, DESK_ROLES } from './labels'
import { SlotPicker } from './SlotPicker'
import { StatusBadge } from './StatusBadge'

const PENDING = ['programada', 'confirmada']

/** Cancelación con motivo y su confirmación (RF-149, RNF-063). */
function CancelDialog({ appointment, onDone, onCancel }) {
  const [reason, setReason] = useState('')
  const [detail, setDetail] = useState('')
  const cancel = useMutation({
    mutationFn: async () =>
      (
        await apiClient.post(`/appointments/${appointment.id}/cancel`, {
          cancel_reason: reason,
          cancel_detail: detail.trim() === '' ? null : detail.trim(),
        })
      ).data.data,
    onSuccess: onDone,
  })
  const errors = fieldErrors(cancel.error)

  return (
    <ConfirmDialog
      title="¿Cancelar la cita?"
      confirmLabel="Cancelar cita"
      cancelLabel="Volver"
      destructive
      confirmDisabled={reason === ''}
      onConfirm={() => cancel.mutate()}
      onCancel={onCancel}
      busy={cancel.isPending}
      error={generalError(cancel.error)}
    >
      <p>La franja queda libre. Si el paciente tiene avisos habilitados, recibirá un correo. No se puede deshacer.</p>
      <Field label="Motivo" name="cancel-reason" error={errors.cancel_reason}>
        <select
          id="cancel-reason"
          value={reason}
          onChange={(event) => setReason(event.target.value)}
          aria-invalid={errors.cancel_reason ? true : undefined}
          aria-describedby={errors.cancel_reason ? 'cancel-reason-error' : undefined}
        >
          <option value="">Selecciona el motivo</option>
          {Object.entries(CANCEL_REASONS).map(([value, label]) => (
            <option key={value} value={value}>
              {label}
            </option>
          ))}
        </select>
      </Field>
      <Field
        label="Detalle (opcional)"
        name="cancel-detail"
        value={detail}
        onChange={(event) => setDetail(event.target.value)}
        error={errors.cancel_detail}
        maxLength={300}
      />
    </ConfirmDialog>
  )
}

/**
 * Reprogramación (RF-149): otro horario libre del mismo odontólogo con la misma duración. Una cita
 * confirmada sigue confirmada y queda por reconfirmar con un nuevo enlace (SRS §5.5.4).
 */
function Reschedule({ appointment, onDone, onCancel }) {
  const timeZone = useTimeZone()
  const [date, setDate] = useState(() => localDate(appointment.starts_at, timeZone))
  const [slot, setSlot] = useState(null)
  const duration = Math.round((new Date(appointment.ends_at) - new Date(appointment.starts_at)) / 60_000)
  const reschedule = useMutation({
    mutationFn: async () =>
      (await apiClient.post(`/appointments/${appointment.id}/reschedule`, { starts_at: slot.startsAt })).data.data,
    onSuccess: onDone,
    onError: () => setSlot(null),
  })
  const sectionRef = useFocusOnMount()

  return (
    <section ref={sectionRef} className="card" aria-labelledby="reschedule-title">
      <h2 id="reschedule-title">Reprogramar</h2>
      {generalError(reschedule.error) && (
        <div className="alert alert-error" role="alert">
          {generalError(reschedule.error)}
        </div>
      )}
      <Field
        label="Nueva fecha"
        name="reschedule-date"
        type="date"
        value={date}
        onChange={(event) => {
          if (!event.target.value) return
          setDate(event.target.value)
          setSlot(null)
        }}
      />
      <SlotPicker
        params={{
          duration_min: duration,
          dentist_id: appointment.dentist.id,
          patient_id: appointment.patient.id,
          from: date,
          to: date,
        }}
        selected={slot}
        onSelect={setSlot}
      />
      <div className="form-actions">
        <button
          type="button"
          className="btn"
          disabled={!slot || reschedule.isPending}
          onClick={() => reschedule.mutate()}
        >
          {slot ? `Reprogramar al ${formatDateTime(slot.startsAt, timeZone)}` : 'Reprogramar'}
        </button>
        <button type="button" className="btn btn-secondary" onClick={onCancel}>
          Volver
        </button>
      </div>
    </section>
  )
}

/**
 * Detalle de la cita (/c/:slug/app/agenda/:uuid; CUS-48 a CUS-50, CUS-77): estado, confirmación y
 * acciones de la recepción y el administrador. El check-in abre la atención aunque falte el
 * consentimiento (RF-067); el administrador entra a ella con el aviso.
 */
export function AppointmentDetailPage() {
  const { uuid } = useParams()
  const { slug, appPath } = useClinic()
  const { user } = useAuth()
  const timeZone = useTimeZone()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const queryKey = ['appointment', slug, uuid]
  const [mode, setMode] = useState(null)
  const [checkedIn, setCheckedIn] = useState(null)

  const appointment = useQuery({
    queryKey,
    queryFn: async () => (await apiClient.get(`/appointments/${uuid}`)).data.data,
  })
  const refresh = (data) => {
    queryClient.setQueryData(queryKey, data)
    // La agenda en caché se descarta: al volver muestra «Cargando…», no los datos anteriores.
    queryClient.resetQueries({ queryKey: ['appointments'] })
    queryClient.resetQueries({ queryKey: ['waiting-room'] })
    setMode(null)
  }
  const confirm = useMutation({
    mutationFn: async () => (await apiClient.post(`/appointments/${uuid}/confirm`)).data.data,
    onSuccess: refresh,
  })
  const checkIn = useMutation({
    mutationFn: async () => (await apiClient.post(`/appointments/${uuid}/check-in`)).data,
    onSuccess: (response) => {
      refresh(response.data)
      // El administrador puede abrir la atención; la recepción la deja en la sala de espera (CUS-25).
      if (user?.role === 'clinic_admin') {
        navigate(appPath(`/atenciones/${response.attention.id}`), {
          state: { consentWarning: response.consent_warning },
        })
      } else {
        setCheckedIn(response)
      }
    },
  })

  if (appointment.isLoading) return <div className="card empty">Cargando…</div>
  if (appointment.isError) {
    return (
      <div className="alert alert-error" role="alert">
        {generalError(appointment.error)}
      </div>
    )
  }

  const data = appointment.data
  const isDesk = DESK_ROLES.includes(user?.role)
  const pending = PENDING.includes(data.status)
  const needsConfirmation = data.status === 'programada' || data.reconfirmation_pending
  const actionError = generalError(confirm.error) ?? generalError(checkIn.error)

  return (
    <>
      <div className="page-header">
        <Link to={appPath('/agenda')} className="btn btn-secondary">
          Volver a la agenda
        </Link>
      </div>

      <div className="card">
        <div className="page-header">
          <h2 className="m-0">
            {data.type.name} · {formatDateTime(data.starts_at, timeZone)} – {formatTime(data.ends_at, timeZone)}
          </h2>
          <StatusBadge appointment={data} />
        </div>
        <dl className="details">
          <div>
            <dt>Paciente</dt>
            <dd>
              <Link to={appPath(`/pacientes/${data.patient.id}`)}>{data.patient.name}</Link>
            </dd>
          </div>
          <div>
            <dt>Odontólogo</dt>
            <dd>{data.dentist.name}</dd>
          </div>
          <div>
            <dt>Confirmación</dt>
            <dd>
              {data.confirmed_at
                ? `Confirmada ${CONFIRMATION_CHANNELS[data.confirmation_channel] ?? ''} el ${formatDateTime(data.confirmed_at, timeZone)}`
                : data.reconfirmation_pending
                  ? 'Pendiente: la cita se reprogramó y falta reconfirmarla.'
                  : 'Pendiente'}
            </dd>
          </div>
          {data.checked_in_at && (
            <div>
              <dt>Llegada</dt>
              <dd>{formatDateTime(data.checked_in_at, timeZone)}</dd>
            </div>
          )}
          {data.status === 'cancelada' && (
            <div>
              <dt>Cancelación</dt>
              <dd>
                {CANCEL_REASONS[data.cancel_reason]}
                {data.cancel_detail ? ` · ${data.cancel_detail}` : ''}
              </dd>
            </div>
          )}
        </dl>

        {actionError && (
          <div className="alert alert-error" role="alert">
            {actionError}
          </div>
        )}
        {checkedIn && (
          <Alert
            tone={checkedIn.consent_warning ? 'warning' : 'success'}
            title={checkedIn.consent_warning ? 'Llegada registrada: falta el consentimiento' : 'Llegada registrada'}
            className="mb-4"
          >
            {checkedIn.consent_warning
              ? `Se abrió la atención. ${checkedIn.consent_warning.detail}`
              : 'Se abrió la atención y el paciente pasó a la sala de espera.'}
          </Alert>
        )}

        {/* Con el diálogo de cancelar abierto la barra sigue montada: al cerrarlo, el foco vuelve a su botón. */}
        {isDesk && pending && mode !== 'reschedule' && (
          <div className="form-actions">
            <button type="button" className="btn" onClick={() => checkIn.mutate()} disabled={checkIn.isPending}>
              Registrar llegada
            </button>
            {needsConfirmation && (
              <button
                type="button"
                className="btn btn-secondary"
                onClick={() => confirm.mutate()}
                disabled={confirm.isPending}
              >
                Confirmar por teléfono
              </button>
            )}
            <button type="button" className="btn btn-secondary" onClick={() => setMode('reschedule')}>
              Reprogramar
            </button>
            <button type="button" className="btn btn-secondary" onClick={() => setMode('cancel')}>
              Cancelar cita
            </button>
          </div>
        )}
      </div>

      {mode === 'reschedule' && <Reschedule appointment={data} onDone={refresh} onCancel={() => setMode(null)} />}
      {mode === 'cancel' && <CancelDialog appointment={data} onDone={refresh} onCancel={() => setMode(null)} />}
    </>
  )
}
