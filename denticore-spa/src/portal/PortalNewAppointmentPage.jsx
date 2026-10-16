import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate } from 'react-router-dom'
import { apiClient } from '../api/client'
import { fieldErrors, generalError } from '../api/errors'
import { useClinic } from '../auth/useClinic'
import { Field } from '../components/Field'
import { SlotPicker } from '../pages/scheduling/SlotPicker'
import { formatDateTime, localDate } from '../ui/format'
import { useTimeZone } from '../ui/useTimeZone'
import { errorText, usePortalAppointmentTypes } from './portalQueries'
import { WithPortalSubject } from './WithPortalSubject'

/** CUS-47 FA-1: tipo visible en el portal, fecha, horario y confirmación (RN-48). */
function BookingForm({ subject }) {
  const { slug, portalPath } = useClinic()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const timeZone = useTimeZone()
  const types = usePortalAppointmentTypes()
  const [typeId, setTypeId] = useState('')
  const [date, setDate] = useState(() => localDate(new Date(), timeZone))
  const [slot, setSlot] = useState(null)
  const book = useMutation({
    mutationFn: async () =>
      (
        await apiClient.post(`/portal/patients/${subject.id}/appointments`, {
          appointment_type_id: typeId,
          dentist_id: slot.dentistId,
          starts_at: slot.startsAt,
        })
      ).data.data,
    onSuccess: (appointment) => {
      queryClient.resetQueries({ queryKey: ['portal-appointments', slug, subject.id] })
      navigate(portalPath(`/citas/${appointment.id}`), { state: { booked: true } })
    },
    onError: (error) => {
      // FE-1: el horario se tomó entre la consulta y la reserva; se vuelve a consultar.
      if (error.response?.status === 409) {
        setSlot(null)
        queryClient.invalidateQueries({ queryKey: ['availability'] })
      }
    },
  })
  const errors = fieldErrors(book.error)
  const error = errorText(book.error)

  if (types.isLoading) {
    return (
      <div className="empty" role="status">
        Cargando…
      </div>
    )
  }
  if (types.isError) {
    return (
      <div className="alert alert-error" role="alert">
        {generalError(types.error)}
      </div>
    )
  }
  if (types.data.length === 0) {
    return <div className="empty">La clínica no tiene tipos de cita para reservar desde el portal.</div>
  }

  const change = (apply) => (event) => {
    apply(event.target.value)
    setSlot(null)
    book.reset()
  }
  const params = typeId ? { appointment_type_id: typeId, patient_id: subject.id, from: date, to: date } : null

  return (
    <form
      noValidate
      onSubmit={(event) => {
        event.preventDefault()
        book.mutate()
      }}
    >
      <div className="form-grid">
        <Field label="Tipo de cita" name="portal-type" size="lg" error={errors.appointment_type_id}>
          <select id="portal-type" value={typeId} onChange={change(setTypeId)}>
            <option value="">Elige un tipo</option>
            {types.data.map((type) => (
              <option key={type.id} value={type.id}>
                {type.name} ({type.duration_min} min)
              </option>
            ))}
          </select>
        </Field>
        <Field
          label="Fecha"
          name="portal-date"
          type="date"
          size="lg"
          min={localDate(new Date(), timeZone)}
          value={date}
          onChange={change(setDate)}
        />
      </div>

      <div className="mt-4">
        {params ? (
          <SlotPicker
            endpoint="/portal/availability"
            params={params}
            selected={slot}
            onSelect={setSlot}
            chooseDentist
            fieldSize="lg"
          />
        ) : (
          <p className="muted">Elige el tipo de cita y la fecha para ver los horarios libres.</p>
        )}
      </div>

      {error && !errors.appointment_type_id && (
        <div className="alert alert-error mt-4" role="alert">
          {error}
        </div>
      )}

      <div className="form-actions flex-wrap">
        <button type="submit" className="btn" disabled={!slot || book.isPending}>
          {book.isPending
            ? 'Reservando…'
            : slot
              ? `Reservar con ${slot.dentistName} el ${formatDateTime(slot.startsAt, timeZone)}`
              : 'Reservar cita'}
        </button>
      </div>
    </form>
  )
}

/** /c/:slug/portal/citas/nueva (CUS-46, CUS-47; RF-148). */
export function PortalNewAppointmentPage() {
  const { portalPath } = useClinic()

  return (
    <>
      <Link to={portalPath('/citas')} className="portal-back">
        Volver a citas
      </Link>
      <h1>Reservar cita</h1>
      <WithPortalSubject>{(subject) => <BookingForm subject={subject} />}</WithPortalSubject>
    </>
  )
}
