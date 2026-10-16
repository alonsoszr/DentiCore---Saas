import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiClient } from '../../api/client'
import { fieldErrors, generalError } from '../../api/errors'
import { useAuth } from '../../auth/useAuth'
import { useClinic } from '../../auth/useClinic'
import { Alert } from '../../components/Alert'
import { ConfirmDialog } from '../../components/ConfirmDialog'
import { Field } from '../../components/Field'
import { formatCivilDate, formatDateTime, localDate, zonedDateTimeToIso } from '../../ui/format'
import { useTimeZone } from '../../ui/useTimeZone'
import { AffectedDecisionsDialog } from './AffectedDecisionsDialog'
import { BLOCK_KINDS, WEEKDAYS } from './labels'
import { InlineError } from '../treatment/InlineError'
import { loadAffected, problemDetail, useManagedDentists } from './useScheduling'

const EMPTY_SLOT = { weekday: '1', start_time: '09:00', end_time: '13:00' }

/** Error de una franja: `slots.N.campo` (RF-142). El de inicio cubre también el día. */
function slotError(errors, index, field) {
  return field === 'end_time'
    ? errors[`slots.${index}.end_time`]
    : (errors[`slots.${index}.start_time`] ?? errors[`slots.${index}.weekday`])
}

/**
 * Horario laboral del odontólogo (CUS-44; RF-142, RF-144, RN-82): franjas por día vigentes desde
 * una fecha. Guardar pide confirmación; si el horario nuevo deja fuera citas activas, se decide
 * qué hacer con cada una antes de aplicarlo.
 */
