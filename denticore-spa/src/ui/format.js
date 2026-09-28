/**
 * Formatos de la interfaz (SDD §1.10, RF-009, RNF-189): moneda en soles con es-PE,
 * fechas dd/MM/yyyy y horas de 24 h en la zona horaria de la clínica.
 */
export const DEFAULT_TIME_ZONE = 'America/Lima'

const currencyFormatter = new Intl.NumberFormat('es-PE', { style: 'currency', currency: 'PEN' })

/** Importe de la API (cadena decimal "1234.56") → "S/ 1,234.56". */
export function formatCurrency(amount) {
  if (amount === null || amount === undefined || amount === '') return '—'
  return currencyFormatter.format(Number(amount))
}

/** Instante ISO 8601 → "dd/MM/yyyy" en la zona de la clínica. */
export function formatDate(value, timeZone = DEFAULT_TIME_ZONE) {
  if (!value) return '—'
  return new Intl.DateTimeFormat('es-PE', { timeZone, day: '2-digit', month: '2-digit', year: 'numeric' }).format(
    new Date(value),
  )
}

/** Instante ISO 8601 → "dd/MM/yyyy HH:mm" (24 h) en la zona de la clínica. */
export function formatDateTime(value, timeZone = DEFAULT_TIME_ZONE) {
  if (!value) return '—'
  const parts = new Intl.DateTimeFormat('es-PE', {
    timeZone,
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
  }).formatToParts(new Date(value))
  const part = (type) => parts.find((item) => item.type === type).value
  return `${part('day')}/${part('month')}/${part('year')} ${part('hour')}:${part('minute')}`
}

/** Fecha civil "YYYY-MM-DD" (p. ej. fecha de nacimiento) → "dd/MM/yyyy", sin desfase de zona. */
export function formatCivilDate(value) {
  if (!value) return '—'
  const [year, month, day] = value.split('-')
  return `${day}/${month}/${year}`
}

/** Edad cumplida a la fecha de hoy para una fecha civil "YYYY-MM-DD". */
export function ageFrom(value, today = new Date()) {
  if (!value) return null
  const [year, month, day] = value.split('-').map(Number)
  let age = today.getFullYear() - year
  const hadBirthday = today.getMonth() + 1 > month || (today.getMonth() + 1 === month && today.getDate() >= day)
  if (!hadBirthday) age -= 1
  return age
}
