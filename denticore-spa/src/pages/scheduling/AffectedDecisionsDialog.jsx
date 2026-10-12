import { useState } from 'react'
import { ConfirmDialog } from '../../components/ConfirmDialog'
import { formatDateTime, localDate, zonedDateTimeToIso } from '../../ui/format'
import { useTimeZone } from '../../ui/useTimeZone'
import { StatusBadge } from './StatusBadge'

/**
 * Decisión por cada cita activa afectada por un bloqueo o un cambio de horario (CUS-44, CUS-48;
 * RF-144, RN-82): reprogramar a un nuevo inicio o cancelar. Todas se aplican en una sola operación;
 * si una reprogramación choca, no se aplica nada.
 */
export function AffectedDecisionsDialog({ title, appointments, confirmLabel, onConfirm, onCancel, busy, error }) {
  const timeZone = useTimeZone()
  const [decisions, setDecisions] = useState(() =>
    Object.fromEntries(
      appointments.map((appointment) => [
        appointment.id,
        { action: '', date: localDate(appointment.starts_at, timeZone), time: '' },
      ]),
    ),
  )

  const decisionOf = (id) => decisions[id] ?? { action: '', date: '', time: '' }
  const set = (id, change) => setDecisions((current) => ({ ...current, [id]: { ...decisionOf(id), ...change } }))
  const complete = appointments.every(({ id }) => {
    const decision = decisionOf(id)
    return decision.action === 'cancelar' || (decision.action === 'reprogramar' && decision.date && decision.time)
  })
  const payload = () =>
    appointments.map(({ id }) => {
      const decision = decisionOf(id)
      return decision.action === 'cancelar'
        ? { appointment_id: id, action: 'cancelar' }
        : {
            appointment_id: id,
            action: 'reprogramar',
            new_starts_at: zonedDateTimeToIso(decision.date, decision.time, timeZone),
          }
    })

  return (
    <ConfirmDialog
      title={title}
      confirmLabel={confirmLabel}
      destructive={appointments.some(({ id }) => decisionOf(id).action === 'cancelar')}
      confirmDisabled={!complete}
      onConfirm={() => onConfirm(payload())}
      onCancel={onCancel}
      busy={busy}
      error={error}
    >
      <p>
        {appointments.length === 1
          ? 'Hay 1 cita activa afectada.'
          : `Hay ${appointments.length} citas activas afectadas.`}{' '}
        Indica qué hacer con cada una. Los pacientes con avisos habilitados recibirán un correo.
      </p>
      <ul className="m-0 flex list-none flex-col gap-4 p-0">
        {appointments.map((appointment) => {
          const decision = decisionOf(appointment.id)
          const group = `decision-${appointment.id}`
          return (
            <li key={appointment.id}>
              <fieldset className="m-0 border-0 p-0">
                <legend className="text-label-md">
                  {appointment.patient.name} · {formatDateTime(appointment.starts_at, timeZone)}
                </legend>
                <div className="flex flex-wrap items-center gap-4">
                  <StatusBadge appointment={appointment} />
                  <label className="checkbox">
                    <input
                      type="radio"
                      name={group}
                      checked={decision.action === 'reprogramar'}
                      onChange={() => set(appointment.id, { action: 'reprogramar' })}
                    />
                    Reprogramar
                  </label>
                  <label className="checkbox">
                    <input
                      type="radio"
                      name={group}
                      checked={decision.action === 'cancelar'}
                      onChange={() => set(appointment.id, { action: 'cancelar' })}
                    />
                    Cancelar
                  </label>
                </div>
                {decision.action === 'reprogramar' && (
                  <div className="flex flex-wrap gap-3">
                    <label className="field">
                      <span>Nueva fecha</span>
                      <input
                        type="date"
                        value={decision.date}
                        onChange={(event) => set(appointment.id, { date: event.target.value })}
                      />
                    </label>
                    <label className="field">
                      <span>Nueva hora</span>
                      <input
                        type="time"
                        step="900"
                        value={decision.time}
                        onChange={(event) => set(appointment.id, { time: event.target.value })}
                      />
                    </label>
                  </div>
                )}
              </fieldset>
            </li>
          )
        })}
      </ul>
    </ConfirmDialog>
  )
}
