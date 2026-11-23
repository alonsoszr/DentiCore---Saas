import { expect, test } from '@playwright/test'
import { SUPER_ADMIN, totp } from './support/auth'
import { auditScreen, login } from './support/screen'

test.beforeEach(({}, info) => {
  test.skip(!info.project.name.endsWith('-1280'), 'TASK-109-b se verifica a 1280 px')
})

test('el administrador de plataforma consulta la bitácora y filtra por acción', async ({ page }) => {
  await page.goto('/login')
  await page.getByLabel('Correo electrónico').fill(SUPER_ADMIN.email)
  await page.getByLabel('Contraseña', { exact: true }).fill(SUPER_ADMIN.password)
  await page.getByRole('button', { name: 'Ingresar' }).click()
  await expect(page).toHaveURL(/\/login\/2fa$/)
  await page.getByLabel('Dígito 1 de 6').fill(totp(SUPER_ADMIN.totpSecret))
  await expect(page).toHaveURL(/\/admin\/clinicas$/)
  await page.getByRole('link', { name: 'Bitácora' }).click()
  await expect(page.getByRole('heading', { name: 'Bitácora de auditoría' })).toBeVisible()
  await expect(page.getByRole('table')).toBeVisible()
  await page.getByLabel('Acción', { exact: true }).fill('auth.login_ok')
  const response = page.waitForResponse((item) => {
    const url = new URL(item.url())
    return url.pathname.endsWith('/platform/audit-logs') && url.searchParams.has('action')
  })
  await page.getByRole('button', { name: 'Filtrar' }).click()
  expect((await response).ok()).toBe(true)
  await auditScreen(page)
})

test('recepción accede a su perfil y la navegación limita la bitácora', async ({ page }) => {
  await login(page, 'recepcion@clinica-demo.test')
  await expect(page.getByRole('link', { name: 'Bitácora' })).toHaveCount(0)
  await page.getByRole('link', { name: 'Mi perfil' }).click()
  await expect(page.getByRole('heading', { name: 'Mi perfil' })).toBeVisible()
  await expect(page.getByLabel('Nombre', { exact: true })).not.toHaveValue('')
  await auditScreen(page)
})
