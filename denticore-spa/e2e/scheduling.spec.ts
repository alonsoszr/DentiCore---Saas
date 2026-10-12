import AxeBuilder from '@axe-core/playwright'
import { expect, test, type Browser, type Page } from '@playwright/test'
import { MAILPIT_URL } from './support/auth'

const WCAG_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']

async function expectNoAxeViolations(page: Page) {
  const { violations } = await new AxeBuilder({ page }).withTags(WCAG_AA).analyze()
  expect(violations.map((violation) => `${violation.id}: ${violation.help}`)).toEqual([])
}

// TASK-071 (criterio 1) y MS-04: la recepción reserva → el paciente confirma con el enlace del
// correo (Mailpit) → la recepción registra la llegada → el odontólogo ve la atención abierta.
// Crea una ficha nueva en cada ejecución (DNI sintético), así que corre en un solo proyecto. Usa la
// agenda de demostración (DemoSchedulingSeeder: el odontólogo atiende todo el día).
test.skip(
  ({ browserName, channel, viewport }) => browserName !== 'chromium' || Boolean(channel) || viewport?.width !== 1280,
  'Flujo con datos nuevos: solo en Chrome a 1280 px',
)

async function login(page: Page, email: string) {
  await page.goto('/c/clinica-demo/login')
  await page.getByLabel('Correo electrónico').fill(email)
  await page.getByLabel('Contraseña', { exact: true }).fill('password')
  await page.getByRole('button', { name: 'Ingresar' }).click()
  await expect(page).toHaveURL(/\/c\/clinica-demo\/app\/pacientes$/)
}

