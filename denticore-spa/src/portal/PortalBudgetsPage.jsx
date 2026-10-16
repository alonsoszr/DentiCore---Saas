import { Link } from 'react-router-dom'
import { generalError } from '../api/errors'
import { useClinic } from '../auth/useClinic'
import { PageHeader } from '../components/PageHeader'
import { BudgetStatusBadge } from '../pages/treatment/TreatmentStatusBadges'
import { formatCurrency, formatDate } from '../ui/format'
import { useTimeZone } from '../ui/useTimeZone'
import { usePortalBudgets } from './portalQueries'
import { WithPortalSubject } from './WithPortalSubject'

/** Tarjeta de un presupuesto: número, estado, total y vencimiento (RF-121). */
export function BudgetCard({ budget }) {
  const { portalPath } = useClinic()
  const timeZone = useTimeZone()

  return (
    <li className="card portal-item">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <Link to={portalPath(`/presupuestos/${budget.id}`)} className="portal-card-link">
          Presupuesto N° {budget.number}
        </Link>
        <BudgetStatusBadge status={budget.status} />
      </div>
      <p className="m-0 tabular-nums">
        Total: <span className="amount">{formatCurrency(budget.total)}</span>
      </p>
      {budget.status === 'emitido' && budget.expires_at && (
        <p className="muted m-0">Vence el {formatDate(budget.expires_at, timeZone)}</p>
      )}
    </li>
  )
}

function BudgetList({ subject }) {
  const budgets = usePortalBudgets(subject)

  if (budgets.isLoading) {
    return (
      <div className="empty" role="status">
        Cargando…
      </div>
    )
  }
  if (budgets.isError) {
    return (
      <div className="alert alert-error" role="alert">
        {generalError(budgets.error)}
      </div>
    )
  }
  if (budgets.data.length === 0) {
    return <div className="empty">Aún no tienes presupuestos.</div>
  }

  return (
    <ul className="portal-list">
      {budgets.data.map((budget) => (
        <BudgetCard key={budget.id} budget={budget} />
      ))}
    </ul>
  )
}

/** /c/:slug/portal/presupuestos (CUS-36). */
export function PortalBudgetsPage() {
  return (
    <>
      <PageHeader title="Presupuestos" />
      <WithPortalSubject>{(subject) => <BudgetList subject={subject} />}</WithPortalSubject>
    </>
  )
}
