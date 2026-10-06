import axios from 'axios'
import { clearToken, getToken } from '../auth/token'

/**
 * Cliente HTTP de la SPA (SDD §1.10, IE-01):
 * - baseURL = VITE_API_URL + '/api/v1'.
 * - Authorization desde sessionStorage, Accept-Language es-PE y X-Correlation-Id.
 * - Cada escritura lleva un Idempotency-Key (UUID v4) que se reutiliza en sus
 *   reintentos (DD-45); una escritura solo se reintenta si lleva esa clave.
 * - 401: se borra el token y se avisa a la sesión para volver al login de la clínica.
 */
export const API_ORIGIN = import.meta.env.VITE_API_URL ?? 'http://localhost:8000'

const WRITE_METHODS = ['post', 'put', 'patch', 'delete']
export const MAX_WRITE_RETRIES = 2

export const apiClient = axios.create({
  baseURL: `${API_ORIGIN}/api/v1`,
  headers: { Accept: 'application/json', 'Accept-Language': 'es-PE' },
})

let unauthorizedHandler = null
let lastActivity = Date.now()

/**
 * Momento de la última respuesta de la API: el servidor mide la inactividad por el último uso
 * del token (RF-036), y SessionTimeout avisa antes de que venza (RNF-065).
 */
export function lastApiActivity() {
  return lastActivity
}

/** La sesión registra aquí qué hacer ante un 401 (volver al login correspondiente). */
export function onUnauthorized(handler) {
  unauthorizedHandler = handler
}

function isWrite(config) {
  return WRITE_METHODS.includes((config.method ?? 'get').toLowerCase())
}

apiClient.interceptors.request.use((config) => {
  const token = getToken()
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  config.headers['X-Correlation-Id'] = crypto.randomUUID()
  if (isWrite(config) && !config.headers['Idempotency-Key']) {
    config.headers['Idempotency-Key'] = config.idempotencyKey ?? crypto.randomUUID()
  }
  return config
})

apiClient.interceptors.response.use(
  (response) => {
    lastActivity = Date.now()
    return response
  },
  (error) => {
    const { config, response } = error
    if (response) lastActivity = Date.now()

    // Un 401 del propio login son credenciales inválidas, no una sesión vencida.
    if (response?.status === 401 && !config?.url?.endsWith('/auth/login')) {
      clearToken()
      unauthorizedHandler?.()
      return Promise.reject(error)
    }

    // Fallo de red en una escritura: se reintenta con la misma Idempotency-Key.
    if (!response && config && isWrite(config) && (config.retryCount ?? 0) < MAX_WRITE_RETRIES) {
      config.retryCount = (config.retryCount ?? 0) + 1
      return apiClient.request(config)
    }

    return Promise.reject(error)
  },
)
