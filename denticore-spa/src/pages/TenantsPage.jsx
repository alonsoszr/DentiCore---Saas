import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { apiClient } from '../api/client'
import { adminPath } from '../auth/paths'
import { fieldErrors, generalError } from '../api/errors'
import { Field } from '../components/Field'
import { PageHeader } from '../components/PageHeader'
import { formatDate } from '../ui/format'

const PLAN_LABELS = { basic: 'Básico', pro: 'Pro', enterprise: 'Enterprise' }
const STATUS_LABELS = { activa: 'Activa', suspendida: 'Suspendida', cancelada: 'Cancelada', eliminada: 'Eliminada' }

const EMPTY_FORM = {
  name: '',
  legal_name: '',
  ruc: '',
  slug: '',
  address: '',
  subscription_plan: 'basic',
  admin_name: '',
  admin_email: '',
}

function slugify(text) {
  return text
    .normalize('NFD')
    .replace(/\p{Diacritic}/gu, '')
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
}

export function TenantsPage() {
  const queryClient = useQueryClient()
  const [showForm, setShowForm] = useState(false)
  const [form, setForm] = useState(EMPTY_FORM)
  const [slugEdited, setSlugEdited] = useState(false)
  const [created, setCreated] = useState(null)
  const [search, setSearch] = useState('')
  const [term, setTerm] = useState('')

  // RF-018: búsqueda por nombre, razón social o RUC.
  const tenantsQuery = useQuery({
    queryKey: ['tenants', term],
    queryFn: async () =>
      (await apiClient.get('/platform/tenants', { params: term ? { q: term } : undefined })).data.data,
  })

  const createTenant = useMutation({
    mutationFn: async (payload) => (await apiClient.post('/platform/tenants', payload)).data.data,
    onSuccess: (tenant, payload) => {
      queryClient.invalidateQueries({ queryKey: ['tenants'] })
      setCreated({ ...tenant, adminEmail: payload.admin.email })
      setForm(EMPTY_FORM)
      setSlugEdited(false)
      setShowForm(false)
    },
  })

  const handleChange = (event) => {
    const { name, value } = event.target
    if (name === 'name' && !slugEdited) {
      setForm({ ...form, name: value, slug: slugify(value) })
      return
    }
    if (name === 'slug') setSlugEdited(true)
    setForm({ ...form, [name]: value })
  }

  const handleSubmit = (event) => {
    event.preventDefault()
    setCreated(null)
    // DD-22: el administrador no recibe contraseña; la define al activar su cuenta.
    createTenant.mutate({
      name: form.name,
      legal_name: form.legal_name,
      ruc: form.ruc,
      slug: form.slug,
      address: form.address,
      subscription_plan: form.subscription_plan,
      admin: { name: form.admin_name, email: form.admin_email },
    })
  }

  const errors = fieldErrors(createTenant.error)
  const tenants = tenantsQuery.data ?? []

  return (
    <>
      <PageHeader title="Clínicas" description="Clínicas registradas en la plataforma.">
        {!showForm && (
          <button type="button" className="btn" onClick={() => setShowForm(true)}>
            Registrar clínica
          </button>
        )}
      </PageHeader>

      {created && (
        <div className="alert alert-success">
          Clínica «{created.name}» registrada con el código <strong>{created.slug}</strong>. Se envió la invitación de
          activación a <strong>{created.adminEmail}</strong> (vence en 72 horas).
        </div>
      )}

      {showForm && (
        <div className="card">
          <h2>Nueva clínica</h2>
          {generalError(createTenant.error) && (
            <div className="alert alert-error">{generalError(createTenant.error)}</div>
          )}
          <form onSubmit={handleSubmit} noValidate autoComplete="off">
            <div className="form-grid">
              <Field
                label="Nombre comercial"
                name="name"
                autoComplete="off"
                value={form.name}
                onChange={handleChange}
                error={errors.name}
              />
              <Field
                label="Razón social"
                name="legal_name"
                autoComplete="off"
                value={form.legal_name}
                onChange={handleChange}
                error={errors.legal_name}
              />
              <Field
                label="RUC"
                name="ruc"
                autoComplete="off"
                inputMode="numeric"
                maxLength={11}
                value={form.ruc}
                onChange={handleChange}
                error={errors.ruc}
              />
              <Field
                label="Código de acceso"
                name="slug"
                autoComplete="off"
                value={form.slug}
                onChange={handleChange}
                error={errors.slug}
                hint="Lo usará el personal de la clínica para iniciar sesión. No se puede cambiar después."
              />
              <Field
                label="Dirección"
                name="address"
                autoComplete="off"
                value={form.address}
                onChange={handleChange}
                error={errors.address}
              />
              <Field label="Plan" name="subscription_plan" error={errors.subscription_plan}>
                <select
                  id="subscription_plan"
                  name="subscription_plan"
                  value={form.subscription_plan}
                  onChange={handleChange}
                >
                  {Object.entries(PLAN_LABELS).map(([value, label]) => (
                    <option key={value} value={value}>
                      {label}
                    </option>
                  ))}
                </select>
              </Field>
            </div>

            <h3 className="form-section">Primer administrador de la clínica</h3>
            <div className="form-grid">
              <Field
                label="Nombre"
                name="admin_name"
                autoComplete="off"
                value={form.admin_name}
                onChange={handleChange}
                error={errors['admin.name']}
              />
              <Field
                label="Correo electrónico"
                name="admin_email"
                autoComplete="off"
                type="email"
                value={form.admin_email}
                onChange={handleChange}
                error={errors['admin.email']}
              />
            </div>

            <div className="form-actions">
              <button type="submit" className="btn" disabled={createTenant.isPending}>
                {createTenant.isPending ? 'Guardando…' : 'Guardar'}
              </button>
              <button
                type="button"
                className="btn btn-secondary"
                onClick={() => {
                  setShowForm(false)
                  createTenant.reset()
                }}
              >
                Cancelar
              </button>
            </div>
          </form>
        </div>
      )}

      <div className="card">
        <form
          className="form-actions"
          role="search"
          onSubmit={(event) => {
            event.preventDefault()
            setTerm(search.trim())
          }}
        >
          <Field
            label="Buscar clínica"
            name="q"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            hint="Nombre, razón social o RUC."
          />
          <button type="submit" className="btn btn-secondary">
            Buscar
          </button>
        </form>
        {tenantsQuery.isLoading && <div className="empty">Cargando…</div>}
        {tenantsQuery.isError && <div className="alert alert-error">{generalError(tenantsQuery.error)}</div>}
        {tenantsQuery.isSuccess && tenants.length === 0 && (
          <div className="empty">Aún no hay clínicas registradas.</div>
        )}
        {tenants.length > 0 && (
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Nombre</th>
                  <th>Código de acceso</th>
                  <th>Plan</th>
                  <th>Odontólogos activos</th>
                  <th>Estado</th>
                  <th>Registrada</th>
                </tr>
              </thead>
              <tbody>
                {tenants.map((tenant) => (
                  <tr key={tenant.id}>
                    <td>
                      <Link to={adminPath(`/clinicas/${tenant.id}`)}>{tenant.name}</Link>
                    </td>
                    <td className="muted">{tenant.slug}</td>
                    <td>{PLAN_LABELS[tenant.plan?.code]}</td>
                    <td>{tenant.active_dentists}</td>
                    <td>
                      <span className={tenant.status === 'activa' ? 'badge' : 'badge badge-muted'}>
                        {STATUS_LABELS[tenant.status]}
                      </span>
                    </td>
                    <td className="muted">{formatDate(tenant.created_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </>
  )
}
