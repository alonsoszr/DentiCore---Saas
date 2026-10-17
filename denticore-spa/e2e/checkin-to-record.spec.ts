import { expect, test } from '@playwright/test'
import {
  CHECK_IN_WINDOW,
  auditScreen,
  hasSlotsBetween,
  login,
  reserveFreeSlot,
  staffOnly,
  staffPage,
} from './support/screen'

// T-170 (SDD §6.3; OB-02, RNF-010): la recepción registra la llegada del paciente y el odontólogo
// abre su historia clínica completa. Se mide el tiempo desde la respuesta del check-in hasta que la
// historia muestra el odontograma y la atención abierta; el umbral de 3 s es de E2 (TASK-116, en el
// entorno de referencia), así que en E1 la medición solo se registra en el informe.
staffOnly()

test('shows the full clinical record within 3 seconds after check-in', async ({
  page,
  browser,
  viewport,
}, testInfo) => {
  test.setTimeout(150_000)
  const dni = String(10_000_000 + Math.floor(Math.random() * 89_999_999))
  // La búsqueda es por nombre y apellido: un apellido único por ejecución (letras del DNI).
  const lastName = `Llegada ${dni
    .split('')
    .map((digit) => 'abcdefghij'[Number(digit)])
    .join('')
    .replace(/^./, (letter) => letter.toUpperCase())}`

  // El odontólogo ya tiene su sesión abierta, como en la consulta.
  const dentist = await staffPage(browser, viewport?.width ?? 1280)
  await login(dentist, 'dentista@clinica-demo.test')

  // Recepción: paciente sintético con la finalidad (a) y una cita de hoy (CUS-14, CUS-47).
  await login(page, 'recepcion@clinica-demo.test')
  await page.getByRole('link', { name: 'Registrar paciente' }).click()
  await page.getByLabel('Número de documento', { exact: true }).fill(dni)
  await page.getByLabel('Nombres', { exact: true }).fill('Elena')
  await page.getByLabel('Apellidos', { exact: true }).fill(lastName)
  await page.getByLabel('Fecha de nacimiento').fill('1985-07-20')
  await page.getByLabel('Sexo').selectOption('femenino')
  await page.getByLabel('Teléfono').fill('987654321')
  await page.getByRole('button', { name: 'Registrar paciente' }).click()
  await expect(page).toHaveURL(/\/c\/clinica-demo\/app\/pacientes\/[0-9a-f-]+$/)
  const patientPath = new URL(page.url()).pathname
  await page.getByRole('link', { name: 'Consentimiento', exact: true }).click()
  await page.getByLabel('(a) Atención odontológica (obligatoria)').check()
  await page.getByLabel('Documento del titular (confirmación)').fill(dni)
  await page.getByRole('button', { name: 'Registrar consentimiento' }).click()
  await expect(page.getByText('Consentimiento registrado.')).toBeVisible()

  await page.getByRole('link', { name: 'Agenda', exact: true }).click()
  await page.getByRole('link', { name: 'Nueva cita' }).click()
  await page.getByLabel('Buscar paciente').fill(lastName)
  await page.getByRole('button', { name: 'Buscar' }).click()
  await page.getByRole('button', { name: `${lastName}, Elena · ${dni}` }).click()
  await page.getByLabel('Tipo de cita').selectOption({ label: 'Consulta (30 min)' })
  // Cita de 15 min (FA-2 de CUS-47) en la próxima hora: la llegada se registra desde 60 min antes del
  // inicio (RN-50) y así caben las reservas de los tres anchos de la matriz.
  await page.getByLabel('Duración (minutos, opcional)').fill('15')
  const slots = page.getByRole('group', { name: 'Diego Odontólogo' }).getByRole('button')
  test.skip(
    !(await hasSlotsBetween(slots, CHECK_IN_WINDOW)),
    'No quedan horarios en la próxima hora (cerca de la medianoche en Lima)',
  )
  await reserveFreeSlot(
    page,
    slots,
    page.getByRole('button', { name: /^Reservar con Diego Odontólogo/ }),
    CHECK_IN_WINDOW,
  )
  await expect(page.getByText('Programada', { exact: true })).toBeVisible()
  const appointmentUrl = page.url()

  // Check-in (CUS-50): abre la atención del paciente.
  const [checkIn] = await Promise.all([
    page.waitForResponse(
      (response) =>
        response.request().method() === 'POST' && /\/appointments\/[0-9a-f-]+\/check-in$/.test(response.url()),
    ),
    page.getByRole('button', { name: 'Registrar llegada' }).click(),
  ])
  expect(checkIn.ok()).toBe(true)
  const checkedInAt = Date.now()

  // Historia clínica completa: datos del paciente, odontograma vigente y la atención abierta.
  await dentist.goto(`${patientPath}/hc`)
  await expect(
    dentist.getByRole('region', { name: 'Paciente' }).getByRole('heading', { name: `Elena ${lastName}` }),
  ).toBeVisible()
  await expect(dentist.getByRole('button', { name: /^Pieza 36/ })).toBeVisible()
  await expect(dentist.getByRole('row', { name: /Abierta/ })).toBeVisible()
  const elapsedMs = Date.now() - checkedInAt

  testInfo.annotations.push({
    type: 'T-170',
    description: `Historia clínica completa a los ${elapsedMs} ms del check-in (umbral de E2: 3000 ms)`,
  })
  await testInfo.attach('t-170', {
    body: JSON.stringify({ project: testInfo.project.name, elapsedMs, thresholdMs: 3000, enforcedFrom: 'E2' }),
    contentType: 'application/json',
  })
  await auditScreen(dentist)

  // El odontólogo entra a la atención abierta y la recepción ve al paciente en la sala de espera.
  await dentist
    .getByRole('row', { name: /Abierta/ })
    .getByRole('link')
    .first()
    .click()
  await expect(dentist).toHaveURL(/\/c\/clinica-demo\/app\/atenciones\/[0-9a-f-]+$/)
  await expect(dentist.getByRole('heading', { name: /^Atención del/ })).toBeVisible()
  await dentist.context().close()

  await expect(page.getByText('Llegada registrada')).toBeVisible()
  await expect(page.getByText('En atención', { exact: true })).toBeVisible()
  await page.goto(appointmentUrl.replace(/\/agenda\/.+$/, '/agenda'))
  await page.getByRole('tab', { name: 'Sala de espera' }).click()
  await expect(page.getByRole('link', { name: `Elena ${lastName}` })).toBeVisible()
  await auditScreen(page)
})
