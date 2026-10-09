import { EMPTY_ITEM, itemErrors } from './labels'
import { ItemFields } from './ItemFields'

/**
 * Lista editable de ítems nuevos de un plan (RF-110, RF-111). Los errores de la API llegan como
 * `items.N.campo` y se muestran en el ítem N.
 */
export function ItemsEditor({ items, onChange, procedures, errors = {}, idPrefix = 'item' }) {
  const update = (index, value) => onChange(items.map((item, position) => (position === index ? value : item)))
  const remove = (index) => onChange(items.filter((_, position) => position !== index))

  return (
    <div className="flex flex-col gap-4">
      {items.map((item, index) => (
        <fieldset key={index} className="form-section">
          <legend className="text-label-md">
            Ítem {index + 1}
            {item.findingLabel ? ` · atiende ${item.findingLabel}` : ''}
          </legend>
          <ItemFields
            idPrefix={`${idPrefix}-${index}`}
            value={item}
            onChange={(value) => update(index, value)}
            procedures={procedures}
            errors={itemErrors(errors, index)}
          />
          {items.length > 1 && (
            <button type="button" className="btn-link" onClick={() => remove(index)}>
              Quitar ítem {index + 1}
            </button>
          )}
        </fieldset>
      ))}
      <div>
        <button type="button" className="btn btn-secondary" onClick={() => onChange([...items, { ...EMPTY_ITEM }])}>
          Agregar otro ítem
        </button>
      </div>
    </div>
  )
}
