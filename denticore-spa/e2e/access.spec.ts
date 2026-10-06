import { expect, test, type Page } from '@playwright/test'
import { MAILPIT_URL, SUPER_ADMIN, syntheticRuc, totp } from './support/auth'

// Flujos completos de MS-01 (TASK-039 y TASK-040): alta de clínica → invitación en Mailpit →
// activación → configuración del 2FA → acceso completo. Crean datos nuevos en cada ejecución,
// así que corren en un solo proyecto de la matriz (la del login cubre navegadores y anchos).
test.skip(({ browserName }, testInfo) => browserName !== 'chromium' || !testInfo.project.name.endsWith('1280'))
test.describe.configure({ mode: 'serial' })

async function typeCode(page: Page, code: string) {
  await page.getByLabel('Dígito 1 de 6').fill(code)
}

/** Enlace de activación del último correo enviado a `email` (Mailpit API v1). */
async function invitationLink(page: Page, email: string): Promise<string> {
  let link = ''
  await expect
    .poll(
      async () => {
        const search = await page.request.get(`${MAILPIT_URL}/api/v1/search`, { params: { query: `to:"${email}"` } })
        const { messages = [] } = await search.json()
        if (messages.length === 0) return ''
        const message = await (await page.request.get(`${MAILPIT_URL}/api/v1/message/${messages[0].ID}`)).json()
        link = (`${message.Text} ${message.HTML}`.match(/https?:\/\/[^\s"<]+\/activar\/[A-Za-z0-9_-]+/) ?? [''])[0]
        return link
      },
      { timeout: 30_000, message: `invitación para ${email} en Mailpit` },
    )
    .not.toBe('')
  return link
}

test('registers a clinic, activates its administrator and configures the second factor', async ({ page }) => {
  const suffix = Date.now().toString(36)
  const slug = `e2e-${suffix}`
  const adminEmail = `admin.${suffix}@clinica-e2e.test`

  // Súper Administrador: contraseña y verificación en dos pasos (A2 → A3).
  await page.goto('/login')
  await page.getByLabel('Correo electrónico').fill(SUPER_ADMIN.email)
  await page.getByLabel('Contraseña', { exact: true }).fill(SUPER_ADMIN.password)
  await page.getByRole('button', { name: 'Ingresar' }).click()
  await expect(page).toHaveURL(/\/login\/2fa$/)
  await typeCode(page, totp(SUPER_ADMIN.totpSecret))
  await expect(page).toHaveURL(/\/admin\/clinicas$/)

  // Alta de la clínica con su primer administrador (CUS-01; TASK-039).
  await page.getByRole('button', { name: 'Registrar clínica' }).click()
  await page.getByLabel('Nombre comercial').fill(`Clínica E2E ${suffix}`)
  await page.getByLabel('Razón social').fill(`Clínica E2E ${suffix} S.A.C.`)
  await page.getByLabel('RUC').fill(syntheticRuc())
  await page.getByLabel('Código de acceso').fill(slug)
  await page.getByLabel('Dirección').fill('Av. Sintética 123, Lima')
  await page.getByLabel('Nombre', { exact: true }).fill('Rosa Prueba')
  await page.getByLabel('Correo electrónico').fill(adminEmail)
  await page.getByRole('button', { name: 'Guardar' }).click()
  await expect(page.getByText(`Se envió la invitación de activación a ${adminEmail}`)).toBeVisible()

  // La invitación llega a Mailpit (criterio 2 de TASK-039).
  const link = await invitationLink(page, adminEmail)
  expect(link).toContain(`/c/${slug}/activar/`)

  // Activación (A6) y primer ingreso con configuración del 2FA (A4; criterio 2 de TASK-040).
  const password = 'Molar-Sano-2026!'
  await page.goto(new URL(link).pathname)
  await expect(page.getByText(`Te invitaron a Clínica E2E ${suffix}`)).toBeVisible()
  await page.getByLabel('Crea tu contraseña').fill(password)
  await page.getByLabel('Confirmar contraseña').fill(password)
  await page.getByLabel('Acepto los términos de uso y la política de privacidad').check()
  await page.getByRole('button', { name: 'Activar cuenta' }).click()
  await expect(page).toHaveURL(new RegExp(`/c/${slug}/login$`))
  await expect(page.getByText('Tu cuenta está activa. Inicia sesión.')).toBeVisible()

  await page.getByLabel('Correo electrónico').fill(adminEmail)
  await page.getByLabel('Contraseña', { exact: true }).fill(password)
  await page.getByRole('button', { name: 'Ingresar' }).click()
  await expect(page).toHaveURL(new RegExp(`/c/${slug}/app/seguridad/2fa$`))

  await page.getByText('¿No puedes escanear el código? Clave de configuración manual').click()
  const secret = (await page.getByTestId('manual-key').innerText()).replace(/\s/g, '')
  await page.getByRole('button', { name: 'Continuar a confirmación' }).click()
  await typeCode(page, totp(secret))
  await page.getByRole('button', { name: 'Confirmar' }).click()

  await expect(page.getByRole('heading', { name: 'Guarda tus códigos de recuperación' })).toBeVisible()
  await page.getByLabel('Guardé mis códigos de recuperación').check()
  await page.getByRole('button', { name: 'Finalizar' }).click()

  // Acceso completo al área de la clínica.
  await expect(page).toHaveURL(new RegExp(`/c/${slug}/app/pacientes$`))
  await expect(page.getByRole('heading', { name: 'Pacientes' })).toBeVisible()
})
