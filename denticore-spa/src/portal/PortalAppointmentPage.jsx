import { useEffect, useRef, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Link, useLocation, useParams } from 'react-router-dom'
import { apiClient } from '../api/client'
import { fieldErrors, generalError } from '../api/errors'
import { useClinic } from '../auth/useClinic'
import { Alert } from '../components/Alert'
import { ConfirmDialog } from '../components/ConfirmDialog'
import { Field } from '../components/Field'
import { CONFIRMATION_CHANNELS } from '../pages/scheduling/labels'
import { SlotPicker } from '../pages/scheduling/SlotPicker'
import { StatusBadge } from '../pages/scheduling/StatusBadge'
import { useFocusOnMount } from '../pages/treatment/useTreatment'
import { formatDateTime, localDate } from '../ui/format'
import { useTimeZone } from '../ui/useTimeZone'
import {
  errorText,
  isUpcoming,
  selfBookingDisabled,
  usePortalAppointments,
  usePortalAppointmentTypes,
} from './portalQueries'
import { WithPortalSubject } from './WithPortalSubject'

/** CUS-48: cancelación con detalle opcional y su confirmación (RNF-063). */
function CancelDialog({ mutation, onCancel }) {
  const [detail, setDetail] = useState('')
  const errors = fieldErrors(mutation.error)

  return (
    <ConfirmDialog
      title="¿Cancelar la cita?"
      confirmLabel="Cancelar cita"
      cancelLabel="Volver"
      destructive
      busy={mutation.isPending}
      error={errors.cancel_detail ? null : generalError(mutation.error)}
      onCancel={onCancel}
      onConfirm={() => mutation.mutate({ action: 'cancel', body: { cancel_detail: detail.trim() || null } })}
    >
      <p>La franja queda libre. Si tienes avisos habilitados, recibirás un correo. No se puede deshacer.</p>
      <Field
        label="Detalle (opcional)"
        name="cancel-detail"
        size="lg"
        maxLength={300}
        value={detail}
        error={errors.cancel_detail}
        onChange={(event) => setDetail(event.target.value)}
      />
    </ConfirmDialog>
  )
}

/** CUS-48: nuevo horario con el mismo odontólogo y tipo; los horarios empiezan 2 horas después. */
function Reschedule({ appointment, subject, mutation, onCancel }) {
  const timeZone = useTimeZone()
  const sectionRef = useFocusOnMount()
  const [date, setDate] = useState(() => localDate(new Date(), timeZone))
  const [slot, setSlot] = useState(null)
  const params = {
    appointment_type_id: appointment.type.id,
    dentist_id: appointment.dentist.id,
    patient_id: subject.id,
    from: date,
    to: date,
  }
  const error = errorText(mutation.error)

  return (
    <section ref={sectionRef} className="portal-section" aria-labelledby="reschedule-title">
      <h2 id="reschedule-title">Reprogramar</h2>
      <Field
        label="Fecha"
        name="reschedule-date"
        type="date"
        size="lg"
        min={localDate(new Date(), timeZone)}
        value={date}
        onChange={(event) => {
          setDate(event.target.value)
          setSlot(null)
        }}
      />
      <div className="mt-4">
        <SlotPicker endpoint="/portal/availability" params={params} selected={slot} onSelect={setSlot} fieldSize="lg" />
      </div>
      {error && (
        <div className="alert alert-error mt-4" role="alert">
          {error}
        </div>
      )}
      <div className="form-actions flex-wrap">
        <button
          type="button"
          className="btn"
          disabled={!slot || mutation.isPending}
          onClick={() =>
            mutation.mutate(
              { action: 'reschedule', body: { starts_at: slot.startsAt } },
              // FE-1: el horario se tomó entre la consulta y el cambio; se vuelve a consultar.
              { onError: (failure) => failure.response?.status === 409 && setSlot(null) },
            )
          }
        >
          {mutation.isPending
            ? 'Reprogramando…'
            : slot
              ? `Reprogramar al ${formatDateTime(slot.startsAt, timeZone)}`
              : 'Reprogramar'}
        </button>
        <button type="button" className="btn btn-secondary" onClick={onCancel} disabled={mutation.isPending}>
          Volver
        </button>
      </div>
    </section>
  )
}

