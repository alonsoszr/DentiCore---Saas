import { useState } from 'react'
import { generalError } from '../api/errors'
import { useAuth } from '../auth/useAuth'
import { PageHeader } from '../components/PageHeader'
import { StatusBadge } from '../pages/scheduling/StatusBadge'
import { formatDateTime } from '../ui/format'
import { useTimeZone } from '../ui/useTimeZone'
import { BudgetCard } from './PortalBudgetsPage'
import { subjectName, usePortalAppointments, usePortalBudgets } from './portalQueries'
import { WithPortalSubject } from './WithPortalSubject'

function QueryError({ query }) {
  return (
    <div className="alert alert-error" role="alert">
      {generalError(query.error)}
    </div>
  )
}

/** Próxima cita activa del sujeto (CUS-77). */
function NextAppointment({ subject }) {
  const timeZone = useTimeZone()
  // Hora de referencia fija durante el montaje: las citas posteriores son las próximas.
  const [now] = useState(() => Date.now())
  const appointments = usePortalAppointments(subject)

  if (appointments.isError) return <QueryError query={appointments} />
  if (!appointments.isSuccess) return null

  const next = appointments.data
    .filter((item) => ['programada', 'confirmada'].includes(item.status) && new Date(item.starts_at).getTime() > now)
    .sort((a, b) => new Date(a.starts_at) - new Date(b.starts_at))[0]

  return (
    <section className="portal-section" aria-labelledby="next-title">
      <h2 id="next-title">Próxima cita</h2>
      {next ? (
        <div className="card portal-item">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <strong>{formatDateTime(next.starts_at, timeZone)}</strong>
            <StatusBadge appointment={next} />
          </div>
          <p className="m-0">
            {next.type.name} con {next.dentist.name}
          </p>
        </div>
      ) : (
        <div className="empty">No tienes citas próximas.</div>
      )}
    </section>
  )
}

/** Presupuestos emitidos que esperan la decisión (CUS-36). */
function PendingBudgets({ subject }) {
  const budgets = usePortalBudgets(subject)

  if (budgets.isError) return <QueryError query={budgets} />

  const pending = (budgets.data ?? []).filter((budget) => budget.status === 'emitido')
  if (pending.length === 0) return null

  return (
    <section className="portal-section" aria-labelledby="pending-title">
      <h2 id="pending-title">Presupuestos por decidir</h2>
      <ul className="portal-list">
        {pending.map((budget) => (
          <BudgetCard key={budget.id} budget={budget} />
        ))}
      </ul>
    </section>
  )
}

/** /c/:slug/portal: inicio del portal del paciente. */
export function PortalHomePage() {
  const { user } = useAuth()

  return (
    <>
      <PageHeader title={`Hola, ${user.name}`} />
      <WithPortalSubject>
        {(subject) => (
          <>
            {subject.relationship === 'representado' && <p className="muted">Información de {subjectName(subject)}</p>}
            <NextAppointment subject={subject} />
            <PendingBudgets subject={subject} />
          </>
        )}
      </WithPortalSubject>
    </>
  )
}
