#!/usr/bin/env node
/*
 * Cobertura de los servicios críticos de la API (TASK-095; SDD §6.4, RNF-126): al menos 95 % de las
 * sentencias en aislamiento, odontograma, presupuestos, citas y riesgo. Los pagos son de E2 y aún no
 * existen. Lee el informe Clover de Pest (`pest --coverage-clover=coverage.xml`).
 *
 * Uso: node scripts/coverage/critical.mjs denticore-api/coverage.xml
 */
import { appendFileSync, readFileSync } from 'node:fs'

const MIN = 95

/** Servicio crítico → carpeta de app/. */
export const CRITICAL = {
  Aislamiento: 'app/Support/Tenancy/',
  Odontograma: 'app/Modules/Odontogram/Services/',
  Presupuestos: 'app/Modules/Treatment/Services/',
  Citas: 'app/Modules/Scheduling/Services/',
  Riesgo: 'app/Modules/Risk/Services/',
}

const attribute = (tag, name) => Number(tag.match(new RegExp(`\\b${name}="(\\d+)"`))?.[1] ?? 0)

/** Sentencias y sentencias cubiertas por servicio crítico, con los archivos que quedan por debajo. */
export function criticalCoverage(clover) {
  const totals = Object.fromEntries(Object.keys(CRITICAL).map((name) => [name, { statements: 0, covered: 0, files: [] }]))
  for (const [, path, body] of clover.matchAll(/<file name="([^"]+)"[^>]*>([\s\S]*?)<\/file>/g)) {
    const normalized = path.replace(/\\/g, '/')
    const name = Object.keys(CRITICAL).find((key) => normalized.includes(`/${CRITICAL[key]}`))
    const metrics = [...body.matchAll(/<metrics\b[^>]*>/g)].at(-1)?.[0]
    if (!name || !metrics) continue
    const statements = attribute(metrics, 'statements')
    const covered = attribute(metrics, 'coveredstatements')
    totals[name].statements += statements
    totals[name].covered += covered
    if (covered < statements) totals[name].files.push(`${normalized.split('/app/')[1]} (${statements - covered} sin cubrir)`)
  }
  return totals
}

function main() {
  const totals = criticalCoverage(readFileSync(process.argv[2] ?? 'coverage.xml', 'utf8'))
  const rows = Object.entries(totals).map(([name, { statements, covered, files }]) => {
    const pct = statements ? (covered / statements) * 100 : 0
    return { name, pct, statements, covered, files, ok: statements > 0 && pct >= MIN }
  })
  const report = [
    `## Cobertura de los servicios críticos (mínimo ${MIN} %)`,
    '',
    '| Servicio | Carpeta | Sentencias | Cobertura | Archivos por completar |',
    '| --- | --- | --- | --- | --- |',
    ...rows.map(
      (row) =>
        `| ${row.ok ? '✅' : '❌'} ${row.name} | ${CRITICAL[row.name]} | ${row.covered}/${row.statements} | ${row.pct.toFixed(1)} % | ${row.files.join(', ')} |`,
    ),
  ].join('\n')
  console.log(report)
  if (process.env.GITHUB_STEP_SUMMARY) appendFileSync(process.env.GITHUB_STEP_SUMMARY, `${report}\n`)
  process.exitCode = rows.every((row) => row.ok) ? 0 : 1
}

main()
