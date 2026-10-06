---
name: Clinical Precision Dental OS
colors:
  # Surfaces (cool slate neutrals)
  background: '#F8FAFC'
  on-background: '#0F1A34'
  surface: '#F8FAFC'
  surface-dim: '#E2E8F0'
  surface-bright: '#FFFFFF'
  surface-container-lowest: '#FFFFFF'
  surface-container-low: '#F1F5F9'
  surface-container: '#EEF2F7'
  surface-container-high: '#E2E8F0'
  surface-container-highest: '#DCE3EC'
  surface-variant: '#F1F5F9'
  on-surface: '#0F1A34'
  on-surface-variant: '#475569'
  inverse-surface: '#0F1A34'
  inverse-on-surface: '#F8FAFC'
  outline: '#7C8AA0'
  outline-variant: '#E2E8F0'
  surface-tint: '#0F1A34'
  # Primary — midnight navy (main actions, active navigation)
  primary: '#0F1A34'
  on-primary: '#FFFFFF'
  primary-container: '#E5EEFF'
  on-primary-container: '#0F1A34'
  primary-fixed: '#DAE2FF'
  primary-fixed-dim: '#BBC6E8'
  on-primary-fixed: '#0F1A34'
  on-primary-fixed-variant: '#3C4662'
  inverse-primary: '#BBC6E8'
  primary-hover: '#1B2A4E'
  primary-pressed: '#0A1122'
  # Secondary — clinical blue (links, tertiary buttons, info)
  secondary: '#0369A1'
  on-secondary: '#FFFFFF'
  secondary-container: '#E0F2FE'
  on-secondary-container: '#075985'
  secondary-fixed: '#E0F2FE'
  secondary-fixed-dim: '#BAE6FD'
  on-secondary-fixed: '#0C4A6E'
  on-secondary-fixed-variant: '#075985'
  focus-ring: '#0369A1'
  focus-halo: '#0EA5E9'
  # Tertiary — clinical teal (success, completed states). Never used in the odontogram.
  tertiary: '#0F766E'
  on-tertiary: '#FFFFFF'
  tertiary-container: '#CCFBF1'
  on-tertiary-container: '#115E59'
  tertiary-fixed: '#CCFBF1'
  tertiary-fixed-dim: '#99F6E4'
  on-tertiary-fixed: '#134E4A'
  on-tertiary-fixed-variant: '#115E59'
  # Error / destructive
  error: '#BA1A1A'
  on-error: '#FFFFFF'
  error-container: '#FFDAD6'
  on-error-container: '#93000A'
  # Warning
  warning: '#B45309'
  on-warning: '#FFFFFF'
  warning-container: '#FFFBEB'
  on-warning-container: '#92400E'
  # Appointment status (text on container)
  status-scheduled: '#475569'
  status-scheduled-container: '#F1F5F9'
  status-confirmed: '#0369A1'
  status-confirmed-container: '#F0F9FF'
  status-in-progress: '#0F1A34'
  status-in-progress-container: '#E5EEFF'
  status-attended: '#0F766E'
  status-attended-container: '#F0FDFA'
  status-cancelled: '#64748B'
  status-cancelled-container: '#F8FAFC'
  status-no-show: '#B45309'
  status-no-show-container: '#FFFBEB'
  # Caries risk level
  risk-low: '#0F766E'
  risk-low-container: '#F0FDFA'
  risk-medium: '#B45309'
  risk-medium-container: '#FFFBEB'
  risk-high: '#B91C1C'
  risk-high-container: '#FEF2F2'
  # Odontogram — official NTS N° 188 symbology ONLY
  odontogram-blue: '#1D4ED8'
  odontogram-red: '#DC2626'
  # AI-originated clinical data marker
  ai-origin: '#6D28D9'
  ai-origin-container: '#F5F3FF'
