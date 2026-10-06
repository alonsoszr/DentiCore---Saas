const DEFAULT_RETRY_SECONDS = 60

/** Segundos de espera de un 429: Retry-After en segundos o como fecha HTTP (SDD §4.1). */
export function retryAfterSeconds(response) {
  const headers = response?.headers ?? {}
  const value = typeof headers.get === 'function' ? headers.get('retry-after') : headers['retry-after']
  const seconds = Number(value)
  if (Number.isFinite(seconds) && seconds > 0) return seconds
  const date = Date.parse(value)
  if (!Number.isNaN(date)) return Math.max(1, Math.ceil((date - Date.now()) / 1000))
  return DEFAULT_RETRY_SECONDS
}

/** mm:ss para las cuentas regresivas. */
export function formatCountdown(totalSeconds) {
  const minutes = Math.floor(totalSeconds / 60)
  const seconds = totalSeconds % 60
  return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`
}
