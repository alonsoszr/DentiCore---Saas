import { useEffect, useRef, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { apiClient } from '../../api/client'
import { fieldErrors, generalError } from '../../api/errors'
import { useClinic } from '../../auth/useClinic'
import { ActiveBadge } from '../../components/ActiveBadge'
import { Field } from '../../components/Field'
import { useAppointmentTypes } from './useScheduling'

/** Color inicial de un tipo nuevo: el token secundario de DESIGN.md (los tipos guardan su propio color). */
function defaultColor() {
  return getComputedStyle(document.documentElement).getPropertyValue('--color-secondary').trim()
}

const emptyForm = () => ({
  name: '',
  default_duration_min: '30',
  color: defaultColor(),
  portal_visible: false,
  is_active: true,
})

/**
 * Tipos de cita (/c/:slug/app/agenda/tipos; CUS-45; RF-145, RN-47): nombre, duración por defecto de
 * 10 a 240 minutos en múltiplos de 5, color en la agenda y visibilidad en el portal. Un tipo en uso
 * no se elimina: se desactiva.
 */
export function AppointmentTypesPage() {
  const { slug } = useClinic()
  const queryClient = useQueryClient()
  const types = useAppointmentTypes()
  const [editing, setEditing] = useState(null)
  const [form, setForm] = useState(emptyForm)
  const formRef = useRef(null)

  useEffect(() => {
    if (editing !== null) formRef.current?.querySelector('input')?.focus()
  }, [editing])

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['appointment-types', slug] })
  const save = useMutation({
    mutationFn: async (payload) =>
      editing === 'new'
        ? apiClient.post('/appointment-types', payload)
        : apiClient.patch(`/appointment-types/${editing}`, payload),
    onSuccess: () => {
      setEditing(null)
      refresh()
    },
  })
  const toggle = useMutation({
    mutationFn: async (type) => apiClient.patch(`/appointment-types/${type.id}`, { is_active: !type.is_active }),
    onSuccess: refresh,
  })

  const open = (type) => {
    save.reset()
    setForm(
      type
        ? {
            name: type.name,
            default_duration_min: String(type.default_duration_min),
            color: type.color,
            portal_visible: type.portal_visible,
            is_active: type.is_active,
          }
        : emptyForm(),
    )
    setEditing(type ? type.id : 'new')
  }
  const set = (field) => (event) =>
    setForm({ ...form, [field]: event.target.type === 'checkbox' ? event.target.checked : event.target.value })

  const errors = fieldErrors(save.error)
  const list = types.data ?? []

  return (
    <div className="card">
      <div className="page-header">
        <h1 className="m-0">Tipos de cita</h1>
        {editing === null && (
          <button type="button" className="btn" onClick={() => open(null)}>
            Nuevo tipo de cita
          </button>
        )}
      </div>

      {editing !== null && (
        <form
          ref={formRef}
          noValidate
          className="form-section"
          onSubmit={(event) => {
            event.preventDefault()
            save.mutate({ ...form, name: form.name.trim(), default_duration_min: Number(form.default_duration_min) })
          }}
        >
          <h2>{editing === 'new' ? 'Nuevo tipo de cita' : 'Editar tipo de cita'}</h2>
          {generalError(save.error) && (
            <div className="alert alert-error" role="alert">
              {generalError(save.error)}
            </div>
          )}
          <div className="form-grid">
            <Field
              label="Nombre"
              name="type-name"
              value={form.name}
              onChange={set('name')}
              error={errors.name}
              maxLength={80}
            />
            <Field
              label="Duración (minutos)"
              name="type-duration"
              type="number"
              inputMode="numeric"
              min="10"
              max="240"
              step="5"
              value={form.default_duration_min}
              onChange={set('default_duration_min')}
              error={errors.default_duration_min}
              hint="De 10 a 240 minutos, en múltiplos de 5."
            />
            <Field label="Color en la agenda" name="type-color" error={errors.color}>
              <input
                id="type-color"
                type="color"
                value={form.color}
                onChange={set('color')}
                aria-invalid={errors.color ? true : undefined}
                aria-describedby={errors.color ? 'type-color-error' : undefined}
              />
            </Field>
          </div>
          <div className="flex flex-wrap gap-4">
            <label className="checkbox">
              <input type="checkbox" checked={form.portal_visible} onChange={set('portal_visible')} />
              Visible en el portal del paciente
            </label>
            <label className="checkbox">
              <input type="checkbox" checked={form.is_active} onChange={set('is_active')} />
              Activo
            </label>
          </div>
          <div className="form-actions">
            <button type="submit" className="btn" disabled={save.isPending}>
              {editing === 'new' ? 'Crear tipo de cita' : 'Guardar cambios'}
            </button>
            <button type="button" className="btn btn-secondary" onClick={() => setEditing(null)}>
              Cancelar
            </button>
          </div>
        </form>
      )}

      {types.isLoading && <div className="empty">Cargando…</div>}
      {types.isError && (
        <div className="alert alert-error" role="alert">
          {generalError(types.error)}
        </div>
      )}
      {types.isSuccess && list.length === 0 && <div className="empty">La clínica aún no tiene tipos de cita.</div>}
      {list.length > 0 && (
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Tipo</th>
                <th className="text-right">Duración</th>
                <th>Portal</th>
                <th>Estado</th>
                <th>
                  <span className="sr-only">Acciones</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {list.map((type) => (
                <tr key={type.id}>
                  <td>
                    <span className="inline-flex items-center gap-2">
                      <span
                        className="inline-block size-3 rounded-full"
                        style={{ background: type.color }}
                        aria-hidden="true"
                      />
                      {type.name}
                    </span>
                  </td>
                  <td className="text-right tabular-nums">{type.default_duration_min} min</td>
                  <td>{type.portal_visible ? 'Visible' : 'No visible'}</td>
                  <td>
                    <ActiveBadge active={type.is_active} />
                  </td>
                  <td>
                    <div className="flex flex-wrap gap-3">
                      <button
                        type="button"
                        className="btn-link"
                        aria-label={`Editar ${type.name}`}
                        onClick={() => open(type)}
                      >
                        Editar
                      </button>
                      <button
                        type="button"
                        className="btn-link"
                        aria-label={`${type.is_active ? 'Desactivar' : 'Activar'} ${type.name}`}
                        onClick={() => toggle.mutate(type)}
                        disabled={toggle.isPending}
                      >
                        {type.is_active ? 'Desactivar' : 'Activar'}
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
