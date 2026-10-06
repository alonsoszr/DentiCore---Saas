import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiClient } from '../api/client'
import { fieldErrors, generalError } from '../api/errors'
import { ROLE_LABELS, TENANT_ROLES } from '../auth/roles'
import { useAuth } from '../auth/useAuth'
import { useClinic } from '../auth/useClinic'
import { Field } from '../components/Field'
import { PageHeader } from '../components/PageHeader'

const EMPTY_FORM = {
  name: '',
  email: '',
  role: 'dentist',
  cop_number: '',
  specialty: '',
  rne_number: '',
  is_data_officer: false,
}

const STATUS_LABELS = {
  pendiente_activacion: 'Pendiente de activación',
  activo: 'Activo',
  bloqueado_temporal: 'Bloqueado temporalmente',
  inactivo: 'Inactivo',
}

/**
 * Usuarios de la clínica (CUS-11, RF-042 a RF-047). El alta es por invitación (DD-22): el
 * usuario define su contraseña al activar su cuenta. Pantalla funcional de MS-01; el diseño
 * definitivo llega con TASK-040.
 */
export function UsersPage() {
  const { user: currentUser } = useAuth()
  const { slug } = useClinic()
  const queryClient = useQueryClient()
  // null = formulario cerrado; { id: null } = alta; { id } = edición.
  const [editing, setEditing] = useState(null)
  const [form, setForm] = useState(EMPTY_FORM)
  const [notice, setNotice] = useState(null)

  const usersQuery = useQuery({
    queryKey: ['users', slug],
    queryFn: async () => (await apiClient.get('/users', { params: { per_page: 100 } })).data.data,
  })

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['users', slug] })

  const saveUser = useMutation({
    mutationFn: async ({ id, payload }) => {
      const response = id ? await apiClient.patch(`/users/${id}`, payload) : await apiClient.post('/users', payload)
      return response.data.data
    },
    onSuccess: (user, { id }) => {
      refresh()
      setNotice(id ? `Se actualizó a ${user.name}.` : `Se envió la invitación a ${user.email} (vence en 72 horas).`)
      closeForm()
    },
  })

  const userAction = useMutation({
    mutationFn: async ({ user, action }) => (await apiClient.post(`/users/${user.id}/${action}`)).data?.data,
    onSuccess: (_data, { user, action }) => {
      refresh()
      setNotice(
        {
          deactivate: `Se desactivó a ${user.name} y se cerraron sus sesiones.`,
          reactivate: `Se reactivó a ${user.name}.`,
          invitation: `Se reenvió la invitación a ${user.email}.`,
        }[action],
      )
    },
  })

  const openCreate = () => {
    saveUser.reset()
    setNotice(null)
    setForm(EMPTY_FORM)
    setEditing({ id: null })
  }

  const openEdit = (user) => {
    saveUser.reset()
    setNotice(null)
    setForm({
      name: user.name,
      email: user.email,
      role: user.role,
      cop_number: user.cop_number ?? '',
      specialty: user.specialty ?? '',
      rne_number: user.rne_number ?? '',
      is_data_officer: Boolean(user.is_data_officer),
    })
    setEditing({ id: user.id })
  }

  function closeForm() {
    setEditing(null)
    setForm(EMPTY_FORM)
  }

  const handleChange = (event) => {
    const { name, value, type, checked } = event.target
    setForm({ ...form, [name]: type === 'checkbox' ? checked : value })
  }

  const handleSubmit = (event) => {
    event.preventDefault()
    const payload = { name: form.name, email: form.email, role: form.role }
    // RN-75, RF-043: COP, especialidad y RNE solo aplican a odontólogos.
    if (form.role === 'dentist') {
      if (form.cop_number) payload.cop_number = form.cop_number
      payload.specialty = form.specialty || null
      payload.rne_number = form.rne_number || null
    }
    // RF-047: el Oficial de Datos Personales es un Administrador de Clínica.
    if (form.role === 'clinic_admin') payload.is_data_officer = form.is_data_officer
    saveUser.mutate({ id: editing.id, payload })
  }

  const deactivate = (user) => {
    // RNF-063: la acción pide confirmación con sus efectos.
    if (window.confirm(`¿Desactivar a ${user.name}? Se cerrarán todas sus sesiones abiertas.`)) {
      userAction.mutate({ user, action: 'deactivate' })
    }
  }

  const errors = fieldErrors(saveUser.error)
  const users = usersQuery.data ?? []
  const isEditing = Boolean(editing?.id)
  const actionError = generalError(userAction.error)

  return (
    <>
      <PageHeader title="Usuarios" description="Personal y cuentas de pacientes de tu clínica.">
        {!editing && (
          <button type="button" className="btn" onClick={openCreate}>
            Nuevo usuario
          </button>
        )}
      </PageHeader>

      {notice && <div className="alert alert-success">{notice}</div>}
      {actionError && <div className="alert alert-error">{actionError}</div>}

      {editing && (
        <div className="card">
          <h2>{isEditing ? 'Editar usuario' : 'Nuevo usuario'}</h2>
          {generalError(saveUser.error) && <div className="alert alert-error">{generalError(saveUser.error)}</div>}
          <form onSubmit={handleSubmit} noValidate autoComplete="off">
            <div className="form-grid">
              <Field
                label="Nombre"
                name="name"
                autoComplete="off"
                value={form.name}
                onChange={handleChange}
                error={errors.name}
              />
              <Field
                label="Correo electrónico"
                name="email"
                autoComplete="off"
                type="email"
                value={form.email}
                onChange={handleChange}
                error={errors.email}
                hint={isEditing ? undefined : 'Recibirá una invitación para crear su contraseña.'}
              />
              <Field label="Rol" name="role" error={errors.role}>
                <select id="role" name="role" value={form.role} onChange={handleChange}>
                  {TENANT_ROLES.map((role) => (
                    <option key={role} value={role}>
                      {ROLE_LABELS[role]}
                    </option>
                  ))}
                </select>
              </Field>
              {form.role === 'dentist' && (
                <>
                  <Field
                    label="Número de COP"
                    name="cop_number"
                    value={form.cop_number}
                    onChange={handleChange}
                    error={errors.cop_number}
                    hint="Colegio Odontológico del Perú."
                  />
                  <Field
                    label="Especialidad"
                    name="specialty"
                    value={form.specialty}
                    onChange={handleChange}
                    error={errors.specialty}
                  />
                  <Field
                    label="Número de RNE"
                    name="rne_number"
                    value={form.rne_number}
                    onChange={handleChange}
                    error={errors.rne_number}
                    hint="Registro Nacional de Especialista."
                  />
                </>
              )}
            </div>
            {form.role === 'clinic_admin' && (
              <label className="checkbox" style={{ marginTop: 14 }}>
                <input type="checkbox" name="is_data_officer" checked={form.is_data_officer} onChange={handleChange} />
                Oficial de Datos Personales
              </label>
            )}
            {errors.is_data_officer && <div className="alert alert-error">{errors.is_data_officer}</div>}
            <div className="form-actions">
              <button type="submit" className="btn" disabled={saveUser.isPending}>
                {saveUser.isPending ? 'Guardando…' : 'Guardar'}
              </button>
              <button type="button" className="btn btn-secondary" onClick={closeForm}>
                Cancelar
              </button>
            </div>
          </form>
        </div>
      )}

      <div className="card">
        {usersQuery.isLoading && <div className="empty">Cargando…</div>}
        {usersQuery.isError && <div className="alert alert-error">{generalError(usersQuery.error)}</div>}
        {users.length > 0 && (
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Nombre</th>
                  <th>Correo</th>
                  <th>Rol</th>
                  <th>Estado</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {users.map((user) => (
                  <tr key={user.id}>
                    <td>
                      {user.name}
                      {user.id === currentUser.id && <span className="muted"> (tú)</span>}
                      {user.is_data_officer && <span className="muted"> · Oficial de datos</span>}
                    </td>
                    <td className="muted">{user.email}</td>
                    <td>{ROLE_LABELS[user.role]}</td>
                    <td>
                      <span className={user.status === 'activo' ? 'badge' : 'badge badge-muted'}>
                        {STATUS_LABELS[user.status] ?? user.status}
                      </span>
                    </td>
                    <td style={{ textAlign: 'right', display: 'flex', gap: 12, justifyContent: 'flex-end' }}>
                      <button type="button" className="btn-link" onClick={() => openEdit(user)}>
                        Editar
                      </button>
                      {user.status === 'pendiente_activacion' && (
                        <button
                          type="button"
                          className="btn-link"
                          onClick={() => userAction.mutate({ user, action: 'invitation' })}
                        >
                          Reenviar invitación
                        </button>
                      )}
                      {user.status === 'inactivo' ? (
                        <button
                          type="button"
                          className="btn-link"
                          onClick={() => userAction.mutate({ user, action: 'reactivate' })}
                        >
                          Reactivar
                        </button>
                      ) : (
                        user.id !== currentUser.id && (
                          <button type="button" className="btn-link" onClick={() => deactivate(user)}>
                            Desactivar
                          </button>
                        )
                      )}
                    </td>
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
