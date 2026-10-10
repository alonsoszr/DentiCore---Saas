import { useEffect, useRef, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Link, useLocation, useParams } from 'react-router-dom'
import { apiClient } from '../../api/client'
import { fieldErrors, generalError } from '../../api/errors'
import { useAuth } from '../../auth/useAuth'
import { useClinic } from '../../auth/useClinic'
import { ConfirmDialog } from '../../components/ConfirmDialog'
import { Field } from '../../components/Field'
import { formatCurrency, formatDate } from '../../ui/format'
import { PatientHeader } from '../patients/PatientHeader'
import { usePatient } from '../patients/usePatient'
import { ItemFields } from './ItemFields'
import { ItemsEditor } from './ItemsEditor'
import { PlanBudgets } from './PlanBudgets'
import {
  EMPTY_ITEM,
  FINAL_PLAN_STATUSES,
  ITEM_STATUS,
  PLAN_STATUS,
  itemPayload,
  itemsFromFindings,
  progressText,
  siteText,
  statusBadge,
} from './labels'
import { usePlan, useProcedures } from './useTreatment'

const CANCEL_ROLES = ['clinic_admin', 'dentist']

function itemForm(item) {
  return {
    ...EMPTY_ITEM,
    procedure_id: item.procedure.id,
    tooth: item.tooth === null ? '' : String(item.tooth),
    surfaces: item.surfaces,
    quantity: String(item.quantity),
    session_number: item.session_number === null ? '' : String(item.session_number),
    observations: item.observations ?? '',
  }
}

/** Mutación del plan que, al terminar, ejecuta `after` y actualiza el plan y sus listas. */
function usePlanMutation(refresh, mutationFn, after) {
  return useMutation({
    mutationFn,
    onSuccess: (...args) => {
      after?.(...args)
      refresh()
    },
  })
}

/** Lleva el foco al primer control del formulario al abrirlo (DESIGN.md › foco). */
function useFocusWhenOpen(open) {
  const ref = useRef(null)
  useEffect(() => {
    if (open) ref.current?.querySelector('input, select, textarea')?.focus()
  }, [open])
  return ref
}

/** Diálogo destructivo con un motivo obligatorio (descartar un ítem o cancelar el plan, RF-129). */
function ReasonDialog({ title, confirmLabel, children, mutation, onConfirm, onCancel }) {
  const [reason, setReason] = useState('')
  const reasonError = fieldErrors(mutation.error).reason

  return (
    <ConfirmDialog
      title={title}
      confirmLabel={confirmLabel}
      onConfirm={() => onConfirm(reason)}
      onCancel={onCancel}
      busy={mutation.isPending}
      confirmDisabled={reason.trim() === ''}
      destructive
      error={reasonError ? null : generalError(mutation.error)}
    >
      {children}
      <Field label="Motivo" name="reason" error={reasonError}>
        <textarea
          id="reason"
          rows={3}
          maxLength={500}
          value={reason}
          onChange={(event) => setReason(event.target.value)}
          aria-invalid={reasonError ? true : undefined}
          aria-describedby={reasonError ? 'reason-error' : undefined}
        />
      </Field>
    </ConfirmDialog>
  )
}

/**
 * Plan de tratamiento (/c/:slug/app/planes/:uuid; CUS-33, CUS-40; RF-110 a RF-114, RF-129, RF-130).
 * En borrador el odontólogo edita el título y los ítems y lo propone; propuesto, puede volver a
 * editarlo. El odontólogo y el administrador descartan ítems y cancelan el plan con motivo, viendo
 * antes lo realizado. La cabecera del paciente muestra sus alergias mientras se elabora el plan
 * (DESIGN.md › Allergy warning). Las transiciones no definidas las rechaza la API (409).
 */
