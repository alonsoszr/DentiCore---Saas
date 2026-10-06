// Disposición del anexo gráfico de la NTS N° 188-MINSA/DGIESP-2022 (página 22) y anatomía
// mínima para dibujar cada pieza. Solo es presentación: la validación de piezas y
// superficies la hace la API (ClinicalValidator, RN-16 y RN-18).

const quadrantTeeth = (quadrant, count, descending) => {
  const positions = Array.from({ length: count }, (_, index) => index + 1)
  return (descending ? positions.reverse() : positions).map((position) => quadrant * 10 + position)
}

/** Filas del anexo, de arriba hacia abajo (lista de verificación C1 a C4). */
export const ROWS = [
  {
    id: 'upper-permanent',
    upper: true,
    dentition: 'permanente',
    teeth: [...quadrantTeeth(1, 8, true), ...quadrantTeeth(2, 8, false)],
  },
  {
    id: 'upper-temporal',
    upper: true,
    dentition: 'temporal',
    teeth: [...quadrantTeeth(5, 5, true), ...quadrantTeeth(6, 5, false)],
  },
  {
    id: 'lower-temporal',
    upper: false,
    dentition: 'temporal',
    teeth: [...quadrantTeeth(8, 5, true), ...quadrantTeeth(7, 5, false)],
  },
  {
    id: 'lower-permanent',
    upper: false,
    dentition: 'permanente',
    teeth: [...quadrantTeeth(4, 8, true), ...quadrantTeeth(3, 8, false)],
  },
]

export const ALL_TEETH = ROWS.flatMap((row) => row.teeth)

/** Filas visibles según la dentición elegida (DD-25, RF-092). */
export function rowsFor(dentition) {
  return dentition === 'mixta' ? ROWS : ROWS.filter((row) => row.dentition === dentition)
}

const quadrant = (tooth) => Math.floor(tooth / 10)
const position = (tooth) => tooth % 10

/** Arco superior: cuadrantes 1, 2, 5 y 6. */
export function isUpper(tooth) {
  return [1, 2, 5, 6].includes(quadrant(tooth))
}

/** Incisivos y caninos. */
export function isAnterior(tooth) {
  return position(tooth) <= 3
}

/** El lado mesial mira a la línea media: a la derecha en los cuadrantes 1, 4, 5 y 8. */
export function mesialIsRight(tooth) {
  return [1, 4, 5, 8].includes(quadrant(tooth))
}

/** Raíces que dibuja el anexo (lista de verificación C6). */
export function rootCount(tooth) {
  if (isAnterior(tooth)) return 1
  const temporal = quadrant(tooth) >= 5
  if (isUpper(tooth)) {
    if (temporal || position(tooth) >= 6) return 3
    return position(tooth) === 4 ? 2 : 1
  }
  return temporal || position(tooth) >= 6 ? 2 : 1
}

/**
 * Coordenadas verticales de la pieza. Superior: recuadro, número, raíces, corona y, abajo, el
 * espacio oclusal para las flechas. Inferior: el orden inverso (anexo, página 22).
 */
export function toothGeometry(tooth) {
  if (isUpper(tooth)) {
    return { upper: true, box: 0, number: 34, apex: 48, edge: 78, crownTop: 78, crownBottom: 114, occlusal: 114 }
  }
  return { upper: false, box: 112, number: 108, apex: 86, edge: 56, crownTop: 20, crownBottom: 56, occlusal: 20 }
}

export const SURFACE_NAMES = {
  M: 'mesial',
  D: 'distal',
  O: 'oclusal',
  I: 'incisal',
  V: 'vestibular',
  L: 'lingual',
  P: 'palatina',
}

/** Superficies de la pieza según RN-18: vestibular, palatina o lingual, mesial, distal y oclusal o incisal. */
export function surfacesFor(tooth) {
  return ['V', isUpper(tooth) ? 'P' : 'L', 'M', 'D', isAnterior(tooth) ? 'I' : 'O']
}

/** Clases de Tailwind del color oficial del hallazgo (§5.13; solo azul y rojo). */
export const FINDING_COLORS = {
  azul: { fill: 'fill-odontogram-blue', stroke: 'stroke-odontogram-blue', text: 'text-odontogram-blue' },
  rojo: { fill: 'fill-odontogram-red', stroke: 'stroke-odontogram-red', text: 'text-odontogram-red' },
}

/** Sigla de la entrada: la del estado o, si no depende del estado, la del hallazgo. */
export function acronymOf(entry) {
  return entry.state?.acronym ?? entry.finding?.acronym ?? null
}

/** Descripción accesible de una entrada: hallazgo, estado y superficies. */
export function describeEntry(entry) {
  const state = entry.state ? `, ${entry.state.name.toLowerCase()}` : ''
  const surfaces = entry.surfaces?.length ? ` en ${entry.surfaces.map((s) => SURFACE_NAMES[s]).join(', ')}` : ''
  return `${entry.finding?.name ?? 'Hallazgo anulado'}${state}${surfaces}`
}
