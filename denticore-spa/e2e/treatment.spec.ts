import AxeBuilder from '@axe-core/playwright'
import { expect, test, type Browser, type Page } from '@playwright/test'

const WCAG_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']

async function expectNoAxeViolations(page: Page) {
  const { violations } = await new AxeBuilder({ page }).withTags(WCAG_AA).analyze()
  expect(violations.map((violation) => `${violation.id}: ${violation.help}`)).toEqual([])
}

// TASK-062 (criterio 1): hallazgo rojo → pendientes → ítem del plan → plan propuesto →
// presupuesto emitido → aceptado en recepción → procedimiento → evolución en el odontograma. Crea
// una ficha nueva en cada ejecución (DNI sintético), así que corre en un solo proyecto. Usa el
// catálogo de demostración (DemoProcedureSeeder).
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

/** La recepción registra la aceptación presencial del titular en su propia sesión (CUS-37). */
async function acceptAtReception(browser: Browser, budgetUrl: string, dni: string) {
  const context = await browser.newContext()
  const page = await context.newPage()
  await login(page, 'recepcion@clinica-demo.test')
  await page.goto(budgetUrl)
  await expect(page.getByRole('heading', { name: 'Registrar la decisión del paciente' })).toBeVisible()
  await expectNoAxeViolations(page)
  await page.getByLabel('Documento de quien decide').fill(dni)
  await page.getByRole('button', { name: 'Registrar aceptación' }).click()
  await page.getByRole('dialog').getByRole('button', { name: 'Confirmar aceptación' }).click()
  await expect(page.getByRole('heading', { name: 'Decisión registrada' })).toBeVisible()
  await expect(page.getByText('Aceptado', { exact: true }).first()).toBeVisible()
  await context.close()
}