function WorkScheduleSection({ dentist, canEdit }) {
  const { slug } = useClinic()
  const timeZone = useTimeZone()
  const queryClient = useQueryClient()
  const queryKey = ['work-schedules', slug, dentist.id]
  const [editing, setEditing] = useState(false)
  const [validFrom, setValidFrom] = useState(() => localDate(new Date(), timeZone))
  const [slots, setSlots] = useState([])
  const [confirming, setConfirming] = useState(false)
  const [affected, setAffected] = useState(null)

  const schedule = useQuery({
    queryKey,
    queryFn: async () => (await apiClient.get(`/dentists/${dentist.id}/work-schedules`)).data.data,
  })
  const save = useMutation({
    mutationFn: async (decisions = []) =>
      apiClient.put(`/dentists/${dentist.id}/work-schedules`, {
        valid_from: validFrom,
        slots: slots.map((slot) => ({ ...slot, weekday: Number(slot.weekday) })),
        ...(decisions.length > 0 ? { decisions } : {}),
      }),
    onSuccess: () => {
      setConfirming(false)
      setAffected(null)
      setEditing(false)
      queryClient.invalidateQueries({ queryKey })
      queryClient.resetQueries({ queryKey: ['appointments'] })
    },
    onError: async (error) => {
      setConfirming(false)
      // RN-82: también si otra cita entró en la franja mientras se decidía (la lista se renueva).
      const list = await loadAffected(error)
      if (list) setAffected(list)
    },
  })

  const current = schedule.data ?? []
  const startEditing = () => {
    save.reset()
    const today = localDate(new Date(), timeZone)
    const active = current.filter((slot) => slot.valid_from <= today)
    setSlots(
      (active.length > 0 ? active : current).map(({ weekday, start_time, end_time }) => ({
        weekday: String(weekday),
        start_time,
        end_time,
      })),
    )
    setValidFrom(today)
    setEditing(true)
  }
  const update = (index, change) =>
    setSlots(slots.map((slot, position) => (position === index ? { ...slot, ...change } : slot)))
  const errors = fieldErrors(save.error)
  const saveError = problemDetail(save.error, generalError(save.error))

  return (
    <section className="card" aria-labelledby="schedule-title">
      <div className="page-header">
        <h2 id="schedule-title" className="m-0">
          Horario laboral
        </h2>
        {canEdit && !editing && (
          <button type="button" className="btn" onClick={startEditing}>
            Cambiar horario
          </button>
        )}
      </div>

      {schedule.isLoading && <div className="empty">Cargando…</div>}
      {schedule.isSuccess && current.length === 0 && !editing && (
        <div className="empty">El odontólogo aún no tiene horario laboral.</div>
      )}
      {current.length > 0 && !editing && (
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Día</th>
                <th>Horario</th>
                <th>Vigencia</th>
              </tr>
            </thead>
            <tbody>
              {current.map((slot) => (
                <tr key={slot.id}>
                  <td>{WEEKDAYS[slot.weekday]}</td>
                  <td className="tabular-nums">
                    {slot.start_time} – {slot.end_time}
                  </td>
                  <td>
                    Desde el {formatCivilDate(slot.valid_from)}
                    {slot.valid_until ? ` hasta el ${formatCivilDate(slot.valid_until)}` : ''}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {editing && (
        <form
          noValidate
          className="form-section"
          onSubmit={(event) => {
            event.preventDefault()
            save.reset()
            setConfirming(true)
          }}
        >
          {!affected && saveError && (
            <div className="alert alert-error" role="alert">
              {saveError}
            </div>
          )}
          <Field
            label="Vigente desde"
            name="valid-from"
            type="date"
            value={validFrom}
            onChange={(event) => setValidFrom(event.target.value)}
            error={errors.valid_from}
          />
          <InlineError>{errors.slots}</InlineError>
          <ul className="m-0 flex list-none flex-col gap-3 p-0">
            {slots.map((slot, index) => (
              <li key={index}>
                <fieldset className="m-0 border-0 p-0">
                  <legend className="sr-only">Franja {index + 1}</legend>
                  <div className="form-grid">
                    <Field label="Día" name={`slot-${index}-weekday`}>
                      <select
                        id={`slot-${index}-weekday`}
                        value={slot.weekday}
                        onChange={(event) => update(index, { weekday: event.target.value })}
                      >
                        {Object.entries(WEEKDAYS).map(([value, label]) => (
                          <option key={value} value={value}>
                            {label}
                          </option>
                        ))}
                      </select>
                    </Field>
                    <Field
                      label="Desde"
                      name={`slot-${index}-start`}
                      type="time"
                      step="900"
                      value={slot.start_time}
                      onChange={(event) => update(index, { start_time: event.target.value })}
                      error={slotError(errors, index, 'start_time')}
                    />
                    <Field
                      label="Hasta"
                      name={`slot-${index}-end`}
                      type="time"
                      step="900"
                      value={slot.end_time}
                      onChange={(event) => update(index, { end_time: event.target.value })}
                      error={slotError(errors, index, 'end_time')}
                    />
                  </div>
                  <button
                    type="button"
                    className="btn-link"
                    onClick={() => setSlots(slots.filter((_, position) => position !== index))}
                  >
                    Quitar franja {index + 1}
                  </button>
                </fieldset>
              </li>
            ))}
          </ul>
          <div>
            <button type="button" className="btn btn-secondary" onClick={() => setSlots([...slots, { ...EMPTY_SLOT }])}>
              Agregar franja
            </button>
          </div>
          <div className="form-actions">
            <button type="submit" className="btn" disabled={save.isPending}>
              Guardar horario
            </button>
            <button type="button" className="btn btn-secondary" onClick={() => setEditing(false)}>
              Cancelar
            </button>
          </div>
        </form>
      )}

      {confirming && (
        <ConfirmDialog
          title="¿Guardar el horario laboral?"
          confirmLabel="Guardar horario"
          onConfirm={() => save.mutate([])}
          onCancel={() => setConfirming(false)}
          busy={save.isPending}
        >
          <p>
            El horario nuevo rige desde el {formatCivilDate(validFrom)} y reemplaza al actual. Si deja fuera citas
            activas, tendrás que reprogramarlas o cancelarlas.
          </p>
        </ConfirmDialog>
      )}

      {affected && (
        <AffectedDecisionsDialog
          key={affected.map((appointment) => appointment.id).join()}
          title="Citas fuera del horario nuevo"
          confirmLabel="Aplicar horario y decisiones"
          appointments={affected}
          onConfirm={(decisions) => save.mutate(decisions)}
          onCancel={() => setAffected(null)}
          busy={save.isPending}
          error={save.isError ? saveError : null}
        />
      )}
    </section>
  )
}

/**
 * Bloqueos de la agenda (CUS-44; RF-143, RF-144, RN-82): de un odontólogo o de toda la clínica.
 * Antes de crearlo se consulta su impacto. El administrador decide sobre las citas afectadas; el
 * odontólogo no puede reprogramarlas ni cancelarlas (CUS-48), así que se le indica pedírselo.
 */
function BlocksSection({ dentist, isAdmin }) {
  const { slug } = useClinic()
  const timeZone = useTimeZone()
  const queryClient = useQueryClient()
  const queryKey = ['schedule-blocks', slug, dentist.id]
  const today = localDate(new Date(), timeZone)
  const emptyForm = {
    scope: 'dentist',
    from_date: today,
    from_time: '08:00',
    to_date: today,
    to_time: '18:00',
    kind: 'capacitacion',
    reason: '',
  }
  const [form, setForm] = useState(null)
  const [affected, setAffected] = useState(null)
  const [toDelete, setToDelete] = useState(null)

  const blocks = useQuery({
    queryKey,
    queryFn: async () => (await apiClient.get('/schedule-blocks', { params: { dentist_id: dentist.id } })).data.data,
  })
  const payload = () => ({
    ...(form.scope === 'dentist' ? { dentist_id: dentist.id } : {}),
    starts_at: zonedDateTimeToIso(form.from_date, form.from_time, timeZone),
    ends_at: zonedDateTimeToIso(form.to_date, form.to_time, timeZone),
    kind: form.kind,
    reason: form.reason.trim(),
  })
  const done = () => {
    setForm(null)
    setAffected(null)
    queryClient.invalidateQueries({ queryKey })
    queryClient.resetQueries({ queryKey: ['appointments'] })
  }
  const create = useMutation({
    mutationFn: async (decisions = []) =>
      apiClient.post('/schedule-blocks', { ...payload(), ...(decisions.length > 0 ? { decisions } : {}) }),
    onSuccess: done,
    onError: async (error) => {
      // RN-82: una cita entró en el intervalo entre la consulta de impacto y la creación.
      const list = await loadAffected(error)
      if (list) setAffected(list)
    },
  })
  const check = useMutation({
    mutationFn: async () => {
      const { starts_at, ends_at, dentist_id } = payload()
      return (
        await apiClient.post('/schedule-blocks/impact', { starts_at, ends_at, ...(dentist_id ? { dentist_id } : {}) })
      ).data.data
    },
    onSuccess: (appointments) => (appointments.length === 0 ? create.mutate([]) : setAffected(appointments)),
  })
  const remove = useMutation({
    mutationFn: async (block) => apiClient.delete(`/schedule-blocks/${block.id}`),
    onSuccess: () => {
      setToDelete(null)
      queryClient.invalidateQueries({ queryKey })
    },
  })

  const errors = { ...fieldErrors(check.error), ...fieldErrors(create.error) }
  const list = blocks.data ?? []
  const set = (field) => (event) => setForm({ ...form, [field]: event.target.value })
  const createError = problemDetail(create.error, generalError(create.error))
  const formError = generalError(check.error) ?? (affected ? null : createError)

  return (
    <section className="card" aria-labelledby="blocks-title">
      <div className="page-header">
        <h2 id="blocks-title" className="m-0">
          Bloqueos
        </h2>
        {form === null && (
          <button
            type="button"
            className="btn btn-secondary"
            onClick={() => {
              check.reset()
              create.reset()
              setForm(emptyForm)
            }}
          >
            Nuevo bloqueo
          </button>
        )}
      </div>

      {form !== null && (
        <form
          noValidate
          className="form-section"
          onSubmit={(event) => {
            event.preventDefault()
            create.reset()
            check.mutate()
          }}
        >
          {formError && (
            <div className="alert alert-error" role="alert">
              {formError}
            </div>
          )}
          {isAdmin && (
            <fieldset className="m-0 border-0 p-0">
              <legend className="text-label-md">Alcance</legend>
              <div className="flex flex-wrap gap-4">
                <label className="checkbox">
                  <input
                    type="radio"
                    name="block-scope"
                    checked={form.scope === 'dentist'}
                    onChange={() => setForm({ ...form, scope: 'dentist' })}
                  />
                  Solo {dentist.name}
                </label>
                <label className="checkbox">
                  <input
                    type="radio"
                    name="block-scope"
                    checked={form.scope === 'clinic'}
                    onChange={() => setForm({ ...form, scope: 'clinic' })}
                  />
                  Toda la clínica
                </label>
              </div>
            </fieldset>
          )}
          <div className="form-grid">
            <Field
              label="Desde (fecha)"
              name="block-from-date"
              type="date"
              value={form.from_date}
              onChange={set('from_date')}
              error={errors.starts_at}
            />
            <Field
              label="Desde (hora)"
              name="block-from-time"
              type="time"
              step="900"
              value={form.from_time}
              onChange={set('from_time')}
            />
            <Field
              label="Hasta (fecha)"
              name="block-to-date"
              type="date"
              value={form.to_date}
              onChange={set('to_date')}
              error={errors.ends_at}
            />
            <Field
              label="Hasta (hora)"
              name="block-to-time"
              type="time"
              step="900"
              value={form.to_time}
              onChange={set('to_time')}
            />
            <Field label="Tipo" name="block-kind" error={errors.kind}>
              <select id="block-kind" value={form.kind} onChange={set('kind')}>
                {Object.entries(BLOCK_KINDS).map(([value, label]) => (
                  <option key={value} value={value}>
                    {label}
                  </option>
                ))}
              </select>
            </Field>
            <Field
              label="Motivo"
              name="block-reason"
              value={form.reason}
              onChange={set('reason')}
              error={errors.reason}
              maxLength={200}
            />
          </div>
          <div className="form-actions">
            <button type="submit" className="btn" disabled={check.isPending || create.isPending}>
              Crear bloqueo
            </button>
            <button type="button" className="btn btn-secondary" onClick={() => setForm(null)}>
              Cancelar
            </button>
          </div>
        </form>
      )}

      {affected && !isAdmin && (
        <Alert tone="warning" title="El bloqueo afecta citas activas" className="mb-4">
          El bloqueo se cruza con {affected.length === 1 ? '1 cita activa' : `${affected.length} citas activas`}. Pide
          al administrador de la clínica que lo cree y decida qué hacer con cada cita.
        </Alert>
      )}

      {blocks.isSuccess && list.length === 0 && <div className="empty">No hay bloqueos vigentes.</div>}
      {list.length > 0 && (
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Desde</th>
                <th>Hasta</th>
                <th>Alcance</th>
                <th>Tipo y motivo</th>
                <th>
                  <span className="sr-only">Acciones</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {list.map((block) => (
                <tr key={block.id}>
                  <td className="tabular-nums">{formatDateTime(block.starts_at, timeZone)}</td>
                  <td className="tabular-nums">{formatDateTime(block.ends_at, timeZone)}</td>
                  <td>{block.dentist ? block.dentist.name : 'Toda la clínica'}</td>
                  <td>
                    {BLOCK_KINDS[block.kind]} · {block.reason}
                  </td>
                  <td>
                    {(isAdmin || block.dentist?.id === dentist.id) && (
                      <button
                        type="button"
                        className="btn-link"
                        aria-label={`Eliminar el bloqueo del ${formatDateTime(block.starts_at, timeZone)}`}
                        onClick={() => setToDelete(block)}
                      >
                        Eliminar
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {affected && isAdmin && (
        <AffectedDecisionsDialog
          key={affected.map((appointment) => appointment.id).join()}
          title="Citas afectadas por el bloqueo"
          confirmLabel="Crear bloqueo y aplicar decisiones"
          appointments={affected}
          onConfirm={(decisions) => create.mutate(decisions)}
          onCancel={() => setAffected(null)}
          busy={create.isPending}
          error={create.isError ? createError : null}
        />
      )}

      {toDelete && (
        <ConfirmDialog
          title="¿Eliminar el bloqueo?"
          confirmLabel="Eliminar bloqueo"
          destructive
          onConfirm={() => remove.mutate(toDelete)}
          onCancel={() => setToDelete(null)}
          busy={remove.isPending}
          error={generalError(remove.error)}
        >
          <p>La agenda vuelve a quedar disponible en ese intervalo.</p>
        </ConfirmDialog>
      )}
    </section>
  )
}

/**
 * Horarios y bloqueos (/c/:slug/app/agenda/horarios; CUS-44). El administrador gestiona el horario
 * y los bloqueos de cualquier odontólogo; el odontólogo consulta su horario y bloquea su agenda.
 */
export function SchedulePage() {
  const { user } = useAuth()
  const dentists = useManagedDentists()
  const [selected, setSelected] = useState(null)
  const isAdmin = user?.role === 'clinic_admin'
  const list = dentists.data ?? []
  const dentist = list.find((item) => item.id === selected) ?? list[0]

  return (
    <>
      <div className="page-header">
        <h1 className="m-0">Horarios y bloqueos</h1>
      </div>
      {dentists.isLoading && <div className="card empty">Cargando…</div>}
      {dentists.isError && (
        <div className="alert alert-error" role="alert">
          {generalError(dentists.error)}
        </div>
      )}
      {dentists.isSuccess && list.length === 0 && (
        <div className="card empty">La clínica aún no tiene odontólogos activos.</div>
      )}
      {dentist && (
        <>
          {isAdmin && (
            <div className="card">
              <Field label="Odontólogo" name="schedule-dentist">
                <select id="schedule-dentist" value={dentist.id} onChange={(event) => setSelected(event.target.value)}>
                  {list.map((item) => (
                    <option key={item.id} value={item.id}>
                      {item.name}
                    </option>
                  ))}
                </select>
              </Field>
            </div>
          )}
          <WorkScheduleSection key={`schedule-${dentist.id}`} dentist={dentist} canEdit={isAdmin} />
          <BlocksSection key={`blocks-${dentist.id}`} dentist={dentist} isAdmin={isAdmin} />
        </>
      )}
    </>
  )
}
