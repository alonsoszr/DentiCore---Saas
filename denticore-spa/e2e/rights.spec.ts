import { randomUUID } from 'node:crypto'
import { expect, test, type Page } from '@playwright/test'
import { auditScreen, login } from './support/screen'
import { MAILPIT_URL, SUPER_ADMIN, syntheticRuc, totp } from './support/auth'

const API = `${process.env.E2E_API_URL ?? process.env.VITE_API_URL ?? 'http://localhost:8000'}/api/v1`

/** Solo prepara una clínica sintética propia; las acciones de ARCO se hacen por la interfaz. */
async function officerClinic(page: Page) {
  const post = async (path: string, data: object, token?: string) => {
    const response = await page.request.post(`${API}${path}`, {
      data,
      headers: {
        Accept: 'application/json',
        'Idempotency-Key': randomUUID(),
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
    })
    expect(response.ok(), `Preparación de ${path}: HTTP ${response.status()}`).toBeTruthy()
    return response.status() === 204 ? null : response.json()
  }
  const initial = await post('/auth/login', { email: SUPER_ADMIN.email, password: SUPER_ADMIN.password })
  const platform = await post('/auth/2fa/verify', { code: totp(SUPER_ADMIN.totpSecret) }, initial.token)
  const slug = `derechos-${randomUUID().slice(0, 8)}`
  const email = `oficial.${slug}@clinica-e2e.test`
  await post(
    '/platform/tenants',
    {
      name: 'Clínica Derechos',
      legal_name: 'Clínica Derechos S.A.C.',
      ruc: syntheticRuc(),
      slug,
      address: 'Av. Sintética 123, Lima',
      subscription_plan: 'basic',
      admin: { name: 'Oficial de Prueba', email },
    },
    platform.token,
  )
  await post('/auth/logout', {}, platform.token)

  let invitation = ''
  await expect
    .poll(
      async () => {
        const search = await (
          await page.request.get(`${MAILPIT_URL}/api/v1/search`, { params: { query: `to:"${email}"` } })
        ).json()
        if (!search.messages?.length) return ''
        const message = await (await page.request.get(`${MAILPIT_URL}/api/v1/message/${search.messages[0].ID}`)).json()
        invitation =
          (`${message.Text} ${message.HTML}`.match(/https?:\/\/[^\s"<]+\/activar\/([A-Za-z0-9_-]+)/) ?? [])[1] ?? ''
        return invitation
      },
      { timeout: 30_000, message: 'Invitación del oficial sintético' },
    )
    .not.toBe('')

  const password = 'Derechos-Seguros-2026!'
  await post(`/auth/invitations/${invitation}/accept`, { password, password_confirmation: password })
  const pending = await post('/auth/login', { tenant_slug: slug, email, password })
  const setup = await post('/auth/2fa/setup', {}, pending.token)
  const session = await post('/auth/2fa/confirm', { code: totp(setup.secret) }, pending.token)
  expect(session.user.is_data_officer).toBe(true)
  await page.addInitScript((token) => sessionStorage.setItem('dc.token', token), session.token)
  return { slug, token: session.token }
}

test('accepts an ARCO cancellation through the UI and rejects a new clinical write (TASK-109-a)', async ({ page }) => {
  test.skip(
    test.info().project.name.endsWith('-1280') === false,
    'Flujo del oficial: 1280 px en los cuatro navegadores',
  )
  test.setTimeout(180_000)
  const { slug, token } = await officerClinic(page)
  const dni = String(10_000_000 + Math.floor(Math.random() * 89_999_999))
  await page.goto(`/c/${slug}/app/pacientes/nuevo`)
  await expect(page.getByLabel('Número de documento', { exact: true })).toBeVisible()
  await page.getByLabel('Número de documento', { exact: true }).fill(dni)
  await page.getByLabel('Nombres', { exact: true }).fill('Elena')
  await page.getByLabel('Apellidos', { exact: true }).fill('Derechos Sintéticos')
  await page.getByLabel('Fecha de nacimiento').fill('1985-07-20')
  await page.getByLabel('Sexo').selectOption('femenino')
  await page.getByLabel('Teléfono').fill('987654321')
  await page.getByRole('button', { name: 'Registrar paciente' }).click()
  await expect(page).toHaveURL(new RegExp(`/c/${slug}/app/pacientes/[0-9a-f-]+$`))
  const patientId = new URL(page.url()).pathname.split('/').at(-1)!
  await page.getByRole('link', { name: 'Consentimiento', exact: true }).click()
  await page.getByLabel('(a) Atención odontológica (obligatoria)').check()
  await page.getByLabel('Documento del titular (confirmación)').fill(dni)
  await page.getByRole('button', { name: 'Registrar consentimiento' }).click()
  await expect(page.getByText('Consentimiento registrado.')).toBeVisible()

  await page.goto(`/c/${slug}/app/pacientes/${patientId}/arco`)
  await page.getByLabel('Tipo de solicitud').selectOption('cancelacion')
  await page.getByLabel('Descripción').fill('Solicito la cancelación de mis datos opcionales y el bloqueo de mi ficha.')
  await page.getByLabel('Documento de confirmación').fill(dni)
  await auditScreen(page)
  await page.getByRole('button', { name: 'Registrar solicitud' }).click()
  await expect(page.getByText(/Solicitud registrada/)).toBeVisible()
  await page.goto(`/c/${slug}/app/cumplimiento/arco`)
  await page.getByRole('button', { name: 'Ver solicitud' }).click()
  await page.getByRole('button', { name: 'Iniciar atención' }).click()
  await page.getByRole('button', { name: 'Aplicar cancelación ARCO' }).click()
  const dialog = page.getByRole('dialog')
  await expect(dialog).toContainText('La historia clínica se conserva')
  await auditScreen(page)
  await dialog.getByRole('button', { name: 'Bloquear paciente' }).click()
  await expect(page.getByText('Paciente bloqueado. La historia clínica se conserva.')).toBeVisible()
  await page
    .getByLabel('Respuesta al solicitante')
    .fill('Se aplicó el bloqueo y se conservará la historia clínica durante el plazo legal.')
  await page.getByRole('button', { name: 'Resolver solicitud' }).click()
  await page.getByRole('dialog').getByRole('button', { name: 'Confirmar resolución' }).click()
  await expect(page.getByText('Resuelta dentro del plazo.')).toBeVisible()
  await auditScreen(page)
  for (const [width, height] of [
    [1440, 900],
    [768, 1024],
  ]) {
    await page.setViewportSize({ width, height })
    await auditScreen(page)
    await test
      .info()
      .attach(`arco-oficial-${width}`, { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' })
  }

  const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' }
  const patient = await (await page.request.get(`${API}/patients/${patientId}`, { headers })).json()
  expect(patient.data.archive_status).toBe('bloqueado')
  const write = await page.request.put(`${API}/patients/${patientId}/medical-history`, {
    headers,
    data: { alergias: ['Dato sintético'] },
  })
  expect(write.status()).toBe(422)
  expect((await write.json()).rule).toBe('RN-10')
})

test('makes ARCO and clinical record copy available from the patient portal at every viewport', async ({ page }) => {
  test.setTimeout(90_000)
  await login(page, 'paciente@clinica-demo.test', /\/c\/clinica-demo\/portal$/)
  await page.getByRole('link', { name: 'Mi salud', exact: true }).click()
  // La insignia llega por otra consulta y desplaza los enlaces; esperar su estado evita perder el clic en WebKit.
  await expect(
    page.getByRole('region', { name: 'Consentimiento de datos' }).getByText('Consentimiento vigente', { exact: true }),
  ).toBeVisible()
  await page.getByRole('link', { name: 'Consultar o registrar una solicitud ARCO' }).click()
  await expect(page.getByRole('heading', { name: 'Registrar solicitud ARCO' })).toBeVisible()
  await expect(page.getByLabel('Documento de confirmación')).toHaveCount(0)
  await auditScreen(page)
  await page.getByRole('link', { name: 'Volver a Mi salud' }).click()
  await expect(
    page.getByRole('region', { name: 'Consentimiento de datos' }).getByText('Consentimiento vigente', { exact: true }),
  ).toBeVisible()
  await page.getByRole('link', { name: 'Solicitar copia de mi historia clínica' }).click()
  await expect(page.getByRole('button', { name: 'Solicitar copia de HC' })).toBeVisible()
  await auditScreen(page)
  if (test.info().project.name.endsWith('-1280')) {
    await page.getByRole('button', { name: 'Solicitar copia de HC' }).click()
    await expect
      .poll(
        async () => {
          const refresh = page.getByRole('button', { name: 'Actualizar estado' })
          if ((await refresh.isVisible()) && (await refresh.isEnabled())) await refresh.click()
          return page.getByRole('link', { name: 'Descargar copia' }).count()
        },
        { timeout: 45_000, message: 'La copia real de HC se prepara y obtiene su URL firmada' },
      )
      .toBe(1)
    await auditScreen(page)
  }
  await test
    .info()
    .attach('documentos-portal', { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' })
})
