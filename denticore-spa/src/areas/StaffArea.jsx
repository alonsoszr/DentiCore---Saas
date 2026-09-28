import { Navigate, Route, Routes } from 'react-router-dom'
import { RequireRole } from '../auth/RequireRole'
import { STAFF_ROLES } from '../auth/roles'
import { useClinic } from '../auth/useClinic'
import { Layout } from '../components/Layout'
import { ForbiddenPage, NotFoundPage } from '../pages/SimplePages'
import { UsersPage } from '../pages/UsersPage'
import { PatientCreatePage } from '../pages/patients/PatientCreatePage'
import { PatientDetailPage } from '../pages/patients/PatientDetailPage'
import { PatientsPage } from '../pages/patients/PatientsPage'

/** Área del personal de la clínica: /c/:slug/app/* (DD-29). */
export default function StaffArea() {
  const { appPath } = useClinic()
  const forbiddenPath = appPath('/sin-acceso')

  const navItems = [
    { to: appPath('/pacientes'), label: 'Pacientes', roles: STAFF_ROLES },
    { to: appPath('/usuarios'), label: 'Usuarios', roles: ['clinic_admin'] },
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
        <Route path="pacientes" element={<PatientsPage />} />
        <Route path="pacientes/nuevo" element={<PatientCreatePage />} />
        <Route path="pacientes/:uuid" element={<PatientDetailPage />} />
        <Route path="*" element={<NotFoundPage />} />
      </Route>
    </Routes>
  )
}
