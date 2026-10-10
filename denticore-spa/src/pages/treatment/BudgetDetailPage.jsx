import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { apiClient } from '../../api/client'
import { fieldErrors, generalError } from '../../api/errors'
import { useAuth } from '../../auth/useAuth'
import { useClinic } from '../../auth/useClinic'
import { Alert } from '../../components/Alert'
import { ConfirmDialog } from '../../components/ConfirmDialog'
import { Field } from '../../components/Field'
import { formatCurrency, formatDate, formatDateTime } from '../../ui/format'
import { PatientHeader } from '../patients/PatientHeader'
import { usePatient } from '../patients/usePatient'
import { InlineError } from './InlineError'
import {
  BUDGET_ROLES,
  BUDGET_STATUS,
  DECISION_CHANNELS,
  DECISION_ROLES,
  REJECTION_REASONS,
  SIGNERS,
  budgetBadge,
  siteText,
} from './labels'
import { useFocusOnMount } from './useTreatment'

const PDF_STATUS = {
  pendiente: 'PDF en generación…',
  generando: 'PDF en generación…',
  listo: 'PDF listo',
  fallido: 'No se pudo generar el PDF.',
}

/** Descuento de una línea del borrador (RN-31): porcentaje y motivo de 5 a 200 caracteres. */
function DiscountForm({ budgetId, line, onDone }) {
  const queryClient = useQueryClient()
  const { slug } = useClinic()
  const [pct, setPct] = useState(line.discount_pct)
  const [reason, setReason] = useState(line.discount_reason ?? '')
  const save = useMutation({
    mutationFn: async () =>
      (
        await apiClient.patch(`/budgets/${budgetId}/lines/${line.id}`, {
          discount_pct: pct,
          discount_reason: reason.trim() === '' ? null : reason.trim(),
        })
      ).data.data,
    onSuccess: (budget) => {
      queryClient.setQueryData(['budget', slug, budgetId], budget)
      onDone()
    },
  })
  const errors = fieldErrors(save.error)
  const prefix = `discount-${line.id}`
  const formRef = useFocusOnMount()

  return (
    <form
      ref={formRef}
      className="form-section"
      aria-label={`Descuento de ${line.description}`}
      noValidate
      onSubmit={(event) => {
        event.preventDefault()
        save.mutate()
      }}
    >
      {generalError(save.error) && (
        <div className="alert alert-error" role="alert">
          {generalError(save.error)}
        </div>
      )}
      <div className="form-grid">
        <Field
          label="Descuento (%)"
          name={`${prefix}-pct`}
          type="number"
          inputMode="decimal"
          min="0"
          max="100"
          step="0.01"
          value={pct}
          onChange={(event) => setPct(event.target.value)}
          error={errors.discount_pct}
        />
        <Field
          label="Motivo del descuento"
          name={`${prefix}-reason`}
          value={reason}
          onChange={(event) => setReason(event.target.value)}
          error={errors.discount_reason}
          hint="Obligatorio si hay descuento: de 5 a 200 caracteres."
          maxLength={200}
        />
      </div>
      <div className="form-actions">
        <button type="submit" className="btn" disabled={save.isPending}>
          Aplicar descuento
        </button>
        <button type="button" className="btn btn-secondary" onClick={onDone}>
          Cancelar
        </button>
      </div>
    </form>
  )
}

/**
 * Decisión presencial del titular o su representante (CUS-37 FA-2), con confirmación. Por un menor
 * decide su representante (RN-12). Un 409 (vencido o ya decidido) recarga el presupuesto.
 */
