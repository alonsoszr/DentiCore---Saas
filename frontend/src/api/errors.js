/**
 * Errores de validación de Laravel (422) como { campo: 'primer mensaje' }.
 */
export function fieldErrors(error) {
  const errors = error?.response?.data?.errors ?? {}
  return Object.fromEntries(Object.entries(errors).map(([field, messages]) => [field, messages[0]]))
}

/**
 * Mensaje general para mostrar cuando el error no es de un campo concreto.
 */
export function generalError(error) {
  const status = error?.response?.status
  if (!error) return null
  if (status === 422) return null
  if (status === 403) return 'No tienes permiso para realizar esta acción.'
  if (status === 404) return 'No se encontró el recurso solicitado.'
  if (!error.response) return 'No se pudo conectar con el servidor. Revisa que la API esté en ejecución.'
  return 'Ocurrió un error inesperado. Inténtalo nuevamente.'
}
