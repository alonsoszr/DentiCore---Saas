import { Navigate, Route, Routes } from 'react-router-dom'
import { adminPath } from '../auth/paths'
import { Layout } from '../components/Layout'
import { NotFoundPage } from '../pages/SimplePages'
import { TenantsPage } from '../pages/TenantsPage'

const NAV_ITEMS = [{ to: adminPath('/clinicas'), label: 'Clínicas' }]

/** Área del Súper Administrador: /admin/* (DD-29). */
export default function AdminArea() {
  return (
    <Routes>
      <Route element={<Layout items={NAV_ITEMS} />}>
        <Route index element={<Navigate to="clinicas" replace />} />
        <Route path="clinicas" element={<TenantsPage />} />
        <Route path="*" element={<NotFoundPage />} />
      </Route>
    </Routes>
  )
}
