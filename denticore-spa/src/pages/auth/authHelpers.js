// Utilidades de las pantallas de acceso (A3, A4, A6).

/** d.al•••@dominio: el correo del login, parcialmente oculto. */
export function maskEmail(email) {
  if (!email || !email.includes('@')) return ''
  const [local, domain] = email.split('@')
  return `${local.slice(0, Math.min(4, Math.max(1, local.length - 1)))}•••@${domain}`
}

/** Clave manual agrupada de 4 en 4. */
export function groupKey(secret) {
  return (secret ?? '').match(/.{1,4}/g)?.join(' ') ?? ''
}

/** La contraseña no contiene el correo (parte local) ni alguna palabra del nombre (RF-040). */
export function containsIdentity(password, invitation) {
  if (!password || !invitation) return false
  const lower = password.toLowerCase()
  const parts = [invitation.email?.split('@')[0], ...(invitation.name ?? '').split(/\s+/)]
  return parts.some((part) => part && part.length >= 3 && lower.includes(part.toLowerCase()))
}
