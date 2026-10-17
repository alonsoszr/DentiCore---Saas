import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { apiClient } from '../../api/client'
import { generalError } from '../../api/errors'
import { useAuth } from '../../auth/useAuth'
import { useClinic } from '../../auth/useClinic'
import { Field } from '../../components/Field'
import { addDays, formatCivilDate, formatTime, isoWeekday, localDate } from '../../ui/format'
import { useTimeZone } from '../../ui/useTimeZone'
import { DESK_ROLES, WEEKDAYS } from './labels'
import { StatusBadge } from './StatusBadge'
import { TableWrap } from '../../components/TableWrap'

const VIEWS = { dia: 'Día', semana: 'Semana', sala: 'Sala de espera' }

/** Lunes de la semana de una fecha civil. */
function mondayOf(date) {
  return addDays(date, 1 - isoWeekday(date))
}

/** Fila de una cita: hora, paciente, odontólogo, tipo y estado, con enlace al detalle. */
function AppointmentRow({ appointment, timeZone, appPath }) {
  return (
    <tr>
      <td className="tabular-nums">
        {formatTime(appointment.starts_at, timeZone)} – {formatTime(appointment.ends_at, timeZone)}
      </td>
      <td>
        <Link to={appPath(`/agenda/${appointment.id}`)}>{appointment.patient.name}</Link>
      </td>
      <td>{appointment.dentist.name}</td>
      <td>
        <span className="inline-flex items-center gap-2">
          <span
            className="inline-block size-3 rounded-full"
            style={{ background: appointment.type.color }}
            aria-hidden="true"
          />
          {appointment.type.name}
        </span>
      </td>
      <td>
        <StatusBadge appointment={appointment} />
      </td>
    </tr>
  )
}

function AppointmentsTable({ appointments, timeZone, appPath, empty }) {
  if (appointments.length === 0) return <div className="empty">{empty}</div>
  return (
    <TableWrap label="Citas">
      <table>
        <thead>
          <tr>
            <th>Hora</th>
            <th>Paciente</th>
            <th>Odontólogo</th>
            <th>Tipo</th>
            <th>Estado</th>
          </tr>
        </thead>
        <tbody>
          {appointments.map((appointment) => (
            <AppointmentRow key={appointment.id} appointment={appointment} timeZone={timeZone} appPath={appPath} />
          ))}
        </tbody>
      </table>
    </TableWrap>
  )
}

/**
 * Sala de espera (CUS-77; RF-153): los pacientes con llegada registrada hoy y su tiempo de espera
 * desde el check-in. Se actualiza cada 30 segundos (SDD §1.10).
 */
