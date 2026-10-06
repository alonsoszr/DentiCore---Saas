import { Link, NavLink, Route, Routes, useLocation, useParams } from 'react-router-dom'
import { generalError } from '../../api/errors'
import { useAuth } from '../../auth/useAuth'
import { useClinic } from '../../auth/useClinic'
import { NotFoundPage } from '../SimplePages'
import { ConsentPage } from './ConsentPage'
import { MedicalHistoryPage } from './MedicalHistoryPage'
import { PatientEditPage } from './PatientEditPage'
import { PatientHeader } from './PatientHeader'
import { PatientRecord } from './PatientRecord'
import { RepresentativesPage } from './RepresentativesPage'
import { usePatient } from './usePatient'

const IDENTITY_ROLES = ['clinic_admin', 'receptionist']

/**
 * Ficha del paciente (/c/:slug/app/pacientes/:uuid/*; CUS-13 a CUS-17, CUS-21). La cabecera
 * con la identidad del paciente encabeza todas sus pantallas (RNF-146).
 */
export function PatientDetailPage() {
  const { uuid } = useParams()
  const location = useLocation()
  const { user } = useAuth()
  const { appPath } = useClinic()
  const patientQuery = usePatient(uuid)
  const patient = patientQuery.data
  const base = appPath(`/pacientes/${uuid}`)

  if (patientQuery.isLoading) return <div className="card empty">Cargando…</div>
  if (patientQuery.isError) return <div className="alert alert-error">{generalError(patientQuery.error)}</div>

  const tabs = [
    { to: base, label: 'Ficha', end: true },
    { to: `${base}/antecedentes`, label: 'Antecedentes' },
    { to: `${base}/consentimiento`, label: 'Consentimiento' },
    { to: `${base}/representantes`, label: 'Representantes' },
    ...(IDENTITY_ROLES.includes(user?.role) ? [{ to: `${base}/editar`, label: 'Editar identificación' }] : []),
  ]

  return (
    <>
      <div className="page-header">
        <nav className="tabs" aria-label="Secciones de la ficha">
          {tabs.map((tab) => (
            <NavLink key={tab.to} to={tab.to} end={tab.end}>
              {tab.label}
            </NavLink>
          ))}
        </nav>
        <Link to={appPath('/pacientes')} className="btn btn-secondary">
          Volver
        </Link>
      </div>

      <PatientHeader patient={patient} />

      {location.state?.created && <div className="alert alert-success">Paciente registrado correctamente.</div>}

      <Routes>
        <Route index element={<PatientRecord query={patientQuery} />} />
        <Route path="antecedentes" element={<MedicalHistoryPage patient={patient} />} />
        <Route path="consentimiento" element={<ConsentPage patient={patient} />} />
        <Route path="representantes" element={<RepresentativesPage patient={patient} />} />
        <Route path="editar" element={<PatientEditPage patient={patient} />} />
        <Route path="*" element={<NotFoundPage />} />
      </Routes>
    </>
  )
}
