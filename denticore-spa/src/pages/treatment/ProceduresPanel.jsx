import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { apiClient } from '../../api/client'
import { fieldErrors, generalError } from '../../api/errors'
import { useClinic } from '../../auth/useClinic'
import { Alert } from '../../components/Alert'
import { ConfirmDialog } from '../../components/ConfirmDialog'
import { Field } from '../../components/Field'
import { InlineError } from './InlineError'
import { ItemFields } from './ItemFields'
import { EMPTY_ITEM, SIGNERS, siteText } from './labels'
import { useFocusOnMount, usePatientPlans, useProcedures } from './useTreatment'

const ACTIVE_PLANS = ['aceptado', 'en_ejecucion']

/** Alergias del paciente en la confirmación: se revisan antes de cada procedimiento (RNF-146). */
function AllergiesNotice({ patient }) {
  const allergies = patient.allergies ?? []
  return allergies.length > 0 ? (
    <Alert tone="warning" title="Alergias registradas">
      {allergies.join(', ')}
    </Alert>
  ) : (
    <p>El paciente no tiene alergias registradas.</p>
  )
}

function useAfterProcedure(patient, onDone) {
  const queryClient = useQueryClient()
  const { slug } = useClinic()
  return (performed) => {
    queryClient.invalidateQueries({ queryKey: ['treatment-plans', slug, patient.id] })
    queryClient.invalidateQueries({ queryKey: ['treatment-plan'] })
    queryClient.invalidateQueries({ queryKey: ['budgets'] })
    queryClient.invalidateQueries({ queryKey: ['odontogram', slug, patient.id] })
    onDone(performed)
  }
}

/** Registro de un ítem aceptado con su cantidad pendiente (RF-126, CA-39.3, CA-39.4). */
function PerformForm({ attention, patient, item, onDone, onCancel }) {
  const pending = item.quantity - item.performed_quantity
  const [quantity, setQuantity] = useState(String(pending))
  const [observations, setObservations] = useState('')
  const [confirming, setConfirming] = useState(false)
  const after = useAfterProcedure(patient, onDone)
  const perform = useMutation({
    mutationFn: async () =>
      (
        await apiClient.post(`/plan-items/${item.id}/performed-procedures`, {
          attention_id: attention.id,
          quantity: Number(quantity),
          observations: observations.trim() === '' ? null : observations.trim(),
        })
      ).data.data,
    onSuccess: after,
    onSettled: () => setConfirming(false),
  })
  const errors = fieldErrors(perform.error)
  const prefix = `perform-${item.id}`
  const formRef = useFocusOnMount()

  return (
    <form
      ref={formRef}
      className="form-section"
      noValidate
      onSubmit={(event) => {
        event.preventDefault()
        perform.reset()
        setConfirming(true)
      }}
    >
      <h3>
        {item.procedure.name}
        {item.tooth !== null && ` · pieza ${siteText(item.tooth, item.surfaces)}`}
      </h3>
      {generalError(perform.error) && (
        <div className="alert alert-error" role="alert">
          {generalError(perform.error)}
        </div>
      )}
      <div className="form-grid">
        <Field
          label={`Cantidad (pendiente: ${pending})`}
          name={`${prefix}-quantity`}
          type="number"
          inputMode="numeric"
          min="1"
          max={pending}
          value={quantity}
          onChange={(event) => setQuantity(event.target.value)}
          error={errors.quantity}
        />
      </div>
      <Field label="Observaciones (opcional)" name={`${prefix}-observations`} error={errors.observations}>
        <textarea
          id={`${prefix}-observations`}
          rows={2}
          maxLength={1000}
          aria-invalid={errors.observations ? true : undefined}
          aria-describedby={errors.observations ? `${prefix}-observations-error` : undefined}
          value={observations}
          onChange={(event) => setObservations(event.target.value)}
        />
      </Field>
      <InlineError>{errors.attention_id}</InlineError>
      <div className="form-actions">
        <button type="submit" className="btn" disabled={quantity === '' || perform.isPending}>
          Registrar procedimiento
        </button>
        <button type="button" className="btn btn-secondary" onClick={onCancel}>
          Cancelar
        </button>
      </div>

      {confirming && (
        <ConfirmDialog
          title={`¿Registrar ${item.procedure.name}?`}
          confirmLabel="Confirmar registro"
          onConfirm={() => perform.mutate()}
          onCancel={() => setConfirming(false)}
          busy={perform.isPending}
        >
          <AllergiesNotice patient={patient} />
          <p>
            Se registra la cantidad {quantity} en esta atención con tu firma. Si el procedimiento tiene hallazgo
            resultante, se agrega su evolución al odontograma. El registro no se puede modificar.
          </p>
        </ConfirmDialog>
      )}
    </form>
  )
}

