import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { apiClient } from '../api/client'
import { fieldErrors, generalError } from '../api/errors'
import { useClinic } from '../auth/useClinic'
import { Alert } from '../components/Alert'
import { ConfirmDialog } from '../components/ConfirmDialog'
import { Field } from '../components/Field'
import { StatusBadge } from '../components/StatusBadge'
import { REJECTION_REASONS, siteText } from '../pages/treatment/labels'
import { BudgetStatusBadge } from '../pages/treatment/TreatmentStatusBadges'
import { useFocusOnMount } from '../pages/treatment/useTreatment'
import { formatCurrency, formatDate, formatDateTime } from '../ui/format'
import { FileCheckIcon, FileClockIcon, FileXIcon } from '../ui/icons'
import { useTimeZone } from '../ui/useTimeZone'
import { usePortalSubject } from './portalQueries'

const PDF = {
  pendiente: ['neutral', FileClockIcon, 'PDF en generación'],
  generando: ['neutral', FileClockIcon, 'PDF en generación'],
  listo: ['secondary', FileCheckIcon, 'PDF listo'],
  fallido: ['error', FileXIcon, 'No se pudo generar el PDF.'],
}

/** Estado del PDF y su enlace firmado de 10 minutos (RF-121). */
function BudgetPdf({ budgetId }) {
  const { slug } = useClinic()
  const pdf = useQuery({
    queryKey: ['portal-budget-pdf', slug, budgetId],
    queryFn: async () => (await apiClient.get(`/portal/budgets/${budgetId}/pdf`)).data.data,
    // En generación se consulta cada 3 s; listo, la URL firmada se renueva antes de sus 10 minutos.
    refetchInterval: (query) => {
      const status = query.state.data?.status
      if (['pendiente', 'generando'].includes(status)) return 3000
      return status === 'listo' ? 9 * 60 * 1000 : false
    },
  })

  if (pdf.isError) {
    return (
      <div className="alert alert-error" role="alert">
        {generalError(pdf.error)}
      </div>
    )
  }
  if (!pdf.isSuccess || !pdf.data.status) return null
  const [tone, Icon, label] = PDF[pdf.data.status]

  return (
    <div className="flex flex-wrap items-center gap-3">
      <StatusBadge tone={tone} icon={Icon}>
        {label}
      </StatusBadge>
      {pdf.data.url && (
        <a className="btn btn-secondary" href={pdf.data.url} target="_blank" rel="noopener noreferrer">
          Descargar PDF (enlace válido 10 minutos)
        </a>
      )}
    </div>
  )
}

/** CUS-37 paso 2: la casilla de aceptación total y la confirmación. El foco entra a la casilla. */
function AcceptForm({ budget, mutation, onCancel }) {
  const [read, setRead] = useState(false)
  const formRef = useFocusOnMount()

  return (
    <form
      ref={formRef}
      className="portal-decision"
      noValidate
      onSubmit={(event) => {
        event.preventDefault()
        mutation.mutate({ decision: 'aceptado' })
      }}
    >
      <label className="checkbox">
        <input type="checkbox" checked={read} onChange={(event) => setRead(event.target.checked)} />
        He leído el presupuesto N° {budget.number} por {formatCurrency(budget.total)} y lo acepto en su totalidad
      </label>
      <div className="form-actions flex-wrap">
        <button type="submit" className="btn" disabled={!read || mutation.isPending}>
          {mutation.isPending ? 'Enviando…' : 'Confirmar aceptación'}
        </button>
        <button type="button" className="btn btn-secondary" onClick={onCancel} disabled={mutation.isPending}>
          Volver
        </button>
      </div>
    </form>
  )
}

