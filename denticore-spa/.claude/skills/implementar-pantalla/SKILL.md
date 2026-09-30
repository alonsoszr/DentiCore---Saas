---
name: implementar-pantalla
description: Implementa o renueva una pantalla de DentiCore a partir de su ficha aprobada en docs/design/screens/. Úsala cuando el usuario pida implementar, rediseñar o renovar una pantalla que tiene ficha.
disable-model-invocation: true
argument-hint: '<ficha> [ruta del componente actual]  (p. ej. auth-login src/pages/LoginPage.jsx)'
---

# Implementar o renovar pantalla: $ARGUMENTS

Sigue los pasos en orden. No saltes pasos ni escribas código antes del paso 4.

## 1. Leer la especificación (sin escribir código)

- `../docs/design/screens/$0.md`: la ficha. **Manda sobre todo lo demás de diseño.** Si no existe, detente y avisa: primero hay que diseñar la pantalla y escribir su ficha.
- `../docs/design/screens/README.md`: componentes compartidos y pendientes (PEND-xx).
- `../docs/DESIGN.md`: tokens, componentes y reglas visuales.
- Las capturas `../docs/design/screens/$0*.png` y `$0*.jpg`: míralas todas.
- `../docs/design/screens/$0.html`: solo layout y espaciado. Ignora lo que indica `CLAUDE.md` (barra de variantes, `setVariant`, envíos simulados, Tailwind por CDN, datos escritos a mano).
- Las secciones del SDD y del SRS que cita la ficha. Búscalas con grep por endpoint o ID (`CUS-06`, `RF-033`) en `../docs/SDD_DentiCore_v2.md` y `../docs/SRS_DentiCore.md`.

## 2. Determinar el modo

- Si se indicó un componente (`$1`) o ya existe uno para la ruta de la ficha (búscalo en `src/App.jsx`, `src/areas/` y `src/pages/`), el modo es **RENOVACIÓN**.
- Si no existe, el modo es **NUEVA**.

En modo **RENOVACIÓN**, lee además, sin modificar nada:

- El componente actual, sus pruebas (`*.test.jsx`) y los componentes y hooks que usa.
- Quién lo importa (grep por su nombre) y con qué props.
- Las clases CSS que usa y dónde están definidas en `src/index.css`.
- Ejecuta sus pruebas actuales (`npm test -- <archivo de prueba>`) y anota el resultado: es la línea base.

## 3. Plan (espera aprobación)

Presenta un plan corto con:

1. Modo (NUEVA o RENOVACIÓN) y componentes que vas a crear o reutilizar.
2. Tabla de estados de la ficha: estado → qué lo dispara (endpoint y código HTTP) → qué se muestra.
3. Textos exactos que usarás, tomados de la ficha o del SRS.
4. Tokens de `DESIGN.md` que faltan en `src/index.css`, si los hay.
5. Pruebas que vas a escribir o modificar.
6. Todo lo marcado **[PENDIENTE]** y cualquier contradicción entre ficha, SDD, HTML y código actual.

Solo en modo **RENOVACIÓN**, agrega una tabla **Diferencias con la versión actual**:

| Elemento | Hoy | Después | Tipo | Fuente |
| :------- | :-- | :------ | :--- | :----- |

- **Tipo** es uno de: `visual` (solo presentación), `texto`, `comportamiento` (nuevas llamadas a la API, estados, rutas o redirecciones) o `eliminación`.
- Todo lo que hoy existe y la ficha no menciona va como `eliminación` con la pregunta "¿Se elimina o se conserva?". Nunca lo elimines sin respuesta.
- Los cambios de `comportamiento` que requieren rutas, páginas o endpoints que aún no existen se listan aparte como "fuera de alcance de esta renovación" y se proponen como tareas separadas.

Termina con "¿Apruebas el plan?" y espera la respuesta.

## 4. Implementar

- Colores, fuentes, radios y sombras solo desde los tokens de `src/index.css` (`@theme`). Si falta un token, agrégalo copiando el valor exacto de `DESIGN.md`; nunca inventes uno.
- Primero los componentes compartidos, después la página.
- Consulta la skill `ui-ux-pro-max` para lo que la ficha no resuelve: `--domain ux` (formularios, errores, foco, accesibilidad) y `--stack react`. Nunca `--design-system` ni `--persist`.
- Para APIs de Tailwind v4, React Router 7 y TanStack Query, usa la documentación actual (Context7 si está disponible). No uses sintaxis de versiones anteriores, como `tailwind.config.js`.
- Textos solo de la ficha o del SRS. Si falta uno, usa el más breve y neutro y anótalo como supuesto.

Además, en modo **RENOVACIÓN**:

- Conserva el nombre, la exportación y las props del componente, para no romper a quien lo importa, salvo que el plan aprobado diga otra cosa.
- Conserva la lógica que funciona: llamadas a la API, `useAuth`, guardias, redirecciones y manejo de errores (`src/api/errors.js`). Cambia la presentación alrededor de ella.
- Implementa solo lo aprobado en la tabla de diferencias.
- Borra clases viejas de `src/index.css` solo cuando grep confirme que ya nadie las usa.

## 5. Pruebas

- Vitest + Testing Library: al menos una prueba por estado de la ficha.
- En modo **RENOVACIÓN**, las pruebas existentes son la red de seguridad:
  - Las que verifican comportamiento (llamadas, parámetros, redirecciones) deben seguir pasando sin cambios.
  - Solo puedes modificar las aserciones de textos o estructura que el plan aprobado cambió. Lista cada prueba modificada y por qué.
  - Nunca borres una prueba para que la suite pase.
- E2E en `e2e/` para el flujo principal, con axe-core y 0 violaciones A/AA.
- Ejecuta y deja en verde: `npm run check:tokens`, `npm run lint`, `npm run format:check`, `npm test`.

## 6. Verificación visual

Si tienes Playwright MCP: levanta `npm run dev`, captura cada estado reproducible a 1440×900 y a 768×1024, y compáralo con las capturas de referencia. Lista cada diferencia de layout, espaciado, color o texto.

## 7. Revisión independiente

Delega en el subagente `ui-reviewer` indicándole la ficha `$0` y, en renovación, el componente. Corrige lo que marque como bloqueante o importante y vuelve a ejecutar el paso 5.

## 8. Entrega

Resume en este orden:

1. Archivos creados y modificados.
2. Estados de la ficha cubiertos y cómo se probó cada uno.
3. En renovación: qué se conservó, qué cambió, qué se eliminó (con aprobación) y qué pruebas se modificaron.
4. Resultado de cada comando del paso 5.
5. Diferencias visuales que quedaron y por qué.
6. Supuestos, pendientes (PEND-xx) y tareas fuera de alcance propuestas.

No hagas commit salvo que te lo pidan.
