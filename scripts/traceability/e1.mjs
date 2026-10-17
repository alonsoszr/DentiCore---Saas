#!/usr/bin/env node
/*
 * Informe de trazabilidad de E1 (TASK-095; SDD §6.4 «Trazabilidad», RNF-005, RNF-128, DD-20).
 *
 * Toma del SRS los requisitos de E1:
 *   - RF y RNF de la fila E1 de §17.4;
 *   - RN de las filas de E1 de la tabla de casos de uso de §14.5;
 *   - CA de §11 de los casos de uso de E1 (§17.2).
 * Los cruza con los IDs que citan las pruebas:
 *   - API (Pest): `->group('RF-xxx', 'RN-xx', 'CA-xx.x')`;
 *   - SPA (Vitest y Playwright): títulos de describe/it/test, `tag: [...]` y el comentario que los precede;
 *   - motor ML (pytest): docstrings del módulo y de cada prueba;
 *   - carga (k6, load/): el comentario de cabecera.
 * Los RNF que no se verifican con una prueba (carga diferida por R-03, controles del pipeline,
 * infraestructura) se declaran en docs/trazabilidad/rnf-e1.json con su evidencia.
 *
 * Falla (código 1) si un RF, una RN o un CA de E1 no tiene prueba, o si un RNF de E1 no tiene prueba
 * ni evidencia declarada. Uso: node scripts/traceability/e1.mjs [--json salida.json] [--anexo anexo.md]
 */
