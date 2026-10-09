import { Navigate, Route, Routes } from 'react-router-dom'
import { RequireRole } from '../auth/RequireRole'
import { STAFF_ROLES } from '../auth/roles'
import { useClinic } from '../auth/useClinic'
import { Layout } from '../components/Layout'
import { ClinicSettingsPage } from '../pages/ClinicSettingsPage'
import { ForbiddenPage, NotFoundPage } from '../pages/SimplePages'
import { UsersPage } from '../pages/UsersPage'
import { AttentionPage } from '../pages/attentions/AttentionPage'
import { PatientCreatePage } from '../pages/patients/PatientCreatePage'
import { PatientDetailPage } from '../pages/patients/PatientDetailPage'
import { PatientsPage } from '../pages/patients/PatientsPage'
import { CatalogPage } from '../pages/treatment/CatalogPage'
import { PlanDetailPage } from '../pages/treatment/PlanDetailPage'

/** Área del personal de la clínica: /c/:slug/app/* (DD-29). */
export default function StaffArea() {
  const { appPath } = useClinic()
  const forbiddenPath = appPath('/sin-acceso')

  const navItems = [
    { to: appPath('/pacientes'), label: 'Pacientes', roles: STAFF_ROLES },
    { to: appPath('/catalogo'), label: 'Catálogo', roles: ['clinic_admin'] },
    { to: appPath('/usuarios'), label: 'Usuarios', roles: ['clinic_admin'] },
    { to: appPath('/configuracion'), label: 'Configuración', roles: ['clinic_admin'] },
  ]

  return (
    <Routes>
      <Route element={<Layout items={navItems} />}>
        <Route index element={<Navigate to="pacientes" replace />} />
        <Route path="sin-acceso" element={<ForbiddenPage />} />
        <Route
          path="usuarios"
          element={
            <RequireRole allow={['clinic_admin']} forbiddenPath={forbiddenPath}>
              <UsersPage />
            </RequireRole>
          }
        />
        <Route
          path="configuracion"
          element={
            <RequireRole allow={['clinic_admin']} forbiddenPath={forbiddenPath}>
              <ClinicSettingsPage />
            </RequireRole>
          }
        />
        <Route
          path="catalogo"
          element={
            <RequireRole allow={['clinic_admin']} forbiddenPath={forbiddenPath}>
              <CatalogPage />
            </RequireRole>
          }
        />
        <Route path="planes/:uuid" element={<PlanDetailPage />} />
        <Route path="pacientes" element={<PatientsPage />} />
        <Route path="pacientes/nuevo" element={<PatientCreatePage />} />
        <Route path="pacientes/:uuid/*" element={<PatientDetailPage />} />
        <Route
          path="atenciones/:uuid"
          element={
            <RequireRole allow={['clinic_admin', 'dentist']} forbiddenPath={forbiddenPath}>
              <AttentionPage />
            </RequireRole>
          }
        />
        <Route path="*" element={<NotFoundPage />} />
      </Route>
    </Routes>
  )
}
