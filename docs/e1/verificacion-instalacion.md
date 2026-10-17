# Verificación de la guía de instalación (RNF-141)

TASK-096, criterio 1.

**Criterio de RNF-141:** un desarrollador nuevo levanta el entorno completo en 60 minutos o menos, siguiendo `INSTALACION.md`. La prueba la hace un integrante que no configuró el entorno antes.

## 1. Corrida automatizada en un clon limpio (2026-10-17)

**Qué se ejecutó:** las secciones 2 a 7 de la guía, al pie de la letra, sobre:
- un clon nuevo desde GitHub (rama `task/096-documentacion-e1`);
- volúmenes de Docker nuevos (proyecto de Compose `dclimpio`).

**Lo que no fue de cero en esta PC:**
- los programas de la sección 1 ya estaban instalados;
- las imágenes de Docker y las cachés de Composer y npm ya existían.

Por eso esta corrida no reemplaza la verificación de la sección 2.

**PC y discos:**
- Windows 10 Home con PHP 8.3 (winget), Node 22 y Docker Desktop;
- el repositorio, en un disco duro mecánico (WDC WD10SPZX, unidad E:);
- el sistema, en un SSD NVMe (unidad C:).

| Paso de la guía | Tiempo |
| :-- | --: |
| 2. Clonar | 6 s |
| 3. Servicios sanos (`--wait`, sin esperar a ClamAV) | 36 s |
| 4. API: `composer install`, migraciones y semilla | 12 min 0 s |
| 4. Modelo de riesgo 1.0.0 (entrenamiento, subida y activación) | 20 s |
| 5. SPA: `npm ci` en el disco duro | 1 h 49 min 52 s |
| 6. Procesos arrancados y sanos | 22 s |
| 7. Comprobación (`/up` 200, login 200, token de recepción) | 2 s |
| **Total en el disco duro** | **2 h 3 min 18 s** |

**Resultado:** la guía funciona sin pasos faltantes. En el disco duro no cumple los 60 minutos.

El mismo `npm ci`, repetido en el SSD (C:) con los mismos `package.json` y `package-lock.json`, tardó **2 min 5 s** (52 veces menos). Por eso la guía pide clonar en un SSD y explica cómo excluir la carpeta del antivirus de Windows.

En un SSD, sumando los pasos anteriores con `npm ci` medido en el SSD, el total queda en unos 15 minutos con las cachés llenas. A eso se suma la descarga de las imágenes (~3 GB) y de los programas en una máquina realmente limpia.

## 2. Verificación por otro integrante (pendiente)

| Integrante | Fecha | Sistema y disco | Tiempo total | Paso más lento | Problemas encontrados |
| :-- | :-- | :-- | --: | :-- | :-- |
| | | | | | |

TASK-096 se cierra con esta fila completa. El resultado va al informe de aceptación de E1 (`docs/e1/TCR-E1.md`).
