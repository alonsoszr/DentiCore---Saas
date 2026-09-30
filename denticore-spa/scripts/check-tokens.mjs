// Verifica que la UI use solo los tokens de docs/DESIGN.md, definidos una vez en src/index.css.
// Uso: node scripts/check-tokens.mjs [archivos...]   (sin argumentos revisa todo src/)
// Lo ejecutan `npm run check:tokens` y el hook de Claude Code (scripts/claude-check-ui.mjs).
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { extname, join, relative, resolve, sep } from 'node:path'
import { fileURLToPath } from 'node:url'

const ROOT = resolve(fileURLToPath(new URL('..', import.meta.url)))
const SRC = join(ROOT, 'src')
const TOKENS_FILE = join(SRC, 'index.css')
const EXTENSIONS = new Set(['.js', '.jsx', '.css'])

const RULES = [
  { pattern: /(?<![&\w])#[0-9a-fA-F]{3,8}\b/g, message: 'color hexadecimal fuera de los tokens' },
  { pattern: /\b(?:rgba?|hsla?|oklch|oklab)\(/g, message: 'color literal fuera de los tokens' },
  {
    pattern: /[\w-]+-\[(?:#|rgb|hsl|oklch|color:)[^\]]*\]/g,
    message: 'valor arbitrario de color en Tailwind; usa una clase del tema',
  },
  { pattern: /\bfont-\[[^\]]*\]/g, message: 'fuente arbitraria en Tailwind; usa la fuente del tema' },
  { pattern: /\bfontFamily\s*:/g, message: 'fontFamily en línea; usa la fuente del tema' },
]

function isChecked(file) {
  const rel = relative(SRC, file)
  if (rel.startsWith('..') || file === TOKENS_FILE) return false
  if (!EXTENSIONS.has(extname(file))) return false
  if (/\.test\.[jt]sx?$/.test(file) || rel.split(sep)[0] === 'test') return false
  return true
}

function listFiles(dir) {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name)
    return statSync(path).isDirectory() ? listFiles(path) : [path]
  })
}

export function checkFiles(paths = listFiles(SRC)) {
  const violations = []
  for (const path of paths.map((p) => resolve(p)).filter(isChecked)) {
    readFileSync(path, 'utf8')
      .split(/\r?\n/)
      .forEach((line, index) => {
        for (const { pattern, message } of RULES) {
          for (const match of line.matchAll(pattern)) {
            violations.push({ file: relative(ROOT, path), line: index + 1, text: match[0], message })
          }
        }
      })
  }
  return violations
}

export function formatViolations(violations) {
  return violations.map((v) => `${v.file}:${v.line}  "${v.text}"  ${v.message}`).join('\n')
}

const isMain = process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)
if (isMain) {
  const violations = checkFiles(process.argv.length > 2 ? process.argv.slice(2) : undefined)
  if (violations.length > 0) {
    console.error(formatViolations(violations))
    console.error(
      `\n${violations.length} infracción(es). Usa solo los tokens de docs/DESIGN.md definidos en src/index.css.`,
    )
    process.exit(1)
  }
  console.log('check:tokens OK: sin colores ni fuentes fuera de los tokens.')
}