/** Enlace de confirmación del correo `cita_creada` enviado a `email` (Mailpit API v1). */
async function confirmationLink(page: Page, email: string): Promise<string> {
  let link = ''
  await expect
    .poll(
      async () => {
        const search = await page.request.get(`${MAILPIT_URL}/api/v1/search`, { params: { query: `to:"${email}"` } })
        const { messages = [] } = await search.json()
        if (messages.length === 0) return ''
        const message = await (await page.request.get(`${MAILPIT_URL}/api/v1/message/${messages[0].ID}`)).json()
        link = (`${message.Text} ${message.HTML}`.match(/https?:\/\/[^\s"<]+\/confirmar\/[A-Za-z0-9_-]+/) ?? [''])[0]
        return link
      },
      { timeout: 30_000, message: `confirmación de cita para ${email} en Mailpit` },
    )
    .not.toBe('')
  return link
}

/** El paciente confirma desde el enlace, sin sesión (CUS-49). */
async function confirmByEmail(browser: Browser, link: string) {
  const context = await browser.newContext()
  const page = await context.newPage()
  await page.goto(new URL(link).pathname)
  await expect(page.getByRole('heading', { name: 'Confirma tu cita' })).toBeVisible()
  await expectNoAxeViolations(page)
  await page.getByRole('button', { name: 'Confirmar mi cita' }).click()
  await expect(page.getByRole('heading', { name: 'Tu cita está confirmada' })).toBeVisible()
  await context.close()
}

test('books an appointment, confirms it by email, checks in and opens the attention', async ({ page, browser }) => {
  test.setTimeout(180_000)
  const dni = String(10_000_000 + Math.floor(Math.random() * 89_999_999))
  const email = `paciente.${dni}@example.test`
  // La búsqueda es por nombre y apellido: un apellido único por ejecución (letras del DNI).
  const lastName = `Agenda ${dni
    .split('')
    .map((digit) => 'abcdefghij'[Number(digit)])
    .join('')
    .replace(/^./, (letter) => letter.toUpperCase())}`

  await login(page, 'recepcion@clinica-demo.test')

  // Paciente sintético con correo y las finalidades (a) y (b) (RN-52).
  await page.getByRole('link', { name: 'Registrar paciente' }).click()
  await page.getByLabel('Número de documento', { exact: true }).fill(dni)
  await page.getByLabel('Nombres', { exact: true }).fill('Ana')
  await page.getByLabel('Apellidos', { exact: true }).fill(lastName)
  await page.getByLabel('Fecha de nacimiento').fill('1990-03-15')
  await page.getByLabel('Sexo').selectOption('femenino')
  await page.getByLabel('Teléfono').fill('987654321')
  await page.getByLabel('Correo electrónico (opcional)').fill(email)
  await page.getByRole('button', { name: 'Registrar paciente' }).click()
  await expect(page).toHaveURL(/\/c\/clinica-demo\/app\/pacientes\/[0-9a-f-]+$/)
  const patientUrl = page.url()
  await page.getByRole('link', { name: 'Consentimiento', exact: true }).click()
  await page.getByLabel('(a) Atención odontológica (obligatoria)').check()
  await page.getByLabel('(b) Notificaciones').check()
  await page.getByLabel('Documento del titular (confirmación)').fill(dni)
  await page.getByRole('button', { name: 'Registrar consentimiento' }).click()
  await expect(page.getByText('Consentimiento registrado.')).toBeVisible()

  // Reserva (CUS-47): el primer horario libre de hoy con el odontólogo de demostración.
  await page.getByRole('link', { name: 'Agenda', exact: true }).click()
  await expectNoAxeViolations(page)
  await page.getByRole('link', { name: 'Nueva cita' }).click()
  await page.getByLabel('Buscar paciente').fill(lastName)
  await page.getByRole('button', { name: 'Buscar' }).click()
  await page.getByRole('button', { name: `${lastName}, Ana · ${dni}` }).click()
  await page.getByLabel('Tipo de cita').selectOption({ label: 'Consulta (30 min)' })
  const slots = page.getByRole('group', { name: 'Diego Odontólogo' }).getByRole('button')
  await expect(slots.first().or(page.getByText('No hay horarios libres ese día.'))).toBeVisible()
  test.skip((await slots.count()) === 0, 'No quedan horarios hoy (cerca de la medianoche en Lima)')
  await expectNoAxeViolations(page)
  await slots.first().click()
  await page.getByRole('button', { name: /^Reservar con Diego Odontólogo/ }).click()
  await expect(page).toHaveURL(/\/c\/clinica-demo\/app\/agenda\/[0-9a-f-]+$/)
  await expect(page.getByText('Programada', { exact: true })).toBeVisible()
  await expectNoAxeViolations(page)
  const appointmentUrl = page.url()

  // Confirmación por el enlace del correo (CUS-49, RF-150).
  await confirmByEmail(browser, await confirmationLink(page, email))
  await page.reload()
  await expect(page.getByText('Confirmada', { exact: true })).toBeVisible()

  // Cancelar pide confirmación (RNF-063): se abre el diálogo y se desiste.
  await page.getByRole('button', { name: 'Cancelar cita' }).click()
  const dialog = page.getByRole('dialog', { name: '¿Cancelar la cita?' })
  await expect(dialog.getByRole('button', { name: 'Cancelar cita' })).toBeDisabled()
  await expectNoAxeViolations(page)
  await dialog.getByRole('button', { name: 'Cancelar', exact: true }).click()
  await expect(dialog).toBeHidden()

  // Check-in (CUS-50): abre la atención y el paciente pasa a la sala de espera.
  await page.getByRole('button', { name: 'Registrar llegada' }).click()
  await expect(page.getByText('Llegada registrada')).toBeVisible()
  await expect(page.getByText('En atención', { exact: true })).toBeVisible()
  await page.goto(appointmentUrl.replace(/\/agenda\/.+$/, '/agenda'))
  await page.getByRole('tab', { name: 'Sala de espera' }).click()
  await expect(page.getByRole('link', { name: `Ana ${lastName}` })).toBeVisible()
  await expectNoAxeViolations(page)

  // El odontólogo ve la atención abierta en la historia clínica del paciente.
  const dentistContext = await browser.newContext()
  const dentist = await dentistContext.newPage()
  await login(dentist, 'dentista@clinica-demo.test')
  await dentist.goto(`${new URL(patientUrl).pathname}/hc`)
  const row = dentist.getByRole('row', { name: /Abierta/ })
  await expect(row).toBeVisible()
  await row.getByRole('link').first().click()
  await expect(dentist).toHaveURL(/\/c\/clinica-demo\/app\/atenciones\/[0-9a-f-]+$/)
  await expect(dentist.getByRole('heading', { name: /^Atención del/ })).toBeVisible()
  await dentistContext.close()
})
