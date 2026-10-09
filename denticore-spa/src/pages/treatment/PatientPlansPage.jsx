import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { apiClient } from '../../api/client'
import { fieldErrors, generalError } from '../../api/errors'
import { useAuth } from '../../auth/useAuth'
import { useClinic } from '../../auth/useClinic'
import { Alert } from '../../components/Alert'
import { Field } from '../../components/Field'
import { formatDate } from '../../ui/format'
import { EMPTY_ITEM, PLAN_STATUS, itemPayload, itemsFromFindings, progressText, statusBadge } from './labels'
import { ItemsEditor } from './ItemsEditor'
import { usePatientPlans, useProcedures } from './useTreatment'

/**
 * Planes de tratamiento del paciente (/c/:slug/app/pacientes/:uuid/planes; CUS-33; RF-110, RF-111,
 * RF-130): estado y avance de cada plan. El odontólogo crea un plan nuevo, también desde los
 * hallazgos pendientes seleccionados, o agrega esos hallazgos a un plan en borrador.
 */
export function PatientPlansPage({ patient }) {
  const { appPath } = useClinic()
  const { user } = useAuth()
  const location = useLocation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const fromFindings = location.state?.fromFindings ?? []
  const isDentist = user?.role === 'dentist'
  const [creating, setCreating] = useState(isDentist && fromFindings.length > 0)
  const [title, setTitle] = useState('')
  const [items, setItems] = useState(() =>
    fromFindings.length > 0 ? itemsFromFindings(fromFindings) : [{ ...EMPTY_ITEM }],
  )

  const plans = usePatientPlans(patient.id)
  const procedures = useProcedures()
  const create = useMutation({
    mutationFn: async (payload) => (await apiClient.post(`/patients/${patient.id}/treatment-plans`, payload)).data.data,
    onSuccess: (plan) => {
      queryClient.invalidateQueries({ queryKey: ['treatment-plans'] })
      queryClient.invalidateQueries({ queryKey: ['pending-findings'] })
      navigate(appPath(`/planes/${plan.id}`))
    },
  })

  const handleSubmit = (event) => {
    event.preventDefault()
    // Un ítem con algún dato se envía aunque le falte el procedimiento: la API responde 422 junto
    // al campo en lugar de crear el plan sin él (RF-111, RN-27).
    const filled = items.filter((item) => item.procedure_id !== '' || item.tooth !== '' || item.finding_ids.length > 0)
    create.mutate({ title: title.trim(), ...(filled.length > 0 ? { items: filled.map(itemPayload) } : {}) })
  }

  const errors = fieldErrors(create.error)
  const list = plans.data ?? []
  const drafts = list.filter((plan) => plan.status === 'borrador')

  return (
    <div className="card">
      <div className="page-header">
        <h2 className="m-0">Planes de tratamiento</h2>
        {isDentist && !creating && (
          <button type="button" className="btn" onClick={() => setCreating(true)}>
            Nuevo plan
          </button>
        )}
      </div>

      {isDentist && fromFindings.length > 0 && drafts.length > 0 && (
        <Alert tone="info" title="Plan en borrador" className="mb-4">
          También puedes agregar los {fromFindings.length} hallazgos seleccionados a un plan en borrador:{' '}
          {drafts.map((plan, index) => (
            <span key={plan.id}>
              {index > 0 && ', '}
              <Link to={appPath(`/planes/${plan.id}`)} state={{ fromFindings }}>
                {plan.title}
              </Link>
            </span>
          ))}
          .
        </Alert>
      )}

      {creating && (
        <form onSubmit={handleSubmit} noValidate className="form-section">
          <h3>Nuevo plan</h3>
          {generalError(create.error) && <div className="alert alert-error">{generalError(create.error)}</div>}
          <Field
            label="Título del plan"
            name="plan-title"
            value={title}
            onChange={(event) => setTitle(event.target.value)}
            error={errors.title}
            maxLength={150}
          />
          <ItemsEditor items={items} onChange={setItems} procedures={procedures.data ?? []} errors={errors} />
          <div className="form-actions">
            <button type="submit" className="btn" disabled={create.isPending}>
              {create.isPending ? 'Creando…' : 'Crear plan en borrador'}
            </button>
            <button type="button" className="btn btn-secondary" onClick={() => setCreating(false)}>
              Cancelar
            </button>
          </div>
        </form>
      )}

      {plans.isLoading && <div className="empty">Cargando…</div>}
      {plans.isError && <div className="alert alert-error">{generalError(plans.error)}</div>}
      {plans.isSuccess && list.length === 0 && (
        <div className="empty">El paciente aún no tiene planes de tratamiento.</div>
      )}
      {list.length > 0 && (
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Plan</th>
                <th>Estado</th>
                <th>Avance</th>
                <th>Creado</th>
              </tr>
            </thead>
            <tbody>
              {list.map((plan) => (
                <tr key={plan.id}>
                  <td>
                    <Link to={appPath(`/planes/${plan.id}`)}>{plan.title}</Link>
                  </td>
                  <td>
                    <span className={statusBadge(plan.status)}>{PLAN_STATUS[plan.status]}</span>
                  </td>
                  <td>{progressText(plan.progress)}</td>
                  <td>{formatDate(plan.created_at)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
