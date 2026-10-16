import { Route, Routes } from 'react-router-dom'
import { useClinic } from '../auth/useClinic'
import { NotFoundPage } from '../pages/SimplePages'
import { PortalBudgetPage } from '../portal/PortalBudgetPage'
import { PortalBudgetsPage } from '../portal/PortalBudgetsPage'
import { PortalHomePage } from '../portal/PortalHomePage'
import { PortalLayout } from '../portal/PortalLayout'
import { FileTextIcon, HomeIcon } from '../ui/icons'

/**
 * Área del paciente y de su representante: /c/:slug/portal/* (DD-29, SDD §4.3.10). Solo usa las
 * rutas /api/v1/portal/*, que nunca entregan notas clínicas (DI-13).
 */
export default function PortalArea() {
  const { portalPath } = useClinic()
  const tabs = [
    { to: portalPath(), label: 'Inicio', Icon: HomeIcon, end: true },
    { to: portalPath('/presupuestos'), label: 'Presupuestos', Icon: FileTextIcon },
  ]

  return (
    <Routes>
      <Route element={<PortalLayout tabs={tabs} />}>
        <Route index element={<PortalHomePage />} />
        <Route path="presupuestos" element={<PortalBudgetsPage />} />
        <Route path="presupuestos/:uuid" element={<PortalBudgetPage />} />
        <Route path="*" element={<NotFoundPage />} />
      </Route>
    </Routes>
  )
}