test('turns a red finding into a performed procedure through an accepted budget', async ({ page, browser }) => {
  test.setTimeout(180_000)
  const dni = String(10_000_000 + Math.floor(Math.random() * 89_999_999))

  await login(page, 'dentista@clinica-demo.test')

  // Paciente sintético con consentimiento (a).
  await page.getByRole('link', { name: 'Registrar paciente' }).click()
  await page.getByLabel('Número de documento', { exact: true }).fill(dni)
  await page.getByLabel('Nombres', { exact: true }).fill('Lucía')
  await page.getByLabel('Apellidos', { exact: true }).fill('Sintética Plan')
  await page.getByLabel('Fecha de nacimiento').fill('1990-03-15')
  await page.getByLabel('Sexo').selectOption('femenino')
  await page.getByLabel('Teléfono').fill('987654321')
  await page.getByRole('button', { name: 'Registrar paciente' }).click()
  await page.getByRole('link', { name: 'Consentimiento', exact: true }).click()
  await page.getByLabel('(a) Atención odontológica (obligatoria)').check()
  await page.getByLabel('Documento del titular (confirmación)').fill(dni)
  await page.getByRole('button', { name: 'Registrar consentimiento' }).click()
  await expect(page.getByText('Consentimiento registrado.')).toBeVisible()

  // Hallazgo rojo en la pieza 36 (CUS-22).
  await page.getByRole('link', { name: 'Historia clínica', exact: true }).click()
  await page.getByRole('button', { name: 'Atender' }).click()
  await expect(page).toHaveURL(/\/c\/clinica-demo\/app\/atenciones\/[0-9a-f-]+$/)
  const attentionUrl = page.url()
  await page.getByRole('button', { name: /^Pieza 18/ }).focus()
  await page.keyboard.type('36')
  await page.keyboard.type('o')
  await page.getByLabel('Hallazgo', { exact: true }).selectOption({ label: 'Lesión de caries dental' })
  await page
    .getByLabel('Estado', { exact: true })
    .selectOption({ label: 'CD · Lesión de caries dental a nivel de la dentina (rojo)' })
  await page.getByRole('button', { name: 'Registrar hallazgo' }).click()
  await expect(page.getByText('Hallazgo registrado.')).toBeVisible()

  // Pendientes de decisión (CUS-34): el hallazgo se convierte en ítem de un plan nuevo (RF-111).
  await page.getByRole('link', { name: 'Volver a la historia clínica' }).click()
  await page.getByRole('link', { name: 'Pendientes', exact: true }).click()
  const pending = page.getByRole('checkbox', { name: /^Seleccionar Lesión de caries dental \(CD\) en la pieza 36/ })
  await expect(pending).toBeVisible()
  await expectNoAxeViolations(page)
  await pending.check()
  await page.getByRole('button', { name: 'Crear ítems del plan (1)' }).click()

  await expect(page.getByText(/atiende Lesión de caries dental \(CD\)/)).toBeVisible()
  await page.getByLabel('Título del plan').fill('Plan de restauraciones')
  await page.getByLabel('Procedimiento').selectOption({ label: 'RES-01 · Restauración con resina (S/ 150.00)' })
  await expectNoAxeViolations(page)
  await page.getByRole('button', { name: 'Crear plan en borrador' }).click()

  // Detalle del plan (CUS-33): borrador con el ítem vinculado, y luego propuesto.
  await expect(page).toHaveURL(/\/c\/clinica-demo\/app\/planes\/[0-9a-f-]+$/)
  await expect(page.getByRole('heading', { name: 'Plan de restauraciones' })).toBeVisible()
  await expect(page.getByText('Atiende 1 hallazgo')).toBeVisible()
  await expect(page.getByText('Borrador', { exact: true })).toBeVisible()
  await expectNoAxeViolations(page)
  await page.getByRole('button', { name: 'Proponer al paciente' }).click()
  await expect(page.getByText('Propuesto', { exact: true }).first()).toBeVisible()
  await expect(page.getByRole('button', { name: 'Volver a editar' })).toBeVisible()
  const planUrl = page.url()

  // El hallazgo ya no está pendiente (RN-27) y el plan figura en la ficha con su avance (RF-130).
  await page.getByRole('link', { name: 'Volver a los planes del paciente' }).click()
  await expect(page.getByRole('link', { name: 'Plan de restauraciones' })).toBeVisible()
  await expect(page.getByText('0 de 1 ítems realizados')).toBeVisible()
  await page.getByRole('link', { name: 'Pendientes', exact: true }).click()
  await expect(page.getByText('No hay hallazgos pendientes de decisión.')).toBeVisible()

  // Consentimientos del plan (CUS-83): la restauración no lo exige.
  await page.goto(`${planUrl}/consentimientos`)
  await expect(page.getByText('Ningún ítem pendiente del plan exige consentimiento informado.')).toBeVisible()
  await expectNoAxeViolations(page)

  // Presupuesto (CUS-35): borrador con el ítem propuesto y emisión confirmada (RNF-063).
  await page.goto(planUrl)
  await page.getByRole('link', { name: 'Nuevo presupuesto' }).click()
  await expect(page.getByRole('heading', { name: 'Nuevo presupuesto' })).toBeVisible()
  await expectNoAxeViolations(page)
  await page.getByRole('button', { name: 'Crear borrador con 1 ítem' }).click()
  await expect(page).toHaveURL(/\/c\/clinica-demo\/app\/presupuestos\/[0-9a-f-]+$/)
  await expect(page.getByRole('heading', { name: 'Presupuesto en borrador' })).toBeVisible()
  await expect(page.getByText('S/ 150.00').first()).toBeVisible()
  await page.getByRole('button', { name: 'Emitir presupuesto' }).click()
  await page.getByRole('dialog').getByRole('button', { name: 'Confirmar emisión' }).click()
  await expect(page.getByText('Emitido', { exact: true })).toBeVisible()
  await expect(page.getByRole('heading', { name: /^Presupuesto P-/ })).toBeVisible()
  await expectNoAxeViolations(page)
  const budgetUrl = page.url()

  await acceptAtReception(browser, budgetUrl, dni)

  // Procedimiento en la atención abierta (CUS-39): confirmación con las alergias a la vista.
  await page.goto(attentionUrl.replace(/\/?$/, '/procedimientos'))
  const procedures = page.getByRole('region', { name: 'Procedimientos' })
  const row = procedures.getByRole('row', { name: /Restauración con resina/ })
  await expect(row).toBeVisible()
  await row.getByRole('button', { name: 'Registrar' }).click()
  await procedures.getByRole('button', { name: 'Registrar procedimiento' }).click()
  await expect(page.getByRole('dialog')).toContainText('alergias')
  await page.getByRole('dialog').getByRole('button', { name: 'Confirmar registro' }).click()
  await expect(page.getByText('Su evolución se agregó al odontograma.')).toBeVisible()
  await expectNoAxeViolations(page)

  // La entrada del procedimiento aparece en el historial de la pieza 36 (RN-39, CA-39.1).
  await page.getByRole('link', { name: 'Volver a la historia clínica' }).click()
  await page.getByRole('button', { name: /^Pieza 36/ }).click()
  const history = page.getByRole('region', { name: 'Historial de la pieza 36' })
  await expect(history.getByText(/· Procedimiento$/)).toBeVisible()
  await expect(history.getByText(/Restauración/).first()).toBeVisible()
})
