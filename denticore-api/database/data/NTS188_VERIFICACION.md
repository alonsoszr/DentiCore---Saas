# Verificación del catálogo y del anexo gráfico NTS 188

**Estado:** Pendiente de firma del validador clínico (PQ-05).

- **Fuente:** NTS N° 188-MINSA/DGIESP-2022, «Norma Técnica de Salud para el uso del odontograma», aprobada por RM N° 559-2022/MINSA. Las secciones citadas son las de la norma.
- **Semilla:** `database/data/nts188_findings.php`, versión 1.0 del catálogo. La carga la migración `2026_10_06_000500_seed_nts188_finding_catalog`.
- **Alcance de la firma:** sin ella no se cierran RNF-004, RNF-160 ni RNF-175, y no se acepta E2 (SDD PQ-05). E1 no queda bloqueada.

## A. Catálogo de hallazgos (§6.1)

Colores según §5.12 y §5.13: azul para buen estado o característica no patológica; rojo para mal estado, temporal o patológico. «BUENO/MALO» indica que el hallazgo se registra en azul (buen estado) o en rojo (mal estado). «—» indica que la norma no define sigla.

| § | Hallazgo | Código | Nivel | Dentición | Estados: color y sigla | Representación gráfica (TASK-051) | Revisado |
| :-- | :-- | :-- | :-- | :-- | :-- | :-- | :-: |
| 6.1.1 | Aparato ortodóntico fijo | `ORTODONCIA_FIJA` | tramo | ambas | BUENO/MALO; — | Cuadrados con una cruz a nivel de los ápices de las piezas extremas, unidos por una línea recta. | ☐ |
| 6.1.2 | Aparato ortodóntico removible | `ORTODONCIA_REMOVIBLE` | tramo | ambas | BUENO/MALO; — | Línea en zigzag a la altura de los ápices del maxilar en tratamiento. | ☐ |
| 6.1.3 | Corona | `CORONA` | pieza | ambas | CM, CF, CMC, CV y CLM, cada una BUENO/MALO | Cuadrado que bordea la corona clínica; la sigla del tipo va en el recuadro. El color del metal va en especificaciones. | ☐ |
| 6.1.4 | Corona temporal | `CORONA_TEMPORAL` | pieza | ambas | rojo; CT | Cuadrado rojo que encierra la corona; CT en el recuadro. | ☐ |
| 6.1.5 | Defectos de desarrollo del esmalte (DDE) | `DDE` | superficie | ambas | O y PE en rojo | Sigla en el recuadro. La fluorosis va en especificaciones, con la clasificación utilizada. | ☐ |
| 6.1.6 | Diastema | `DIASTEMA` | tramo | ambas | azul; — | Paréntesis invertido entre las dos piezas. | ☐ |
| 6.1.7 | Edéntulo total superior / inferior | `EDENTULO_TOTAL` | tramo | ambas | azul; — | Línea recta horizontal sobre las coronas de las piezas ausentes del maxilar. | ☐ |
| 6.1.8 | Espigo-muñón | `ESPIGO_MUNON` | pieza | ambas | BUENO/MALO; — | Línea vertical en la raíz unida a un cuadrado en la corona. | ☐ |
| 6.1.9 | Fosas y fisuras profundas | `FOSAS_FISURAS_PROF` | pieza | ambas | azul; FFP | Sigla en el recuadro. | ☐ |
| 6.1.10 | Fractura dental | `FRACTURA` | pieza | ambas | rojo; — | Línea sobre la corona y/o la raíz. | ☐ |
| 6.1.11 | Fusión | `FUSION` | tramo | ambas | azul; — | Dos circunferencias interceptadas sobre los números de las piezas. | ☐ |
| 6.1.12 | Geminación | `GEMINACION` | pieza | ambas | azul; — | Circunferencia sobre el número de la pieza. | ☐ |
| 6.1.13 | Giroversión | `GIROVERSION` | pieza | ambas | azul; — | Flecha curva en el sentido de la giroversión, a nivel de la zona oclusal. | ☐ |
| 6.1.14 | Impactación | `IMPACTACION` | pieza | ambas | azul; I | Sigla en el recuadro. | ☐ |
| 6.1.15 | Implante dental | `IMPLANTE` | pieza | ambas | BUENO/MALO; IMP | Sigla en el recuadro de cada pieza reemplazada. | ☐ |
| 6.1.16 | Lesión de caries dental | `CARIES` | superficie | ambas | MB, CE, CD y CDP en rojo | Lesión pintada en rojo según su forma; sigla en el recuadro. | ☐ |
| 6.1.17 | Macrodoncia | `MACRODONCIA` | pieza | ambas | azul; MAC | Sigla en el recuadro. | ☐ |
| 6.1.18 | Microdoncia | `MICRODONCIA` | pieza | ambas | azul; MIC | Sigla en el recuadro. | ☐ |
| 6.1.19 | Movilidad patológica | `MOVILIDAD` | pieza | ambas | M1 y M2 en rojo | «M» seguida del grado, en el recuadro. | ☐ |
| 6.1.20 | Pieza dentaria ausente | `AUSENTE` | pieza | ambas | DNE, DEX y DAO en azul | Aspa sobre la pieza y sigla en el recuadro. | ☐ |
| 6.1.21 | Pieza dentaria en clavija | `CLAVIJA` | pieza | ambas | azul; — | Triángulo por encima o por debajo de las raíces. | ☐ |
| 6.1.22 | Pieza dentaria ectópica | `ECTOPICA` | pieza | ambas | azul; E | Sigla en el recuadro. | ☐ |
| 6.1.23 | Pieza dentaria en erupción | `EN_ERUPCION` | pieza | ambas | azul; — | Flecha en zigzag sobre la pieza, dirigida al plano oclusal. | ☐ |
| 6.1.24 | Pieza dentaria extruida | `EXTRUIDA` | pieza | ambas | azul; — | Flecha recta vertical fuera de la pieza, en sentido externo. | ☐ |
| 6.1.25 | Pieza dentaria intruida | `INTRUIDA` | pieza | ambas | azul; — | Flecha recta vertical fuera de la pieza, hacia la zona incisal u oclusal. | ☐ |
| 6.1.26 | Pieza dentaria supernumeraria | `SUPERNUMERARIA` | tramo | ambas | azul; S | «S» encerrada en una circunferencia entre los ápices de las piezas adyacentes. | ☐ |
| 6.1.27 | Pulpotomía | `PULPOTOMIA` | pieza | temporal | BUENO/MALO; PP | Pulpa coronal pintada; PP en el recuadro. | ☐ |
| 6.1.28 | Posición anormal dentaria | `POSICION_ANORMAL` | pieza | ambas | M, D, V, P y L en azul | Sigla en el recuadro. | ☐ |
| 6.1.29 | Prótesis dental parcial fija | `PROTESIS_FIJA` | tramo | ambas | BUENO/MALO; — | Línea horizontal con líneas verticales sobre los pilares, a nivel de los ápices. | ☐ |
| 6.1.30 | Prótesis dental completa superior / inferior | `PROTESIS_COMPLETA` | tramo | ambas | BUENO/MALO; — | Dos líneas paralelas horizontales a nivel de los ápices del maxilar. | ☐ |
| 6.1.31 | Prótesis dental parcial removible | `PROTESIS_REMOVIBLE` | tramo | ambas | BUENO/MALO; — | Dos líneas paralelas horizontales a nivel de los ápices de las piezas reemplazadas. | ☐ |
| 6.1.32 | Remanente radicular | `REMANENTE_RADICULAR` | pieza | ambas | rojo; RR | Sigla en el recuadro. | ☐ |
| 6.1.33 | Restauración definitiva | `RESTAURACION` | superficie | ambas | AM, R, IV, IM, IE y C, cada una BUENO/MALO | Restauración pintada según su forma; sigla del material en el recuadro. | ☐ |
| 6.1.34 | Restauración temporal | `RESTAURACION_TEMP` | superficie | ambas | rojo; — | Contorno de la restauración en las superficies comprometidas. | ☐ |
| 6.1.35 | Sellantes | `SELLANTE` | superficie | ambas | BUENO/MALO; S | Recorrido del sellante según las fosas y fisuras; S en el recuadro. | ☐ |
| 6.1.36 | Superficie desgastada | `DESGASTE` | superficie | ambas | rojo; DES | Desgaste dibujado donde se observa; DES en el recuadro. | ☐ |
| 6.1.37 | Tratamiento de conducto | `TRATAMIENTO_CONDUCTO` | pieza | ambas | TC y PC, cada una BUENO/MALO | Línea recta vertical en la raíz; sigla en el recuadro. | ☐ |
| 6.1.38 | Transposición dentaria | `TRANSPOSICION` | tramo | ambas | azul; — | Dos flechas curvas entrecruzadas a la altura de los números de las piezas. | ☐ |

