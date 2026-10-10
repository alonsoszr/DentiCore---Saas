import AxeBuilder from '@axe-core/playwright'
import { expect, test, type Browser, type Page } from '@playwright/test'

const WCAG_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']

async function expectNoAxeViolations(page: Page) {
  const { violations } = await new AxeBuilder({ page }).withTags(WCAG_AA).analyze()
  expect(violations.map((violation) => `${violation.id}: ${violation.help}`)).toEqual([])
}

// TASK-062 (criterio 1) y criterio de salida de MS-03: hallazgo rojo → pendientes → ítems del plan
// (restauración y extracción) → plan propuesto → presupuesto emitido con IGV y número → aceptado en
// recepción → consentimiento informado de la extracción → procedimientos → evolución en el
// odontograma. Crea una ficha nueva en cada ejecución (DNI sintético), así que corre en un solo
// proyecto. Usa el catálogo y la plantilla de demostración (DemoProcedureSeeder).
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
  await page
    .getByRole('group', { name: /^Ítem 1/ })
    .getByLabel('Procedimiento')
    .selectOption({ label: 'RES-01 · Restauración con resina (S/ 150.00)' })
  // La extracción exige consentimiento informado (RN-76); va en la pieza 48 para no tocar la 36.
  await page.getByRole('button', { name: 'Agregar otro ítem' }).click()
  const extractionItem = page.getByRole('group', { name: 'Ítem 2' })
  await extractionItem.getByLabel('Procedimiento').selectOption({ label: 'EXO-01 · Extracción simple (S/ 120.00)' })
  await extractionItem.getByLabel('Pieza').fill('48')
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
  await expect(page.getByText('0 de 2 ítems realizados')).toBeVisible()
  await page.getByRole('link', { name: 'Pendientes', exact: true }).click()
  await expect(page.getByText('No hay hallazgos pendientes de decisión.')).toBeVisible()

  // Presupuesto (CUS-35): borrador con el ítem propuesto y emisión confirmada (RNF-063).
  await page.goto(planUrl)
  await page.getByRole('link', { name: 'Nuevo presupuesto' }).click()
  await expect(page.getByRole('heading', { name: 'Nuevo presupuesto' })).toBeVisible()
  await expectNoAxeViolations(page)
  await page.getByRole('button', { name: 'Crear borrador con 2 ítems' }).click()
  await expect(page).toHaveURL(/\/c\/clinica-demo\/app\/presupuestos\/[0-9a-f-]+$/)
  await expect(page.getByRole('heading', { name: 'Presupuesto en borrador' })).toBeVisible()
  await expect(page.getByText('S/ 270.00').first()).toBeVisible()
  await page.getByRole('button', { name: 'Emitir presupuesto' }).click()
  await page.getByRole('dialog').getByRole('button', { name: 'Confirmar emisión' }).click()
  await expect(page.getByText('Emitido', { exact: true })).toBeVisible()
  await expect(page.getByRole('heading', { name: /^Presupuesto P-/ })).toBeVisible()
  await expectNoAxeViolations(page)
  const budgetUrl = page.url()

  await acceptAtReception(browser, budgetUrl, dni)

  // Consentimiento informado de la extracción (CUS-83): vista previa y firma en el dispositivo.
  await page.goto(`${planUrl}/consentimientos`)
  const consentRow = page.getByRole('row', { name: /Extracción simple/ })
  await expect(page.getByRole('row', { name: /Restauración con resina/ })).toHaveCount(0)
  await consentRow.getByRole('button', { name: 'Firmar: consentimiento de Extracción simple' }).click()
  await page.getByLabel('Riesgos (opcional)').fill('sangrado leve e inflamación')
  await page.getByLabel('Alternativas (opcional)').fill('mantener la pieza con controles')
  await page.getByRole('button', { name: 'Ver texto a firmar' }).click()
  await expect(
    page.getByText(/Yo, Lucía Sintética Plan, autorizo .* el procedimiento Extracción simple en la pieza 48/),
  ).toBeVisible()
  await expect(page.getByText(/riesgos: sangrado leve e inflamación/)).toBeVisible()
  await expectNoAxeViolations(page)
  await page.getByLabel('Documento de quien firma').fill(dni)
  await page.getByRole('button', { name: 'Registrar firma' }).click()
  await expect(consentRow.getByText('Firmado', { exact: true })).toBeVisible()

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

  // La extracción usa el consentimiento firmado (RN-76) y deja la pieza 48 ausente.
  const extractionRow = procedures.getByRole('row', { name: /Extracción simple/ })
  await extractionRow.getByRole('button', { name: 'Registrar' }).click()
  await procedures.getByRole('button', { name: 'Registrar procedimiento' }).click()
  await page.getByRole('dialog').getByRole('button', { name: 'Confirmar registro' }).click()
  await expect(page.getByText('Su evolución se agregó al odontograma.')).toBeVisible()
  await expect(procedures.getByText('El paciente no tiene ítems aceptados pendientes de realizar.')).toBeVisible()

  // La entrada del procedimiento aparece en el historial de la pieza 36 (RN-39, CA-39.1).
  await page.getByRole('link', { name: 'Volver a la historia clínica' }).click()
  // El odontograma vigente muestra la restauración en la 36 y la 48 ausente por extracción.
  const restoredTooth = page.getByRole('button', { name: /^Pieza 36: .*Restauración definitiva/ })
  const extractedTooth = page.getByRole('button', { name: /^Pieza 48: .*diente ausente por extracción/ })
  await expect(restoredTooth).toBeVisible()
  await expect(extractedTooth).toBeVisible()
  await restoredTooth.click()
  const history = page.getByRole('region', { name: 'Historial de la pieza 36' })
  await expect(history.getByText(/· Procedimiento$/)).toBeVisible()
  await expect(history.getByText(/Restauración/).first()).toBeVisible()
  await extractedTooth.click()
  const extractionHistory = page.getByRole('region', { name: 'Historial de la pieza 48' })
  await expect(extractionHistory.getByText(/· Procedimiento$/)).toBeVisible()
})
