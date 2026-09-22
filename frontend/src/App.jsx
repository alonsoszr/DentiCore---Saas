import { Navigate, Route, Routes } from 'react-router-dom'
import { RequireRole } from './auth/RequireRole'
import { ROLE_LABELS, STAFF_ROLES, homePathFor } from './auth/roles'
import { useAuth } from './auth/useAuth'
import { Layout } from './components/Layout'
import { LoginPage } from './pages/LoginPage'
import { ForbiddenPage, NotFoundPage, PatientHomePage } from './pages/SimplePages'
import { TenantsPage } from './pages/TenantsPage'
import { UsersPage } from './pages/UsersPage'
import { PatientCreatePage } from './pages/patients/PatientCreatePage'
import { PatientDetailPage } from './pages/patients/PatientDetailPage'
import { PatientsPage } from './pages/patients/PatientsPage'

const ALL_ROLES = Object.keys(ROLE_LABELS)
const FORBIDDEN_PATH = '/sin-acceso'

/** Guard por rol (UX): la autorización real la aplica siempre el backend. */
function only(roles, element) {
  return (
    <RequireRole allow={roles} forbiddenPath={FORBIDDEN_PATH}>
      {element}
    </RequireRole>
  )
}

function HomeRedirect() {
  const { user } = useAuth()
  return <Navigate to={homePathFor(user.role)} replace />
}

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />

      <Route element={only(ALL_ROLES, <Layout />)}>
        <Route index element={<HomeRedirect />} />
        <Route path={FORBIDDEN_PATH} element={<ForbiddenPage />} />

        <Route path="/clinicas" element={only(['super_admin'], <TenantsPage />)} />
        <Route path="/usuarios" element={only(['clinic_admin'], <UsersPage />)} />
        <Route path="/pacientes" element={only(STAFF_ROLES, <PatientsPage />)} />
        <Route path="/pacientes/nuevo" element={only(STAFF_ROLES, <PatientCreatePage />)} />
        <Route path="/pacientes/:uuid" element={only(STAFF_ROLES, <PatientDetailPage />)} />
        <Route path="/inicio" element={only(['patient'], <PatientHomePage />)} />

        <Route path="*" element={<NotFoundPage />} />
      </Route>
    </Routes>
  )
}