## B. Interpretaciones que el validador debe confirmar

La norma no tiene columnas de nivel ni de dentición. Se derivaron de su gráfico y de sus definiciones (§5.1).

1. **Hallazgos sin sigla:** cuando la norma no define una sigla, se representan solo con su símbolo gráfico. No se crearon siglas propias porque RNF-151 prohíbe la simbología no oficial y RNF-061 admite texto o ícono además de la sigla. Esto difiere del criterio 1 de TASK-043 en el Plan, que pedía sigla no nula en todos los hallazgos; prima el SRS.
2. **Nivel `tramo`:** se usa en los hallazgos que la norma dibuja entre dos o más piezas del mismo arco: aparatos ortodónticos, diastema, edéntulo total, fusión, supernumeraria, prótesis y transposición. El edéntulo total y la prótesis completa se registran como un tramo de 18 a 28, o de 48 a 38.
3. **Nivel `superficie`:** se usa en DDE, caries, restauración definitiva y temporal, sellantes y superficie desgastada, que la norma dibuja en las superficies comprometidas.
4. **Dentición `temporal` de la pulpotomía:** sale de §5.1 (29). La pulpectomía (PC, §5.1 (28)) también es de dentición decidua, pero es un estado dentro de «Tratamiento de conducto», que aplica a ambas denticiones. El validador decide si la pulpectomía debe ser un hallazgo aparte.
5. **Grados de movilidad:** la norma fija «M» seguida del grado, sin enumerar los grados. Se sembraron M1 y M2, los que muestra su ejemplo. Agregar más grados requiere publicar una versión nueva del catálogo.
6. **Fluorosis (§6.1.5):** no es un estado; se anota en especificaciones.
7. **Posición anormal P y L:** la norma no restringe la palatinización (P) a las piezas superiores ni la lingualización (L) a las inferiores, y el validador clínico no lo hace.
8. **Hallazgos sin estado:** cuando la norma usa un solo color, el estado se llama «Presente».
9. **Numeración:** la norma numera «5.2.23» la pieza en erupción por error tipográfico; aquí figura como 6.1.23.

