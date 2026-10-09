import { Field } from '../../components/Field'
import { SURFACE_NAMES, surfacesFor } from '../../components/odontogram/teeth'
import { formatCurrency } from '../../ui/format'

const capitalize = (text) => text.charAt(0).toUpperCase() + text.slice(1)

/** Pieza del Sistema Dígito Dos con superficies propias (11–48 y 51–85). */
function knownTooth(tooth) {
  const quadrant = Math.floor(tooth / 10)
  const position = tooth % 10
  return (
    (quadrant >= 1 && quadrant <= 4 && position >= 1 && position <= 8) ||
    (quadrant >= 5 && quadrant <= 8 && position >= 1 && position <= 5)
  )
}

/**
 * Campos de un ítem del plan (RF-110, RN-26): procedimiento activo del catálogo, pieza, superficies,
 * cantidad (1–32), sesión y observaciones. Las superficies ofrecidas son las de la pieza (RN-18);
 * la API valida lo que exige el procedimiento y devuelve el error junto a cada campo.
 */
export function ItemFields({ idPrefix, value, onChange, procedures, errors = {}, lockProcedure = false }) {
  const tooth = Number(value.tooth)
  const toothKnown = value.tooth !== '' && knownTooth(tooth)
  const procedure = procedures.find((item) => item.id === value.procedure_id)
  const set = (field) => (event) => onChange({ ...value, [field]: event.target.value })
  const toggle = (surface) =>
    onChange({
      ...value,
      surfaces: value.surfaces.includes(surface)
        ? value.surfaces.filter((item) => item !== surface)
        : [...value.surfaces, surface],
    })

  return (
    <div className="flex flex-col gap-3">
      <div className="form-grid">
        <Field label="Procedimiento" name={`${idPrefix}-procedure`} error={errors.procedure_id}>
          <select
            id={`${idPrefix}-procedure`}
            value={value.procedure_id}
            onChange={set('procedure_id')}
            disabled={lockProcedure}
            aria-invalid={errors.procedure_id ? true : undefined}
            aria-describedby={errors.procedure_id ? `${idPrefix}-procedure-error` : undefined}
          >
            <option value="">Selecciona un procedimiento</option>
            {procedures
              .filter((item) => item.is_active || item.id === value.procedure_id)
              .map((item) => (
                <option key={item.id} value={item.id}>
                  {item.code} · {item.name} ({formatCurrency(item.price)})
                </option>
              ))}
          </select>
        </Field>
        <Field
          label={procedure?.requires_tooth ? 'Pieza' : 'Pieza (opcional)'}
          name={`${idPrefix}-tooth`}
          type="number"
          inputMode="numeric"
          min="11"
          max="85"
          value={value.tooth}
          onChange={(event) => onChange({ ...value, tooth: event.target.value, surfaces: [] })}
          error={errors.tooth}
        />
        <Field
          label="Cantidad"
          name={`${idPrefix}-quantity`}
          type="number"
          inputMode="numeric"
          min="1"
          max="32"
          value={value.quantity}
          onChange={set('quantity')}
          error={errors.quantity}
        />
        <Field
          label="Sesión (opcional)"
          name={`${idPrefix}-session`}
          type="number"
          inputMode="numeric"
          min="1"
          value={value.session_number}
          onChange={set('session_number')}
          error={errors.session_number}
        />
      </div>
      {toothKnown && (
        <fieldset className="m-0 border-0 p-0">
          <legend className="text-label-md">
            {procedure?.requires_surface ? 'Superficies' : 'Superficies (opcional)'}
          </legend>
          <div className="flex flex-wrap gap-3">
            {surfacesFor(tooth).map((surface) => (
              <label key={surface} className="checkbox">
                <input type="checkbox" checked={value.surfaces.includes(surface)} onChange={() => toggle(surface)} />
                {capitalize(SURFACE_NAMES[surface])}
              </label>
            ))}
          </div>
        </fieldset>
      )}
      {errors.surfaces && (
        <div className="field">
          <span className="error" role="alert">
            {errors.surfaces}
          </span>
        </div>
      )}
      <Field label="Observaciones (opcional)" name={`${idPrefix}-observations`} error={errors.observations}>
        <textarea
          id={`${idPrefix}-observations`}
          rows={2}
          maxLength={500}
          value={value.observations}
          onChange={set('observations')}
          aria-invalid={errors.observations ? true : undefined}
          aria-describedby={errors.observations ? `${idPrefix}-observations-error` : undefined}
        />
      </Field>
      {errors.finding_ids && (
        <div className="field">
          <span className="error" role="alert">
            {errors.finding_ids}
          </span>
        </div>
      )}
    </div>
  )
}
