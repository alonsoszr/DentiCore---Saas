import AxeBuilder from '@axe-core/playwright'
import { expect, test } from '@playwright/test'
import { auditScreen, expectSpanishText } from './support/screen'

// Prueba de humo (TASK-020): el personal de la clínica de demostración (semilla local,
// datos sintéticos) inicia sesión en /c/:slug/login y llega a su área.
test('logs a receptionist into the demo clinic', async ({ page }, testInfo) => {
  await page.goto('/c/clinica-demo/login')

  await page.getByLabel('Correo electrónico').fill('recepcion@clinica-demo.test')
  await page.getByLabel('Contraseña', { exact: true }).fill('password')
  await page.getByRole('button', { name: 'Ingresar' }).click()

  await expect(page).toHaveURL(/\/c\/clinica-demo\/app\/pacientes$/)
  await expect(page.getByRole('heading', { name: 'Pacientes' })).toBeVisible()
  await expectSpanishText(page)

  // Informe de accesibilidad (axe-core) adjunto al reporte; T-172 lo exige sin
  // violaciones en el portal a partir de MS-13.
  const accessibility = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze()
  await testInfo.attach('axe-core', { body: JSON.stringify(accessibility, null, 2), contentType: 'application/json' })
})

// Fichas auth-login (A1) y auth-login-platform (A2).
test.describe('login screens', () => {
  // RF-033 (TASK-027): el mismo 401 «Credenciales inválidas» para todo fallo. Usa una cuenta que no
  // existe: los 16 proyectos de la matriz fallarían 16 veces y bloquearían una cuenta real (RF-035).
  test('rejects invalid credentials with the single message and keeps the email (CA-06.3)', async ({ page }) => {
    await page.goto('/c/clinica-demo/login')

    await page.getByLabel('Correo electrónico').fill('nadie@clinica-demo.test')
    await page.getByLabel('Contraseña', { exact: true }).fill('incorrecta')
    await page.getByRole('button', { name: 'Ingresar' }).click()

    await expect(page.getByRole('alert')).toHaveText(/^Credenciales inválidas\.?$/)
    await expect(page.getByLabel('Correo electrónico')).toHaveValue('nadie@clinica-demo.test')
    await expect(page.getByLabel('Contraseña', { exact: true })).toHaveValue('')
    await auditScreen(page)
  })

  test('the clinic login has no accessibility violations', async ({ page }) => {
    await page.goto('/c/clinica-demo/login')
    await expect(page.getByRole('heading', { level: 1, name: 'Inicia sesión' })).toBeVisible()

    await auditScreen(page)
  })

  test('the platform login has no accessibility violations', async ({ page }) => {
    await page.goto('/login')
    await expect(page.getByText('DentiCore · Administración de la plataforma')).toBeVisible()

    await auditScreen(page)
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
