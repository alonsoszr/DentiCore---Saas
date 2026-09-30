# denticore-spa

SPA de DentiCore: React 18, React Router 7, Axios, TanStack Query, zod, Vite y Tailwind CSS v4 (JavaScript, sin TypeScript).

## Comandos

- `npm run dev` / `npm run build` / `npm run preview`
- `npm run lint` y `npm run format:check` antes de dar una tarea por terminada.
- `npm test` (Vitest), `npm run test:coverage` (umbral 70 %), `npm run e2e` (Playwright + axe-core).
- `npm run check:size`: el JS inicial no puede superar 300 KB comprimido.
- `npm run gen:api`: regenera `src/api/schemas.js` desde el OpenAPI. No edites ese archivo a mano.

## Arquitectura (SDD §1.10)

- Cliente HTTP: `src/api/client.js`. Errores 422/409 llegan en `errors` y se muestran junto al campo.
- Sesión: token en `sessionStorage` (`dc.token`, `src/auth/token.js`). Nunca en `localStorage`.
- Guardias: `src/auth/` (`RequireAuth` → `RequireTwoFactor` → `RequireRole` → `RequireFeature`). Solo ocultan navegación; el backend decide.
- Rutas: `/login` y `/admin/*` (plataforma), `/c/:slug/login`, `/c/:slug/app/*` (personal), `/c/:slug/portal/*` (paciente).
- Formatos `es-PE` con `src/ui/format.js`: `S/ 1,234.56`, fechas `dd/MM/yyyy`, hora 24 h en la zona de la clínica.
- Toda la interfaz en español del Perú.

## Diseño

- `docs/DESIGN.md` es la ÚNICA fuente de colores, tipografía, radios, espaciado y componentes. No uses valores fuera de sus tokens.
- Los tokens se definen una sola vez en `src/index.css` con `@theme` de Tailwind v4. Las variables actuales de `:root` (`--accent: #46607a`, etc.) son de la versión anterior: se reemplazan por los tokens de `docs/DESIGN.md`, sin mantener dos paletas.
- Ningún estado se comunica solo con color: todo badge lleva texto e ícono. Foco visible en todo control.
- Las acciones irreversibles piden confirmación con sus efectos (RNF-063).
- El odontograma usa solo azul `#1D4ED8` y rojo `#DC2626` del catálogo NTS 188, siempre con sigla.

## Pantallas de referencia (`docs/design/screens/`)

- Cada pantalla tiene una ficha `<pantalla>.md` (manda), un prototipo `.html` de Stitch y capturas `.png`/`.jpg`.
- Los `.html` son SOLO referencia de layout, espaciado y estilos.
- Ignora siempre del HTML: la barra superior de variantes o "vista de prueba" (RUTA ACTIVA, Variantes, Simulador), las funciones que cambian variantes (`setVariant` u otras), envíos simulados con `setTimeout`/`alert`, el Tailwind por CDN y su configuración inline, y los datos de ejemplo escritos a mano.
- Cada "variante" del HTML es un ESTADO del componente, definido en la ficha.
- No copies textos del HTML que no estén en la ficha o en el SRS.
- Si una ficha marca algo como [PENDIENTE], no lo inventes: detente y pregunta.
- Reutiliza los componentes compartidos listados en `docs/design/screens/README.md`.

## Skills de diseño

- Usa la skill `ui-ux-pro-max` en toda tarea de interfaz (pantallas, componentes, formularios, tablas, accesibilidad):
  - Consultas por tema: `--domain ux`, `--domain icons`, `--domain chart`.
  - Buenas prácticas del stack: `--stack react` y `--stack html-tailwind`.
  - Antes de entregar una pantalla, revísala contra sus prioridades 1 a 3 (Accesibilidad, Touch e interacción, Rendimiento) y las de formularios y navegación.
- Con `ui-ux-pro-max` NO uses `--design-system` ni `--persist`, y no crees `design-system/` ni `MASTER.md`: el design system ya existe en `docs/DESIGN.md`.
- No elijas paletas, fuentes ni estilos desde la skill. Si una recomendación contradice `docs/DESIGN.md`, gana `docs/DESIGN.md`.
- No uses las skills `banner-design`, `brand`, `design`, `design-system`, `slides` ni `ui-styling` en este proyecto.
- No agregues dependencias de UI (shadcn/ui, Radix, librerías de componentes o íconos) sin aprobación.

## Pruebas

- Vitest + Testing Library para todo componente con lógica (estados, validaciones, guardias).
- E2E con Playwright + axe-core: 0 violaciones A/AA en las pantallas nuevas.

## Flujo y verificación

- Implementa cada pantalla con `/implementar-pantalla <ficha>`, una pantalla por sesión.
- Tras cada edición en `src/`, un hook ejecuta ESLint y `check:tokens` sobre el archivo. Si reporta errores, corrígelos antes de seguir.
- Antes de dar por terminada una pantalla, pide la revisión del subagente `ui-reviewer`.
- Para Tailwind v4, React Router 7 y TanStack Query usa la documentación actual (Context7 si está disponible), no sintaxis de versiones anteriores.
