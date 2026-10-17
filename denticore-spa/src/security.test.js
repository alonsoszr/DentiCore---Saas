import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

/** Archivos de código de la SPA, sin las pruebas. */
function sourceFiles(dir) {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name)
    if (statSync(path).isDirectory()) return sourceFiles(path)
    return /\.(jsx?|tsx?)$/.test(name) && !/\.test\./.test(name) ? [path] : []
  })
}

// RNF-104 (SDD §1.7): la SPA no inserta HTML sin sanear; React escapa todo el texto que pinta.
describe('SPA security (RNF-104)', () => {
  it('never injects raw HTML into the page', () => {
    const offenders = sourceFiles(join(process.cwd(), 'src')).filter((file) =>
      /dangerouslySetInnerHTML|\.innerHTML\s*=|\.outerHTML\s*=|insertAdjacentHTML|document\.write/.test(
        readFileSync(file, 'utf8'),
      ),
    )

    expect(offenders).toEqual([])
  })
})