export function PlanDetailPage() {
  const { uuid } = useParams()
  const { appPath, slug } = useClinic()
  const { user } = useAuth()
  const location = useLocation()
  const queryClient = useQueryClient()
  const plan = usePlan(uuid)
  const patient = usePatient(plan.data?.patient_id)
  const procedures = useProcedures()
  const fromFindings = location.state?.fromFindings ?? []

  const [editingTitle, setEditingTitle] = useState(false)
  const [title, setTitle] = useState('')
  const [adding, setAdding] = useState(fromFindings.length > 0)
  const [newItems, setNewItems] = useState(() =>
    fromFindings.length > 0 ? itemsFromFindings(fromFindings) : [{ ...EMPTY_ITEM }],
  )
  const [editingItem, setEditingItem] = useState(null)
  const [itemValue, setItemValue] = useState(EMPTY_ITEM)
  const [removing, setRemoving] = useState(null)
  const [discarding, setDiscarding] = useState(null)
  const [cancelPreview, setCancelPreview] = useState(null)
  const titleRef = useFocusWhenOpen(editingTitle)
  const addRef = useFocusWhenOpen(adding)
  const editRef = useFocusWhenOpen(editingItem)

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['treatment-plan', slug, uuid] })
    queryClient.invalidateQueries({ queryKey: ['treatment-plans'] })
    queryClient.invalidateQueries({ queryKey: ['pending-findings'] })
  }
  const saveTitle = usePlanMutation(
    refresh,
    (value) => apiClient.patch(`/treatment-plans/${uuid}`, { title: value }),
    () => setEditingTitle(false),
  )
  const addItems = usePlanMutation(
    refresh,
    (payload) => apiClient.post(`/treatment-plans/${uuid}/items`, payload),
    () => {
      setAdding(false)
      setNewItems([{ ...EMPTY_ITEM }])
    },
  )
  const updateItem = usePlanMutation(
    refresh,
    ({ id, payload }) => apiClient.patch(`/plan-items/${id}`, payload),
    () => setEditingItem(null),
  )
  const removeItem = usePlanMutation(
    refresh,
    (id) => apiClient.delete(`/plan-items/${id}`),
    () => setRemoving(null),
  )
  const discardItem = usePlanMutation(
    refresh,
    ({ id, reason }) => apiClient.post(`/plan-items/${id}/discard`, { reason }),
    () => setDiscarding(null),
  )
  const transition = usePlanMutation(refresh, (name) => apiClient.post(`/treatment-plans/${uuid}/${name}`))
  const preview = useMutation({
    mutationFn: async () => (await apiClient.get(`/treatment-plans/${uuid}/cancellation-preview`)).data.data,
    onSuccess: (data) => setCancelPreview(data),
  })
  const cancel = usePlanMutation(
    refresh,
    (reason) => apiClient.post(`/treatment-plans/${uuid}/cancel`, { reason }),
    () => setCancelPreview(null),
  )

  if (plan.isLoading) return <div className="card empty">Cargando…</div>
  if (plan.isError) return <div className="alert alert-error">{generalError(plan.error)}</div>

  const data = plan.data
  const isDentist = user?.role === 'dentist'
  const isDraft = data.status === 'borrador'
  const isFinal = FINAL_PLAN_STATUSES.includes(data.status)
  const canEdit = isDentist && isDraft
  const canPropose = canEdit && data.items.some((item) => item.status === 'propuesto')
  const canCancel = CANCEL_ROLES.includes(user?.role) && !isFinal
  // En borrador un ítem propuesto se quita; descartar es para el plan ya presentado (CUS-40).
  const canDiscard = (item) =>
    CANCEL_ROLES.includes(user?.role) && !isFinal && !isDraft && ['propuesto', 'aceptado'].includes(item.status)
  const procedureList = procedures.data ?? []
  // Firma de consentimientos: ítems pendientes cuyo procedimiento lo exige (RN-76).
  const needsConsent = data.items.some(
    (item) =>
      ['propuesto', 'aceptado'].includes(item.status) &&
      procedureList.find((procedure) => procedure.id === item.procedure.id)?.requires_informed_consent,
  )
  const actionError = generalError(transition.error) ?? generalError(preview.error)

  return (
    <>
      <div className="page-header">
        <Link to={appPath(`/pacientes/${data.patient_id}/planes`)} className="btn btn-secondary">
          Volver a los planes del paciente
        </Link>
      </div>

      {patient.data && <PatientHeader patient={patient.data} />}

      <div className="card">
        <div className="page-header">
          <h2 className="m-0">{data.title}</h2>
          <span className={statusBadge(data.status)}>{PLAN_STATUS[data.status]}</span>
        </div>

        {editingTitle && (
          <form
            ref={titleRef}
            className="flex flex-wrap items-end gap-3"
            onSubmit={(event) => {
              event.preventDefault()
              saveTitle.mutate(title.trim())
            }}
          >
            <Field
              label="Título del plan"
              name="title"
              value={title}
              onChange={(event) => setTitle(event.target.value)}
              error={fieldErrors(saveTitle.error).title ?? generalError(saveTitle.error)}
              maxLength={150}
            />
            <button type="submit" className="btn" disabled={saveTitle.isPending}>
              Guardar título
            </button>
            <button type="button" className="btn btn-secondary" onClick={() => setEditingTitle(false)}>
              Cancelar
            </button>
          </form>
        )}

        <dl className="details">
          <dt>Avance</dt>
          <dd>{progressText(data.progress)}</dd>
          <dt>Elaborado por</dt>
          <dd>
            {data.created_by.name} · {formatDate(data.created_at)}
          </dd>
          {data.status === 'cancelado' && (
            <>
              <dt>Cancelado</dt>
              <dd>
                {formatDate(data.cancelled_at)} · {data.cancel_reason}
              </dd>
            </>
          )}
          {data.status === 'completado' && (
            <>
              <dt>Completado</dt>
              <dd>{formatDate(data.completed_at)}</dd>
            </>
          )}
        </dl>

        {actionError && <div className="alert alert-error">{actionError}</div>}

        <div className="form-actions">
          {canEdit && !editingTitle && (
            <button
              type="button"
              className="btn btn-secondary"
              onClick={() => {
                setTitle(data.title)
                setEditingTitle(true)
              }}
            >
              Editar título
            </button>
          )}
          {canPropose && (
            <button
              type="button"
              className="btn"
              onClick={() => transition.mutate('propose')}
              disabled={transition.isPending}
            >
              Proponer al paciente
            </button>
          )}
          {isDentist && data.status === 'propuesto' && (
            <button
              type="button"
              className="btn btn-secondary"
              onClick={() => transition.mutate('reopen')}
              disabled={transition.isPending}
            >
              Volver a editar
            </button>
          )}
          {canCancel && (
            <button
              type="button"
              className="btn btn-secondary"
              onClick={() => {
                cancel.reset()
                preview.mutate()
              }}
              disabled={preview.isPending}
            >
              Cancelar plan
            </button>
          )}
        </div>
      </div>

      <div className="card">
        <div className="page-header">
          <h2 className="m-0">Ítems del plan</h2>
          {canEdit && !adding && (
            <button type="button" className="btn btn-secondary" onClick={() => setAdding(true)}>
              Agregar ítems
            </button>
          )}
        </div>

        {canEdit && adding && (
          <form
            ref={addRef}
            noValidate
            className="form-section"
            onSubmit={(event) => {
              event.preventDefault()
              addItems.mutate({ items: newItems.map(itemPayload) })
            }}
          >
            {generalError(addItems.error) && <div className="alert alert-error">{generalError(addItems.error)}</div>}
            <ItemsEditor
              items={newItems}
              onChange={setNewItems}
              procedures={procedureList}
              errors={fieldErrors(addItems.error)}
              idPrefix="new-item"
            />
            <div className="form-actions">
              <button type="submit" className="btn" disabled={addItems.isPending}>
                Guardar ítems
              </button>
              <button type="button" className="btn btn-secondary" onClick={() => setAdding(false)}>
                Cancelar
              </button>
            </div>
          </form>
        )}

        {editingItem && (
          <form
            ref={editRef}
            noValidate
            className="form-section"
            onSubmit={(event) => {
              event.preventDefault()
              const { procedure_id, tooth, surfaces, quantity, session_number, observations } = itemPayload(itemValue)
              updateItem.mutate({
                id: editingItem,
                payload: { procedure_id, tooth, surfaces, quantity, session_number, observations },
              })
            }}
          >
            <h3>Editar ítem</h3>
            {generalError(updateItem.error) && (
              <div className="alert alert-error">{generalError(updateItem.error)}</div>
            )}
            <ItemFields
              idPrefix="edit-item"
              value={itemValue}
              onChange={setItemValue}
              procedures={procedureList}
              errors={fieldErrors(updateItem.error)}
            />
            <div className="form-actions">
              <button type="submit" className="btn" disabled={updateItem.isPending}>
                Guardar ítem
              </button>
              <button type="button" className="btn btn-secondary" onClick={() => setEditingItem(null)}>
                Cancelar
              </button>
            </div>
          </form>
        )}

        {data.items.length === 0 ? (
          <div className="empty">El plan aún no tiene ítems.</div>
        ) : (
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>#</th>
                  <th>Procedimiento</th>
                  <th>Pieza</th>
                  <th>Cantidad</th>
                  <th>Sesión</th>
                  <th>Estado</th>
                  <th>
                    <span className="sr-only">Acciones</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                {data.items.map((item) => (
                  <tr key={item.id}>
                    <td>{item.position}</td>
                    <td>
                      {item.procedure.name}
                      {item.finding_ids.length > 0 && (
                        <div className="muted">
                          {item.finding_ids.length === 1
                            ? 'Atiende 1 hallazgo'
                            : `Atiende ${item.finding_ids.length} hallazgos`}
                        </div>
                      )}
                      {item.observations && <div className="muted">{item.observations}</div>}
                      {item.discard_reason && <div className="muted">Descartado: {item.discard_reason}</div>}
                    </td>
                    <td>{siteText(item.tooth, item.surfaces)}</td>
                    <td>
                      {item.performed_quantity} de {item.quantity}
                    </td>
                    <td>{item.session_number ?? '—'}</td>
                    <td>
                      <span className={statusBadge(item.status)}>{ITEM_STATUS[item.status]}</span>
                    </td>
                    <td>
                      <div className="flex flex-wrap gap-3">
                        {canEdit && item.status === 'propuesto' && (
                          <>
                            <button
                              type="button"
                              className="btn-link"
                              onClick={() => {
                                updateItem.reset()
                                setItemValue(itemForm(item))
                                setEditingItem(item.id)
                              }}
                            >
                              Editar
                            </button>
                            <button
                              type="button"
                              className="btn-link"
                              onClick={() => {
                                removeItem.reset()
                                setRemoving(item)
                              }}
                            >
                              Quitar
                            </button>
                          </>
                        )}
                        {canDiscard(item) && (
                          <button
                            type="button"
                            className="btn-link"
                            onClick={() => {
                              discardItem.reset()
                              setDiscarding(item)
                            }}
                          >
                            Descartar
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {!isDraft && <PlanBudgets plan={data} needsConsent={needsConsent} />}

      {removing && (
        <ConfirmDialog
          title={`¿Quitar el ítem ${removing.position}?`}
          confirmLabel="Quitar ítem"
          destructive
          onConfirm={() => removeItem.mutate(removing.id)}
          onCancel={() => setRemoving(null)}
          busy={removeItem.isPending}
          error={generalError(removeItem.error)}
        >
          <p>
            «{removing.procedure.name}» sale del plan. Los hallazgos que atendía vuelven a quedar pendientes de
            decisión.
          </p>
        </ConfirmDialog>
      )}

      {discarding && (
        <ReasonDialog
          title={`Descartar el ítem ${discarding.position}`}
          confirmLabel="Descartar ítem"
          mutation={discardItem}
          onConfirm={(reason) => discardItem.mutate({ id: discarding.id, reason })}
          onCancel={() => setDiscarding(null)}
        >
          <p>
            «{discarding.procedure.name}» queda descartado con su motivo y no se podrá realizar. Esta acción no se puede
            deshacer.
          </p>
        </ReasonDialog>
      )}

      {cancelPreview && (
        <ReasonDialog
          title="Cancelar el plan"
          confirmLabel="Confirmar cancelación"
          mutation={cancel}
          onConfirm={(reason) => cancel.mutate(reason)}
          onCancel={() => setCancelPreview(null)}
        >
          <p>El plan quedará cancelado y no admitirá más cambios. Lo realizado hasta hoy:</p>
          <dl className="details">
            <dt>Ítems realizados</dt>
            <dd>
              {cancelPreview.items_performed} de {cancelPreview.items_total}
            </dd>
            <dt>Valor realizado</dt>
            <dd>{formatCurrency(cancelPreview.performed_amount)}</dd>
            <dt>Monto aceptado</dt>
            <dd>
              {cancelPreview.accepted_amount === null
                ? 'Sin presupuesto aceptado'
                : formatCurrency(cancelPreview.accepted_amount)}
            </dd>
          </dl>
        </ReasonDialog>
      )}
    </>
  )
}
