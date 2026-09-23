import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import { useAuth } from '../auth/useAuth'
import { ROLE_LABELS, STAFF_ROLES } from '../auth/roles'

const NAV_ITEMS = [
  { to: '/clinicas', label: 'Clínicas', roles: ['super_admin'] },
  { to: '/pacientes', label: 'Pacientes', roles: STAFF_ROLES },
  { to: '/usuarios', label: 'Usuarios', roles: ['clinic_admin'] },
  { to: '/inicio', label: 'Mi ficha', roles: ['patient'] },
]

export function Layout() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()

  const handleLogout = async () => {
    await logout().catch(() => {})
    navigate('/login', { replace: true })
  }

  return (
    <div className="app">
      <aside className="sidebar">
        <div className="brand">
          DentiCore
          <small>{user.tenant?.name ?? 'Plataforma'}</small>
        </div>

        <nav className="nav">
          {NAV_ITEMS.filter((item) => item.roles.includes(user.role)).map((item) => (
            <NavLink key={item.to} to={item.to}>
              {item.label}
            </NavLink>
          ))}
        </nav>

        <div className="sidebar-footer">
          <div>
            <strong>{user.name}</strong>
            <div>{ROLE_LABELS[user.role]}</div>
          </div>
          <div>
            <button type="button" className="btn btn-secondary" onClick={handleLogout}>
              Cerrar sesión
            </button>
          </div>
        </div>
      </aside>

      <main className="main">
        <Outlet />
      </main>
    </div>
  )
}
