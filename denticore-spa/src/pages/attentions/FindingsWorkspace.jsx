import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiClient } from '../../api/client'
import { fieldErrors, generalError } from '../../api/errors'
import { useClinic } from '../../auth/useClinic'
import { ConfirmDialog } from '../../components/ConfirmDialog'
import { Field } from '../../components/Field'
import { Odontogram } from '../../components/odontogram/Odontogram'
import { ToothHistory } from '../../components/odontogram/ToothHistory'
import { SURFACE_NAMES, surfacesFor } from '../../components/odontogram/teeth'
import { DENTITIONS } from './labels'

const capitalize = (text) => text.charAt(0).toUpperCase() + text.slice(1)

/** Hallazgos del catálogo que aplican a la dentición de la pieza (RN-17; CUS-22 paso 5). */
function findingsFor(catalog, tooth) {
  const dentition = Math.floor(tooth / 10) >= 5 ? 'temporal' : 'permanente'
  return catalog.filter((finding) => finding.dentition === 'ambas' || finding.dentition === dentition)
}

/**
 * Campos de un hallazgo: superficies (también con casillas, además del teclado del odontograma),
 * hallazgo y estado del catálogo NTS 188 y pieza final si es de tramo. El color lo fija el estado.
 */
function FindingFields({ idPrefix, tooth, catalog, value, onChange, errors }) {
  const finding = catalog.find((item) => item.code === value.finding_code)
  const toggle = (surface) =>
    onChange({
      ...value,
      surfaces: value.surfaces.includes(surface)
        ? value.surfaces.filter((s) => s !== surface)
        : [...value.surfaces, surface],
    })

  return (
    <div className="flex flex-col gap-3">
      <fieldset className="m-0 border-0 p-0">
        <legend className="text-label-md">Superficies</legend>
        <div className="flex flex-wrap gap-3">
          {surfacesFor(tooth).map((surface) => (
            <label key={surface} className="checkbox">
              <input type="checkbox" checked={value.surfaces.includes(surface)} onChange={() => toggle(surface)} />
              {capitalize(SURFACE_NAMES[surface])}
            </label>
          ))}
        </div>
        {errors.surfaces && (
          <div className="field">
            <span className="error" role="alert">
              {errors.surfaces}
            </span>
          </div>
        )}
      </fieldset>
      <div className="form-grid">
        <Field label="Hallazgo" name={`${idPrefix}-finding`} error={errors.finding_code}>
          <select
            id={`${idPrefix}-finding`}
            value={value.finding_code}
            onChange={(event) => onChange({ ...value, finding_code: event.target.value, state_code: '' })}
          >
            <option value="">Elija un hallazgo</option>
            {findingsFor(catalog, tooth).map((item) => (
              <option key={item.code} value={item.code}>
                {item.name}
              </option>
            ))}
          </select>
        </Field>
        <Field label="Estado" name={`${idPrefix}-state`} error={errors.state_code}>
          <select
            id={`${idPrefix}-state`}
            value={value.state_code}
            disabled={!finding}
            onChange={(event) => onChange({ ...value, state_code: event.target.value })}
          >
            <option value="">Elija un estado</option>
            {(finding?.states ?? []).map((state) => (
              <option key={state.code} value={state.code}>
                {state.acronym ? `${state.acronym} · ` : ''}
                {state.name} ({state.color})
              </option>
            ))}
          </select>
        </Field>
        {finding?.level === 'tramo' && (
          <Field
            label="Pieza final"
            name={`${idPrefix}-tooth-end`}
            type="number"
            inputMode="numeric"
            value={value.tooth_end}
            error={errors.tooth_end}
            hint="Pieza del mismo arco donde termina el tramo."
            onChange={(event) => onChange({ ...value, tooth_end: event.target.value })}
          />
        )}
      </div>
    </div>
  )
}

const emptyFinding = { surfaces: [], finding_code: '', state_code: '', tooth_end: '', note: '' }

