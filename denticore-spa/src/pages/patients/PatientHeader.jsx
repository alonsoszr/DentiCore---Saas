import { Alert } from '../../components/Alert'

/**
 * Cabecera fija del paciente en toda pantalla clínica (RNF-146): nombre, edad, número de HC,
 * estado del consentimiento y aviso permanente de alergias (RF-064, RNF-149).
 */
export function PatientHeader({ patient }) {
  const allergies = patient.allergies ?? []
  let consent = { label: 'Sin consentimiento vigente', className: 'badge badge-muted' }
  if (patient.has_current_consent) {
    consent = patient.consent_outdated
      ? { label: 'Consentimiento de una versión anterior', className: 'badge badge-muted' }
      : { label: 'Consentimiento vigente', className: 'badge' }
  }

  return (
    <section className="card patient-header" aria-label="Paciente">
      <div className="flex flex-wrap items-center gap-3">
        <h1 className="m-0 text-headline-md">
          {patient.first_name} {patient.last_name}
        </h1>
        <span className="muted">{patient.age_years} años</span>
        <span className="muted">HC {patient.clinical_record_number}</span>
        <span className={consent.className}>{consent.label}</span>
      </div>
      {allergies.length > 0 ? (
        <Alert tone="warning" title="Alergias" className="mt-3">
          {allergies.join(', ')}
        </Alert>
      ) : (
        <p className="m-0 mt-2 muted">Sin alergias registradas.</p>
      )}
    </section>
  )
}
