import { Route, Routes } from 'react-router-dom'
import { useClinic } from '../auth/useClinic'
import { NotFoundPage } from '../pages/SimplePages'
import { PortalAppointmentPage } from '../portal/PortalAppointmentPage'
import { PortalAppointmentsPage } from '../portal/PortalAppointmentsPage'
import { PortalBudgetPage } from '../portal/PortalBudgetPage'
import { PortalBudgetsPage } from '../portal/PortalBudgetsPage'
import { PortalConsentPage } from '../portal/PortalConsentPage'
import { PortalHealthPage } from '../portal/PortalHealthPage'
import { PortalHomePage } from '../portal/PortalHomePage'
import { PortalLayout } from '../portal/PortalLayout'
import { PortalNewAppointmentPage } from '../portal/PortalNewAppointmentPage'
import { CalendarIcon, FileTextIcon, HeartPulseIcon, HomeIcon } from '../ui/icons'

/**
 * Área del paciente y de su representante: /c/:slug/portal/* (DD-29, SDD §3.4, §4.3.10). Solo usa
 * las rutas /api/v1/portal/*, que nunca entregan notas clínicas (DI-13).
 */
export default function PortalArea() {
  const { portalPath } = useClinic()
  const tabs = [
    { to: portalPath(), label: 'Inicio', Icon: HomeIcon, end: true },
    { to: portalPath('/citas'), label: 'Citas', Icon: CalendarIcon },
    { to: portalPath('/presupuestos'), label: 'Presupuestos', Icon: FileTextIcon },
    { to: portalPath('/mi-historia'), label: 'Mi salud', Icon: HeartPulseIcon },
  ]

  return (
    <Routes>
      <Route element={<PortalLayout tabs={tabs} />}>
        <Route index element={<PortalHomePage />} />
        <Route path="citas" element={<PortalAppointmentsPage />} />
        <Route path="citas/nueva" element={<PortalNewAppointmentPage />} />
        <Route path="citas/:uuid" element={<PortalAppointmentPage />} />
        <Route path="presupuestos" element={<PortalBudgetsPage />} />
        <Route path="presupuestos/:uuid" element={<PortalBudgetPage />} />
        <Route path="mi-historia" element={<PortalHealthPage />} />
        <Route path="consentimiento" element={<PortalConsentPage />} />
        <Route path="*" element={<NotFoundPage />} />
      </Route>
    </Routes>
  )
}