function WaitingRoom({ timeZone, appPath, dentistId }) {
  const { slug } = useClinic()
  const room = useQuery({
    queryKey: ['waiting-room', slug, dentistId ?? null],
    queryFn: async () =>
      (await apiClient.get('/waiting-room', { params: dentistId ? { dentist_id: dentistId } : {} })).data.data,
    refetchInterval: 30_000,
  })
  // La espera se mide al momento de la última actualización (cada 30 s).
  const now = room.dataUpdatedAt
  const list = room.data ?? []

  if (room.isLoading) {
    return (
      <div className="empty" role="status">
        Cargando…
      </div>
    )
  }
  if (room.isError) {
    return (
      <div className="alert alert-error" role="alert">
        {generalError(room.error)}
      </div>
    )
  }
  if (list.length === 0) return <div className="empty">No hay pacientes en la sala de espera.</div>

  return (
    <TableWrap label="Sala de espera">
      <table>
        <thead>
          <tr>
            <th>Llegada</th>
            <th className="text-right">Espera</th>
            <th>Paciente</th>
            <th>Odontólogo</th>
            <th>Cita</th>
          </tr>
        </thead>
        <tbody>
          {list.map((appointment) => (
            <tr key={appointment.id}>
              <td className="tabular-nums">{formatTime(appointment.checked_in_at, timeZone)}</td>
              <td className="text-right tabular-nums">
                {Math.max(0, Math.floor((now - new Date(appointment.checked_in_at).getTime()) / 60_000))} min
              </td>
              <td>
                <Link to={appPath(`/agenda/${appointment.id}`)}>{appointment.patient.name}</Link>
              </td>
              <td>{appointment.dentist.name}</td>
              <td className="tabular-nums">{formatTime(appointment.starts_at, timeZone)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </TableWrap>
  )
}

/**
 * Agenda (/c/:slug/app/agenda; CUS-77; RF-153): las citas en vista de día o de semana, filtrables
 * por odontólogo, y la sala de espera. La recepción y el administrador reservan desde aquí; el
 * odontólogo consulta todas las agendas.
 */
export function AgendaPage() {
  const { slug, appPath } = useClinic()
  const { user } = useAuth()
  const timeZone = useTimeZone()
  const [view, setView] = useState('dia')
  const [date, setDate] = useState(() => localDate(new Date(), timeZone))
  const [dentist, setDentist] = useState(null)

  const from = view === 'semana' ? mondayOf(date) : date
  const to = view === 'semana' ? addDays(from, 6) : date
  const appointments = useQuery({
    queryKey: ['appointments', slug, from, to, dentist?.id ?? null],
    queryFn: async () =>
      (await apiClient.get('/appointments', { params: { from, to, ...(dentist ? { dentist_id: dentist.id } : {}) } }))
        .data.data,
    enabled: view !== 'sala',
  })

  const all = appointments.data ?? []
  // El filtro conserva al odontólogo elegido aunque no tenga citas en el rango cargado.
  const dentists = [
    ...new Map([
      ...all.map((item) => [item.dentist.id, item.dentist]),
      ...(dentist ? [[dentist.id, dentist]] : []),
    ]).values(),
  ]
  const step = view === 'semana' ? 7 : 1
  const days = Array.from({ length: 7 }, (_, index) => addDays(from, index))
  const views = Object.keys(VIEWS)

  // Pestañas (WAI-ARIA Tabs): flechas, Inicio y Fin mueven el foco y la selección.
  const onTabKeyDown = (event) => {
    const index = views.indexOf(view)
    const next = { ArrowRight: index + 1, ArrowLeft: index - 1, Home: 0, End: views.length - 1 }[event.key]
    if (next === undefined) return
    event.preventDefault()
    const target = views[(next + views.length) % views.length]
    setView(target)
    document.getElementById(`agenda-tab-${target}`)?.focus()
  }

  return (
    <>
      <div className="page-header">
        <h1 className="m-0">Agenda</h1>
        {DESK_ROLES.includes(user?.role) && (
          <Link to={appPath('/agenda/nueva')} className="btn">
            Nueva cita
          </Link>
        )}
      </div>

      <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div className="tabs" role="tablist" aria-label="Vistas de la agenda" onKeyDown={onTabKeyDown}>
          {Object.entries(VIEWS).map(([value, label]) => (
            <button
              key={value}
              id={`agenda-tab-${value}`}
              type="button"
              role="tab"
              aria-selected={view === value}
              aria-controls="agenda-panel"
              tabIndex={view === value ? 0 : -1}
              className={view === value ? 'active' : undefined}
              onClick={() => setView(value)}
            >
              {label}
            </button>
          ))}
        </div>
        <Field label="Odontólogo" name="agenda-dentist">
          <select
            id="agenda-dentist"
            value={dentist?.id ?? ''}
            onChange={(event) => setDentist(dentists.find((item) => item.id === event.target.value) ?? null)}
          >
            <option value="">Todos</option>
            {dentists.map((item) => (
              <option key={item.id} value={item.id}>
                {item.name}
              </option>
            ))}
          </select>
        </Field>
      </div>

      <section id="agenda-panel" role="tabpanel" aria-labelledby={`agenda-tab-${view}`} className="card">
        {view === 'sala' ? (
          <WaitingRoom timeZone={timeZone} appPath={appPath} dentistId={dentist?.id} />
        ) : (
          <>
            <div className="mb-4 flex flex-wrap items-end gap-3">
              <button type="button" className="btn btn-secondary" onClick={() => setDate(addDays(date, -step))}>
                {view === 'dia' ? 'Día anterior' : 'Semana anterior'}
              </button>
              <Field
                label="Fecha"
                name="agenda-date"
                type="date"
                value={date}
                onChange={(event) => event.target.value && setDate(event.target.value)}
              />
              <button type="button" className="btn btn-secondary" onClick={() => setDate(addDays(date, step))}>
                {view === 'dia' ? 'Día siguiente' : 'Semana siguiente'}
              </button>
            </div>

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
            {appointments.isSuccess && view === 'dia' && (
              <>
                <h2>
                  {WEEKDAYS[isoWeekday(date)]} {formatCivilDate(date)}
                </h2>
                <AppointmentsTable
                  appointments={all}
                  timeZone={timeZone}
                  appPath={appPath}
                  empty="No hay citas para este día."
                />
              </>
            )}
            {appointments.isSuccess &&
              view === 'semana' &&
              days.map((day) => (
                <div key={day} className="mb-4">
                  <h2>
                    {WEEKDAYS[isoWeekday(day)]} {formatCivilDate(day)}
                  </h2>
                  <AppointmentsTable
                    appointments={all.filter((item) => localDate(item.starts_at, timeZone) === day)}
                    timeZone={timeZone}
                    appPath={appPath}
                    empty="Sin citas."
                  />
                </div>
              ))}
          </>
        )}
      </section>
    </>
  )
}
