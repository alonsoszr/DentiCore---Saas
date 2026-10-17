import { expect, test } from '@playwright/test'
import { auditScreen, login, staffOnly } from './support/screen'

// RNF-146 (DEF-008 del TCR-E1): con dos pestañas del mismo usuario en fichas distintas, cada franja
// del paciente avisa que hay otro paciente abierto, y el aviso desaparece al cerrar la otra pestaña.
// Solo lee las fichas de la semilla de demostración, así que corre en toda la matriz desde 768 px.
staffOnly()

test('warns about another patient open in another tab and clears it when that tab closes', async ({ context }) => {
  const first = await context.newPage()
  await login(first, 'dentista@clinica-demo.test')
  const links = first.getByRole('link', { name: 'Ver ficha' })
  await expect(links.nth(1)).toBeVisible()
  const [firstHref, secondHref] = [await links.nth(0).getAttribute('href'), await links.nth(1).getAttribute('href')]

  await first.goto(firstHref!)
  const firstHeader = first.getByRole('region', { name: 'Paciente', exact: true })
  const firstName = (await firstHeader.getByRole('heading').first().textContent())!.trim()

  // La sesión vive en sessionStorage (RNF-094): la segunda pestaña inicia sesión con el mismo usuario.
  const second = await context.newPage()
  await login(second, 'dentista@clinica-demo.test')
  await second.goto(secondHref!)
  const secondHeader = second.getByRole('region', { name: 'Paciente', exact: true })
  const secondName = (await secondHeader.getByRole('heading').first().textContent())!.trim()

  await expect(firstHeader.getByRole('status')).toContainText(
    `En otra pestaña tienes abierta la ficha de ${secondName}.`,
  )
  await expect(secondHeader.getByRole('status')).toContainText(
    `En otra pestaña tienes abierta la ficha de ${firstName}.`,
  )
  await auditScreen(first)

  await second.close()
  await expect(firstHeader.getByRole('status')).toBeEmpty()
})
