import { Alert } from '../../components/Alert'
import { PatientConsentBadge } from './ConsentStatusBadge'
import { useOtherPatientTabs } from './useOtherPatientTabs'

const listFormat = new Intl.ListFormat('es-PE', { style: 'long', type: 'conjunction' })

/**
 * Cabecera fija del paciente en toda pantalla clínica (RNF-146): nombre, edad, número de HC,
 * estado del consentimiento, aviso permanente de alergias (RF-064, RNF-149) y aviso si el usuario
 * tiene abierto otro paciente en otra pestaña (RNF-146). `headingLevel` es 1
 * (el paciente es el título de la página) o 2 (bajo el título de otra página, como la atención).
 */
export function PatientHeader({ patient, headingLevel = 1 }) {
  const Heading = headingLevel === 1 ? 'h1' : 'h2'
  const allergies = patient.allergies ?? []
  const otherTabs = useOtherPatientTabs(patient)

  return (
    <section className="card patient-header" aria-label="Paciente">
      <div className="flex flex-wrap items-center gap-3">
        <Heading className="m-0 text-headline-md">
          {patient.first_name} {patient.last_name}
        </Heading>
        <span className="muted">{patient.age_years} años</span>
        <span className="muted">HC {patient.clinical_record_number}</span>
        <PatientConsentBadge patient={patient} />
      </div>
      {allergies.length > 0 ? (
        <Alert tone="warning" title="Alergias" className="mt-3">
          {allergies.join(', ')}
        </Alert>
      ) : (
        <p className="m-0 mt-2 muted">Sin alergias registradas.</p>
      )}
      {/* Región de estado siempre montada: el lector de pantalla anuncia el aviso cuando aparece. */}
      <div role="status" aria-atomic="true">
        {otherTabs.length > 0 && (
          <Alert tone="warning" role={null} title="Otro paciente abierto en otra pestaña" className="mt-3">
            En otra pestaña tienes {otherTabs.length === 1 ? 'abierta la ficha' : 'abiertas las fichas'} de{' '}
            {listFormat.format(otherTabs.map((other) => other.name))}. Lo que registres aquí queda en la ficha de{' '}
            {patient.first_name} {patient.last_name}.
          </Alert>
        )}
      </div>
    </section>
  )
}