/**
 * Procedimiento de urgencia (CUS-39 FA-1, RF-128): crea en un paso el plan, el presupuesto aceptado
 * por quien firma y el procedimiento realizado.
 */
function UrgentForm({ attention, patient, procedures, onDone, onCancel }) {
  const [item, setItem] = useState({ ...EMPTY_ITEM })
  // Por un menor acepta su representante (RN-12).
  const [signer, setSigner] = useState(patient.age_years < 18 ? 'representante' : 'titular')
  const [documentNumber, setDocumentNumber] = useState('')
  const [confirming, setConfirming] = useState(false)
  const after = useAfterProcedure(patient, onDone)
  const urgent = useMutation({
    mutationFn: async () =>
      (
        await apiClient.post(`/attentions/${attention.id}/urgent-procedures`, {
          procedure_id: item.procedure_id,
          tooth: item.tooth === '' ? null : Number(item.tooth),
          surfaces: item.surfaces,
          quantity: Number(item.quantity || 1),
          observations: item.observations.trim() === '' ? null : item.observations.trim(),
          signer,
          signer_document_number: documentNumber.trim(),
        })
      ).data.data,
    onSuccess: after,
    onSettled: () => setConfirming(false),
  })
  const errors = fieldErrors(urgent.error)
  const procedure = procedures.find((entry) => entry.id === item.procedure_id)
  const formRef = useFocusOnMount()

  return (
    <form
      ref={formRef}
      className="form-section"
      noValidate
      onSubmit={(event) => {
        event.preventDefault()
        urgent.reset()
        setConfirming(true)
      }}
    >
      <h3>Procedimiento de urgencia</h3>
      {generalError(urgent.error) && (
        <div className="alert alert-error" role="alert">
          {generalError(urgent.error)}
        </div>
      )}
      <ItemFields
        idPrefix="urgent"
        value={item}
        onChange={setItem}
        procedures={procedures}
        errors={errors}
        withSession={false}
      />
      <div className="form-grid">
        <Field label="Quién acepta" name="urgent-signer" error={errors.signer}>
          <select
            id="urgent-signer"
            value={signer}
            onChange={(event) => setSigner(event.target.value)}
            aria-invalid={errors.signer ? true : undefined}
            aria-describedby={errors.signer ? 'urgent-signer-error' : undefined}
          >
            {Object.entries(SIGNERS).map(([value, label]) => (
              <option key={value} value={value}>
                {label}
              </option>
            ))}
          </select>
        </Field>
        <Field
          label="Documento de quien acepta"
          name="urgent-document"
          value={documentNumber}
          onChange={(event) => setDocumentNumber(event.target.value)}
          error={errors.signer_document_number}
          autoComplete="off"
          maxLength={12}
        />
      </div>
      <div className="form-actions">
        <button
          type="submit"
          className="btn"
          disabled={item.procedure_id === '' || documentNumber.trim() === '' || urgent.isPending}
        >
          Registrar urgencia
        </button>
        <button type="button" className="btn btn-secondary" onClick={onCancel}>
          Cancelar
        </button>
      </div>

      {confirming && (
        <ConfirmDialog
          title="¿Registrar el procedimiento de urgencia?"
          confirmLabel="Confirmar urgencia"
          onConfirm={() => urgent.mutate()}
          onCancel={() => setConfirming(false)}
          busy={urgent.isPending}
        >
          <AllergiesNotice patient={patient} />
          <p>
            Se crea un plan de urgencia con {procedure?.name ?? 'el procedimiento'}, se emite su presupuesto aceptado
            por quien firma y se registra el procedimiento en esta atención. Nada de esto se puede modificar.
          </p>
        </ConfirmDialog>
      )}
    </form>
  )
}

