import { expect, test, type Browser } from '@playwright/test'
import { CHECK_IN_WINDOW, auditScreen, gotoSettled, hasSlotsBetween, login, reserveFreeSlot } from './support/screen'

// TASK-098 (criterio 1; SRS §17.1, OB-02, RES-08): el flujo de valor de E1, de principio a fin y solo
// por la interfaz, con datos sintéticos: cita → historia clínica → riesgo → plan → presupuesto →
// procedimiento. Es la versión automatizada del guion docs/e1/demostracion.md.
//
// Corre solo en chromium-1280: con un odontólogo y pasos de 15 min caben 4 citas en la próxima hora
// (RN-50), y T-170 ya ocupa 3 en cada job de la matriz (768, 1280 y 1920 px).
test.skip(
  ({ browserName, viewport }) => browserName !== 'chromium' || viewport?.width !== 1280,
  'Flujo de demostración: solo en chromium-1280',
)

async function receptionPage(browser: Browser) {
  const page = await (await browser.newContext()).newPage()
  await login(page, 'recepcion@clinica-demo.test')
  return page
}

test('runs the E1 value flow: appointment, clinical record, risk, plan, budget and procedure', async ({
  page,
  browser,
}) => {
  test.setTimeout(300_000)
  const dni = String(10_000_000 + Math.floor(Math.random() * 89_999_999))
  const lastName = `Demostración ${dni
    .split('')
    .map((digit) => 'abcdefghij'[Number(digit)])
    .join('')
    .replace(/^./, (letter) => letter.toUpperCase())}`
  const reception = await receptionPage(browser)

  // 1. Cita (recepción): paciente con las finalidades (a) y (d), sus variables sociodemográficas, una
  // cita de 15 min en la próxima hora y su llegada (CUS-14, CUS-17, CUS-54, CUS-47, CUS-50).
  await reception.getByRole('link', { name: 'Registrar paciente' }).click()
  await reception.getByLabel('Número de documento', { exact: true }).fill(dni)
  await reception.getByLabel('Nombres', { exact: true }).fill('Elena')
  await reception.getByLabel('Apellidos', { exact: true }).fill(lastName)
  await reception.getByLabel('Fecha de nacimiento').fill('1985-07-20')
  await reception.getByLabel('Sexo').selectOption('femenino')
  await reception.getByLabel('Teléfono').fill('987654321')
  await reception.getByRole('button', { name: 'Registrar paciente' }).click()
  await expect(reception).toHaveURL(/\/c\/clinica-demo\/app\/pacientes\/[0-9a-f-]+$/)
  const patientPath = new URL(reception.url()).pathname
  await reception.getByRole('link', { name: 'Consentimiento', exact: true }).click()
  await reception.getByLabel('(a) Atención odontológica (obligatoria)').check()
  await reception.getByLabel('(d) Predicción de riesgo').check()
  await reception.getByLabel('Documento del titular (confirmación)').fill(dni)
  await reception.getByRole('button', { name: 'Registrar consentimiento' }).click()
  await expect(reception.getByText('Consentimiento registrado.')).toBeVisible()
  await reception.getByRole('link', { name: 'Riesgo', exact: true }).click()
  await reception.getByLabel('Nivel educativo').selectOption('sin_estudios')
  await reception.getByLabel('Situación laboral').selectOption('desempleado')
  await reception.getByRole('button', { name: 'Guardar variables' }).click()
  await expect(reception.getByText('Variables guardadas.')).toBeVisible()

  await reception.getByRole('link', { name: 'Agenda', exact: true }).click()
  await reception.getByRole('link', { name: 'Nueva cita' }).click()
  await reception.getByLabel('Buscar paciente').fill(lastName)
  await reception.getByRole('button', { name: 'Buscar' }).click()
  await reception.getByRole('button', { name: `${lastName}, Elena · ${dni}` }).click()
  await reception.getByLabel('Tipo de cita').selectOption({ label: 'Consulta (30 min)' })
  await reception.getByLabel('Duración (minutos, opcional)').fill('15')
  const slots = reception.getByRole('group', { name: 'Diego Odontólogo' }).getByRole('button')
  test.skip(
    !(await hasSlotsBetween(slots, CHECK_IN_WINDOW)),
    'No quedan horarios en la próxima hora (medianoche en Lima)',
  )
  await reserveFreeSlot(
    reception,
    slots,
    reception.getByRole('button', { name: /^Reservar con Diego Odontólogo/ }),
    CHECK_IN_WINDOW,
  )
  await expect(reception.getByText('Programada', { exact: true })).toBeVisible()
  await reception.getByRole('button', { name: 'Registrar llegada' }).click()
  await expect(reception.getByText('Llegada registrada')).toBeVisible()

  // 2. Historia clínica (odontólogo): la HC completa con la atención abierta por la llegada, y un
  // hallazgo rojo en la pieza 36 (CUS-25, CUS-21, CUS-22).
  await login(page, 'dentista@clinica-demo.test')
  await gotoSettled(page, `${patientPath}/hc`)
  const header = page.getByRole('region', { name: 'Paciente', exact: true })
  await expect(header.getByRole('heading', { name: `Elena ${lastName}` })).toBeVisible()
  await page
    .getByRole('row', { name: /Abierta/ })
    .getByRole('link')
    .first()
    .click()
  await expect(page).toHaveURL(/\/c\/clinica-demo\/app\/atenciones\/[0-9a-f-]+$/)
  const attentionUrl = page.url()
  await gotoSettled(page, attentionUrl)
  await page.getByRole('button', { name: /^Pieza 18/ }).focus()
  await page.keyboard.type('36')
  await page.keyboard.type('o')
  await page.getByLabel('Hallazgo', { exact: true }).selectOption({ label: 'Lesión de caries dental' })
  await page
    .getByLabel('Estado', { exact: true })
    .selectOption({ label: 'CD · Lesión de caries dental a nivel de la dentina (rojo)' })
  await page.getByRole('button', { name: 'Registrar hallazgo' }).click()
  await expect(page.getByText('Hallazgo registrado.')).toBeVisible()
  await auditScreen(page)

  // 3. Riesgo (odontólogo): variables clínicas y cálculo con su explicación (CUS-54 a CUS-56).
  await gotoSettled(page, `${patientPath}/riesgo/variables`)
  await page.getByLabel('CPOD').fill('12')
  await page.getByLabel('Índice de placa (%)').fill('90')
  await page.getByLabel('Lesiones activas').fill('8')
  await page.getByLabel('Azúcar entre comidas (veces al día)').selectOption('4+')
  await page.getByLabel('Usa pasta dental fluorada').selectOption('false')
  await page.getByLabel('Cepillado diario (veces)').selectOption('0')
  await page.getByLabel('Visitas al odontólogo por año').selectOption('<1')
  await page.getByRole('button', { name: 'Guardar variables' }).click()
  await expect(page.getByText('El registro está completo para calcular el riesgo.')).toBeVisible()
  await page.getByRole('link', { name: 'Ir al cálculo' }).click()
  await page.getByRole('button', { name: 'Calcular riesgo' }).click()
  await expect(page.getByText('Riesgo alto')).toBeVisible()
  await expect(page.getByText('Factores que aumentan el riesgo')).toBeVisible()
  await expect(page.getByText('Herramienta de apoyo; no constituye diagnóstico')).toBeVisible()
  await auditScreen(page)

  // 4. Plan (odontólogo): el hallazgo pendiente pasa a un plan propuesto (CUS-34, CUS-33, RN-27).
  await gotoSettled(page, `${patientPath}/pendientes`)
  await page.getByRole('checkbox', { name: /^Seleccionar Lesión de caries dental \(CD\) en la pieza 36/ }).check()
  await page.getByRole('button', { name: 'Crear ítems del plan (1)' }).click()
  await page.getByLabel('Título del plan').fill('Plan de la demostración')
  await page
    .getByRole('group', { name: /^Ítem 1/ })
    .getByLabel('Procedimiento')
    .selectOption({ label: 'RES-01 · Restauración con resina (S/ 150.00)' })
  await page.getByRole('button', { name: 'Crear plan en borrador' }).click()
  await expect(page).toHaveURL(/\/c\/clinica-demo\/app\/planes\/[0-9a-f-]+$/)
  await expect(page.getByRole('heading', { name: 'Plan de la demostración' })).toBeVisible()
  await page.waitForLoadState('networkidle')
  await page.getByRole('button', { name: 'Proponer al paciente' }).click()
  await expect(page.getByRole('button', { name: 'Volver a editar' })).toBeVisible()
  const planUrl = page.url()

  // 5. Presupuesto: emisión confirmada (odontólogo) y aceptación presencial (recepción) (CUS-35,
  // CUS-37, RNF-063).
  await gotoSettled(page, planUrl)
  await page.getByRole('link', { name: 'Nuevo presupuesto' }).click()
  await page.getByRole('button', { name: 'Crear borrador con 1 ítem' }).click()
  await expect(page).toHaveURL(/\/c\/clinica-demo\/app\/presupuestos\/[0-9a-f-]+$/)
  await page.getByRole('button', { name: 'Emitir presupuesto' }).click()
  await page.getByRole('dialog').getByRole('button', { name: 'Confirmar emisión' }).click()
  await expect(page.getByRole('heading', { name: /^Presupuesto P-/ })).toBeVisible()
  await auditScreen(page)

  await reception.goto(page.url())
  await reception.getByLabel('Documento de quien decide').fill(dni)
  await reception.getByRole('button', { name: 'Registrar aceptación' }).click()
  await reception.getByRole('dialog').getByRole('button', { name: 'Confirmar aceptación' }).click()
  await expect(reception.getByRole('heading', { name: 'Decisión registrada' })).toBeVisible()
  await reception.context().close()

  // 6. Procedimiento (odontólogo): la restauración se registra en la atención abierta y la pieza 36
  // queda restaurada en el odontograma, con su entrada en el historial (CUS-39, RN-39, CA-39.1).
  await gotoSettled(page, attentionUrl.replace(/\/?$/, '/procedimientos'))
  const procedures = page.getByRole('region', { name: 'Procedimientos', exact: true })
  await procedures
    .getByRole('row', { name: /Restauración con resina/ })
    .getByRole('button', { name: 'Registrar' })
    .click()
  await procedures.getByRole('button', { name: 'Registrar procedimiento' }).click()
  await page.getByRole('dialog').getByRole('button', { name: 'Confirmar registro' }).click()
  await expect(page.getByText('Su evolución se agregó al odontograma.')).toBeVisible()

  await gotoSettled(page, `${patientPath}/hc`)
  const restoredTooth = page.getByRole('button', { name: /^Pieza 36: .*Restauración definitiva/ })
  await expect(restoredTooth).toBeVisible()
  await restoredTooth.click()
  await expect(
    page.getByRole('region', { name: 'Historial de la pieza 36' }).getByText(/· Procedimiento$/),
  ).toBeVisible()
  await auditScreen(page)
})