typography:
  display-lg:
    fontFamily: Plus Jakarta Sans
    fontSize: 3rem
    fontWeight: '700'
    lineHeight: '1.15'
    letterSpacing: -0.02em
  headline-lg:
    fontFamily: Plus Jakarta Sans
    fontSize: 2rem
    fontWeight: '700'
    lineHeight: '1.25'
    letterSpacing: -0.015em
  headline-lg-mobile:
    fontFamily: Plus Jakarta Sans
    fontSize: 1.625rem
    fontWeight: '700'
    lineHeight: '1.3'
    letterSpacing: -0.01em
  headline-md:
    fontFamily: Plus Jakarta Sans
    fontSize: 1.5rem
    fontWeight: '600'
    lineHeight: '1.35'
    letterSpacing: -0.01em
  title-lg:
    fontFamily: Plus Jakarta Sans
    fontSize: 1.125rem
    fontWeight: '600'
    lineHeight: '1.4'
  title-md:
    fontFamily: Plus Jakarta Sans
    fontSize: 1rem
    fontWeight: '600'
    lineHeight: '1.4'
  body-lg:
    fontFamily: Plus Jakarta Sans
    fontSize: 1rem
    fontWeight: '400'
    lineHeight: '1.6'
  body-md:
    fontFamily: Plus Jakarta Sans
    fontSize: 0.875rem
    fontWeight: '400'
    lineHeight: '1.5'
  label-md:
    fontFamily: Plus Jakarta Sans
    fontSize: 0.875rem
    fontWeight: '500'
    lineHeight: '1.25'
  label-sm:
    fontFamily: Plus Jakarta Sans
    fontSize: 0.75rem
    fontWeight: '600'
    lineHeight: '1.2'
    letterSpacing: 0.02em
  data-md:
    fontFamily: Plus Jakarta Sans
    fontSize: 0.875rem
    fontWeight: '500'
    lineHeight: '1.4'
  mono-md:
    fontFamily: JetBrains Mono
    fontSize: 0.875rem
    fontWeight: '500'
    lineHeight: '1.5'
rounded:
  sm: 0.25rem
  DEFAULT: 0.5rem
  md: 0.75rem
  lg: 1rem
  xl: 1.5rem
  full: 9999px
spacing:
  gutter: 1.5rem
  gutter-sm: 1rem
  margin: 2rem
  margin-sm: 1rem
  space-xs: 0.25rem
  space-sm: 0.5rem
  space-md: 1rem
  space-lg: 1.5rem
  space-xl: 2.5rem
---

## Brand & Style

DentiCore is a multi-clinic SaaS for dental clinics in Peru. It serves five audiences: the platform Super Administrator, Clinic Administrators, Dentists, Receptionists, and Patients or their legal representatives through a patient portal. The visual tone balances clinical hygiene with executive software precision: calm, trustworthy, and precise, never playful.

The style merges **Corporate / Modern** structure with **Minimalist** clarity: generous whitespace on authentication screens, dense but readable data on internal screens, polished form inputs, and restrained clinical accents that reduce fatigue during long clinical workflows.

Hard rules:
- All interface copy is in **Spanish (Peru)**. Dates `dd/MM/yyyy`, 24-hour time (America/Lima), currency `S/ 1,234.56`.
- **No** gradients, glassmorphism, stock photos of people, or decorative illustrations inside the application.
- **No** third-party sign-in (Google, Microsoft or other SSO) and **no** self sign-up. Access is email + password + TOTP second factor; accounts are created only by invitation.
- **Never communicate a state with color alone.** Every colored badge, status or finding also shows text, an acronym or an icon.
- Icons: outline style (Lucide), 1.5px stroke, 16–20px.

## Colors

- **Primary (`#0F1A34`)**: deep midnight navy for clinical authority. Primary buttons, active navigation, key headings. Hover `#1B2A4E`, pressed `#0A1122`.
- **Secondary (`#0369A1`)**: clinical blue for text links, tertiary (ghost) buttons, informational badges and the focus ring. It passes WCAG AA on white (5.9:1).
- **Focus halo (`#0EA5E9`)**: used **only** as a translucent halo around the focus ring. Never as a text or icon color: it fails contrast on white (2.8:1).
- **Tertiary (`#0F766E`)**: clinical teal for success and completed states (attended appointment, low risk, completed plan). It is **never** used inside the odontogram.
- **Warning (`#B45309` on `#FFFBEB`)**: pending reviews, expiring items, no-shows, suspended-clinic banner.
- **Error (`#BA1A1A`)**: destructive actions and validation errors.
- **Neutrals**: canvas `#F8FAFC`, cards `#FFFFFF`, subtle fills `#F1F5F9`, dividers and card borders `#E2E8F0`, secondary text `#475569`, form control borders `#7C8AA0` (3.5:1, meets the 3:1 non-text contrast rule).
- **AI origin (`#6D28D9` on `#F5F3FF`)**: marks clinical data suggested by generative AI and accepted by a dentist. Always paired with the label "IA".

### Status palette (use identically on every screen)

