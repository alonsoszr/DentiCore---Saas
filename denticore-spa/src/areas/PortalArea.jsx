import { Route, Routes } from 'react-router-dom'
import { useClinic } from '../auth/useClinic'
import { Layout } from '../components/Layout'
import { NotFoundPage, PatientHomePage } from '../pages/SimplePages'

/**
 * Área del paciente: /c/:slug/portal/* (DD-29). Por ahora solo "Mi ficha"; el portal de
 * E1 (TASK-085 a TASK-089) la reemplaza con las rutas /api/v1/portal/*.
 */
export default function PortalArea() {
  const { portalPath } = useClinic()
  const navItems = [{ to: portalPath(), label: 'Mi ficha' }]

  return (
    <Routes>
      <Route element={<Layout items={navItems} />}>
        <Route index element={<PatientHomePage />} />
        <Route path="*" element={<NotFoundPage />} />
      </Route>
    </Routes>
  )
}