function AppointmentDetail({ subject }) {
  const { uuid } = useParams()
  const location = useLocation()
  const { slug } = useClinic()
  const timeZone = useTimeZone()
  const queryClient = useQueryClient()
  const appointments = usePortalAppointments(subject)
  // RF-026: sin autoagendamiento, la disponibilidad del portal responde 403 y no se reprograma.
  const canReschedule = !selfBookingDisabled(usePortalAppointmentTypes())
  const [mode, setMode] = useState(null)
  const [notice, setNotice] = useState(() => (location.state?.booked ? 'Cita reservada.' : null))
  const [now] = useState(() => Date.now())
  const noticeRef = useRef(null)
  // Al volver de «Reprogramar», el foco regresa a su botón.
  const returnFocus = useRef(false)
  useEffect(() => {
    if (notice) noticeRef.current?.focus()
  }, [notice])

  const act = useMutation({
    mutationFn: async ({ action, body = {} }) =>
      (await apiClient.post(`/portal/appointments/${uuid}/${action}`, body)).data.data,
    onSuccess: (data, { action }) => {
      setMode(null)
      queryClient.setQueryData(['portal-appointments', slug, subject.id], (list = []) =>
        list.map((item) => (item.id === data.id ? data : item)),
      )
      queryClient.resetQueries({ queryKey: ['availability'] })
      setNotice({ confirm: 'Cita confirmada.', reschedule: 'Cita reprogramada.', cancel: 'Cita cancelada.' }[action])
    },
    onError: (error) => {
      if (error.response?.status === 409) queryClient.invalidateQueries({ queryKey: ['availability'] })
    },
  })

  const appointment = appointments.data?.find((item) => item.id === uuid)
  if (!appointment) {
    return (
      <>
        <h1>Cita</h1>
        {appointments.isLoading && (
          <div className="empty" role="status">
            Cargando…
          </div>
        )}
        {appointments.isError && (
          <div className="alert alert-error" role="alert">
            {generalError(appointments.error)}
          </div>
        )}
        {appointments.isSuccess && (
          <div className="alert alert-error" role="alert">
            No encontramos la cita.
          </div>
        )}
      </>
    )
  }

  const pending = isUpcoming(appointment, now)
  const needsConfirmation = appointment.status === 'programada' || appointment.reconfirmation_pending
  const confirmedBy = [
    'Confirmada',
    CONFIRMATION_CHANNELS[appointment.confirmation_channel],
    `el ${formatDateTime(appointment.confirmed_at, timeZone)}.`,
  ]

  return (
    <>
      <div className="page-header">
        <div>
          <h1>Cita del {formatDateTime(appointment.starts_at, timeZone)}</h1>
          <p>
            {appointment.type.name} con {appointment.dentist.name}
          </p>
        </div>
        <StatusBadge appointment={appointment} />
      </div>

      {notice && (
        <div ref={noticeRef} tabIndex={-1} className="mb-4">
          <Alert tone="success" size="lg" title={notice} />
        </div>
      )}
      {appointment.confirmed_at && <p className="muted">{confirmedBy.filter(Boolean).join(' ')}</p>}
      {act.isError && mode === null && (
        <div className="alert alert-error mb-4" role="alert">
          {errorText(act.error)}
        </div>
      )}

      {/* Con el diálogo de cancelar abierto la barra sigue montada: al cerrarlo, el foco vuelve. */}
      {pending && mode !== 'reschedule' && (
        <div className="form-actions flex-wrap">
          {needsConfirmation && (
            <button
              type="button"
              className="btn"
              onClick={() => act.mutate({ action: 'confirm' })}
              disabled={act.isPending}
            >
              {act.isPending ? 'Confirmando…' : 'Confirmar asistencia'}
            </button>
          )}
          {canReschedule && (
            <button
              type="button"
              className="btn btn-secondary"
              ref={(node) => {
                if (node && returnFocus.current) {
                  returnFocus.current = false
                  node.focus()
                }
              }}
              onClick={() => {
                act.reset()
                setMode('reschedule')
              }}
            >
              Reprogramar
            </button>
          )}
          <button
            type="button"
            className="btn btn-secondary"
            onClick={() => {
              act.reset()
              setMode('cancel')
            }}
          >
            Cancelar cita
          </button>
        </div>
      )}
      {mode === 'reschedule' && (
        <Reschedule
          appointment={appointment}
          subject={subject}
          mutation={act}
          onCancel={() => {
            returnFocus.current = true
            setMode(null)
          }}
        />
      )}
      {mode === 'cancel' && <CancelDialog mutation={act} onCancel={() => setMode(null)} />}
    </>
  )
}

/** /c/:slug/portal/citas/:id (CUS-48, CUS-49; RN-49). */
export function PortalAppointmentPage() {
  const { portalPath } = useClinic()

  return (
    <>
      <Link to={portalPath('/citas')} className="portal-back">
        Volver a citas
      </Link>
      <WithPortalSubject>{(subject) => <AppointmentDetail subject={subject} />}</WithPortalSubject>
    </>
  )
}
