import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { apiClient } from '../../api/client'
import { fieldErrors, generalError } from '../../api/errors'
import { useClinic } from '../../auth/useClinic'
import { Field } from '../../components/Field'
import { formatTime } from '../../ui/format'
import { useTimeZone } from '../../ui/useTimeZone'

/** Mensaje de un error de disponibilidad: el general o los de cada parámetro (422). */
function availabilityError(error) {
  return generalError(error) ?? Object.values(fieldErrors(error)).join(' ')
}

/**
 * Horarios libres de un día agrupados por odontólogo (CUS-46; RF-146; SRS §11.13 pasos 1 a 3):
 * inicios cada 15 minutos sin cruces con bloqueos ni con citas activas del odontólogo o del
 * paciente. Con `chooseDentist` se elige un odontólogo o «Cualquiera». Cada inicio es un botón
 * con `aria-pressed`; el elegido además cambia de relleno, no solo de color. El portal usa
 * `endpoint="/portal/availability"` (solo tipos visibles en el portal, desde 2 horas después) y
 * `fieldSize="lg"` (controles de 48 px).
 */
export function SlotPicker({
  params,
  selected,
  onSelect,
  chooseDentist = false,
  endpoint = '/availability',
  fieldSize,
}) {
  const { slug } = useClinic()
  const timeZone = useTimeZone()
  const [dentistId, setDentistId] = useState('')
  const availability = useQuery({
    queryKey: ['availability', slug, endpoint, params],
    queryFn: async () => (await apiClient.get(endpoint, { params })).data.data,
    enabled: Boolean(params),
  })

  if (!params) return null
  if (availability.isLoading) {
    return (
      <div className="empty" role="status">
        Buscando horarios…
      </div>
    )
  }
  if (availability.isError) {
    return (
      <div className="alert alert-error" role="alert">
        {availabilityError(availability.error)}
      </div>
    )
  }

  const all = availability.data ?? []
  const groups = all.filter((group) => group.slots.length > 0 && (!dentistId || group.dentist.id === dentistId))

  return (
    <div className="flex flex-col gap-4">
      {chooseDentist && all.length > 1 && (
        <Field label="Odontólogo" name="slot-dentist" size={fieldSize}>
          <select id="slot-dentist" value={dentistId} onChange={(event) => setDentistId(event.target.value)}>
            <option value="">Cualquiera</option>
            {all.map((group) => (
              <option key={group.dentist.id} value={group.dentist.id}>
                {group.dentist.name}
              </option>
            ))}
          </select>
        </Field>
      )}
      {groups.length === 0 && (
        <div className="empty" role="status">
          No hay horarios libres ese día.
        </div>
      )}
      {groups.map((group) => (
        <fieldset key={group.dentist.id} className="m-0 border-0 p-0">
          <legend className="text-label-md">{group.dentist.name}</legend>
          <div className="flex flex-wrap gap-2">
            {group.slots.map((start) => {
              const isSelected = selected?.dentistId === group.dentist.id && selected?.startsAt === start
              return (
                <button
                  key={start}
                  type="button"
                  className={`min-w-20 tabular-nums ${isSelected ? 'btn' : 'btn btn-secondary'}`}
                  aria-pressed={isSelected}
                  onClick={() =>
                    onSelect({ dentistId: group.dentist.id, dentistName: group.dentist.name, startsAt: start })
                  }
                >
                  {formatTime(start, timeZone)}
                </button>
              )
            })}
          </div>
        </fieldset>
      ))}
    </div>
  )
}
