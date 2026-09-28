/**
 * Errores de la API. Las respuestas 409/422 traen `errors: {campo: [motivo]}`
 * (SDD §1.7, RF-008); se muestran junto a cada campo del formulario.
 */
export function fieldErrors(error) {
  const status = error?.response?.status
  if (status !== 422 && status !== 409) return {}
  const errors = error.response.data?.errors ?? {}
  return Object.fromEntries(Object.entries(errors).map(([field, messages]) => [field, messages[0]]))
}

/**
 * Mensaje general cuando el error no corresponde a un campo concreto. Si la API
 * envía `detail` (problem+json en español) se muestra tal cual.
 */
export function generalError(error) {
  if (!error) return null
  const status = error.response?.status
  const detail = error.response?.data?.detail
  const hasFieldErrors = Object.keys(fieldErrors(error)).length > 0

  if ((status === 422 || status === 409) && hasFieldErrors) return null
  if (!error.response) return 'No se pudo conectar con el servidor. Revisa que la API esté en ejecución.'
  if (detail) return detail
  if (status === 403) return 'No tienes permiso para realizar esta acción.'
  if (status === 404) return 'No se encontró el recurso solicitado.'
  if (status === 409) return 'La operación entra en conflicto con el estado actual. Actualiza e inténtalo de nuevo.'
  if (status === 429) return 'Demasiados intentos. Espera un momento e inténtalo nuevamente.'
  return 'Ocurrió un error inesperado. Inténtalo nuevamente.'
}