function DecisionForm({ budget, patient, onDecided, onConflict }) {
  const [form, setForm] = useState({
    decision: 'aceptado',
    signer: patient.age_years < 18 ? 'representante' : 'titular',
    signer_document_number: '',
    rejection_reason: '',
    rejection_detail: '',
  })
  const [file, setFile] = useState(null)
  const [confirming, setConfirming] = useState(false)
  const decide = useMutation({
    mutationFn: async () => {
      const data = new FormData()
      data.append('decision', form.decision)
      data.append('signer', form.signer)
      data.append('signer_document_number', form.signer_document_number.trim())
      if (form.decision === 'rechazado') {
        if (form.rejection_reason) data.append('rejection_reason', form.rejection_reason)
        if (form.rejection_detail.trim()) data.append('rejection_detail', form.rejection_detail.trim())
      }
      if (file) data.append('signed_file', file)
      return (await apiClient.post(`/budgets/${budget.id}/decision`, data)).data.data
    },
    onSuccess: (decided) => {
      setConfirming(false)
      onDecided(decided)
    },
    onError: (error) => {
      setConfirming(false)
      if (error.response?.status === 409) onConflict(generalError(error))
    },
  })
  const errors = fieldErrors(decide.error)
  const set = (field) => (event) => setForm({ ...form, [field]: event.target.value })
  const accepting = form.decision === 'aceptado'

  return (
    <section className="card" aria-labelledby="decision-title">
      <h2 id="decision-title">Registrar la decisión del paciente</h2>
      <form
        noValidate
        onSubmit={(event) => {
          event.preventDefault()
          decide.reset()
          setConfirming(true)
        }}
      >
        {generalError(decide.error) && (
          <div className="alert alert-error" role="alert">
            {generalError(decide.error)}
          </div>
        )}
        <fieldset className="m-0 border-0 p-0">
          <legend className="text-label-md">Decisión</legend>
          <div className="flex flex-wrap gap-4">
            <label className="checkbox">
              <input type="radio" name="decision" value="aceptado" checked={accepting} onChange={set('decision')} />
              Acepta el presupuesto completo
            </label>
            <label className="checkbox">
              <input type="radio" name="decision" value="rechazado" checked={!accepting} onChange={set('decision')} />
              Rechaza el presupuesto
            </label>
          </div>
        </fieldset>
        <div className="form-grid">
          <Field label="Quién decide" name="signer" error={errors.signer}>
            <select
              id="signer"
              value={form.signer}
              onChange={set('signer')}
              aria-invalid={errors.signer ? true : undefined}
              aria-describedby={errors.signer ? 'signer-error' : undefined}
            >
              {Object.entries(SIGNERS).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </select>
          </Field>
          <Field
            label="Documento de quien decide"
            name="signer_document_number"
            value={form.signer_document_number}
            onChange={set('signer_document_number')}
            error={errors.signer_document_number}
            hint="Se verifica contra la ficha del paciente o de su representante."
            autoComplete="off"
            maxLength={12}
          />
          {!accepting && (
            <Field label="Motivo del rechazo (opcional)" name="rejection_reason" error={errors.rejection_reason}>
              <select
                id="rejection_reason"
                value={form.rejection_reason}
                onChange={set('rejection_reason')}
                aria-invalid={errors.rejection_reason ? true : undefined}
                aria-describedby={errors.rejection_reason ? 'rejection_reason-error' : undefined}
              >
                <option value="">Sin motivo</option>
                {Object.entries(REJECTION_REASONS).map(([value, label]) => (
                  <option key={value} value={value}>
                    {label}
                  </option>
                ))}
              </select>
            </Field>
          )}
          {!accepting && (
            <Field
              label="Detalle del rechazo (opcional)"
              name="rejection_detail"
              value={form.rejection_detail}
              onChange={set('rejection_detail')}
              error={errors.rejection_detail}
              maxLength={200}
            />
          )}
          <Field label="Presupuesto firmado (PDF, opcional)" name="signed_file" error={errors.signed_file}>
            <input
              id="signed_file"
              type="file"
              accept="application/pdf"
              aria-invalid={errors.signed_file ? true : undefined}
              aria-describedby={errors.signed_file ? 'signed_file-error' : undefined}
              onChange={(event) => setFile(event.target.files?.[0] ?? null)}
            />
          </Field>
        </div>
        <div className="form-actions">
          <button type="submit" className="btn" disabled={form.signer_document_number.trim() === ''}>
            {accepting ? 'Registrar aceptación' : 'Registrar rechazo'}
          </button>
        </div>
      </form>

      {confirming && (
        <ConfirmDialog
          title={
            accepting ? `¿Registrar la aceptación de ${budget.number}?` : `¿Registrar el rechazo de ${budget.number}?`
          }
          confirmLabel={accepting ? 'Confirmar aceptación' : 'Confirmar rechazo'}
          destructive={!accepting}
          onConfirm={() => decide.mutate()}
          onCancel={() => setConfirming(false)}
          busy={decide.isPending}
        >
          {accepting ? (
            <p>
              El paciente acepta el presupuesto completo por {formatCurrency(budget.total)}. Los demás presupuestos
              emitidos del plan quedarán reemplazados, y el plan y sus ítems pasarán a aceptado. La decisión no se puede
              modificar.
            </p>
          ) : (
            <p>El presupuesto quedará rechazado y el plan seguirá propuesto. La decisión no se puede modificar.</p>
          )}
        </ConfirmDialog>
      )}
    </section>
  )
}

/**
 * Presupuesto (/c/:slug/app/presupuestos/:uuid; CUS-35 a CUS-37; RF-115 a RF-123, RN-28 a RN-37):
 * borrador con descuentos por línea y emisión con confirmación; emitido, su PDF, la corrección y la
 * decisión presencial; decidido, la evidencia de la decisión. Un emitido no se modifica (RN-34).
 */
export function BudgetDetailPage() {
  const { uuid } = useParams()
  const { slug, appPath } = useClinic()
  const { user } = useAuth()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const queryKey = ['budget', slug, uuid]
  const [discountLine, setDiscountLine] = useState(null)
  const [confirmIssue, setConfirmIssue] = useState(false)
  const [confirmDelete, setConfirmDelete] = useState(false)
  const [conflict, setConflict] = useState(null)

  const budget = useQuery({ queryKey, queryFn: async () => (await apiClient.get(`/budgets/${uuid}`)).data.data })
  const patient = usePatient(budget.data?.patient_id)
  const issued = budget.data && budget.data.status !== 'borrador'
  const pdf = useQuery({
    queryKey: ['budget-pdf', slug, uuid],
    queryFn: async () => (await apiClient.get(`/budgets/${uuid}/pdf`)).data.data,
    enabled: Boolean(issued),
    // En generación se consulta cada 3 s; listo, la URL firmada se renueva antes de sus 10 minutos.
    refetchInterval: (query) => {
      const status = query.state.data?.status
      if (['pendiente', 'generando'].includes(status)) return 3000
      return status === 'listo' ? 9 * 60 * 1000 : false
    },
  })

  const afterChange = (data) => {
    queryClient.setQueryData(queryKey, data)
    queryClient.invalidateQueries({ queryKey: ['budgets'] })
    queryClient.invalidateQueries({ queryKey: ['treatment-plan'] })
    queryClient.invalidateQueries({ queryKey: ['budget-pdf', slug, uuid] })
  }
  const issue = useMutation({
    mutationFn: async () => (await apiClient.post(`/budgets/${uuid}/issue`)).data.data,
    onSuccess: (data) => {
      setConfirmIssue(false)
      afterChange(data)
    },
    onError: () => setConfirmIssue(false),
  })
  const remove = useMutation({
    mutationFn: async () => apiClient.delete(`/budgets/${uuid}`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['budgets'] })
      navigate(appPath(`/planes/${budget.data.plan_id}`))
    },
  })
  const correct = useMutation({
    mutationFn: async () => (await apiClient.post(`/budgets/${uuid}/corrections`)).data.data,
    onSuccess: (draft) => {
      queryClient.invalidateQueries({ queryKey: ['budgets'] })
      navigate(appPath(`/presupuestos/${draft.id}`))
    },
  })
  const regenerate = useMutation({
    mutationFn: async () => apiClient.post(`/budgets/${uuid}/pdf/regenerate`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['budget-pdf', slug, uuid] }),
  })

  if (budget.isLoading) return <div className="card empty">Cargando…</div>
  if (budget.isError)
    return (
      <div className="alert alert-error" role="alert">
        {generalError(budget.error)}
      </div>
    )

  const data = budget.data
  const isDraft = data.status === 'borrador'
  const canManage = BUDGET_ROLES.includes(user?.role)
  const lineErrors = fieldErrors(issue.error)
  // RN-32: la emisión rechazada trae el motivo general y el de cada línea inactiva.
  const issueError = generalError(issue.error) ?? issue.error?.response?.data?.detail
  const actionError = generalError(correct.error) ?? generalError(regenerate.error)

  return (
    <>
      <div className="page-header">
        <Link to={appPath(`/planes/${data.plan_id}`)} className="btn btn-secondary">
          Volver al plan
        </Link>
      </div>

      {patient.data && <PatientHeader patient={patient.data} />}

      <div className="card">
        <div className="page-header">
          <h2 className="m-0">{data.number ? `Presupuesto ${data.number}` : 'Presupuesto en borrador'}</h2>
          <span className={budgetBadge(data.status)}>{BUDGET_STATUS[data.status]}</span>
        </div>
        <dl className="details">
          {data.issued_at && (
            <>
              <dt>Fecha de emisión</dt>
              <dd>{formatDate(data.issued_at)}</dd>
              <dt>Vence</dt>
              <dd>{formatDate(data.expires_at)} a las 23:59</dd>
            </>
          )}
          {data.dentist && (
            <>
              <dt>Odontólogo</dt>
              <dd>
                {data.dentist.name} · COP {data.dentist.cop}
              </dd>
            </>
          )}
          {data.corrects_budget_id && (
            <>
              <dt>Corrige a</dt>
              <dd>
                <Link to={appPath(`/presupuestos/${data.corrects_budget_id}`)}>el presupuesto anterior</Link>
              </dd>
            </>
          )}
        </dl>

        {isDraft && (
          <Alert tone="info" title="Borrador" className="mb-4">
            Los precios se actualizan al catálogo vigente al emitir. Un presupuesto emitido no se puede modificar.
          </Alert>
        )}
        {issueError && (
          <div className="alert alert-error" role="alert">
            {issueError}
          </div>
        )}
        {actionError && (
          <div className="alert alert-error" role="alert">
            {actionError}
          </div>
        )}

        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Procedimiento</th>
                <th>Pieza</th>
                <th>Cantidad</th>
                <th className="text-right">Precio</th>
                <th className="text-right">Descuento</th>
                <th className="text-right">Subtotal</th>
                {isDraft && canManage && (
                  <th>
                    <span className="sr-only">Acciones</span>
                  </th>
                )}
              </tr>
            </thead>
            <tbody>
              {data.lines.map((line) => (
                <tr key={line.id}>
                  <td>
                    {line.description}
                    <InlineError>{lineErrors[`lines.${line.id}`]}</InlineError>
                  </td>
                  <td>{siteText(line.tooth, line.surfaces)}</td>
                  <td>{line.quantity}</td>
                  <td className="text-right tabular-nums">{formatCurrency(line.unit_price)}</td>
                  <td className="text-right tabular-nums">
                    {Number(line.discount_pct) > 0 ? `${line.discount_pct} %` : '—'}
                    {line.discount_reason && <div className="muted">{line.discount_reason}</div>}
                    {line.discount_approved && <div className="muted">Aprobado por el administrador</div>}
                  </td>
                  <td className="text-right tabular-nums">{formatCurrency(line.subtotal)}</td>
                  {isDraft && canManage && (
                    <td>
                      <button
                        type="button"
                        className="btn-link"
                        aria-label={`Descuento de ${line.description}`}
                        onClick={() => setDiscountLine(line.id)}
                      >
                        Descuento
                      </button>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {discountLine && (
          <DiscountForm
            key={discountLine}
            budgetId={uuid}
            line={data.lines.find((line) => line.id === discountLine)}
            onDone={() => setDiscountLine(null)}
          />
        )}

        <dl className="details tabular-nums">
          <dt>Suma de subtotales</dt>
          <dd>{formatCurrency(data.subtotal)}</dd>
          <dt>Descuentos</dt>
          <dd>{formatCurrency(data.discount_total)}</dd>
          <dt>Base imponible</dt>
          <dd>{formatCurrency(data.base_amount)}</dd>
          <dt>IGV</dt>
          <dd>{formatCurrency(data.igv_amount)}</dd>
          <dt>Total</dt>
          <dd>
            <strong>{formatCurrency(data.total)}</strong>
          </dd>
        </dl>
        <p className="muted">
          {data.prices_include_igv ? 'Los precios incluyen IGV.' : 'Los precios no incluyen IGV.'}
        </p>

        {canManage && (
          <div className="form-actions">
            {isDraft && (
              <>
                <button type="button" className="btn" onClick={() => setConfirmIssue(true)} disabled={issue.isPending}>
                  Emitir presupuesto
                </button>
                <button type="button" className="btn btn-secondary" onClick={() => setConfirmDelete(true)}>
                  Eliminar borrador
                </button>
              </>
            )}
            {data.status === 'emitido' && (
              <button
                type="button"
                className="btn btn-secondary"
                onClick={() => correct.mutate()}
                disabled={correct.isPending}
              >
                Corregir
              </button>
            )}
          </div>
        )}
      </div>

      {issued && (
        <section className="card" aria-labelledby="pdf-title">
          <h2 id="pdf-title">Documento PDF</h2>
          {pdf.isError && (
            <div className="alert alert-error" role="alert">
              {generalError(pdf.error)}
            </div>
          )}
          {pdf.isSuccess && (
            <div className="flex flex-wrap items-center gap-3">
              <span className={pdf.data.status === 'listo' ? 'badge' : 'badge badge-muted'}>
                {PDF_STATUS[pdf.data.status] ?? 'Sin PDF'}
              </span>
              {pdf.data.url && (
                <a href={pdf.data.url} target="_blank" rel="noopener noreferrer">
                  Descargar PDF (enlace válido 10 minutos)
                </a>
              )}
              {pdf.data.status === 'fallido' && canManage && (
                <button
                  type="button"
                  className="btn btn-secondary"
                  onClick={() => regenerate.mutate()}
                  disabled={regenerate.isPending}
                >
                  Regenerar PDF
                </button>
              )}
            </div>
          )}
        </section>
      )}

      {conflict && data.status !== 'emitido' && (
        <div className="alert alert-error" role="alert">
          {conflict}
        </div>
      )}

      {data.decision && (
        <section className="card" aria-labelledby="decided-title">
          <h2 id="decided-title">Decisión registrada</h2>
          <dl className="details">
            <dt>Decisión</dt>
            <dd>{BUDGET_STATUS[data.status]}</dd>
            <dt>Canal</dt>
            <dd>{DECISION_CHANNELS[data.decision.channel] ?? data.decision.channel}</dd>
            <dt>Decidió</dt>
            <dd>{SIGNERS[data.decision.signer]}</dd>
            <dt>Registrado por</dt>
            <dd>
              {data.decision.by?.name ?? '—'} · {formatDateTime(data.decision.decided_at)}
            </dd>
            {data.decision.rejection_reason && (
              <>
                <dt>Motivo</dt>
                <dd>
                  {REJECTION_REASONS[data.decision.rejection_reason]}
                  {data.decision.rejection_detail ? ` · ${data.decision.rejection_detail}` : ''}
                </dd>
              </>
            )}
          </dl>
        </section>
      )}

      {data.status === 'emitido' && DECISION_ROLES.includes(user?.role) && patient.data && (
        <DecisionForm
          budget={data}
          patient={patient.data}
          onDecided={afterChange}
          onConflict={(message) => {
            setConflict(message)
            queryClient.invalidateQueries({ queryKey })
          }}
        />
      )}

      {confirmIssue && (
        <ConfirmDialog
          title="¿Emitir el presupuesto?"
          confirmLabel="Confirmar emisión"
          onConfirm={() => issue.mutate()}
          onCancel={() => setConfirmIssue(false)}
          busy={issue.isPending}
        >
          <p>
            Se asignará el número correlativo de la clínica, se congelarán los precios vigentes del catálogo y se fijará
            su vencimiento. Un presupuesto emitido no se puede modificar: para cambiarlo se corrige con uno nuevo.
          </p>
        </ConfirmDialog>
      )}

      {confirmDelete && (
        <ConfirmDialog
          title="¿Eliminar el borrador?"
          confirmLabel="Eliminar borrador"
          destructive
          onConfirm={() => remove.mutate()}
          onCancel={() => setConfirmDelete(false)}
          busy={remove.isPending}
          error={generalError(remove.error)}
        >
          <p>El borrador y sus líneas se eliminan. Los ítems del plan no cambian.</p>
        </ConfirmDialog>
      )}
    </>
  )
}
