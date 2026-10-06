/** Tipos de documento de identidad aceptados (SRS §11.3, RN-09). */
export const DOCUMENT_TYPES = {
  dni: 'DNI',
  ce: 'Carné de extranjería',
  pasaporte: 'Pasaporte',
  cpp: 'Carné de Permiso Temporal de Permanencia (CPP)',
}

export function today() {
  return new Date().toISOString().slice(0, 10)
}

/** RN-12: menor de 18 años a la fecha de hoy (la API lo vuelve a verificar con la fecha de la clínica). */
export function isMinor(birthDate) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(birthDate)) return false
  const [year, month, day] = birthDate.split('-').map(Number)
  const adulthood = `${year + 18}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`
  return adulthood > today()
}
