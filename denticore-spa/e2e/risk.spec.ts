import AxeBuilder from '@axe-core/playwright'
import { expect, test, type Browser, type Page } from '@playwright/test'

const WCAG_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']

async function expectNoAxeViolations(page: Page) {
  const { violations } = await new AxeBuilder({ page }).withTags(WCAG_AA).analyze()
  expect(violations.map((violation) => `${violation.id}: ${violation.help}`)).toEqual([])
}

// TASK-083 y MS-05 (CUS-54 a CUS-58). Requiere el modelo 1.0.0 activo (e2e.yml lo entrena y lo
// registra) y la paciente de DemoRiskSeeder. Crea datos nuevos en cada ejecución, así que corre en
// un solo proyecto. Las pruebas `@motor-detenido` corren aparte, con el contenedor `ml` detenido.
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

async function dentistPage(browser: Browser): Promise<Page> {
  const context = await browser.newContext()
  const page = await context.newPage()
  await login(page, 'dentista@clinica-demo.test')
  return page
}

test('captures the variables by role, computes a high risk and acknowledges its alert', async ({ page, browser }) => {
  test.setTimeout(180_000)
  const dni = String(10_000_000 + Math.floor(Math.random() * 89_999_999))
  const lastName = `Riesgo ${dni
    .split('')
    .map((digit) => 'abcdefghij'[Number(digit)])
    .join('')
    .replace(/^./, (letter) => letter.toUpperCase())}`

  // Recepción: paciente sintético con las finalidades (a) y (d) (RN-58) y sus datos sociodemográficos.
  await login(page, 'recepcion@clinica-demo.test')
  await page.getByRole('link', { name: 'Registrar paciente' }).click()
  await page.getByLabel('Número de documento', { exact: true }).fill(dni)
  await page.getByLabel('Nombres', { exact: true }).fill('Rosa')
  await page.getByLabel('Apellidos', { exact: true }).fill(lastName)
  await page.getByLabel('Fecha de nacimiento').fill('1990-03-15')
  await page.getByLabel('Sexo').selectOption('femenino')
  await page.getByLabel('Teléfono').fill('987654321')
  await page.getByRole('button', { name: 'Registrar paciente' }).click()
  await expect(page).toHaveURL(/\/c\/clinica-demo\/app\/pacientes\/[0-9a-f-]+$/)
  const patientPath = new URL(page.url()).pathname
  await page.getByRole('link', { name: 'Consentimiento', exact: true }).click()
  await page.getByLabel('(a) Atención odontológica (obligatoria)').check()
  await page.getByLabel('(d) Predicción de riesgo').check()
  await page.getByLabel('Documento del titular (confirmación)').fill(dni)
  await page.getByRole('button', { name: 'Registrar consentimiento' }).click()
  await expect(page.getByText('Consentimiento registrado.')).toBeVisible()

  await page.getByRole('link', { name: 'Riesgo', exact: true }).click()
  await expect(page).toHaveURL(/\/riesgo\/variables$/)
  await expect(page.getByLabel('CPOD')).toHaveCount(0)
  await page.getByLabel('Nivel educativo').selectOption('sin_estudios')
  await page.getByLabel('Situación laboral').selectOption('desempleado')
  await expectNoAxeViolations(page)
  await page.getByRole('button', { name: 'Guardar variables' }).click()
  await expect(page.getByText('Variables guardadas.')).toBeVisible()

  // Odontólogo: variables clínicas y hábitos, y cálculo (CUS-54, CUS-55).
  const dentist = await dentistPage(browser)
  await dentist.goto(`${patientPath}/riesgo/variables`)
  await dentist.getByLabel('CPOD').fill('12')
  await dentist.getByLabel('Índice de placa (%)').fill('90')
  await dentist.getByLabel('Lesiones activas').fill('8')
  await dentist.getByLabel('Azúcar entre comidas (veces al día)').selectOption('4+')
  await dentist.getByLabel('Usa pasta dental fluorada').selectOption('false')
  await dentist.getByLabel('Cepillado diario (veces)').selectOption('0')
  await dentist.getByLabel('Visitas al odontólogo por año').selectOption('<1')
  await dentist.getByRole('button', { name: 'Guardar variables' }).click()
  await expect(dentist.getByText('El registro está completo para calcular el riesgo.')).toBeVisible()
  await expectNoAxeViolations(dentist)

  await dentist.getByRole('link', { name: 'Ir al cálculo' }).click()
  await dentist.getByRole('button', { name: 'Calcular riesgo' }).click()
  await expect(dentist.getByText('Riesgo alto')).toBeVisible()
  // DESIGN.md › Status palette: el nivel alto usa `risk-high` (#B91C1C), no el color base del badge.
  await expect(dentist.locator('.badge-risk-alto')).toHaveCSS('color', 'rgb(185, 28, 28)')
  await expect(dentist.getByText('Probabilidad calibrada')).toBeVisible()
  await expect(dentist.getByText('Factores que aumentan el riesgo')).toBeVisible()
  await expect(dentist.getByText('Herramienta de apoyo; no constituye diagnóstico')).toBeVisible()
  await expectNoAxeViolations(dentist)

  // Alerta de riesgo alto (CUS-57, CUS-58): se reconoce con una justificación.
  await dentist.getByRole('link', { name: 'Alertas', exact: true }).click()
  await dentist.getByRole('button', { name: `Reconocer la alerta de Rosa ${lastName}` }).click()
  const dialog = dentist.getByRole('dialog', { name: `Reconocer la alerta de Rosa ${lastName}` })
  await expect(dialog.getByRole('button', { name: 'Reconocer alerta' })).toBeDisabled()
  await dialog.getByRole('radio', { name: 'Justificación' }).check()
  await dialog.getByLabel('Texto de la justificación').fill('Control preventivo vigente en otra clínica.')
  await expectNoAxeViolations(dentist)
  await dialog.getByRole('button', { name: 'Reconocer alerta' }).click()
  await expect(dialog).toBeHidden()
  await expect(dentist.getByRole('row', { name: new RegExp(lastName) }).getByText('Reconocida')).toBeVisible()
  await dentist.context().close()
})