/** Datos del hallazgo para la API: superficies solo en los de superficie (RN-17). */
function payload(tooth, value, catalog) {
  const finding = catalog.find((item) => item.code === value.finding_code)
  return {
    tooth,
    tooth_end: finding?.level === 'tramo' && value.tooth_end !== '' ? Number(value.tooth_end) : null,
    surfaces: finding?.level === 'pieza' ? [] : value.surfaces,
    finding_code: value.finding_code,
    state_code: value.state_code,
  }
}

/**
 * Hallazgos de la atención (CUS-22, CUS-23; RF-087 a RF-093): odontograma vigente, registro de
 * un hallazgo en la pieza elegida, historial de la pieza y corrección con motivo y confirmación.
 */
export function FindingsWorkspace({ attention, patient }) {
  const { slug } = useClinic()
  const queryClient = useQueryClient()
  const [tooth, setTooth] = useState(null)
  const [dentition, setDentition] = useState(null)
  const [finding, setFinding] = useState(emptyFinding)
  const [correcting, setCorrecting] = useState(null)
  const [correction, setCorrection] = useState({ kind: 'reemplazo', reason: '', ...emptyFinding })
  const [notice, setNotice] = useState(null)

  const catalog = useQuery({
    queryKey: ['finding-catalog'],
    queryFn: async () => (await apiClient.get('/finding-catalog')).data.data,
    staleTime: Infinity,
  })
  const odontogram = useQuery({
    queryKey: ['odontogram', slug, patient.id, 'vigente'],
    queryFn: async () => (await apiClient.get(`/patients/${patient.id}/odontogram`)).data.data,
  })
  const history = useQuery({
    queryKey: ['tooth-history', slug, patient.id, tooth],
    queryFn: async () => (await apiClient.get(`/patients/${patient.id}/teeth/${tooth}/history`)).data.data,
    enabled: tooth !== null,
  })
  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['odontogram', slug, patient.id] })
    queryClient.invalidateQueries({ queryKey: ['tooth-history', slug, patient.id] })
  }

  const record = useMutation({
    mutationFn: async () =>
      (
        await apiClient.post(`/attentions/${attention.id}/odontogram-entries`, {
          ...payload(tooth, finding, catalog.data ?? []),
          note: finding.note.trim() === '' ? null : finding.note,
        })
      ).data.data,
    onSuccess: () => {
      setFinding(emptyFinding)
      setNotice('Hallazgo registrado.')
      refresh()
    },
  })
  const correct = useMutation({
    mutationFn: async () => {
      const data = { kind: correction.kind, reason: correction.reason }
      return (
        await apiClient.post(
          `/odontogram-entries/${correcting.id}/corrections`,
          correction.kind === 'reemplazo'
            ? { ...data, ...payload(correcting.tooth, correction, catalog.data ?? []) }
            : data,
        )
      ).data.data
    },
    onSuccess: () => {
      setCorrecting(null)
      setNotice('Corrección registrada.')
      refresh()
    },
  })

  const selectTooth = (next) => {
    setTooth(next)
    setFinding(emptyFinding)
    setNotice(null)
  }
  const recordErrors = fieldErrors(record.error)
  const correctionErrors = fieldErrors(correct.error)

  return (
    <section className="card flex flex-col gap-3" aria-labelledby="findings-title">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <h2 id="findings-title">Odontograma</h2>
        <div className="field">
          <label htmlFor="attention-dentition">Dentición</label>
          <div className="field-control">
            <select
              id="attention-dentition"
              value={dentition ?? odontogram.data?.default_dentition ?? 'permanente'}
              onChange={(event) => setDentition(event.target.value)}
            >
              {Object.entries(DENTITIONS).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </select>
          </div>
        </div>
      </div>

      {odontogram.isError && <div className="alert alert-error">{generalError(odontogram.error)}</div>}
      {odontogram.isSuccess && (
        <Odontogram
          label="Odontograma vigente"
          entries={odontogram.data.entries}
          dentition={dentition ?? odontogram.data.default_dentition}
          selectedTooth={tooth}
          selectedSurfaces={finding.surfaces}
          onSelectTooth={selectTooth}
          onToggleSurface={(surface) =>
            setFinding((current) => ({
              ...current,
              surfaces: current.surfaces.includes(surface)
                ? current.surfaces.filter((s) => s !== surface)
                : [...current.surfaces, surface],
            }))
          }
        />
      )}
      {notice && <div className="alert alert-success">{notice}</div>}

      {tooth === null ? (
        <p className="muted m-0">Elija una pieza en el odontograma o escriba su número.</p>
      ) : (
        <form
          className="form-section flex flex-col gap-3"
          aria-labelledby="record-title"
          onSubmit={(event) => {
            event.preventDefault()
            record.mutate()
          }}
        >
          <h3 id="record-title" className="m-0">
            Registrar hallazgo en la pieza {tooth}
          </h3>
          <FindingFields
            idPrefix="record"
            tooth={tooth}
            catalog={catalog.data ?? []}
            value={finding}
            onChange={setFinding}
            errors={recordErrors}
          />
          <Field label="Nota del hallazgo" name="record-note">
            <textarea
              id="record-note"
              rows={2}
              maxLength={500}
              value={finding.note}
              onChange={(event) => setFinding({ ...finding, note: event.target.value })}
            />
          </Field>
          {recordErrors.tooth && <div className="alert alert-error">{recordErrors.tooth}</div>}
          {record.isError && generalError(record.error) && (
            <div className="alert alert-error">{generalError(record.error)}</div>
          )}
          <div className="form-actions">
            <button type="submit" className="btn" disabled={record.isPending}>
              Registrar hallazgo
            </button>
          </div>
        </form>
      )}

      {tooth !== null && history.isSuccess && (
        <ToothHistory
          tooth={tooth}
          entries={history.data}
          onCorrect={(entry) => {
            correct.reset()
            setCorrection({ kind: 'reemplazo', reason: '', ...emptyFinding, surfaces: entry.surfaces ?? [] })
            setCorrecting(entry)
          }}
        />
      )}

      {correcting && (
        <ConfirmDialog
          title="Corregir la entrada"
          confirmLabel="Confirmar corrección"
          busy={correct.isPending}
          error={generalError(correct.error)}
          onCancel={() => setCorrecting(null)}
          onConfirm={() => correct.mutate()}
        >
          {correcting.author?.id !== attention.dentist.id && (
            <p>Corrige una entrada registrada por {correcting.author?.name}.</p>
          )}
          <p>La entrada original se conserva sin cambios y queda marcada como corregida.</p>
          <fieldset className="m-0 mb-3 border-0 p-0">
            <legend className="text-label-md">Tipo de corrección</legend>
            {[
              ['anulacion', 'Anulación', 'La entrada no debió registrarse.'],
              ['reemplazo', 'Reemplazo', 'Se registra el dato correcto.'],
            ].map(([value, label, hint]) => (
              <div key={value}>
                <label className="checkbox">
                  <input
                    type="radio"
                    name="correction-kind"
                    value={value}
                    checked={correction.kind === value}
                    onChange={() => setCorrection({ ...correction, kind: value })}
                  />
                  {label}
                </label>
                <span className="muted">{hint}</span>
              </div>
            ))}
          </fieldset>
          <Field
            label="Motivo de la corrección"
            name="correction-reason"
            error={correctionErrors.reason}
            hint="Entre 10 y 500 caracteres."
          >
            <textarea
              id="correction-reason"
              rows={2}
              maxLength={500}
              value={correction.reason}
              onChange={(event) => setCorrection({ ...correction, reason: event.target.value })}
            />
          </Field>
          {correction.kind === 'reemplazo' && (
            <FindingFields
              idPrefix="correction"
              tooth={correcting.tooth}
              catalog={catalog.data ?? []}
              value={correction}
              onChange={(value) => setCorrection({ ...correction, ...value })}
              errors={correctionErrors}
            />
          )}
        </ConfirmDialog>
      )}
    </section>
  )
}