/** CUS-37 FA-1: rechazo con motivo opcional (≤ 200 caracteres); el plan sigue propuesto. */
function RejectDialog({ budget, mutation, onCancel }) {
  const [reason, setReason] = useState('')
  const [detail, setDetail] = useState('')
  const errors = fieldErrors(mutation.error)

  return (
    <ConfirmDialog
      title={`¿Rechazar el presupuesto N° ${budget.number}?`}
      confirmLabel="Rechazar presupuesto"
      cancelLabel="Volver"
      destructive
      busy={mutation.isPending}
      error={errors.rejection_reason || errors.rejection_detail ? null : generalError(mutation.error)}
      onCancel={onCancel}
      onConfirm={() =>
        mutation.mutate({
          decision: 'rechazado',
          rejection_reason: reason || null,
          rejection_detail: detail.trim() || null,
        })
      }
    >
      <p>Tu plan de tratamiento sigue propuesto.</p>
      <Field label="Motivo (opcional)" name="rejection-reason" error={errors.rejection_reason} size="lg">
        <select id="rejection-reason" value={reason} onChange={(event) => setReason(event.target.value)}>
          <option value="">Sin indicar</option>
          {Object.entries(REJECTION_REASONS).map(([value, label]) => (
            <option key={value} value={value}>
              {label}
            </option>
          ))}
        </select>
      </Field>
      <Field label="Detalle (opcional)" name="rejection-detail" error={errors.rejection_detail} size="lg">
        <textarea
          id="rejection-detail"
          rows={3}
          maxLength={200}
          value={detail}
          onChange={(event) => setDetail(event.target.value)}
        />
      </Field>
    </ConfirmDialog>
  )
}

/** Decisión del titular adulto o del representante (CUS-37; RN-12, RN-36). */
function Decision({ budget, onDecided }) {
  const { slug } = useClinic()
  const queryClient = useQueryClient()
  const subjects = usePortalSubject()
  const [mode, setMode] = useState(null)
  // Al volver del formulario de aceptación, el foco regresa a «Aceptar presupuesto».
  const returnFocus = useRef(false)
  const decide = useMutation({
    mutationFn: async (payload) => (await apiClient.post(`/portal/budgets/${budget.id}/decision`, payload)).data.data,
    onSuccess: (data) => {
      setMode(null)
      queryClient.setQueryData(['portal-budget', slug, budget.id], data)
      queryClient.invalidateQueries({ queryKey: ['portal-budgets'] })
      onDecided(data)
    },
    onError: (error) => {
      // FE-1 y FE-2: el presupuesto venció o ya se decidió; se recarga para mostrar su estado.
      if (error.response?.status === 409) {
        setMode(null)
        queryClient.invalidateQueries({ queryKey: ['portal-budget', slug, budget.id] })
      }
    },
  })

  if (!subjects.isSuccess) return null
  const subject = subjects.data.find((item) => item.id === budget.patient_id)

  if (subject?.relationship === 'titular' && subject.is_minor) {
    return (
      <Alert tone="info" size="lg" role={null}>
        La decisión corresponde al representante legal.
      </Alert>
    )
  }

  return (
    <section aria-label="Decisión sobre el presupuesto" className="portal-section">
      {decide.isError && mode === null && (
        <div className="alert alert-error mb-4" role="alert">
          {generalError(decide.error)}
        </div>
      )}
      {mode === 'aceptar' ? (
        <AcceptForm
          budget={budget}
          mutation={decide}
          onCancel={() => {
            returnFocus.current = true
            setMode(null)
          }}
        />
      ) : (
        <div className="form-actions flex-wrap">
          <button
            type="button"
            className="btn"
            ref={(node) => {
              if (node && returnFocus.current) {
                returnFocus.current = false
                node.focus()
              }
            }}
            onClick={() => {
              decide.reset()
              setMode('aceptar')
            }}
          >
            Aceptar presupuesto
          </button>
          <button
            type="button"
            className="btn btn-secondary"
            onClick={() => {
              decide.reset()
              setMode('rechazar')
            }}
          >
            Rechazar
          </button>
        </div>
      )}
      {mode === 'rechazar' && <RejectDialog budget={budget} mutation={decide} onCancel={() => setMode(null)} />}
    </section>
  )
}

function BackLink() {
  const { portalPath } = useClinic()
  return (
    <Link to={portalPath('/presupuestos')} className="portal-back">
      Volver a presupuestos
    </Link>
  )
}

