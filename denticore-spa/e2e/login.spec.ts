import AxeBuilder from '@axe-core/playwright'
import { expect, test } from '@playwright/test'

// Prueba de humo (TASK-020): el personal de la clínica de demostración (semilla local,
// datos sintéticos) inicia sesión en /c/:slug/login y llega a su área.
test('logs a receptionist into the demo clinic', async ({ page }, testInfo) => {
  await page.goto('/c/clinica-demo/login')

  await page.getByLabel('Correo electrónico').fill('recepcion@clinica-demo.test')
  await page.getByLabel('Contraseña').fill('password')
  await page.getByRole('button', { name: 'Ingresar' }).click()

  await expect(page).toHaveURL(/\/c\/clinica-demo\/app\/pacientes$/)
  await expect(page.getByRole('heading', { name: 'Pacientes' })).toBeVisible()

  // Informe de accesibilidad (axe-core) adjunto al reporte; T-172 lo exige sin
  // violaciones en el portal a partir de MS-13.
  const accessibility = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze()
  await testInfo.attach('axe-core', { body: JSON.stringify(accessibility, null, 2), contentType: 'application/json' })
})
