/*
 * T-176 (SDD §6.3; TASK-091; RNF-038, RN-46): perfil PC-E. 50 reservas simultáneas del mismo
 * horario del mismo odontólogo dan exactamente 1 éxito y 49 conflictos (409), sin errores 5xx.
 * Corre contra la API local con los datos de demostración (en la CI, el job e2e):
 *   k6 run -e API_URL=http://localhost:8000/api/v1 load/pc-e.js
 * Las fichas son sintéticas (RES-08) y se crean en `setup()`, una por usuario virtual.
 */
import http from 'k6/http'
import { Counter } from 'k6/metrics'
import { check, fail } from 'k6'

const API = __ENV.API_URL || 'http://localhost:8000/api/v1'
const RESERVAS = 50

const exitosas = new Counter('reservas_exitosas')
const conflictos = new Counter('reservas_en_conflicto')
const errores = new Counter('reservas_con_error')

export const options = {
  scenarios: {
    mismo_horario: { executor: 'per-vu-iterations', vus: RESERVAS, iterations: 1, maxDuration: '2m' },
  },
  thresholds: {
    reservas_exitosas: ['count==1'],
    reservas_en_conflicto: [`count==${RESERVAS - 1}`],
    reservas_con_error: ['count==0'],
  },
}

/** Clave de idempotencia distinta por escritura (SDD §4.1). */
function idempotencyKey() {
  const hex = () => Math.floor(Math.random() * 0x10000).toString(16).padStart(4, '0')
  return `${hex()}${hex()}-${hex()}-4${hex().slice(1)}-a${hex().slice(1)}-${hex()}${hex()}${hex()}`
}

function headers(token, write = false) {
  return {
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      Authorization: `Bearer ${token}`,
      ...(write ? { 'Idempotency-Key': idempotencyKey() } : {}),
    },
  }
}

function isoDate(date) {
  return date.toISOString().slice(0, 10)
}

export function setup() {
  const login = http.post(
    `${API}/auth/login`,
    JSON.stringify({ tenant_slug: 'clinica-demo', email: 'recepcion@clinica-demo.test', password: 'password' }),
    { headers: { Accept: 'application/json', 'Content-Type': 'application/json' } },
  )
  if (login.status !== 200) fail(`No se pudo iniciar sesión como recepción: ${login.status}`)
  const token = login.json('token')

  // Tipo «Consulta» de la semilla y el primer horario libre a partir de 10 días (lejos de los E2E).
  const types = http.get(`${API}/appointment-types`, headers(token)).json('data')
  const type = types.find((item) => item.name === 'Consulta' && item.is_active)
  if (!type) fail('No existe el tipo «Consulta» de la semilla')

  let slot = null
  for (let offset = 10; offset < 40 && !slot; offset += 1) {
    const day = isoDate(new Date(Date.now() + offset * 86400000))
    const groups = http
      .get(`${API}/availability?appointment_type_id=${type.id}&from=${day}&to=${day}`, headers(token))
      .json('data')
    const group = groups.find((item) => item.slots.length > 0)
    if (group) slot = { dentistId: group.dentist.id, startsAt: group.slots[0] }
  }
  if (!slot) fail('No hay horarios libres en los próximos 40 días')

  // Una ficha sintética por usuario virtual: el conflicto es del horario del odontólogo (RN-46).
  const patients = []
  for (let index = 0; index < RESERVAS; index += 1) {
    const response = http.post(
      `${API}/patients`,
      JSON.stringify({
        document_type: 'dni',
        document_number: String(60000000 + Math.floor(Math.random() * 9999999)),
        first_name: 'Carga',
        last_name: `Sintética ${index + 1}`,
        birth_date: '1990-01-01',
        sex: 'femenino',
        phone: `9${String(Math.floor(Math.random() * 99999999)).padStart(8, '0')}`,
      }),
      headers(token, true),
    )
    if (response.status !== 201) fail(`No se pudo crear la ficha ${index + 1}: ${response.status} ${response.body}`)
    patients.push(response.json('data.id'))
  }

  return { token, typeId: type.id, slot, patients }
}

export default function (data) {
  const response = http.post(
    `${API}/appointments`,
    JSON.stringify({
      patient_id: data.patients[__VU - 1],
      dentist_id: data.slot.dentistId,
      appointment_type_id: data.typeId,
      starts_at: data.slot.startsAt,
    }),
    headers(data.token, true),
  )

  if (response.status === 201) exitosas.add(1)
  else if (response.status === 409) conflictos.add(1)
  else errores.add(1)

  check(response, { 'reserva o conflicto (201/409)': (r) => r.status === 201 || r.status === 409 })
}
