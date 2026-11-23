import { Navigate, Route, Routes } from 'react-router-dom'
import { adminPath } from '../auth/paths'
import { Layout } from '../components/Layout'
import { NotFoundPage } from '../pages/SimplePages'
import { TenantDetailPage } from '../pages/TenantDetailPage'
import { TenantsPage } from '../pages/TenantsPage'
import { AuditLogPage } from '../pages/compliance/AuditLogPage'
import { ProfilePage } from '../pages/compliance/ProfilePage'

const NAV_ITEMS = [
  { to: adminPath('/clinicas'), label: 'Clínicas' },
  { to: adminPath('/auditoria'), label: 'Bitácora' },
  { to: adminPath('/perfil'), label: 'Mi perfil' },
]

/** Área del Súper Administrador: /admin/* (DD-29). */
export default function AdminArea() {
  return (
    <Routes>
      <Route element={<Layout items={NAV_ITEMS} />}>
        <Route index element={<Navigate to="clinicas" replace />} />
        <Route path="clinicas" element={<TenantsPage />} />
        <Route path="clinicas/:uuid" element={<TenantDetailPage />} />
        <Route path="auditoria" element={<AuditLogPage platform />} />
        <Route path="perfil" element={<ProfilePage />} />
        <Route path="*" element={<NotFoundPage />} />
      </Route>
    </Routes>
  )
}