test('shows the support and low confidence legends for confidence 0.55', async ({ browser }) => {
  // T-131 (CA-55.7, RN-63): la predicción vigente se sustituye en la red por una de confianza 0,55.
  const dentist = await dentistPage(browser)
  await dentist.route('**/api/v1/risk-predictions/*', async (route) => {
    const response = await route.fetch()
    const body = await response.json()
    body.data = { ...body.data, level: 'medio', confidence: '0.5500', low_confidence: true }
    await route.fulfill({ response, json: body })
  })
  await dentist.route('**/api/v1/patients/*/clinical-record', async (route) => {
    const response = await route.fetch()
    const body = await response.json()
    body.data.current_risk_prediction ??= {
      id: '00000000-0000-4000-8000-000000000000',
      level: 'medio',
      confidence: '0.5500',
      low_confidence: true,
      predicted_at: new Date().toISOString(),
      expires_at: new Date().toISOString(),
    }
    await route.fulfill({ response, json: body })
  })
  await dentist.route('**/api/v1/risk-predictions/00000000-0000-4000-8000-000000000000', (route) =>
    route.fulfill({
      json: {
        data: {
          id: '00000000-0000-4000-8000-000000000000',
          status: 'vigente',
          level: 'medio',
          confidence: '0.5500',
          low_confidence: true,
          model_version: '1.0.0',
          thresholds: { medium: '0.300', high: '0.600' },
          predicted_at: new Date().toISOString(),
          expires_at: new Date().toISOString(),
          legend: 'Herramienta de apoyo; no constituye diagnóstico',
          explanation_global: [],
          alert_id: null,
          probability: '0.4500',
          top_increasing: [],
          top_decreasing: [],
          explanation_individual: [],
        },
      },
    }),
  )

  await dentist.getByLabel('Buscar paciente').fill('Riesgo Demo')
  await dentist.getByRole('button', { name: 'Buscar' }).click()
  await dentist
    .getByRole('row', { name: /Riesgo Demo, Rita/ })
    .getByRole('link', { name: 'Ver ficha' })
    .click()
  await dentist.getByRole('link', { name: 'Riesgo', exact: true }).click()
  await expect(dentist.getByText('Baja confianza')).toBeVisible()
  await expect(dentist.getByText('Herramienta de apoyo; no constituye diagnóstico')).toBeVisible()
  await expectNoAxeViolations(dentist)
  await dentist.context().close()
})

test('shows "Predicción no disponible" and keeps the attention editable with the engine stopped @motor-detenido', async ({
  browser,
}) => {
  // CA-55.3, RN-64 y criterio 2 de TASK-083: con el motor detenido la atención continúa.
  test.setTimeout(120_000)
  const dentist = await dentistPage(browser)
  await dentist.getByLabel('Buscar paciente').fill('Riesgo Demo')
  await dentist.getByRole('button', { name: 'Buscar' }).click()
  await dentist
    .getByRole('row', { name: /Riesgo Demo, Rita/ })
    .getByRole('link', { name: 'Ver ficha' })
    .click()
  await dentist.getByRole('link', { name: 'Historia clínica', exact: true }).click()
  await dentist.getByRole('button', { name: 'Atender' }).click()
  await expect(dentist).toHaveURL(/\/c\/clinica-demo\/app\/atenciones\/[0-9a-f-]+$/)

  await dentist.getByRole('button', { name: 'Calcular riesgo' }).click()
  await expect(dentist.getByRole('heading', { name: 'Predicción no disponible' })).toBeVisible({ timeout: 15_000 })
  await expectNoAxeViolations(dentist)

  await dentist.getByLabel('Motivo de consulta').fill('Control de rutina con el motor de riesgo detenido.')
  await dentist.getByRole('button', { name: 'Guardar nota' }).click()
  await expect(dentist.getByText('Nota guardada.')).toBeVisible()
  await dentist.context().close()
})
