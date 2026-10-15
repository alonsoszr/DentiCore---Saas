import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { apiClient } from '../api/client'
import { fieldErrors, generalError } from '../api/errors'
import { adminPath } from '../auth/paths'
import { ConfirmDialog } from '../components/ConfirmDialog'
import { Field } from '../components/Field'
import { PageHeader } from '../components/PageHeader'
import { useFocusFirstError } from '../ui/useFocusFirstError'
import { formatDate } from '../ui/format'
import { PLAN_LABELS } from './tenantLabels'
import { TenantStatusBadge } from './TenantStatusBadge'
const ADMIN_STATUS_LABELS = {
  pendiente_activacion: 'Pendiente de activación',
  activo: 'Activo',
  bloqueado_temporal: 'Bloqueado temporalmente',
  inactivo: 'Inactivo',
}

/**
 * Detalle de una clínica (CUS-02, CUS-03, CUS-04): datos, administrador, suspensión y
 * reactivación con motivo y confirmación (RNF-063), y cambio de plan (RF-022). Pantalla
 * funcional de MS-01; el diseño definitivo llega con docs/DESIGN.md.
 */
export function TenantDetailPage() {
  const { uuid } = useParams()
  const queryClient = useQueryClient()
  const [reason, setReason] = useState('')
  const [plan, setPlan] = useState('')
  const [notice, setNotice] = useState(null)
  const [confirming, setConfirming] = useState(false)

  const tenantQuery = useQuery({
    queryKey: ['tenants', 'detail', uuid],
    queryFn: async () => (await apiClient.get(`/platform/tenants/${uuid}`)).data.data,
  })
  const plansQuery = useQuery({
    queryKey: ['plans'],
    queryFn: async () => (await apiClient.get('/platform/plans')).data.data,
  })

  const onUpdated = (message) => (tenant) => {
    queryClient.setQueryData(['tenants', 'detail', uuid], tenant)
    queryClient.invalidateQueries({ queryKey: ['tenants'] })
    setNotice(message)
    setReason('')
  }

  const changeStatus = useMutation({
    mutationFn: async ({ action, reason: text }) =>
      (await apiClient.post(`/platform/tenants/${uuid}/${action}`, { reason: text })).data.data,
    onSuccess: (tenant, { action }) => {
      setConfirming(false)
      onUpdated(action === 'suspend' ? 'Clínica suspendida.' : 'Clínica reactivada.')(tenant)
    },
    // Un 422 del motivo se muestra junto al campo: el diálogo se cierra para verlo.
    onError: (error) => error.response?.status === 422 && setConfirming(false),
  })
  const changePlan = useMutation({
    mutationFn: async (code) =>
      (await apiClient.put(`/platform/tenants/${uuid}/plan`, { subscription_plan: code })).data.data,
    onSuccess: onUpdated('Plan actualizado.'),
  })
  const resendInvitation = useMutation({
    mutationFn: () => apiClient.post(`/platform/tenants/${uuid}/admin-invitation`),
    onSuccess: () => setNotice('Se reenvió la invitación al administrador (vence en 72 horas).'),
  })
  useFocusFirstError(changeStatus.error)
  useFocusFirstError(changePlan.error)

  const tenant = tenantQuery.data
  if (tenantQuery.isLoading) return <div className="empty">Cargando…</div>
  if (tenantQuery.isError)
    return (
      <div className="alert alert-error" role="alert">
        {generalError(tenantQuery.error)}
      </div>
    )

  const suspended = tenant.status === 'suspendida'
  const statusErrors = fieldErrors(changeStatus.error)
  const planErrors = fieldErrors(changePlan.error)
  const maxDentists = tenant.plan?.max_dentists

  // RNF-063: la acción pide confirmación con sus efectos en un diálogo del sistema de diseño.
  const handleStatus = (event) => {
    event.preventDefault()
    setNotice(null)
    changeStatus.reset()
    setConfirming(true)
  }

  const handlePlan = (event) => {
    event.preventDefault()
    setNotice(null)
    changePlan.mutate(plan)
  }

  return (
    <>
      <PageHeader title={tenant.name} description={`Código de acceso: ${tenant.slug}`}>
        <Link to={adminPath('/clinicas')} className="btn btn-secondary">
          Volver
        </Link>
      </PageHeader>

      {notice && (
        <div className="alert alert-success" role="status">
          {notice}
        </div>
      )}

      <div className="card">
        <dl className="details">
          <div>
            <dt>Razón social</dt>
            <dd>{tenant.legal_name}</dd>
          </div>
          <div>
            <dt>RUC</dt>
            <dd>{tenant.ruc}</dd>
          </div>
          <div>
            <dt>Dirección</dt>
            <dd>{tenant.address}</dd>
          </div>
          <div>
            <dt>Estado</dt>
            <dd>
              <TenantStatusBadge status={tenant.status} />
            </dd>
          </div>
          {tenant.status_reason && (
            <div>
              <dt>Motivo</dt>
              <dd>{tenant.status_reason}</dd>
            </div>
          )}
          <div>
            <dt>Plan</dt>
            <dd>{PLAN_LABELS[tenant.plan?.code] ?? tenant.plan?.name}</dd>
          </div>
          <div>
            <dt>Odontólogos activos</dt>
            <dd>{maxDentists ? `${tenant.active_dentists} de ${maxDentists}` : tenant.active_dentists}</dd>
          </div>
          <div>
            <dt>Registrada</dt>
            <dd>{formatDate(tenant.created_at)}</dd>
          </div>
        </dl>
      </div>

      {tenant.admin && (
        <div className="card">
          <h2>Primer administrador</h2>
          <dl className="details">
            <div>
              <dt>Nombre</dt>
              <dd>{tenant.admin.name}</dd>
            </div>
            <div>
              <dt>Correo</dt>
              <dd>{tenant.admin.email}</dd>
            </div>
            <div>
              <dt>Estado</dt>
              <dd>{ADMIN_STATUS_LABELS[tenant.admin.status] ?? tenant.admin.status}</dd>
            </div>
          </dl>
          {tenant.admin.status === 'pendiente_activacion' && (
            <div className="form-actions">
              {generalError(resendInvitation.error) && (
                <div className="alert alert-error" role="alert">
                  {generalError(resendInvitation.error)}
                </div>
              )}
              <button
                type="button"
                className="btn btn-secondary"
                disabled={resendInvitation.isPending}
                onClick={() => {
                  setNotice(null)
                  resendInvitation.mutate()
                }}
              >
                Reenviar invitación
              </button>
            </div>
          )}
        </div>
      )}

      <div className="card">
        <h2>Cambiar plan</h2>
        {generalError(changePlan.error) && (
          <div className="alert alert-error" role="alert">
            {generalError(changePlan.error)}
          </div>
        )}
        <form onSubmit={handlePlan} noValidate>
          <Field label="Nuevo plan" name="subscription_plan" error={planErrors.subscription_plan}>
            <select id="subscription_plan" value={plan} onChange={(event) => setPlan(event.target.value)}>
              <option value="">Selecciona…</option>
              {(plansQuery.data ?? []).map((option) => (
                <option key={option.code} value={option.code} disabled={option.code === tenant.plan?.code}>
                  {PLAN_LABELS[option.code] ?? option.name}
                  {option.max_dentists ? ` (hasta ${option.max_dentists} odontólogos)` : ' (odontólogos ilimitados)'}
                </option>
              ))}
            </select>
          </Field>
          <div className="form-actions">
            <button type="submit" className="btn" disabled={!plan || changePlan.isPending}>
              Cambiar plan
            </button>
          </div>
        </form>
      </div>

      <div className="card">
        <h2>{suspended ? 'Reactivar clínica' : 'Suspender clínica'}</h2>
        {!confirming && generalError(changeStatus.error) && (
          <div className="alert alert-error" role="alert">
            {generalError(changeStatus.error)}
          </div>
        )}
        <form onSubmit={handleStatus} noValidate>
          <Field
            label={suspended ? 'Motivo de la reactivación' : 'Motivo de la suspensión'}
            name="reason"
            value={reason}
            onChange={(event) => setReason(event.target.value)}
            error={statusErrors.reason}
            maxLength={500}
          />
          <div className="form-actions">
            <button type="submit" className={suspended ? 'btn' : 'btn btn-danger'} disabled={changeStatus.isPending}>
              {suspended ? 'Reactivar clínica' : 'Suspender clínica'}
            </button>
          </div>
        </form>
      </div>

      {confirming && (
        <ConfirmDialog
          title={suspended ? `¿Reactivar «${tenant.name}»?` : `¿Suspender «${tenant.name}»?`}
          confirmLabel={suspended ? 'Reactivar clínica' : 'Suspender clínica'}
          destructive={!suspended}
          busy={changeStatus.isPending}
          error={generalError(changeStatus.error)}
          onCancel={() => setConfirming(false)}
          onConfirm={() => changeStatus.mutate({ action: suspended ? 'reactivate' : 'suspend', reason })}
        >
          <p>
            {suspended
              ? 'Su personal volverá a registrar información.'
              : 'Su personal solo podrá consultar la información hasta la reactivación.'}
          </p>
        </ConfirmDialog>
      )}
    </>
  )
}
