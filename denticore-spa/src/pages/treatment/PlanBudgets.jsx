import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { apiClient } from '../../api/client'
import { generalError } from '../../api/errors'
import { useAuth } from '../../auth/useAuth'
import { useClinic } from '../../auth/useClinic'
import { formatCurrency, formatDate } from '../../ui/format'
import { BUDGET_ROLES, BUDGET_STATUS, budgetBadge } from './labels'

/**
 * Presupuestos del plan (CUS-35, CUS-36; RF-121): número, estado, total y vencimiento. Desde un plan
 * propuesto el personal crea uno nuevo; los planes con procedimientos que exigen consentimiento
 * informado enlazan a su firma (CUS-83).
 */
export function PlanBudgets({ plan, needsConsent }) {
  const { slug, appPath } = useClinic()
  const { user } = useAuth()
  const budgets = useQuery({
    queryKey: ['budgets', slug, plan.patient_id],
    queryFn: async () => (await apiClient.get(`/patients/${plan.patient_id}/budgets`)).data.data,
  })

  const list = (budgets.data ?? []).filter((budget) => budget.plan_id === plan.id)
  const canCreate = BUDGET_ROLES.includes(user?.role) && plan.status === 'propuesto'

  return (
    <section className="card" aria-labelledby="plan-budgets-title">
      <div className="page-header">
        <h2 id="plan-budgets-title" className="m-0">
          Presupuestos
        </h2>
        <div className="flex flex-wrap gap-3">
          {needsConsent && !['completado', 'cancelado'].includes(plan.status) && (
            <Link to={appPath(`/planes/${plan.id}/consentimientos`)} className="btn btn-secondary">
              Consentimientos informados
            </Link>
          )}
          {canCreate && (
            <Link to={appPath(`/planes/${plan.id}/presupuesto`)} className="btn">
              Nuevo presupuesto
            </Link>
          )}
        </div>
      </div>
      {budgets.isError && (
        <div className="alert alert-error" role="alert">
          {generalError(budgets.error)}
        </div>
      )}
      {budgets.isSuccess && list.length === 0 && <div className="empty">El plan aún no tiene presupuestos.</div>}
      {list.length > 0 && (
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Presupuesto</th>
                <th>Estado</th>
                <th className="text-right">Total</th>
                <th>Vence</th>
              </tr>
            </thead>
            <tbody>
              {list.map((budget) => (
                <tr key={budget.id}>
                  <td>
                    <Link to={appPath(`/presupuestos/${budget.id}`)}>{budget.number ?? 'Borrador sin número'}</Link>
                  </td>
                  <td>
                    <span className={budgetBadge(budget.status)}>{BUDGET_STATUS[budget.status]}</span>
                  </td>
                  <td className="text-right tabular-nums">{formatCurrency(budget.total)}</td>
                  <td>{budget.expires_at ? formatDate(budget.expires_at) : '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )
}
