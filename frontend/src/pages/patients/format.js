/**
 * Fechas 'YYYY-MM-DD' de la API a formato local, sin desfase por zona horaria.
 */
export function formatDate(value) {
  if (!value) return '—'
  const [year, month, day] = value.split('-')
  return `${day}/${month}/${year}`
}

export function ageFrom(value) {
  if (!value) return null
  const birth = new Date(`${value}T00:00:00`)
  const today = new Date()
  let age = today.getFullYear() - birth.getFullYear()
  const hadBirthday =
    today.getMonth() > birth.getMonth() ||
    (today.getMonth() === birth.getMonth() && today.getDate() >= birth.getDate())
  if (!hadBirthday) age -= 1
  return age
}