import { appendFileSync, existsSync, readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs'
import { dirname, join, relative, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..')
const read = (path) => readFileSync(join(ROOT, path), 'utf8').replace(/\r\n/g, '\n')

/** «RF-001 a RF-016, RF-018» → ['RF-001', …, 'RF-016', 'RF-018']. */
export function expandIds(list) {
  const ids = []
  for (const part of list.split(/,|\by\b/).map((item) => item.trim().replace(/[.★]/g, '').trim())) {
    const range = part.match(/^([A-Z]+)-(\d+) a (?:[A-Z]+-)?(\d+)$/)
    if (range) {
      const width = range[2].length
      for (let n = Number(range[2]); n <= Number(range[3]); n += 1) ids.push(`${range[1]}-${String(n).padStart(width, '0')}`)
    } else if (/^[A-Z]+-\d+$/.test(part)) {
      ids.push(part)
    }
  }
  return ids
}

/** Requisitos de E1 según el SRS. */
export function e1Requirements(srs) {
  const lines = srs.split('\n')
  const e1Row = lines.find((line) => /^\| E1 \|/.test(line))
  if (!e1Row) throw new Error('No se encontró la fila E1 de §17.4 en el SRS')
  const [rfList, rnfList] = e1Row.split('|').map((cell) => cell.trim()).slice(2, 4)

  const cusSection = srs.split(/^### 17\.2 .*$/m)[1]
  if (!cusSection) throw new Error('No se encontró §17.2 en el SRS')
  const cusLine = cusSection.split('\n').find((line) => line.startsWith('CUS-'))
  const cus = new Set(expandIds(cusLine))

  const section145 = srs.split(/^### 14\.5\b.*$/m)[1]?.split(/^### /m)[0]
  if (!section145) throw new Error('No se encontró §14.5 en el SRS')
  const rn = new Set()
  for (const line of section145.split('\n')) {
    const cells = line.split('|').map((cell) => cell.trim())
    if (cells.length > 7 && cells[7] === 'E1') expandIds(cells[4]).forEach((id) => rn.add(id))
  }

  const ca = [...new Set([...srs.matchAll(/^- \*\*(CA-(\d+)\.\d+)\*\*/gm)]
    .filter((match) => cus.has(`CUS-${match[2].padStart(2, '0')}`))
    .map((match) => match[1]))]

  return { rf: expandIds(rfList), rnf: expandIds(rnfList), rn: [...rn].sort(byText), ca, cus: [...cus] }
}

const byText = (a, b) => a.localeCompare(b)

const ID = /\b(?:RF|RNF|RN|CA)-\d+(?:\.\d+)?\b/g

function filesUnder(dir, pattern) {
  const absolute = join(ROOT, dir)
  if (!existsSync(absolute)) return []
  return readdirSync(absolute).flatMap((name) => {
    if (['node_modules', 'vendor', '__pycache__', '.venv'].includes(name)) return []
    const path = join(dir, name)
    return statSync(join(ROOT, path)).isDirectory() ? filesUnder(path, pattern) : pattern.test(name) ? [path] : []
  })
}

/** Comentario de línea (`//`) inmediatamente anterior a la posición `index`. */
function precedingComment(code, index) {
  const before = code.slice(0, index).split('\n')
  before.pop()
  const comment = []
  while (before.length && /^\s*\/\//.test(before.at(-1))) comment.unshift(before.pop())
  return comment.join('\n')
}

/** IDs que cita cada archivo de prueba, según la convención de su herramienta. */
export function citedIds() {
  const cited = new Map()
  const cite = (text, file) => {
    for (const [id] of text.matchAll(ID)) {
      if (!cited.has(id)) cited.set(id, new Set())
      cited.get(id).add(file)
    }
  }

  for (const file of filesUnder('denticore-api/tests', /\.php$/)) {
    for (const group of read(file).matchAll(/->group\(([^)]*)\)/g)) cite(group[1], file)
  }

  const jsTests = [...filesUnder('denticore-spa/src', /\.test\.jsx?$/), ...filesUnder('denticore-spa/e2e', /\.spec\.ts$/)]
  for (const file of jsTests) {
    const code = read(file)
    for (const call of code.matchAll(/\b(?:describe|it|test)(?:\.\w+)?\(\s*(['"`])((?:(?!\1).)*)\1(\s*,\s*\{[^}]*\})?/g)) {
      cite(`${call[2]} ${call[3] ?? ''} ${precedingComment(code, call.index)}`, file)
    }
  }

  for (const file of filesUnder('denticore-ml/tests', /^test_.*\.py$/)) {
    for (const docstring of read(file).matchAll(/"""([\s\S]*?)"""/g)) cite(docstring[1], file)
  }

  for (const file of filesUnder('load', /\.js$/)) {
    cite(read(file).match(/^\/\*[\s\S]*?\*\//)?.[0] ?? '', file)
  }

  return cited
}

const toPosix = (file) => file.replace(/\\/g, '/')

/**
 * Anexo del informe de aceptación (TASK-097): cada RF, RN, CA y RNF de E1 con todas las pruebas que lo
 * citan, la evidencia declarada en rnf-e1.json o la marca «sin prueba».
 */
export function annex(required, cited, declared) {
  const row = (id) => {
    const files = [...(cited.get(id) ?? [])].map(toPosix).sort(byText)
    if (files.length) return `| ${id} | ${files.length} | ${files.map((file) => `\`${file}\``).join('<br>')} |`
    if (declared[id]) return `| ${id} | 0 | ${declared[id].verificacion}: ${declared[id].evidencia} |`
    return `| ${id} | 0 | **sin prueba** |`
  }
  const section = (title, ids) => [`## ${title} (${ids.length})`, '', '| ID | Pruebas | Archivos o evidencia |', '| --- | --: | --- |', ...ids.map(row), '']
  return [
    '# Anexo: trazabilidad de E1 por ID',
    '',
    'Generado por `node scripts/traceability/e1.mjs --anexo` (TASK-097). Una prueba cita el ID en `->group(...)` (Pest), en el título, las etiquetas o el comentario previo (Vitest y Playwright), en el docstring (pytest) o en la cabecera del script de carga (k6).',
    '',
    ...section('RF de §17.4', required.rf),
    ...section('RN de los CUS de E1 (§14.5)', required.rn),
    ...section('CA de §11 de los CUS de E1', required.ca),
    ...section('RNF de §17.4', required.rnf),
  ].join('\n')
}

function main() {
  const required = e1Requirements(read('docs/SRS_DentiCore.md'))
  const cited = citedIds()
  const evidence = JSON.parse(read('docs/trazabilidad/rnf-e1.json'))
  const declared = evidence.rnf ?? {}

  const missing = (ids) => ids.filter((id) => !cited.has(id))
  const gaps = {
    rf: missing(required.rf),
    rn: missing(required.rn),
    ca: missing(required.ca),
    rnf: missing(required.rnf).filter((id) => !declared[id]),
  }
  const strayDeclarations = Object.keys(declared).filter((id) => !required.rnf.includes(id))

  const row = (id) => {
    const files = [...(cited.get(id) ?? [])].map((file) => relative(ROOT, join(ROOT, file)).replace(/\\/g, '/'))
    if (files.length) return `| ${id} | prueba | ${files.slice(0, 3).join(', ')}${files.length > 3 ? ` y ${files.length - 3} más` : ''} |`
    if (declared[id]) return `| ${id} | ${declared[id].verificacion} | ${declared[id].evidencia} |`
    return `| ${id} | **sin prueba** | |`
  }
  const covered = (ids, gapList) => `${ids.length - gapList.length} de ${ids.length}`
  const report = [
    '# Trazabilidad de E1',
    '',
    '| Tipo | Con prueba o evidencia |',
    '| --- | --- |',
    `| RF (§17.4) | ${covered(required.rf, gaps.rf)} |`,
    `| RN de los CUS de E1 (§14.5) | ${covered(required.rn, gaps.rn)} |`,
    `| CA de §11 de los CUS de E1 | ${covered(required.ca, gaps.ca)} |`,
    `| RNF (§17.4) | ${covered(required.rnf, gaps.rnf)} |`,
    '',
    ...Object.entries(gaps)
      .filter(([, ids]) => ids.length)
      .map(([kind, ids]) => `**${kind.toUpperCase()} de E1 sin prueba:** ${ids.join(', ')}`),
    ...(strayDeclarations.length ? [`**Declarados en rnf-e1.json fuera de E1:** ${strayDeclarations.join(', ')}`] : []),
    '',
    '## RNF de E1',
    '',
    '| RNF | Verificación | Evidencia |',
    '| --- | --- | --- |',
    ...required.rnf.map(row),
  ].join('\n')

  console.log(report)
  if (process.env.GITHUB_STEP_SUMMARY) appendFileSync(process.env.GITHUB_STEP_SUMMARY, `${report}\n`)
  const jsonAt = process.argv.indexOf('--json')
  if (jsonAt > 0) {
    const ids = [...required.rf, ...required.rn, ...required.ca, ...required.rnf]
    writeFileSync(
      process.argv[jsonAt + 1],
      JSON.stringify({ gaps, tests: Object.fromEntries(ids.map((id) => [id, [...(cited.get(id) ?? [])].sort(byText)])) }, null, 2),
    )
  }

  const annexAt = process.argv.indexOf('--anexo')
  if (annexAt > 0) writeFileSync(process.argv[annexAt + 1], `${annex(required, cited, declared)}\n`)

  const total = gaps.rf.length + gaps.rn.length + gaps.ca.length + gaps.rnf.length + strayDeclarations.length
  process.exitCode = total > 0 ? 1 : 0
}

if (process.argv[1] && fileURLToPath(import.meta.url) === resolve(process.argv[1])) main()
