<?php

/*
 * Catálogo de hallazgos clínicos del odontograma (TASK-043b; SDD §2.6 `finding_catalog`,
 * `finding_states`; RN-17, RF-078). Transcrito de §6.1 «Disposiciones específicas» de la
 * NTS N° 188-MINSA/DGIESP-2022, aprobada por RM N° 559-2022/MINSA (docs/, 24 páginas).
 *
 * - `acronym` del hallazgo: la sigla que la norma fija para todo el hallazgo. Si la sigla
 *   depende del tipo o del material, va en cada estado. Si la norma no define sigla, queda
 *   nula: el hallazgo se representa con su símbolo gráfico (RNF-151 prohíbe simbología no
 *   oficial; RNF-061 admite texto o ícono además de la sigla).
 * - `level` y `dentition` no son columnas de la norma: se derivan de su representación
 *   gráfica y de sus definiciones (§5.1). Cada derivación figura en
 *   `NTS188_VERIFICACION.md` y espera la firma del validador clínico (PQ-05).
 * - Estados: el color lo fija la norma (azul = buen estado o característica no patológica;
 *   rojo = mal estado, temporal o patológico, §5.12–§5.13).
 */

$conditions = fn (?string $acronym = null, string $prefix = '', ?string $name = null) => [
    ['code' => $prefix.'BUENO', 'name' => $name === null ? 'Buen estado' : "{$name} en buen estado", 'color' => 'azul', 'acronym' => $acronym],
    ['code' => $prefix.'MALO', 'name' => $name === null ? 'Mal estado' : "{$name} en mal estado", 'color' => 'rojo', 'acronym' => $acronym],
];
$present = fn (string $color) => [['code' => 'PRESENTE', 'name' => 'Presente', 'color' => $color, 'acronym' => null]];
$byType = fn (array $types) => array_merge(...array_map(
    fn (string $acronym, string $name) => $conditions($acronym, "{$acronym}_", $name),
    array_keys($types),
    $types,
));

