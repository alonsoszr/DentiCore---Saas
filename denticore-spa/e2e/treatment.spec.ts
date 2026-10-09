import AxeBuilder from '@axe-core/playwright'
import { expect, test, type Page } from '@playwright/test'

const WCAG_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']

async function expectNoAxeViolations(page: Page) {
  const { violations } = await new AxeBuilder({ page }).withTags(WCAG_AA).analyze()
  expect(violations.map((violation) => `${violation.id}: ${violation.help}`)).toEqual([])
}

// TASK-062: hallazgo rojo → pendientes → ítem del plan → plan propuesto. Crea una ficha nueva en
// cada ejecución (DNI sintético), así que corre en un solo proyecto. Usa el catálogo de
// demostración (DemoProcedureSeeder).
test.skip(
  ({ browserName, channel, viewport }) => browserName !== 'chromium' || Boolean(channel) || viewport?.width !== 1280,
  'Flujo con datos nuevos: solo en Chrome a 1280 px',
)

test('turns a red finding into an item of a proposed treatment plan', async ({ page }) => {
  test.setTimeout(90_000)
  const dni = String(10_000_000 + Math.floor(Math.random() * 89_999_999))

  await page.goto('/c/clinica-demo/login')
  await page.getByLabel('Correo electrónico').fill('dentista@clinica-demo.test')
  await page.getByLabel('Contraseña', { exact: true }).fill('password')
  await page.getByRole('button', { name: 'Ingresar' }).click()
  await expect(page).toHaveURL(/\/c\/clinica-demo\/app\/pacientes$/)

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

  // El hallazgo ya no está pendiente (RN-27) y el plan figura en la ficha con su avance (RF-130).
  await page.getByRole('link', { name: 'Volver a los planes del paciente' }).click()
  await expect(page.getByRole('link', { name: 'Plan de restauraciones' })).toBeVisible()
  await expect(page.getByText('0 de 1 ítems realizados')).toBeVisible()
  await page.getByRole('link', { name: 'Pendientes', exact: true }).click()
  await expect(page.getByText('No hay hallazgos pendientes de decisión.')).toBeVisible()
})
