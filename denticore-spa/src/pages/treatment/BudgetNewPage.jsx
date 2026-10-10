import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { apiClient } from '../../api/client'
import { fieldErrors, generalError } from '../../api/errors'
import { useClinic } from '../../auth/useClinic'
import { formatCurrency } from '../../ui/format'
import { PatientHeader } from '../patients/PatientHeader'
import { usePatient } from '../patients/usePatient'
import { InlineError } from './InlineError'
import { siteText } from './labels'
import { usePlan, useProcedures } from './useTreatment'

/**
 * Nuevo presupuesto de un plan propuesto (/c/:slug/app/planes/:uuid/presupuesto; CUS-35 pasos 1 a 3;
 * RN-28): una línea por cada ítem propuesto elegido, con el precio vigente del catálogo. Los
 * descuentos y la emisión se hacen en el presupuesto creado.
 */
export function BudgetNewPage() {
  const { uuid } = useParams()
  const { appPath } = useClinic()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const plan = usePlan(uuid)
  const procedures = useProcedures()
  const patient = usePatient(plan.data?.patient_id)
  const [excluded, setExcluded] = useState([])

  const create = useMutation({
    mutationFn: async (payload) => (await apiClient.post(`/treatment-plans/${uuid}/budgets`, payload)).data.data,
    onSuccess: (budget) => {
      queryClient.invalidateQueries({ queryKey: ['budgets'] })
      navigate(appPath(`/presupuestos/${budget.id}`))
    },
  })

  if (plan.isLoading) return <div className="card empty">Cargando…</div>
  if (plan.isError)
    return (
      <div className="alert alert-error" role="alert">
        {generalError(plan.error)}
      </div>
    )

  const proposed = plan.data.items.filter((item) => item.status === 'propuesto')
  const included = proposed.filter((item) => !excluded.includes(item.id))
  const priceOf = (item) => procedures.data?.find((procedure) => procedure.id === item.procedure.id)?.price
  const toggle = (id) => setExcluded((ids) => (ids.includes(id) ? ids.filter((item) => item !== id) : [...ids, id]))
  const errors = fieldErrors(create.error)

  return (
    <>
      <div className="page-header">
        <Link to={appPath(`/planes/${uuid}`)} className="btn btn-secondary">
          Volver al plan
        </Link>
      </div>

      {patient.data && <PatientHeader patient={patient.data} />}

      <div className="card">
        <h2>Nuevo presupuesto</h2>
        <p className="muted">
          Plan «{plan.data.title}». Elige los ítems que incluye el presupuesto; para aceptar solo una parte del
          tratamiento se emite un presupuesto con esas líneas.
        </p>
        {generalError(create.error) && (
          <div className="alert alert-error" role="alert">
            {generalError(create.error)}
          </div>
        )}

        {proposed.length === 0 ? (
          <div className="empty">El plan no tiene ítems propuestos para presupuestar.</div>
        ) : (
          <fieldset className="m-0 border-0 p-0">
            <legend className="text-label-md">Ítems del plan</legend>
            <div className="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>
                      <span className="sr-only">Incluir</span>
                    </th>
                    <th>Procedimiento</th>
                    <th>Pieza</th>
                    <th>Cantidad</th>
                    <th className="text-right">Precio vigente</th>
                  </tr>
                </thead>
                <tbody>
                  {proposed.map((item) => {
                    // Los errores llegan por posición dentro de los ítems enviados (plan_item_ids.N).
                    const lineError = errors[`plan_item_ids.${included.findIndex((entry) => entry.id === item.id)}`]
                    return (
                      <tr key={item.id}>
                        <td>
                          <input
                            type="checkbox"
                            aria-label={`Incluir ${item.procedure.name} (ítem ${item.position})`}
                            checked={!excluded.includes(item.id)}
                            onChange={() => toggle(item.id)}
                          />
                        </td>
                        <td>
                          {item.procedure.name}
                          <InlineError>{lineError}</InlineError>
                        </td>
                        <td>{siteText(item.tooth, item.surfaces)}</td>
                        <td>{item.quantity}</td>
                        <td className="text-right tabular-nums">{formatCurrency(priceOf(item))}</td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          </fieldset>
        )}

        <div className="form-actions">
          <button
            type="button"
            className="btn"
            disabled={included.length === 0 || create.isPending}
            onClick={() => create.mutate({ plan_item_ids: included.map((item) => item.id) })}
          >
            {create.isPending
              ? 'Creando…'
              : `Crear borrador con ${included.length} ${included.length === 1 ? 'ítem' : 'ítems'}`}
          </button>
        </div>
      </div>
    </>
  )
}
