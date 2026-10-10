import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import { loginPathFor } from '../auth/paths'
import { ROLE_LABELS } from '../auth/roles'
import { useAuth } from '../auth/useAuth'
import { SessionTimeout } from '../ui/SessionTimeout'

/**
 * Estructura común de las áreas autenticadas. Cada área (admin, app, portal) define
 * su menú en `items` ({ to, label, roles }).
 */
export function Layout({ items }) {
  const { user, logout } = useAuth()
  const navigate = useNavigate()

  const handleLogout = async () => {
    const loginPath = loginPathFor(user.tenant?.slug)
    await logout().catch(() => {})
    navigate(loginPath, { replace: true })
  }

  return (
    <div className="app">
      <aside className="sidebar">
        <div className="brand">
          DentiCore
          <small>{user.tenant?.name ?? 'Plataforma'}</small>
        </div>

        <nav className="nav">
          {items
            .filter((item) => !item.roles || item.roles.includes(user.role))
            .map((item) => (
              <NavLink key={item.to} to={item.to} end={item.end}>
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
      <SessionTimeout />
    </div>
  )
}