return [
    'source' => 'NTS N° 188-MINSA/DGIESP-2022, Norma Técnica de Salud para el uso del odontograma (RM N° 559-2022/MINSA), §6.1',
    'version' => '1.0',
    'review' => 'pendiente de firma del validador clínico (PQ-05)',
    'findings' => [
        ['section' => '6.1.1', 'code' => 'ORTODONCIA_FIJA', 'name' => 'Aparato ortodóntico fijo', 'acronym' => null, 'level' => 'tramo', 'dentition' => 'ambas', 'states' => $conditions()],
        ['section' => '6.1.2', 'code' => 'ORTODONCIA_REMOVIBLE', 'name' => 'Aparato ortodóntico removible', 'acronym' => null, 'level' => 'tramo', 'dentition' => 'ambas', 'states' => $conditions()],
        ['section' => '6.1.3', 'code' => 'CORONA', 'name' => 'Corona', 'acronym' => null, 'level' => 'pieza', 'dentition' => 'ambas', 'states' => $byType([
            'CM' => 'Corona metálica',
            'CF' => 'Corona fenestrada',
            'CMC' => 'Corona metal cerámica',
            'CV' => 'Corona Veneer',
            'CLM' => 'Corona libre de metal',
        ])],
        ['section' => '6.1.4', 'code' => 'CORONA_TEMPORAL', 'name' => 'Corona temporal', 'acronym' => 'CT', 'level' => 'pieza', 'dentition' => 'ambas', 'states' => $present('rojo')],
        ['section' => '6.1.5', 'code' => 'DDE', 'name' => 'Defectos de desarrollo del esmalte (DDE)', 'acronym' => null, 'level' => 'superficie', 'dentition' => 'ambas', 'states' => [
            ['code' => 'O', 'name' => 'Opacidades del esmalte', 'color' => 'rojo', 'acronym' => 'O'],
            ['code' => 'PE', 'name' => 'Pigmentación del esmalte', 'color' => 'rojo', 'acronym' => 'PE'],
        ]],
        ['section' => '6.1.6', 'code' => 'DIASTEMA', 'name' => 'Diastema', 'acronym' => null, 'level' => 'tramo', 'dentition' => 'ambas', 'states' => $present('azul')],
        ['section' => '6.1.7', 'code' => 'EDENTULO_TOTAL', 'name' => 'Edéntulo total superior / inferior', 'acronym' => null, 'level' => 'tramo', 'dentition' => 'ambas', 'states' => $present('azul')],
        ['section' => '6.1.8', 'code' => 'ESPIGO_MUNON', 'name' => 'Espigo-muñón', 'acronym' => null, 'level' => 'pieza', 'dentition' => 'ambas', 'states' => $conditions()],
        ['section' => '6.1.9', 'code' => 'FOSAS_FISURAS_PROF', 'name' => 'Fosas y fisuras profundas', 'acronym' => 'FFP', 'level' => 'pieza', 'dentition' => 'ambas', 'states' => $present('azul')],
        ['section' => '6.1.10', 'code' => 'FRACTURA', 'name' => 'Fractura dental', 'acronym' => null, 'level' => 'pieza', 'dentition' => 'ambas', 'states' => $present('rojo')],
        ['section' => '6.1.11', 'code' => 'FUSION', 'name' => 'Fusión', 'acronym' => null, 'level' => 'tramo', 'dentition' => 'ambas', 'states' => $present('azul')],
        ['section' => '6.1.12', 'code' => 'GEMINACION', 'name' => 'Geminación', 'acronym' => null, 'level' => 'pieza', 'dentition' => 'ambas', 'states' => $present('azul')],
        ['section' => '6.1.13', 'code' => 'GIROVERSION', 'name' => 'Giroversión', 'acronym' => null, 'level' => 'pieza', 'dentition' => 'ambas', 'states' => $present('azul')],
        ['section' => '6.1.14', 'code' => 'IMPACTACION', 'name' => 'Impactación', 'acronym' => 'I', 'level' => 'pieza', 'dentition' => 'ambas', 'states' => $present('azul')],
        ['section' => '6.1.15', 'code' => 'IMPLANTE', 'name' => 'Implante dental', 'acronym' => 'IMP', 'level' => 'pieza', 'dentition' => 'ambas', 'states' => $conditions()],
        ['section' => '6.1.16', 'code' => 'CARIES', 'name' => 'Lesión de caries dental', 'acronym' => null, 'level' => 'superficie', 'dentition' => 'ambas', 'states' => [
            ['code' => 'MB', 'name' => 'Mancha blanca', 'color' => 'rojo', 'acronym' => 'MB'],
            ['code' => 'CE', 'name' => 'Lesión de caries dental a nivel del esmalte', 'color' => 'rojo', 'acronym' => 'CE'],
            ['code' => 'CD', 'name' => 'Lesión de caries dental a nivel de la dentina', 'color' => 'rojo', 'acronym' => 'CD'],
            ['code' => 'CDP', 'name' => 'Lesión de caries dental a nivel de la dentina/compromiso de la pulpa', 'color' => 'rojo', 'acronym' => 'CDP'],
        ]],
        ['section' => '6.1.17', 'code' => 'MACRODONCIA', 'name' => 'Macrodoncia', 'acronym' => 'MAC', 'level' => 'pieza', 'dentition' => 'ambas', 'states' => $present('azul')],
        ['section' => '6.1.18', 'code' => 'MICRODONCIA', 'name' => 'Microdoncia', 'acronym' => 'MIC', 'level' => 'pieza', 'dentition' => 'ambas', 'states' => $present('azul')],
        // La norma fija «M» seguida del grado; su ejemplo muestra los grados 1 y 2.
        ['section' => '6.1.19', 'code' => 'MOVILIDAD', 'name' => 'Movilidad patológica', 'acronym' => null, 'level' => 'pieza', 'dentition' => 'ambas', 'states' => [
            ['code' => 'M1', 'name' => 'Movilidad de grado 1', 'color' => 'rojo', 'acronym' => 'M1'],
            ['code' => 'M2', 'name' => 'Movilidad de grado 2', 'color' => 'rojo', 'acronym' => 'M2'],
        ]],
        ['section' => '6.1.20', 'code' => 'AUSENTE', 'name' => 'Pieza dentaria ausente', 'acronym' => null, 'level' => 'pieza', 'dentition' => 'ambas', 'states' => [
            ['code' => 'DNE', 'name' => 'Diente no erupcionado', 'color' => 'azul', 'acronym' => 'DNE'],
            ['code' => 'DEX', 'name' => 'Diente ausente por extracción debido a experiencia de lesiones de caries dental', 'color' => 'azul', 'acronym' => 'DEX'],
            ['code' => 'DAO', 'name' => 'Diente ausente por otras razones', 'color' => 'azul', 'acronym' => 'DAO'],
        ]],
        ['section' => '6.1.21', 'code' => 'CLAVIJA', 'name' => 'Pieza dentaria en clavija', 'acronym' => null, 'level' => 'pieza', 'dentition' => 'ambas', 'states' => $present('azul')],
        ['section' => '6.1.22', 'code' => 'ECTOPICA', 'name' => 'Pieza dentaria ectópica', 'acronym' => 'E', 'level' => 'pieza', 'dentition' => 'ambas', 'states' => $present('azul')],
        // La norma lo numera «5.2.23» por error tipográfico; corresponde a 6.1.23.
        ['section' => '6.1.23', 'code' => 'EN_ERUPCION', 'name' => 'Pieza dentaria en erupción', 'acronym' => null, 'level' => 'pieza', 'dentition' => 'ambas', 'states' => $present('azul')],
        ['section' => '6.1.24', 'code' => 'EXTRUIDA', 'name' => 'Pieza dentaria extruida', 'acronym' => null, 'level' => 'pieza', 'dentition' => 'ambas', 'states' => $present('azul')],
        ['section' => '6.1.25', 'code' => 'INTRUIDA', 'name' => 'Pieza dentaria intruida', 'acronym' => null, 'level' => 'pieza', 'dentition' => 'ambas', 'states' => $present('azul')],
        ['section' => '6.1.26', 'code' => 'SUPERNUMERARIA', 'name' => 'Pieza dentaria supernumeraria', 'acronym' => 'S', 'level' => 'tramo', 'dentition' => 'ambas', 'states' => $present('azul')],
        // §5.1 (29): la pulpotomía se define en la dentición decidua.
        ['section' => '6.1.27', 'code' => 'PULPOTOMIA', 'name' => 'Pulpotomía', 'acronym' => 'PP', 'level' => 'pieza', 'dentition' => 'temporal', 'states' => $conditions()],
        ['section' => '6.1.28', 'code' => 'POSICION_ANORMAL', 'name' => 'Posición anormal dentaria', 'acronym' => null, 'level' => 'pieza', 'dentition' => 'ambas', 'states' => [
            ['code' => 'M', 'name' => 'Mesializado', 'color' => 'azul', 'acronym' => 'M'],
            ['code' => 'D', 'name' => 'Distalizado', 'color' => 'azul', 'acronym' => 'D'],
            ['code' => 'V', 'name' => 'Vestibularizado', 'color' => 'azul', 'acronym' => 'V'],
            ['code' => 'P', 'name' => 'Palatinizado', 'color' => 'azul', 'acronym' => 'P'],
            ['code' => 'L', 'name' => 'Lingualizado', 'color' => 'azul', 'acronym' => 'L'],
        ]],
        ['section' => '6.1.29', 'code' => 'PROTESIS_FIJA', 'name' => 'Prótesis dental parcial fija', 'acronym' => null, 'level' => 'tramo', 'dentition' => 'ambas', 'states' => $conditions()],
        ['section' => '6.1.30', 'code' => 'PROTESIS_COMPLETA', 'name' => 'Prótesis dental completa superior / inferior', 'acronym' => null, 'level' => 'tramo', 'dentition' => 'ambas', 'states' => $conditions()],
        ['section' => '6.1.31', 'code' => 'PROTESIS_REMOVIBLE', 'name' => 'Prótesis dental parcial removible', 'acronym' => null, 'level' => 'tramo', 'dentition' => 'ambas', 'states' => $conditions()],
        ['section' => '6.1.32', 'code' => 'REMANENTE_RADICULAR', 'name' => 'Remanente radicular', 'acronym' => 'RR', 'level' => 'pieza', 'dentition' => 'ambas', 'states' => $present('rojo')],
        ['section' => '6.1.33', 'code' => 'RESTAURACION', 'name' => 'Restauración definitiva', 'acronym' => null, 'level' => 'superficie', 'dentition' => 'ambas', 'states' => $byType([
            'AM' => 'Amalgama dental',
            'R' => 'Resina',
            'IV' => 'Ionómero de vidrio',
            'IM' => 'Incrustación metálica',
            'IE' => 'Incrustación estética',
            'C' => 'Carilla',
        ])],
        ['section' => '6.1.34', 'code' => 'RESTAURACION_TEMP', 'name' => 'Restauración temporal', 'acronym' => null, 'level' => 'superficie', 'dentition' => 'ambas', 'states' => $present('rojo')],
        ['section' => '6.1.35', 'code' => 'SELLANTE', 'name' => 'Sellantes', 'acronym' => 'S', 'level' => 'superficie', 'dentition' => 'ambas', 'states' => $conditions()],
        ['section' => '6.1.36', 'code' => 'DESGASTE', 'name' => 'Superficie desgastada', 'acronym' => 'DES', 'level' => 'superficie', 'dentition' => 'ambas', 'states' => $present('rojo')],
        ['section' => '6.1.37', 'code' => 'TRATAMIENTO_CONDUCTO', 'name' => 'Tratamiento de conducto', 'acronym' => null, 'level' => 'pieza', 'dentition' => 'ambas', 'states' => $byType([
            'TC' => 'Tratamiento de conductos',
            'PC' => 'Pulpectomía',
        ])],
        ['section' => '6.1.38', 'code' => 'TRANSPOSICION', 'name' => 'Transposición dentaria', 'acronym' => null, 'level' => 'tramo', 'dentition' => 'ambas', 'states' => $present('azul')],
    ],
];
