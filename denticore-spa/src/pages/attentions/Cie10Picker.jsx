import { useId, useState } from 'react'
import { apiClient } from '../../api/client'
import { Field } from '../../components/Field'

/**
 * Búsqueda en el catálogo CIE-10 (CUS-80; RF-085): por código o texto, con el capítulo K00–K14
 * primero. Entrega el código elegido y su tipo (presuntivo o definitivo).
 */
export function Cie10Picker({ onAdd, busy = false, error = null }) {
  const id = useId()
  const [term, setTerm] = useState('')
  const [results, setResults] = useState(null)
  const [code, setCode] = useState('')
  const [type, setType] = useState('presuntivo')

  const search = async (event) => {
    event.preventDefault()
    const { data } = await apiClient.get('/cie10', { params: { q: term } })
    setResults(data.data)
    setCode(data.data[0]?.code ?? '')
  }

  const add = () => {
    const picked = results?.find((result) => result.code === code)
    if (picked) onAdd({ cie10_code: picked.code, type, description: picked.description })
  }

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap items-end gap-2">
        <Field
          label="Buscar diagnóstico CIE-10"
          name={`${id}-cie10`}
          value={term}
          onChange={(event) => setTerm(event.target.value)}
          onKeyDown={(event) => event.key === 'Enter' && search(event)}
          placeholder="Código o texto, p. ej. caries"
        />
        <button type="button" className="btn btn-secondary" onClick={search} disabled={term.trim() === ''}>
          Buscar
        </button>
      </div>

      {results && results.length === 0 && <p className="muted m-0">Sin resultados para «{term}».</p>}
      {results && results.length > 0 && (
        <div className="flex flex-wrap items-end gap-2">
          <Field label="Diagnóstico" name={`${id}-code`} error={error}>
            <select id={`${id}-code`} value={code} onChange={(event) => setCode(event.target.value)}>
              {results.map((result) => (
                <option key={result.code} value={result.code}>
                  {result.code} · {result.description}
                </option>
              ))}
            </select>
          </Field>
          <Field label="Tipo" name={`${id}-type`}>
            <select id={`${id}-type`} value={type} onChange={(event) => setType(event.target.value)}>
              <option value="presuntivo">Presuntivo</option>
              <option value="definitivo">Definitivo</option>
            </select>
          </Field>
          <button type="button" className="btn" onClick={add} disabled={busy || !code}>
            Agregar diagnóstico
          </button>
        </div>
      )}
    </div>
  )
}
