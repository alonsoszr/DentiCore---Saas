import { useState } from 'react'
import { Link } from 'react-router-dom'
import { generalError } from '../api/errors'
import { useClinic } from '../auth/useClinic'
import { PageHeader } from '../components/PageHeader'
import { StatusBadge } from '../pages/scheduling/StatusBadge'
import { formatDateTime } from '../ui/format'
import { useTimeZone } from '../ui/useTimeZone'
import { isUpcoming, selfBookingDisabled, usePortalAppointments, usePortalAppointmentTypes } from './portalQueries'
import { WithPortalSubject } from './WithPortalSubject'

function AppointmentCard({ appointment }) {
  const { portalPath } = useClinic()
  const timeZone = useTimeZone()

  return (
    <li className="card portal-item">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <Link to={portalPath(`/citas/${appointment.id}`)} className="portal-card-link">
          {formatDateTime(appointment.starts_at, timeZone)}
        </Link>
        <StatusBadge appointment={appointment} />
      </div>
      <p className="m-0">
        {appointment.type.name} con {appointment.dentist.name}
      </p>
    </li>
  )
}

function AppointmentList({ subject }) {
  const appointments = usePortalAppointments(subject)
  // Hora de referencia fija durante el montaje.
  const [now] = useState(() => Date.now())

  if (appointments.isLoading) {
    return (
      <div className="empty" role="status">
        Cargando…
      </div>
    )
  }
  if (appointments.isError) {
    return (
      <div className="alert alert-error" role="alert">
        {generalError(appointments.error)}
      </div>
    )
  }

  const upcoming = appointments.data
    .filter((item) => isUpcoming(item, now))
    .sort((a, b) => new Date(a.starts_at) - new Date(b.starts_at))
  const past = appointments.data
    .filter((item) => !isUpcoming(item, now))
    .sort((a, b) => new Date(b.starts_at) - new Date(a.starts_at))

  return (
    <>
      <section className="portal-section" aria-labelledby="upcoming-title">
        <h2 id="upcoming-title">Próximas citas</h2>
        {upcoming.length === 0 ? (
          <div className="empty">No tienes citas próximas.</div>
        ) : (
          <ul className="portal-list">
            {upcoming.map((appointment) => (
              <AppointmentCard key={appointment.id} appointment={appointment} />
            ))}
          </ul>
        )}
      </section>
      {past.length > 0 && (
        <section className="portal-section" aria-labelledby="past-title">
          <h2 id="past-title">Historial de citas</h2>
          <ul className="portal-list">
            {past.map((appointment) => (
              <AppointmentCard key={appointment.id} appointment={appointment} />
            ))}
          </ul>
        </section>
      )}
    </>
  )
}

/** /c/:slug/portal/citas (CUS-77, CUS-48, CUS-49). */
export function PortalAppointmentsPage() {
  const { portalPath } = useClinic()
  // RF-026: sin autoagendamiento la clínica reserva por el paciente.
  const types = usePortalAppointmentTypes()
  const disabled = selfBookingDisabled(types)

  return (
    <>
      <PageHeader title="Citas">
        {!disabled && (
          <Link to={portalPath('/citas/nueva')} className="btn">
            Reservar cita
          </Link>
        )}
      </PageHeader>
      {disabled && <p className="muted">Para reservar o reprogramar una cita, comunícate con la clínica.</p>}
      <WithPortalSubject>{(subject) => <AppointmentList subject={subject} />}</WithPortalSubject>
    </>
  )
}
