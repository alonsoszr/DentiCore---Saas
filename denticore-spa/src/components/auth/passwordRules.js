// Estado de los requisitos de contraseña de RF-040 para PasswordRequirements.

/** Longitud de RF-040: entre 10 y 128 caracteres. */
export function lengthState(password) {
  return password.length >= 10 && password.length <= 128 ? 'ok' : 'pending'
}

/** Un requisito validado en el servidor queda 'failed' si su mensaje vino en el 422. */
export function serverState(messages, pattern) {
  return messages.some((message) => pattern.test(message)) ? 'failed' : 'pending'
}
