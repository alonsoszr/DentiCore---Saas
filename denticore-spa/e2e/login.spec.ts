import AxeBuilder from '@axe-core/playwright'
import { expect, test, type Page } from '@playwright/test'

const WCAG_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']

async function expectNoAxeViolations(page: Page) {
  const { violations } = await new AxeBuilder({ page }).withTags(WCAG_AA).analyze()
  expect(violations.map((violation) => `${violation.id}: ${violation.help}`)).toEqual([])
}

// Prueba de humo (TASK-020): el personal de la clínica de demostración (semilla local,
// datos sintéticos) inicia sesión en /c/:slug/login y llega a su área.
test('logs a receptionist into the demo clinic', async ({ page }, testInfo) => {
  await page.goto('/c/clinica-demo/login')

  await page.getByLabel('Correo electrónico').fill('recepcion@clinica-demo.test')
  await page.getByLabel('Contraseña', { exact: true }).fill('password')
  await page.getByRole('button', { name: 'Ingresar' }).click()

  await expect(page).toHaveURL(/\/c\/clinica-demo\/app\/pacientes$/)
  await expect(page.getByRole('heading', { name: 'Pacientes' })).toBeVisible()

  // Informe de accesibilidad (axe-core) adjunto al reporte; T-172 lo exige sin
  // violaciones en el portal a partir de MS-13.
  const accessibility = await new AxeBuilder({ page }).withTags(WCAG_AA).analyze()
  await testInfo.attach('axe-core', { body: JSON.stringify(accessibility, null, 2), contentType: 'application/json' })
})

// Fichas auth-login (A1) y auth-login-platform (A2).
test.describe('login screens', () => {
  // Pendiente de TASK-027: la API aún responde 422 con el error en `email` en lugar del
  // 401 "Credenciales inválidas" de RF-033. El estado 401 está cubierto en LoginPage.test.jsx.
  test.fixme('rejects invalid credentials with the single message and keeps the email (CA-06.3)', async ({ page }) => {
    await page.goto('/c/clinica-demo/login')

    await page.getByLabel('Correo electrónico').fill('recepcion@clinica-demo.test')
    await page.getByLabel('Contraseña', { exact: true }).fill('incorrecta')
    await page.getByRole('button', { name: 'Ingresar' }).click()

    await expect(page.getByRole('alert')).toHaveText('Credenciales inválidas')
    await expect(page.getByLabel('Correo electrónico')).toHaveValue('recepcion@clinica-demo.test')
    await expect(page.getByLabel('Contraseña', { exact: true })).toHaveValue('')
    await expectNoAxeViolations(page)
  })

  test('the clinic login has no accessibility violations', async ({ page }) => {
    await page.goto('/c/clinica-demo/login')
    await expect(page.getByRole('heading', { level: 1, name: 'Inicia sesión' })).toBeVisible()

    await expectNoAxeViolations(page)
  })

  test('the platform login has no accessibility violations', async ({ page }) => {
    await page.goto('/login')
    await expect(page.getByText('DentiCore · Administración de la plataforma')).toBeVisible()

    await expectNoAxeViolations(page)
  })

  test('hides the brand panel below 1024px and keeps the form usable at 768px', async ({ page }) => {
    await page.setViewportSize({ width: 768, height: 1024 })
    await page.goto('/c/clinica-demo/login')

    await expect(page.getByText('Historia clínica, odontograma y agenda en un solo lugar')).toBeHidden()
    await page.getByLabel('Correo electrónico').fill('recepcion@clinica-demo.test')
    await page.getByLabel('Contraseña', { exact: true }).fill('password')
    await page.getByRole('button', { name: 'Ingresar' }).click()

    await expect(page).toHaveURL(/\/c\/clinica-demo\/app\/pacientes$/)
  })
})