/** /c/:slug/portal/presupuestos/:id (CUS-36, CUS-37). */
export function PortalBudgetPage() {
  const { uuid } = useParams()
  const { slug } = useClinic()
  const timeZone = useTimeZone()
  const [decided, setDecided] = useState(null)
  // Tras decidir, el foco pasa al aviso: el botón que lo tenía ya no existe.
  const decidedRef = useRef(null)
  useEffect(() => {
    if (decided) decidedRef.current?.focus()
  }, [decided])
  const budget = useQuery({
    queryKey: ['portal-budget', slug, uuid],
    queryFn: async () => (await apiClient.get(`/portal/budgets/${uuid}`)).data.data,
  })

  if (!budget.isSuccess) {
    return (
      <>
        <BackLink />
        <h1>Presupuesto</h1>
        {budget.isError ? (
          <div className="alert alert-error" role="alert">
            {generalError(budget.error)}
          </div>
        ) : (
          <div className="empty" role="status">
            Cargando…
          </div>
        )}
      </>
    )
  }

  const data = budget.data
  const subtitle = [
    data.dentist && `${data.dentist.name} · COP ${data.dentist.cop}`,
    data.issued_at && `Emitido el ${formatDate(data.issued_at, timeZone)}`,
  ].filter(Boolean)

  return (
    <>
      <BackLink />
      <div className="page-header">
        <div>
          <h1>Presupuesto N° {data.number}</h1>
          {subtitle.length > 0 && <p>{subtitle.join(' · ')}</p>}
        </div>
        <BudgetStatusBadge status={data.status} />
      </div>

      {decided && (
        <div ref={decidedRef} tabIndex={-1} className="mb-4">
          <Alert
            tone={decided.status === 'aceptado' ? 'success' : 'info'}
            size="lg"
            title={decided.status === 'aceptado' ? 'Presupuesto aceptado' : 'Presupuesto rechazado'}
          >
            {decided.status === 'aceptado' && (
              <>
                Saldo por pagar: <span className="amount">{formatCurrency(decided.total)}</span>.
              </>
            )}
          </Alert>
        </div>
      )}

      <div className="card">
        <ul className="portal-lines">
          {data.lines.map((line) => (
            <li key={line.id}>
              <div className="min-w-0">
                <strong>{line.description}</strong>
                {line.tooth && <div className="muted">{siteText(line.tooth, line.surfaces)}</div>}
                <div className="muted tabular-nums">
                  {line.quantity} × <span className="amount">{formatCurrency(line.unit_price)}</span>
                  {Number(line.discount_pct) > 0 && ` · Descuento ${line.discount_pct} %`}
                </div>
              </div>
              <span className="amount tabular-nums">{formatCurrency(line.subtotal)}</span>
            </li>
          ))}
        </ul>

        <dl className="portal-totals tabular-nums">
          <div>
            <dt>Descuentos</dt>
            <dd className="amount">{formatCurrency(data.discount_total)}</dd>
          </div>
          <div>
            <dt>Base imponible</dt>
            <dd className="amount">{formatCurrency(data.base_amount)}</dd>
          </div>
          <div>
            <dt>IGV</dt>
            <dd className="amount">{formatCurrency(data.igv_amount)}</dd>
          </div>
          <div className="portal-total">
            <dt>Total</dt>
            <dd className="amount">{formatCurrency(data.total)}</dd>
          </div>
        </dl>
        <p className="muted">
          {data.prices_include_igv ? 'Los precios incluyen IGV.' : 'Los precios no incluyen IGV.'}
          {data.status === 'emitido' && data.expires_at && ` Vence el ${formatDate(data.expires_at, timeZone)}.`}
        </p>
        <BudgetPdf budgetId={data.id} />
      </div>

      {data.status === 'emitido' && <Decision budget={data} onDecided={setDecided} />}
      {data.decision && !decided && (
        <p className="muted">
          {data.status === 'aceptado' ? 'Aceptado' : 'Rechazado'} el{' '}
          {formatDateTime(data.decision.decided_at, timeZone)}.
        </p>
      )}
    </>
  )
}
