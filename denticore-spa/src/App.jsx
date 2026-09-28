import { lazy, Suspense } from 'react'
import { Navigate, Route, Routes } from 'react-router-dom'
import { RequireAuth, RequireTwoFactor } from './auth/guards'
import { homePathFor, PLATFORM_LOGIN_PATH } from './auth/paths'
import { RequireRole } from './auth/RequireRole'
import { STAFF_ROLES } from './auth/roles'
import { useAuth } from './auth/useAuth'
import { LoginPage } from './pages/LoginPage'
import { NotFoundPage } from './pages/SimplePages'

// Code splitting por área (SDD §1.10, RNF-028).
const AdminArea = lazy(() => import('./areas/AdminArea'))
const StaffArea = lazy(() => import('./areas/StaffArea'))
const PortalArea = lazy(() => import('./areas/PortalArea'))

/** RequireAuth → RequireTwoFactor → RequireRole; un rol ajeno al área vuelve a su inicio. */
function Protected({ allow, children }) {
  return (
    <RequireAuth>
      <RequireTwoFactor>
        <AreaRole allow={allow}>
          <Suspense fallback={null}>{children}</Suspense>
        </AreaRole>
      </RequireTwoFactor>
    </RequireAuth>
  )
}

function AreaRole({ allow, children }) {
  const { user } = useAuth()
  return (
    <RequireRole allow={allow} forbiddenPath={homePathFor(user)}>
      {children}
    </RequireRole>
  )
}

function RootRedirect() {
  const { user, isLoading } = useAuth()
  if (isLoading) return null
  return <Navigate to={user ? homePathFor(user) : PLATFORM_LOGIN_PATH} replace />
}

export default function App() {
  return (
    <Routes>
      <Route path="/" element={<RootRedirect />} />
      <Route path="/login" element={<LoginPage />} />
      <Route path="/c/:slug/login" element={<LoginPage />} />

      <Route
        path="/admin/*"
        element={
          <Protected allow={['super_admin']}>
            <AdminArea />
          </Protected>
        }
      />
      <Route
        path="/c/:slug/app/*"
        element={
          <Protected allow={STAFF_ROLES}>
            <StaffArea />
          </Protected>
        }
      />
      <Route
        path="/c/:slug/portal/*"
        element={
          <Protected allow={['patient']}>
            <PortalArea />
          </Protected>
        }
      />

      <Route path="*" element={<NotFoundPage />} />
    </Routes>
  )
}