| Domain | State (UI label) | Text | Background | Icon |
| :-- | :-- | :-- | :-- | :-- |
| Appointment | Programada | `#475569` | `#F1F5F9` | clock |
| Appointment | Confirmada | `#0369A1` | `#F0F9FF` | check |
| Appointment | En atención | `#0F1A34` | `#E5EEFF` | activity |
| Appointment | Atendida | `#0F766E` | `#F0FDFA` | check-check |
| Appointment | Cancelada | `#64748B` | `#F8FAFC` | x |
| Appointment | Inasistencia | `#B45309` | `#FFFBEB` | alert-triangle |
| Caries risk | Bajo | `#0F766E` | `#F0FDFA` | shield-check |
| Caries risk | Medio | `#B45309` | `#FFFBEB` | alert-circle |
| Caries risk | Alto | `#B91C1C` | `#FEF2F2` | alert-octagon |
| Budget | Borrador · Emitido · Aceptado · Rechazado · Vencido · Reemplazado | neutral · secondary · tertiary · error · warning · neutral | matching container | file icons |
| Plan | Borrador · Propuesto · Aceptado · En ejecución · Completado · Cancelado | neutral · secondary · secondary · primary · tertiary · neutral | matching container | — |
| Consent | Vigente · Sustituido · Revocado | tertiary · neutral · error | matching container | — |

### Odontogram colors (regulatory)

The odontogram follows the Peruvian technical standard **NTS N° 188-MINSA** and uses **only two colors**, taken from the official finding catalog:
- **Red `#DC2626`**: pathology or poor condition.
- **Blue `#1D4ED8`**: good condition or treatment performed.

Every finding shows its official **acronym** (sigla) next to or below the tooth. The color is determined by the finding state in the catalog and is never a free user choice. No teal, green, grey fills or custom colors are allowed in the odontogram.

## Typography

**Plus Jakarta Sans** is used at every level for its humanist geometry and legibility at dense data scales.

- **Headlines & titles**: tight tracking (`-0.015em` to `-0.02em`) and bold weights for authentication titles, page titles and key patient metrics.
- **Body**: `body-md` (14px) on internal staff screens, `body-lg` (16px) on authentication screens and the whole patient portal.
- **Numerical data** (`data-md`): tabular numerals (`tnum`) for amounts, times, document numbers, clinical record numbers, tooth numbers and budget/receipt numbers (P-000124, R-000058).
- **Monospace** (`mono-md`, JetBrains Mono): only for TOTP setup keys and recovery codes.
- **Labels**: `label-md` above form fields; `label-sm` for badges and table headers.

## Layout & Spacing

8pt base grid.

- **Authentication (split view)**: 55/45 split on desktop. The left column centers the form in a 420px max-width container. The right column is a **brand panel** on navy `#0F1A34`: clinic logo and name (or "DentiCore · Administración de la plataforma" on the platform login), one short tagline and up to three feature lines with icons. **No photographs of people.** Below 1024px the brand panel is hidden.
- **Staff application**: fixed 260px sidebar (white, right border `#E2E8F0`), collapsible to a 72px icon rail; top bar with global patient search (shortcut `/`), notifications and user menu; content area on a 12-column grid with `1.5rem` gutters. Minimum supported width is **768px** (tablet portrait): at 768–1023px the sidebar collapses to the icon rail.
- **Patient portal**: mobile-first at 360–430px, bottom tab navigation (Inicio, Citas, Presupuestos, Mi salud), single column, `1rem` margins.

### Density

| Context | Control height | Table row | Card padding |
| :-- | :-- | :-- | :-- |
| Authentication and patient portal | 48px | — | 1.5rem |
| Staff application | 40px | 48px | 1.25rem |

## Elevation & Depth

Tonal surfaces over heavy shadows:

- **Level 0 (base)**: canvas `#F8FAFC`; white surfaces separated by `1px solid #E2E8F0`.
- **Level 1 (cards, forms)**: `0 1px 3px rgba(15, 26, 52, 0.05), 0 10px 24px -4px rgba(15, 26, 52, 0.03)`.
- **Level 2 (popovers, menus, side panels, modals)**: `0 12px 32px -6px rgba(15, 26, 52, 0.08), 0 4px 12px -2px rgba(15, 26, 52, 0.04)`.
- **Focus**: 2px solid `#0369A1` outline with 2px offset, plus a 3px `#0EA5E9` halo at 18% opacity. Focus must be visible on every interactive element, including teeth in the odontogram.

## Shapes