/**
 * Procedimientos de la atención (/c/:slug/app/atenciones/:uuid/procedimientos; CUS-39; RF-126 a
 * RF-128, RN-38, RN-39, RN-76): los ítems aceptados del paciente con cantidad pendiente y el
 * procedimiento de urgencia. La API rechaza lo que no cumpla (consentimiento, pieza ausente).
 */
export function ProceduresPanel({ attention, patient }) {
  const plans = usePatientPlans(patient.id)
  const procedures = useProcedures()
  const [performing, setPerforming] = useState(null)
  const [urgent, setUrgent] = useState(false)
  const [done, setDone] = useState(null)

  const items = (plans.data ?? [])
    .filter((plan) => ACTIVE_PLANS.includes(plan.status))
    .flatMap((plan) =>
      plan.items
        .filter((item) => item.status === 'aceptado' && item.quantity > item.performed_quantity)
        .map((item) => ({ ...item, planTitle: plan.title })),
    )
  const finish = (performed) => {
    setPerforming(null)
    setUrgent(false)
    setDone(performed)
  }

  return (
    <section className="card" aria-labelledby="procedures-title" id="procedimientos">
      <div className="page-header">
        <h2 id="procedures-title" className="m-0">
          Procedimientos
        </h2>
        {!urgent && (
          <button
            type="button"
            className="btn btn-secondary"
            onClick={() => {
              setDone(null)
              setPerforming(null)
              setUrgent(true)
            }}
          >
            Procedimiento de urgencia
          </button>
        )}
      </div>

      {done && (
        <Alert tone="success" title="Procedimiento registrado" className="mb-4">
          {done.odontogram_entry_id
            ? 'Su evolución se agregó al odontograma.'
            : 'El procedimiento no tiene hallazgo resultante: el odontograma no cambia.'}
        </Alert>
      )}
      {plans.isError && (
        <div className="alert alert-error" role="alert">
          {generalError(plans.error)}
        </div>
      )}
      {plans.isSuccess && items.length === 0 && (
        <div className="empty">El paciente no tiene ítems aceptados pendientes de realizar.</div>
      )}
      {items.length > 0 && (
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Procedimiento</th>
                <th>Pieza</th>
                <th>Plan</th>
                <th>Realizado</th>
                <th>
                  <span className="sr-only">Acciones</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {items.map((item) => (
                <tr key={item.id}>
                  <td>{item.procedure.name}</td>
                  <td>{siteText(item.tooth, item.surfaces)}</td>
                  <td>{item.planTitle}</td>
                  <td>
                    {item.performed_quantity} de {item.quantity}
                  </td>
                  <td>
                    <button
                      type="button"
                      className="btn-link"
                      aria-label={`Registrar ${item.procedure.name}`}
                      onClick={() => {
                        setDone(null)
                        setUrgent(false)
                        setPerforming(item.id)
                      }}
                    >
                      Registrar
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {performing && items.some((item) => item.id === performing) && (
        <PerformForm
          key={performing}
          attention={attention}
          patient={patient}
          item={items.find((item) => item.id === performing)}
          onDone={finish}
          onCancel={() => setPerforming(null)}
        />
      )}
      {urgent && (
        <UrgentForm
          attention={attention}
          patient={patient}
          procedures={procedures.data ?? []}
          onDone={finish}
          onCancel={() => setUrgent(false)}
        />
      )}
    </section>
  )
}
