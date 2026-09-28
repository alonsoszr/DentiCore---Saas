// Presupuesto del JS inicial (SDD §1.10 y §6.4, RNF-028): ≤ 300 KB comprimido.
// Suma los scripts que index.html carga de entrada (incluidos los modulepreload).
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { gzipSync } from 'node:zlib'

const LIMIT_BYTES = 300 * 1024
const dist = new URL('../dist/', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1')
const html = readFileSync(join(dist, 'index.html'), 'utf8')
const entries = [...html.matchAll(/(?:src|href)="\/?(assets\/[^"]+\.js)"/g)].map((match) => match[1])

let total = 0
for (const file of new Set(entries)) {
  const size = gzipSync(readFileSync(join(dist, file))).length
  total += size
  console.log(`${file}: ${(size / 1024).toFixed(1)} KB gzip`)
}
console.log(`JS inicial: ${(total / 1024).toFixed(1)} KB gzip (límite ${LIMIT_BYTES / 1024} KB)`)

if (entries.length === 0 || total > LIMIT_BYTES) {
  console.error(entries.length === 0 ? 'No se encontró JS de entrada en dist/index.html' : 'Se supera el presupuesto')
  process.exit(1)
}
