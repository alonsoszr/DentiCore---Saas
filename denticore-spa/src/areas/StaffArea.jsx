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
import { AgendaPage } from '../pages/scheduling/AgendaPage'
import { AppointmentDetailPage } from '../pages/scheduling/AppointmentDetailPage'
import { AppointmentNewPage } from '../pages/scheduling/AppointmentNewPage'
import { AppointmentTypesPage } from '../pages/scheduling/AppointmentTypesPage'
import { SchedulePage } from '../pages/scheduling/SchedulePage'
import { BudgetDetailPage } from '../pages/treatment/BudgetDetailPage'
import { BudgetNewPage } from '../pages/treatment/BudgetNewPage'
import { CatalogPage } from '../pages/treatment/CatalogPage'
import { ConsentTemplatesPage } from '../pages/treatment/ConsentTemplatesPage'
import { PlanConsentsPage } from '../pages/treatment/PlanConsentsPage'
import { PlanDetailPage } from '../pages/treatment/PlanDetailPage'

/** Área del personal de la clínica: /c/:slug/app/* (DD-29). */
export default function StaffArea() {
  const { appPath } = useClinic()
  const forbiddenPath = appPath('/sin-acceso')

  const navItems = [
    { to: appPath('/agenda'), label: 'Agenda', roles: STAFF_ROLES, end: true },
    { to: appPath('/pacientes'), label: 'Pacientes', roles: STAFF_ROLES },
    { to: appPath('/agenda/horarios'), label: 'Horarios', roles: ['clinic_admin', 'dentist'] },
    { to: appPath('/agenda/tipos'), label: 'Tipos de cita', roles: ['clinic_admin'] },
    { to: appPath('/catalogo'), label: 'Catálogo', roles: ['clinic_admin'] },
    { to: appPath('/usuarios'), label: 'Usuarios', roles: ['clinic_admin'] },
    { to: appPath('/configuracion'), label: 'Configuración', roles: ['clinic_admin'], end: true },
    { to: appPath('/configuracion/consentimientos'), label: 'Consentimientos', roles: ['clinic_admin'] },
  ]

  return (
    <Routes>
      <Route element={<Layout items={navItems} />}>
        <Route index element={<Navigate to="pacientes" replace />} />
        <Route path="sin-acceso" element={<ForbiddenPage />} />
        <Route path="agenda" element={<AgendaPage />} />
        <Route
          path="agenda/nueva"
          element={
            <RequireRole allow={['clinic_admin', 'receptionist']} forbiddenPath={forbiddenPath}>
              <AppointmentNewPage />
            </RequireRole>
          }
        />
        <Route path="agenda/:uuid" element={<AppointmentDetailPage />} />
        <Route
          path="agenda/horarios"
          element={
            <RequireRole allow={['clinic_admin', 'dentist']} forbiddenPath={forbiddenPath}>
              <SchedulePage />
            </RequireRole>
          }
        />
        <Route
          path="agenda/tipos"
          element={
            <RequireRole allow={['clinic_admin']} forbiddenPath={forbiddenPath}>
              <AppointmentTypesPage />
            </RequireRole>
          }
        />
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
          path="configuracion/consentimientos"
          element={
            <RequireRole allow={['clinic_admin']} forbiddenPath={forbiddenPath}>
              <ConsentTemplatesPage />
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
        <Route path="planes/:uuid/presupuesto" element={<BudgetNewPage />} />
        <Route path="planes/:uuid/consentimientos" element={<PlanConsentsPage />} />
        <Route path="presupuestos/:uuid" element={<BudgetDetailPage />} />
        <Route path="pacientes" element={<PatientsPage />} />
        <Route path="pacientes/nuevo" element={<PatientCreatePage />} />
        <Route path="pacientes/:uuid/*" element={<PatientDetailPage />} />
        {['atenciones/:uuid', 'atenciones/:uuid/procedimientos'].map((path) => (
          <Route
            key={path}
            path={path}
            element={
              <RequireRole allow={['clinic_admin', 'dentist']} forbiddenPath={forbiddenPath}>
                <AttentionPage />
              </RequireRole>
            }
          />
        ))}
        <Route path="*" element={<NotFoundPage />} />
      </Route>
    </Routes>
  )
}
