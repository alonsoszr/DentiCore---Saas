import { STAFF_ROLES } from './roles'

/**
 * Rutas de la SPA (SDD §1.10, DD-29): /login para el Súper Administrador,
 * /c/:slug/login para cada clínica, /admin/* (plataforma), /c/:slug/app/* (personal)
 * y /c/:slug/portal/* (paciente).
 */
export const PLATFORM_LOGIN_PATH = '/login'

export function loginPathFor(slug) {
  return slug ? `/c/${slug}/login` : PLATFORM_LOGIN_PATH
}

export function adminPath(path = '') {
  return `/admin${path}`
}

export function appPath(slug, path = '') {
  return `/c/${slug}/app${path}`
}

export function portalPath(slug, path = '') {
  return `/c/${slug}/portal${path}`
}

/** Pantalla inicial de cada usuario tras iniciar sesión. */
export function homePathFor(user) {
  if (!user) return PLATFORM_LOGIN_PATH
  if (user.role === 'super_admin') return adminPath('/clinicas')
  const slug = user.tenant?.slug
  if (STAFF_ROLES.includes(user.role)) return appPath(slug, '/pacientes')
  return portalPath(slug)
}

/** Código de clínica de una ruta /c/:slug/..., o null fuera de una clínica. */
export function slugFromPathname(pathname) {
  return pathname.match(/^\/c\/([^/]+)/)?.[1] ?? null
}

/** Verificación del segundo factor (A3): /c/:slug/login/2fa o /login/2fa (PEND-02). */
export function twoFactorVerifyPath(slug) {
  return `${loginPathFor(slug)}/2fa`
}

/** Configuración del segundo factor (A4; PEND-07): dentro del área del usuario. */
export function twoFactorSetupPath(slug) {
  return slug ? appPath(slug, '/seguridad/2fa') : adminPath('/seguridad/2fa')
}
