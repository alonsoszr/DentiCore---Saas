import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiClient } from '../api/client'
import { fieldErrors, generalError } from '../api/errors'
import { ROLE_LABELS, TENANT_ROLES } from '../auth/roles'
import { useAuth } from '../auth/useAuth'
import { Field } from '../components/Field'
import { PageHeader } from '../components/PageHeader'

const EMPTY_FORM = { name: '', email: '', password: '', role: 'dentist', is_active: true }

export function UsersPage() {
  const { user: currentUser } = useAuth()
  const queryClient = useQueryClient()
  // null = formulario cerrado; { uuid: null } = alta; { uuid } = edición.
  const [editing, setEditing] = useState(null)
  const [form, setForm] = useState(EMPTY_FORM)
  const [notice, setNotice] = useState(null)

  const usersQuery = useQuery({
    queryKey: ['users'],
    queryFn: async () => (await apiClient.get('/users')).data.data,
  })

  const saveUser = useMutation({
    mutationFn: async ({ uuid, payload }) => {
      const response = uuid
        ? await apiClient.patch(`/users/${uuid}`, payload)
        : await apiClient.post('/users', payload)
      return response.data.data
    },
    onSuccess: (user, { uuid }) => {
      queryClient.invalidateQueries({ queryKey: ['users'] })
      setNotice(uuid ? `Se actualizó a ${user.name}.` : `Se creó la cuenta de ${user.name}.`)
      closeForm()
    },
  })

  const openCreate = () => {
    saveUser.reset()
    setNotice(null)
    setForm(EMPTY_FORM)
    setEditing({ uuid: null })
  }

  const openEdit = (user) => {
    saveUser.reset()
    setNotice(null)
    setForm({ name: user.name, email: user.email, password: '', role: user.role, is_active: user.is_active })
    setEditing({ uuid: user.uuid })
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
    const payload = { ...form }
    // En edición, la contraseña vacía significa "no cambiarla".
    if (editing.uuid && !payload.password) delete payload.password
    saveUser.mutate({ uuid: editing.uuid, payload })
  }

  const errors = fieldErrors(saveUser.error)
  const users = usersQuery.data ?? []
  const isEditing = Boolean(editing?.uuid)

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

      {editing && (
        <div className="card">
          <h2>{isEditing ? 'Editar usuario' : 'Nuevo usuario'}</h2>
          {generalError(saveUser.error) && <div className="alert alert-error">{generalError(saveUser.error)}</div>}
          <form onSubmit={handleSubmit} noValidate autoComplete="off">
            <div className="form-grid">
              <Field label="Nombre" name="name" autoComplete="off" value={form.name} onChange={handleChange} error={errors.name} />
              <Field
                label="Correo electrónico"
                name="email"
                autoComplete="off"
                type="email"
                value={form.email}
                onChange={handleChange}
                error={errors.email}
              />
              <Field
                label="Contraseña"
                name="password"
                type="password"
                value={form.password}
                onChange={handleChange}
                error={errors.password}
                autoComplete="new-password"
                hint={isEditing ? 'Déjala vacía para no cambiarla.' : 'Mínimo 8 caracteres.'}
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
            </div>
            <label className="checkbox" style={{ marginTop: 14 }}>
              <input type="checkbox" name="is_active" checked={form.is_active} onChange={handleChange} />
              Cuenta activa
              {isEditing && <span className="muted">(al desactivarla se cierran sus sesiones abiertas)</span>}
            </label>
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
                  <tr key={user.uuid}>
                    <td>
                      {user.name}
                      {user.uuid === currentUser.uuid && <span className="muted"> (tú)</span>}
                    </td>
                    <td className="muted">{user.email}</td>
                    <td>{ROLE_LABELS[user.role]}</td>
                    <td>
                      <span className={user.is_active ? 'badge' : 'badge badge-muted'}>
                        {user.is_active ? 'Activo' : 'Inactivo'}
                      </span>
                    </td>
                    <td style={{ textAlign: 'right' }}>
                      <button type="button" className="btn-link" onClick={() => openEdit(user)}>
                        Editar
                      </button>
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
