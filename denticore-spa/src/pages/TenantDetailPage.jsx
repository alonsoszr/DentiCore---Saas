import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { apiClient } from '../api/client'
import { fieldErrors, generalError } from '../api/errors'
import { adminPath } from '../auth/paths'
import { Field } from '../components/Field'
import { PageHeader } from '../components/PageHeader'
import { formatDate } from '../ui/format'

const STATUS_LABELS = { activa: 'Activa', suspendida: 'Suspendida', cancelada: 'Cancelada', eliminada: 'Eliminada' }
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
    onSuccess: (tenant, { action }) =>
      onUpdated(action === 'suspend' ? 'Clínica suspendida.' : 'Clínica reactivada.')(tenant),
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

  const tenant = tenantQuery.data
  if (tenantQuery.isLoading) return <div className="empty">Cargando…</div>
  if (tenantQuery.isError) return <div className="alert alert-error">{generalError(tenantQuery.error)}</div>

  const suspended = tenant.status === 'suspendida'
  const statusErrors = fieldErrors(changeStatus.error)
  const planErrors = fieldErrors(changePlan.error)
  const maxDentists = tenant.plan?.max_dentists

  const handleStatus = (event) => {
    event.preventDefault()
    setNotice(null)
    const action = suspended ? 'reactivate' : 'suspend'
    // RNF-063: la acción pide confirmación con sus efectos.
    const question = suspended
      ? `¿Reactivar «${tenant.name}»? Su personal volverá a registrar información.`
      : `¿Suspender «${tenant.name}»? Su personal solo podrá consultar la información hasta la reactivación.`
    if (window.confirm(question)) {
      changeStatus.mutate({ action, reason })
    }
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

      {notice && <div className="alert alert-success">{notice}</div>}

      <div className="card">
        <dl className="details">
          <dt>Razón social</dt>
          <dd>{tenant.legal_name}</dd>
          <dt>RUC</dt>
          <dd>{tenant.ruc}</dd>
          <dt>Dirección</dt>
          <dd>{tenant.address}</dd>
          <dt>Estado</dt>
          <dd>
            <span className={suspended ? 'badge badge-muted' : 'badge'}>{STATUS_LABELS[tenant.status]}</span>
          </dd>
          {tenant.status_reason && (
            <>
              <dt>Motivo</dt>
              <dd>{tenant.status_reason}</dd>
            </>
          )}
          <dt>Plan</dt>
          <dd>{tenant.plan?.name}</dd>
          <dt>Odontólogos activos</dt>
          <dd>{maxDentists ? `${tenant.active_dentists} de ${maxDentists}` : tenant.active_dentists}</dd>
          <dt>Registrada</dt>
          <dd>{formatDate(tenant.created_at)}</dd>
        </dl>
      </div>

      {tenant.admin && (
        <div className="card">
          <h2>Primer administrador</h2>
          <dl className="details">
            <dt>Nombre</dt>
            <dd>{tenant.admin.name}</dd>
            <dt>Correo</dt>
            <dd>{tenant.admin.email}</dd>
            <dt>Estado</dt>
            <dd>{ADMIN_STATUS_LABELS[tenant.admin.status] ?? tenant.admin.status}</dd>
          </dl>
          {tenant.admin.status === 'pendiente_activacion' && (
            <div className="form-actions">
              {generalError(resendInvitation.error) && (
                <div className="alert alert-error">{generalError(resendInvitation.error)}</div>
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
        {generalError(changePlan.error) && <div className="alert alert-error">{generalError(changePlan.error)}</div>}
        <form onSubmit={handlePlan} noValidate>
          <Field label="Nuevo plan" name="subscription_plan" error={planErrors.subscription_plan}>
            <select id="subscription_plan" value={plan} onChange={(event) => setPlan(event.target.value)}>
              <option value="">Selecciona…</option>
              {(plansQuery.data ?? []).map((option) => (
                <option key={option.code} value={option.code} disabled={option.code === tenant.plan?.code}>
                  {option.name}
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
        {generalError(changeStatus.error) && (
          <div className="alert alert-error">{generalError(changeStatus.error)}</div>
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
            <button type="submit" className="btn" disabled={changeStatus.isPending}>
              {suspended ? 'Reactivar clínica' : 'Suspender clínica'}
            </button>
          </div>
        </form>
      </div>
    </>
  )
}
