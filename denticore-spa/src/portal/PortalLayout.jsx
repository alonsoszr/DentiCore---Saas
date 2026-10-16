import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import { loginPathFor } from '../auth/paths'
import { useAuth } from '../auth/useAuth'
import { SessionTimeout } from '../ui/SessionTimeout'

/**
 * Estructura del portal del paciente (DESIGN.md › Patient portal): una columna desde 360 px,
 * pestañas inferiores en el móvil (arriba desde 1024 px), texto de 16 px y controles de 48 px.
 * `tabs` es la lista de pestañas ({ to, label, Icon, end }).
 */
export function PortalLayout({ tabs }) {
  const { user, logout } = useAuth()
  const navigate = useNavigate()

  const handleLogout = async () => {
    const loginPath = loginPathFor(user.tenant?.slug)
    await logout().catch(() => {})
    navigate(loginPath, { replace: true })
  }

  return (
    <div className="portal">
      <a href="#portal-main" className="portal-skip">
        Ir al contenido
      </a>
      <header className="portal-header">
        <div className="brand">
          DentiCore
          <small>{user.tenant?.name}</small>
        </div>
        <button type="button" className="btn btn-secondary" onClick={handleLogout}>
          Cerrar sesión
        </button>
      </header>

      <nav className="portal-tabs" aria-label="Secciones del portal" style={{ '--portal-tabs': tabs.length }}>
        {tabs.map(({ to, label, Icon, end }) => (
          <NavLink key={to} to={to} end={end}>
            <Icon className="size-5" />
            {label}
          </NavLink>
        ))}
      </nav>

      <main id="portal-main" className="portal-main" tabIndex={-1}>
        <Outlet />
      </main>
      <SessionTimeout />
    </div>
  )
}
