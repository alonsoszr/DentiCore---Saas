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

/** Partes de fecha y hora de un instante en una zona horaria (calendario gregoriano, 24 h). */
function zonedParts(instant, timeZone) {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hourCycle: 'h23',
  }).formatToParts(new Date(instant))
  const part = (type) => Number(parts.find((item) => item.type === type).value)
  return {
    year: part('year'),
    month: part('month'),
    day: part('day'),
    hour: part('hour'),
    minute: part('minute'),
    second: part('second'),
  }
}

/** Instante ISO 8601 → "HH:mm" (24 h) en la zona de la clínica. */
export function formatTime(value, timeZone = DEFAULT_TIME_ZONE) {
  if (!value) return '—'
  const { hour, minute } = zonedParts(value, timeZone)
  return `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`
}

/** Instante → fecha civil "YYYY-MM-DD" en la zona de la clínica (p. ej. el día de una cita). */
export function localDate(value, timeZone = DEFAULT_TIME_ZONE) {
  const { year, month, day } = zonedParts(value, timeZone)
  return `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`
}

/** Fecha civil "YYYY-MM-DD" desplazada `days` días (sin zona horaria). */
export function addDays(date, days) {
  const [year, month, day] = date.split('-').map(Number)
  return new Date(Date.UTC(year, month - 1, day + days)).toISOString().slice(0, 10)
}

/** Día ISO de una fecha civil "YYYY-MM-DD": 1 = lunes … 7 = domingo. */
export function isoWeekday(date) {
  const [year, month, day] = date.split('-').map(Number)
  return new Date(Date.UTC(year, month - 1, day)).getUTCDay() || 7
}

/**
 * Fecha "YYYY-MM-DD" y hora "HH:mm" locales de la clínica → instante ISO 8601 en UTC. Corrige el
 * desfase de la zona (también en un cambio de horario, si la zona lo tuviera).
 */
export function zonedDateTimeToIso(date, time, timeZone = DEFAULT_TIME_ZONE) {
  const [year, month, day] = date.split('-').map(Number)
  const [hour, minute] = time.split(':').map(Number)
  const wallClock = Date.UTC(year, month - 1, day, hour, minute)
  const offset = (instant) => {
    const parts = zonedParts(instant, timeZone)
    return Date.UTC(parts.year, parts.month - 1, parts.day, parts.hour, parts.minute, parts.second) - instant
  }
  let instant = wallClock - offset(wallClock)
  instant = wallClock - offset(instant)
  return new Date(instant).toISOString()
}