## C. Anexo gráfico (página 22 de la norma)

Puntos que TASK-051 debe cumplir; se revisan contra el componente SVG.

| # | Elemento del anexo | Revisado |
| :-- | :-- | :-: |
| C1 | Fila superior permanente: 18 a 11 y 21 a 28, con los recuadros de siglas encima de los números. | ☐ |
| C2 | Fila superior temporal: 55 a 51 y 61 a 65, con recuadros encima. | ☐ |
| C3 | Fila inferior temporal: 85 a 81 y 71 a 75, con recuadros debajo. | ☐ |
| C4 | Fila inferior permanente: 48 a 41 y 31 a 38, con recuadros debajo. | ☐ |
| C5 | Cada corona es un cuadrado dividido en cinco superficies: cuatro trapecios y una superficie central. | ☐ |
| C6 | Las raíces siguen el anexo: una en incisivos y caninos; en premolares y molares, el número que dibuja el anexo. | ☐ |
| C7 | El encabezado «ODONTOGRAMA» y la fecha; abajo, los bloques «Especificaciones» y «Observaciones» (§5.14, §5.15). | ☐ |
| C8 | Solo se usan los colores azul `#1D4ED8` y rojo `#DC2626`, siempre con sigla o símbolo (§5.13, RNF-061, RNF-151). | ☐ |
| C9 | Cuando una pieza tiene varios hallazgos, todos van en su recuadro (§5.16). | ☐ |

## D. Firma

| Validador (cirujano dentista) | COP | Fecha | Resultado |
| :-- | :-- | :-- | :-- |
| | | | Pendiente |