- **Buttons & form fields**: 8px radius.
- **Cards, modals, side panels & odontogram canvas**: 16px radius.
- **Chips & badges**: full pill (`9999px`).
- **Checkboxes**: 20px square with 4px radius; radios 20px circular.

## Components

- **Buttons**
  - *Primary*: navy fill `#0F1A34`, white text, 8px radius. 48px high in authentication and portal, 40px in the staff app. Hover `#1B2A4E`, pressed `#0A1122`. One primary button per view or panel.
  - *Secondary*: white background, `1px #7C8AA0` border, navy text.
  - *Tertiary / ghost*: no border, `#0369A1` text. Used for "¿Olvidaste tu contraseña?", "Volver" or "Ver historial".
  - *Destructive*: `#BA1A1A` fill or text. Always opens a confirmation dialog.
  - *Loading*: spinner inside the button, label kept, button disabled.

- **Input fields**
  - `1px #7C8AA0` border, 8px radius, white fill, `#0F1A34` text, `#64748B` placeholder. Height per the density table.
  - Labels on top (`label-md`), helper text below in `#475569`.
  - Focus: border `#0369A1` plus the focus halo.
  - Error: border `#BA1A1A`, message below the field with an alert icon; form data is preserved.
  - Password fields include a show/hide toggle. Password requirements are shown as a live checklist with check or x icons.
  - Six-digit TOTP input: 6 separate boxes, tabular numerals.

- **Alerts & banners**: inline alert on top of forms (error, warning, info, success) with icon, title and one-line message. Global top banner (warning) for a suspended clinic: "Clínica suspendida: estás en modo solo lectura."

- **Cards & data modules**: white, `1px #E2E8F0` border, 16px radius, Level 1 shadow, 1.25rem padding in the staff app.

- **Tables**: header row in `label-sm` uppercase `#475569` on `#F8FAFC`; 48px rows with `#E2E8F0` dividers; numeric columns right-aligned with tabular numerals; row hover `#F1F5F9`; sticky header on long lists; pagination below.

- **Chips & status badges**: pill, `label-sm`, 16px icon + text, colors from the status palette. Never color-only.

- **Patient identity strip**: fixed band at the top of every clinical screen with patient full name, age, clinical record number (HC), document, consent badge and, when present, a red allergy badge with alert icon ("Alergia: Penicilina"). It stays visible while scrolling.

- **Allergy warning**: before confirming a performed procedure and while building a treatment plan, a red inline alert lists the registered allergies.

- **Confirmation dialogs**: required for irreversible actions (issue budget, close attention, cancel plan, void payment, revoke consent). The dialog lists the consequences. Merging or deleting clinical records requires typing the clinical record number.

- **Session timeout dialog**: shown 2 minutes before inactivity logout ("Tu sesión está por cerrarse") with "Seguir conectado" and "Cerrar sesión".

- **Checkboxes & radios**: checked state filled `#0F1A34` with white check.

- **Odontogram & clinical charting**
  - FDI two-digit numbering: permanent 11–18, 21–28, 31–38, 41–48; primary 51–55, 61–65, 71–75, 81–85.
  - Each tooth shows its number and a 5-surface diagram (vestibular, lingual/palatal, mesial, distal, occlusal/incisal); healthy anatomy with neutral `#7C8AA0` outlines and no fill.
  - Findings drawn **only** in `#DC2626` (red) or `#1D4ED8` (blue) with the official NTS N° 188 acronym in a small box above/below the tooth. Missing tooth: blue X.
  - Corrected entries are shown struck through with the label "Corregida" and the reason.
  - Every entry shows its origin (Manual, Procedimiento, IA) and author; AI-originated entries carry the `#6D28D9` "IA" marker.
  - Legend always visible: "Rojo: patología o mal estado · Azul: buen estado o tratamiento realizado · Siglas según NTS N° 188".

- **Caries risk card**: level badge with text ("Riesgo alto"), calibrated probability, confidence, model version and date, top 3 increasing and top 3 decreasing factors as horizontal bars, and the fixed legend "Herramienta de apoyo; no constituye diagnóstico". When confidence < 0.60 add a "Baja confianza" badge. When the engine is unavailable show a neutral card "Predicción no disponible" (not an error style).

## Accessibility

- WCAG 2.1 AA: text contrast ≥ 4.5:1, control borders and icons ≥ 3:1.
- Full keyboard operation in the staff app, including the odontogram (tooth by number, surface by letter).
- Patient portal: base text ≥ 16px, touch targets ≥ 44 × 44px, instructions in short sentences, 200% zoom without loss of content.
