/**
 * Token Bearer de la sesión (SDD §1.7, DD-44): solo en sessionStorage, nunca en
 * localStorage. Se borra en el cierre de sesión y ante un 401.
 */
export const TOKEN_KEY = 'dc.token'

export function getToken() {
  return sessionStorage.getItem(TOKEN_KEY)
}

export function setToken(token) {
  sessionStorage.setItem(TOKEN_KEY, token)
}

export function clearToken() {
  sessionStorage.removeItem(TOKEN_KEY)
}
