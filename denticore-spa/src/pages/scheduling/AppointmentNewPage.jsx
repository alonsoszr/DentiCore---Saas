import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate } from 'react-router-dom'
import { apiClient } from '../../api/client'
import { fieldErrors, generalError } from '../../api/errors'
import { useClinic } from '../../auth/useClinic'
import { Field } from '../../components/Field'
import { InlineError } from '../treatment/InlineError'
import { formatDateTime, localDate } from '../../ui/format'
import { useTimeZone } from '../../ui/useTimeZone'
import { SlotPicker } from './SlotPicker'
import { useAppointmentTypes } from './useScheduling'

/**
 * Reservar cita (/c/:slug/app/agenda/nueva; CUS-47; RF-147; SRS §11.13): paciente, tipo de cita,
 * fecha y uno de los horarios libres agrupados por odontólogo. El personal puede ajustar la
 * duración del tipo (FA-2). Si el horario se ocupó entre la consulta y la reserva, la API responde
 * 409 y se vuelven a mostrar los horarios.
 */
export function AppointmentNewPage() {
  const { slug, appPath } = useClinic()
  const timeZone = useTimeZone()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const types = useAppointmentTypes()
  const [search, setSearch] = useState('')
  const [term, setTerm] = useState('')
  const [patient, setPatient] = useState(null)
  const [typeId, setTypeId] = useState('')
  const [duration, setDuration] = useState('')
  const [date, setDate] = useState(() => localDate(new Date(), timeZone))
  const [slot, setSlot] = useState(null)

  const patients = useQuery({
    queryKey: ['patients', slug, 'search', term],
    queryFn: async () => (await apiClient.get('/patients', { params: { q: term } })).data.data,
    enabled: term.length > 0,
  })
  const activeTypes = (types.data ?? []).filter((type) => type.is_active)
  const type = activeTypes.find((item) => item.id === typeId)
  const durationMin = duration === '' ? type?.default_duration_min : Number(duration)
  // RN-47: de 10 a 240 minutos en múltiplos de 5; con otra duración no se consulta la disponibilidad.
  const durationValid = duration === '' || (durationMin >= 10 && durationMin <= 240 && durationMin % 5 === 0)
  const params =
    patient && type && date && durationValid
      ? {
          appointment_type_id: type.id,
          ...(duration !== '' ? { duration_min: Number(duration) } : {}),
          patient_id: patient.id,
          from: date,
          to: date,
        }
      : null

  const book = useMutation({
    mutationFn: async () =>
      (
        await apiClient.post('/appointments', {
          patient_id: patient.id,
          dentist_id: slot.dentistId,
          appointment_type_id: type.id,
          starts_at: slot.startsAt,
          ...(duration !== '' ? { duration_min: Number(duration) } : {}),
        })
      ).data.data,
    onSuccess: (appointment) => {
      // La agenda en caché se descarta: al volver muestra «Cargando…», no el día sin la cita nueva.
      queryClient.resetQueries({ queryKey: ['appointments'] })
      navigate(appPath(`/agenda/${appointment.id}`))
    },
    onError: (error) => {
      if (error.response?.status === 409) {
        setSlot(null)
        queryClient.invalidateQueries({ queryKey: ['availability'] })
      }
    },
  })
  const errors = fieldErrors(book.error)
  const changeSearch = () => {
    setSlot(null)
    book.reset()
  }

  return (
    <div className="card">
      <div className="page-header">
        <h1 className="m-0">Nueva cita</h1>
        <Link to={appPath('/agenda')} className="btn btn-secondary">
          Volver a la agenda
        </Link>
      </div>
      {generalError(book.error) && (
        <div className="alert alert-error" role="alert">
          {generalError(book.error)}
        </div>
      )}

      <section aria-labelledby="new-patient-title" className="form-section">
        <h2 id="new-patient-title">Paciente</h2>
        {patient ? (
          <div className="flex flex-wrap items-center gap-3">
            <strong>
              {patient.last_name}, {patient.first_name}
            </strong>
            <span className="muted">{patient.document_number}</span>
            <button
              type="button"
              className="btn-link"
              onClick={() => {
                setPatient(null)
                changeSearch()
              }}
            >
              Cambiar paciente
            </button>
          </div>
        ) : (
          <>
            <form
              className="search-form"
              onSubmit={(event) => {
                event.preventDefault()
                setTerm(search.trim())
              }}
            >
              <Field
                label="Buscar paciente"
                name="new-patient-search"
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                hint="Nombre o apellido."
                maxLength={100}
              />
              <button type="submit" className="btn btn-secondary">
                Buscar
              </button>
            </form>
            {patients.isLoading && (
              <div className="empty" role="status">
                Buscando…
              </div>
            )}
            {patients.isSuccess && patients.data.length === 0 && (
              <div className="empty" role="status">
                Ningún paciente coincide con la búsqueda.
              </div>
            )}
            {(patients.data ?? []).length > 0 && (
              <ul className="m-0 flex list-none flex-col gap-2 p-0">
                {patients.data.map((item) => (
                  <li key={item.id}>
                    <button type="button" className="btn-link" onClick={() => setPatient(item)}>
                      {item.last_name}, {item.first_name} · {item.document_number}
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </>
        )}
        <InlineError>{errors.patient_id}</InlineError>
      </section>

      <section aria-labelledby="new-slot-title" className="form-section">
        <h2 id="new-slot-title">Tipo, fecha y horario</h2>
        <div className="form-grid">
          <Field label="Tipo de cita" name="new-type" error={errors.appointment_type_id}>
            <select
              id="new-type"
              value={typeId}
              onChange={(event) => {
                setTypeId(event.target.value)
                setDuration('')
                changeSearch()
              }}
            >
              <option value="">Selecciona un tipo</option>
              {activeTypes.map((item) => (
                <option key={item.id} value={item.id}>
                  {item.name} ({item.default_duration_min} min)
                </option>
              ))}
            </select>
          </Field>
          <Field
            label="Duración (minutos, opcional)"
            name="new-duration"
            type="number"
            inputMode="numeric"
            min="10"
            max="240"
            step="5"
            value={duration}
            placeholder={type ? String(type.default_duration_min) : ''}
            onChange={(event) => {
              setDuration(event.target.value)
              changeSearch()
            }}
            error={
              errors.duration_min ?? (durationValid ? undefined : 'Indica de 10 a 240 minutos, en múltiplos de 5.')
            }
            hint="Solo si la cita dura distinto que el tipo."
          />
          <Field
            label="Fecha"
            name="new-date"
            type="date"
            value={date}
            onChange={(event) => {
              if (!event.target.value) return
              setDate(event.target.value)
              changeSearch()
            }}
          />
        </div>
        {!params && (
          <p className="muted">Elige el paciente, el tipo de cita y la fecha para ver los horarios libres.</p>
        )}
        <SlotPicker params={params} selected={slot} onSelect={setSlot} chooseDentist />
      </section>

      <div className="form-actions">
        <button type="button" className="btn" disabled={!slot || book.isPending} onClick={() => book.mutate()}>
          {slot
            ? `Reservar con ${slot.dentistName} el ${formatDateTime(slot.startsAt, timeZone)} (${durationMin} min)`
            : 'Reservar cita'}
        </button>
      </div>
    </div>
  )
}
