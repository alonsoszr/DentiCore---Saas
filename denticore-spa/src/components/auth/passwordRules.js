// Estado de los requisitos de contraseña de RF-040 para PasswordRequirements.

/**
 * Longitud de RF-040: entre 10 y 128 caracteres. Se valida en vivo: 'unmet' (x gris) mientras no
 * llega a 10, 'ok' al cumplirse y 'failed' si supera 128.
 */
export function lengthState(password) {
  if (password.length > 128) return 'failed'
  return password.length >= 10 ? 'ok' : 'unmet'
}

/** Un requisito validado en el servidor queda 'failed' si su mensaje vino en el 422. */
export function serverState(messages, pattern) {
  return messages.some((message) => pattern.test(message)) ? 'failed' : 'pending'
}
