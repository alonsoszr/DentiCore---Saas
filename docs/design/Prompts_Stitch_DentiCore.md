# Prompts para Google Stitch — DentiCore

Prompts para diseñar las pantallas de DentiCore en Stitch, alineados con el SRS v1.0, el SDD v2 y el Plan de Implementación. Cada prompt indica la entrega a la que pertenece (E1 = curso, E2 = piloto, E3 = comercial), para que diseñes primero lo que se construye primero.

---

## 0. Antes de empezar

### 0.1 Corrige el DESIGN.md de Stitch

El DESIGN.md "Clinical Precision Dental OS" es una buena base, pero tiene 6 problemas que Stitch va a copiar si no los corriges:

| # | Problema | Corrección |
| :-: | :-- | :-- |
| 1 | En el YAML `primary` y `tertiary` valen `#000000`, pero el texto dice `#0F1A34` y `#14B8A6`. `secondary` vale `#006591` en el YAML y `#0EA5E9` en el texto. | Deja un solo valor: `primary: '#0F1A34'`, `secondary: '#0369A1'`, `tertiary: '#0F766E'`. |
| 2 | El botón fantasma usa texto `#0EA5E9` sobre blanco: contraste ~2,8:1. No cumple AA (RNF-060 pide ≥ 4,5:1). | Texto de enlaces y botones terciarios en `#0369A1` (~5,9:1). `#0EA5E9` solo para el anillo de foco. |
| 3 | Badges: `#0D9488` sobre `#F0FDFA` (~3,6:1) y `#0284C7` sobre `#F0F9FF` (~3,8:1) no cumplen AA. | Texto de badges en `#0F766E` (teal) y `#0369A1` (azul). |
| 4 | Incluye botones SSO de Google y Microsoft. El SRS (CUS-06) solo admite correo + contraseña + segundo factor TOTP. | Eliminar el SSO de todo el sistema. |
| 5 | El odontograma usa relleno menta para piezas tratadas. Contradice la NTS 188 y RN-17/RNF-151: solo azul y rojo del catálogo oficial, con sigla. | El odontograma usa exclusivamente azul `#1D4ED8` y rojo `#DC2626` + sigla. El teal nunca aparece en el odontograma. |
| 6 | La columna derecha del login pide "fotos de estilo de vida médico". | Panel de marca con el logo y nombre de la clínica, sin fotos de personas (evita imágenes genéricas de IA). |

### 0.2 Cómo usar estos prompts

1. **Un solo proyecto de Stitch** para todo DentiCore, con el DESIGN.md corregido aplicado como design system del proyecto.
2. **Pega el Bloque base (§1) al inicio del primer prompt.** En los siguientes prompts del mismo proyecto basta con la línea "Sigue el Bloque base y el design system del proyecto". Si Stitch se desvía (colores, fuente, idioma), vuelve a pegar el bloque completo.
3. **Un prompt = una pantalla o un flujo corto.** No pidas 5 pantallas en un prompt: Stitch simplifica y pierde reglas.
4. **Tamaños:** pantallas del personal a 1440 × 900 (verifica también 768 px, tablet vertical). Portal del paciente a 390 px de ancho (teléfono).
5. **Orden recomendado:** Acceso (§2) → Estructura de la app (§3) → Agenda (§4) → Pacientes (§5) → Atención y odontograma (§6) → Plan y presupuesto (§7) → Riesgo (§8) → Portal (§11) → Administración (§9, §10) → Pagos (§12).
6. Al terminar cada pantalla, exporta la captura y el HTML a `docs/design/screens/<pantalla>.png|html`.

---

## 1. Bloque base (pégalo en el primer prompt)

```text
PROYECTO: DentiCore, SaaS web multi-clínica para clínicas odontológicas del Perú. Cada clínica tiene su propio acceso (URL /c/<codigo-clinica>/...). Usuarios: Súper Administrador (plataforma), Administrador de Clínica, Odontólogo, Recepcionista, y Paciente o su representante legal (portal).

DESIGN SYSTEM: aplica "Clinical Precision Dental OS" con estas reglas obligatorias:
- Colores: primario navy #0F1A34 (botón principal, ítem activo del menú). Enlaces y botones terciarios #0369A1. #0EA5E9 SOLO como anillo de foco (3px, 18% de opacidad). Lienzo #F8FAFC. Superficies #FFFFFF con borde 1px #E2E8F0. Texto principal #0F1A34, secundario #475569. Error/destructivo #BA1A1A.
- Tipografía Plus Jakarta Sans. Números tabulares en montos, horas, DNI y códigos.
- Radios: botones y campos 8px, cards y modales 16px, badges en píldora.
- En pantallas internas: controles de 40px de alto y tablas densas (filas de 48px). En autenticación y portal: controles de 48px.
- Sombras suaves según el design system. Sin degradados, sin glassmorphism, sin fotos de stock de personas, sin ilustraciones decorativas dentro de la app. Iconos de línea estilo Lucide.
- Ningún estado se comunica solo con color: todo badge lleva texto y, si aplica, ícono.
- Contraste mínimo 4,5:1 en texto y 3:1 en bordes de controles. Foco visible en todo elemento interactivo.

IDIOMA Y FORMATO: toda la interfaz en español del Perú. Fechas dd/MM/yyyy, hora 24 h (America/Lima), moneda "S/ 1,234.56". Datos de ejemplo ficticios y peruanos: nombres como "María Quispe Huamán", DNI de 8 dígitos, clínica de ejemplo "Clínica Dental Sonrisa Andina" (código de acceso: sonrisa-andina).

PALETA SEMÁNTICA (usar siempre igual en todas las pantallas):
- Cita: Programada (gris #475569 sobre #F1F5F9, ícono reloj) · Confirmada (#0369A1 sobre #F0F9FF, ícono check) · En atención (#0F1A34 sobre #E5EEFF, ícono pulso) · Atendida (#0F766E sobre #F0FDFA, ícono check doble) · Cancelada (#64748B sobre #F8FAFC, ícono x) · Inasistencia (#B45309 sobre #FFFBEB, ícono alerta).
- Riesgo de caries: Bajo (#0F766E) · Medio (#B45309) · Alto (#B91C1C), siempre con texto.
- Odontograma: SOLO azul #1D4ED8 y rojo #DC2626 del catálogo NTS 188, siempre con su sigla. Prohibido usar teal, verde u otros colores en el odontograma.
```

---

## 2. Acceso y autenticación (E1 · MS-01) — usa el DESIGN.md del login

### Prompt A1 — Inicio de sesión de la clínica

```text
Sigue el Bloque base y el design system del proyecto.

Diseña la pantalla de INICIO DE SESIÓN del personal de una clínica, ruta /c/sonrisa-andina/login, desktop 1440×900, layout dividido 55/45.

COLUMNA IZQUIERDA (formulario, contenedor máx. 420px centrado):
- Arriba: logotipo de DentiCore pequeño.
- Encabezado: logo circular y nombre de la clínica "Clínica Dental Sonrisa Andina" (la clínica se identifica por la URL, el usuario NO escribe ningún código).
- Título "Inicia sesión" y subtítulo "Accede con tu correo y contraseña".
- Campos: "Correo electrónico" y "Contraseña" (con botón para mostrar u ocultar). Etiquetas arriba del campo.
- Enlace terciario "¿Olvidaste tu contraseña?" alineado a la derecha, debajo de la contraseña.
- Botón principal navy de ancho completo "Ingresar".
- Pie: "¿Problemas para ingresar? Contacta al administrador de tu clínica."
- NO incluyas inicio de sesión con Google, Microsoft ni ningún proveedor externo. NO incluyas "Registrarse": las cuentas se crean solo por invitación.

COLUMNA DERECHA (panel de marca, fondo navy #0F1A34): nombre de la clínica, frase breve "Historia clínica, odontograma y agenda en un solo lugar" y 3 líneas con ícono: "Odontograma según NTS N° 188", "Datos protegidos y aislados por clínica", "Acceso con segundo factor". Sin fotografías de personas.

Genera además estas VARIANTES del mismo formulario:
1. Error de credenciales: alerta en rojo sobre el formulario con el texto exacto "Credenciales inválidas" (nunca indica si falló el correo o la contraseña).
2. Demasiados intentos: alerta ámbar "Demasiados intentos. Vuelve a intentarlo en 1 minuto."
3. Enviando: botón con indicador de carga y campos deshabilitados.
4. Clínica suspendida (tras ingresar): banner informativo "Esta clínica está suspendida. Podrás consultar la información en modo solo lectura."
5. Móvil y tablet a 768px: el panel de marca se oculta y el formulario ocupa el ancho.
```

### Prompt A2 — Inicio de sesión de plataforma (Súper Administrador)

```text
Sigue el Bloque base y el design system del proyecto.

Crea una variante del login para la dirección de administración de la plataforma, ruta /login, usada solo por el Súper Administrador. Igual que el login de clínica, pero:
- En lugar del logo y nombre de la clínica, muestra "DentiCore · Administración de la plataforma" y una etiqueta "Acceso restringido".
- El panel derecho muestra el logotipo de DentiCore y la frase "Gestión de clínicas, planes y seguridad de la plataforma".
- Mismos campos, mismo mensaje único de error "Credenciales inválidas". Sin SSO ni registro.
```

### Prompt A3 — Verificación del segundo factor

```text
Sigue el Bloque base y el design system del proyecto.

Diseña la pantalla de VERIFICACIÓN EN DOS PASOS que aparece después de ingresar correo y contraseña. Mismo layout dividido que el login.
- Título "Verificación en dos pasos". Texto: "Ingresa el código de 6 dígitos de tu aplicación de autenticación."
- 6 casillas individuales para los dígitos, con foco visible y números tabulares grandes.
- Botón principal "Verificar".
- Enlace "Usar un código de recuperación": al activarlo, las 6 casillas se reemplazan por un campo de texto "Código de recuperación" (formato XXXX-XXXX).
- Enlace "Volver al inicio de sesión".
Variantes: (1) código incorrecto: "Código incorrecto. Te quedan 4 intentos." (2) bloqueo: "Demasiados intentos. Espera 15 minutos o contacta al administrador de tu clínica."
```

### Prompt A4 — Configuración obligatoria del segundo factor

```text
Sigue el Bloque base y el design system del proyecto.

Diseña el flujo de CONFIGURACIÓN DEL SEGUNDO FACTOR, obligatorio para Administradores de Clínica y Súper Administradores en su primer ingreso. Mientras no lo completen, no pueden acceder a nada más. Card centrada de máx. 560px sobre el lienzo, con indicador de 3 pasos:

Paso 1 "Escanea el código": aviso "Tu rol requiere verificación en dos pasos para proteger los datos de los pacientes". Código QR grande, debajo la clave manual en texto monoespaciado con botón "Copiar". Sugerencia: "Usa Google Authenticator, Microsoft Authenticator u otra app compatible".
Paso 2 "Confirma": campo de 6 dígitos y botón "Confirmar".
Paso 3 "Guarda tus códigos de recuperación": cuadrícula de 10 códigos (formato XXXX-XXXX) en fuente monoespaciada, botones "Descargar" y "Copiar", advertencia ámbar "Cada código se usa una sola vez. Guárdalos en un lugar seguro: no volverás a verlos." y casilla obligatoria "Guardé mis códigos de recuperación" que habilita el botón "Finalizar".
```

### Prompt A5 — Recuperar y restablecer contraseña

```text
Sigue el Bloque base y el design system del proyecto.

Diseña 2 pantallas con el layout dividido del login:

1) "¿Olvidaste tu contraseña?" (/c/sonrisa-andina/login/recuperar): campo "Correo electrónico" y botón "Enviar enlace". Estado enviado con el mensaje neutro "Si el correo está registrado, recibirás un enlace para restablecer tu contraseña. El enlace vence en 60 minutos." (el mensaje es el mismo exista o no la cuenta). Enlace "Volver al inicio de sesión".

2) "Restablecer contraseña" (/c/sonrisa-andina/restablecer/<token>): campos "Nueva contraseña" y "Confirmar contraseña" con lista de requisitos que se marcan en vivo con ícono check o x (no solo color):
   - Entre 10 y 128 caracteres
   - No es una contraseña común
   - No contiene tu correo ni tu nombre
   - No es igual a tus últimas 5 contraseñas
   Botón "Guardar contraseña". Nota: "Al guardar se cerrarán todas tus sesiones abiertas."
   Variante: enlace vencido o ya usado: "Este enlace ya no es válido" con botón "Solicitar un enlace nuevo".
```

### Prompt A6 — Activar cuenta por invitación

```text
Sigue el Bloque base y el design system del proyecto.

Diseña la pantalla ACTIVAR CUENTA (/c/sonrisa-andina/activar/<token>) a la que llega un usuario invitado por correo. Layout dividido del login.
- Encabezado: "Te invitaron a Clínica Dental Sonrisa Andina".
- Card de resumen de solo lectura: nombre "Luis Paredes Rojas", correo, rol con badge "Odontólogo".
- Campos "Crea tu contraseña" y "Confirmar contraseña" con la misma lista de requisitos en vivo del restablecimiento.
- Casilla "Acepto los términos de uso y la política de privacidad" con enlaces.
- Botón "Activar cuenta". Nota si el rol es Administrador: "En el siguiente paso configurarás la verificación en dos pasos."
- Variante: invitación vencida (72 h): "Esta invitación venció. Pide al administrador de tu clínica que te envíe una nueva."
```

---

## 3. Estructura de la app del personal (E1)

### Prompt B1 — Layout general, navegación por rol y sesión

```text
Sigue el Bloque base y el design system del proyecto.

Diseña el LAYOUT BASE de la aplicación del personal (/c/sonrisa-andina/app), desktop 1440×900. Esta estructura se reutilizará en todas las pantallas internas.

- Sidebar izquierdo fijo de 260px, fondo blanco con borde derecho, colapsable a solo íconos. Arriba: logo y nombre de la clínica. Ítem activo con fondo #E5EEFF y texto navy.
- Menú del ODONTÓLOGO: Inicio, Agenda, Sala de espera, Pacientes, Alertas de riesgo (badge con contador).
- Muestra también, en una segunda versión del sidebar, el menú de RECEPCIÓN: Inicio, Agenda, Sala de espera, Pacientes, Presupuestos.
- Y el del ADMINISTRADOR DE CLÍNICA: Inicio, Agenda, Pacientes, Usuarios, Horarios, Catálogo de procedimientos, Consentimiento informado, Configuración.
- Barra superior: buscador global "Buscar paciente por nombre o DNI" con atajo "/" visible, campana de notificaciones con contador, menú de usuario (nombre, rol, "Cerrar sesión").
- Área de contenido: encabezado con migas de pan, título de página y acciones a la derecha.

Incluye también estos 3 COMPONENTES GLOBALES en la misma pantalla o en artboards aparte:
1. Banner superior ámbar de clínica suspendida: "Clínica suspendida: estás en modo solo lectura. No puedes registrar ni modificar información."
2. Modal de inactividad: "Tu sesión está por cerrarse. Por seguridad, la sesión se cierra tras 30 minutos sin actividad. Se cerrará en 1:59." Botones "Seguir conectado" (principal) y "Cerrar sesión".
3. Panel desplegable de notificaciones in-app: "Presupuesto P-000124 aceptado por María Quispe", "Atención cerrada incompleta: falta diagnóstico CIE-10", "Alerta de riesgo alto: José Mamani". Enlace "Marcar todas como leídas".
```

### Prompt B2 — Inicio por rol (E3 · Should, opcional)

```text
Sigue el Bloque base, el layout base y el design system del proyecto.

Diseña la pantalla INICIO del ODONTÓLOGO "Dr. Luis Paredes" (hoy, lunes 05/10/2026):
- Fila de 4 indicadores: "Citas de hoy 9", "En sala de espera 2", "Atenciones abiertas 1", "Alertas de riesgo alto 3".
- Columna principal: "Mi agenda de hoy" como lista cronológica (hora, paciente, tipo de cita, badge de estado de la paleta semántica, botón "Abrir ficha").
- Columna lateral: "Sala de espera" (pacientes con check-in y minutos esperando) y "Alertas de riesgo sin atender" (paciente, fecha, badge "Alto", botón "Atender alerta").
- Aviso en la parte superior si hay una atención abierta de un día anterior: "Tienes 1 atención cerrada incompleta. Agrega el diagnóstico con una adenda."
Crea también la versión de RECEPCIÓN: indicadores "Citas de hoy", "Por confirmar", "Check-in pendientes", "Presupuestos por vencer"; lista de citas del día de todos los odontólogos con botón "Check-in".
```

---

## 4. Etapa 1 — Agenda y sala de espera (E1 · MS-04)

### Prompt C1 — Agenda diaria y semanal

```text
Sigue el Bloque base, el layout base y el design system del proyecto.

Diseña la AGENDA de la clínica (menú Agenda), rol Recepción, desktop 1440×900.
- Barra de herramientas: selector de fecha con flechas y botón "Hoy", conmutador "Día / Semana", filtro por odontólogo (multiselección con avatar e iniciales), botón principal "Nueva cita".
- Vista DÍA: una columna por odontólogo (Dr. Luis Paredes, Dra. Carmen Vílchez, Dr. Jorge Salazar), franja horaria de 07:00 a 21:00 con líneas cada 15 min. Zonas fuera del horario laboral en gris con rayado. Bloqueos (p. ej. "Capacitación 13:00–14:00") en gris oscuro con ícono candado. Línea roja de la hora actual.
- Cada cita es un bloque con: hora de inicio y fin, nombre del paciente, tipo de cita ("Evaluación", "Control", "Profilaxis", "Endodoncia") y badge de estado de la paleta semántica con ícono. Muestra las 6 variantes: Programada, Confirmada, En atención, Atendida, Cancelada e Inasistencia. Una cita con ícono "Por reconfirmar" (fue reprogramada).
- Al seleccionar una cita, panel lateral derecho (400px): datos del paciente, teléfono, tipo, odontólogo, estado, historial de cambios y acciones "Confirmar", "Reprogramar", "Check-in" (solo desde 60 min antes hasta el fin de la cita), "Cancelar" (destructivo, pide motivo).
- Vista SEMANA como segundo artboard: una columna por día (lunes a sábado) para un odontólogo.
```

### Prompt C2 — Reservar y reprogramar cita

```text
Sigue el Bloque base, el layout base y el design system del proyecto.

Diseña el panel lateral o modal "NUEVA CITA":
1. "Paciente": buscador por nombre o DNI con resultados (nombre, DNI, edad) y opción "Registrar paciente nuevo".
2. "Tipo de cita": selector (muestra la duración, p. ej. "Evaluación · 30 min").
3. "Odontólogo": selector.
4. "Fecha": calendario mensual donde los días sin disponibilidad aparecen deshabilitados.
5. "Horario disponible": cuadrícula de chips de hora cada 15 min (09:00, 09:15, 09:30…), solo horarios libres del odontólogo y del paciente.
6. "Notas para recepción" (opcional).
Botón "Reservar cita". Aviso informativo si el paciente no autorizó notificaciones: "Este paciente no recibirá recordatorios por correo."
Estados de error: "El odontólogo ya tiene una cita en ese horario" y "El paciente ya tiene otra cita que se cruza con este horario".
Segundo artboard: modal "REPROGRAMAR CITA" con la cita actual arriba, el mismo selector de fecha y horario, y la nota "La cita volverá a requerir confirmación del paciente".
Tercer artboard: modal "CANCELAR CITA" con motivo obligatorio y botón destructivo "Cancelar cita".
```

### Prompt C3 — Sala de espera y check-in

```text
Sigue el Bloque base, el layout base y el design system del proyecto.

Diseña la pantalla SALA DE ESPERA (se actualiza cada 30 s; muestra "Actualizado hace 12 s").
- Tabla de las citas del día con columnas: hora, paciente (nombre y DNI), odontólogo, tipo, estado (badge semántico), tiempo de espera, acción.
- Pestañas con contador: "Por llegar (6)", "En espera (2)", "En atención (1)", "Finalizadas (4)".
- Botón "Check-in" en las citas habilitadas. Las que aún no están en ventana muestran el botón deshabilitado con el texto de ayuda "Disponible desde 08:00".
- Modal de confirmación de check-in con resumen del paciente. Si no tiene consentimiento de datos vigente, muestra un aviso ámbar: "El paciente no tiene consentimiento de datos vigente. Podrá atenderse, pero no se registrarán datos clínicos hasta que lo otorgue." y el botón secundario "Registrar consentimiento".
```

---

## 5. Etapa 2 — Pacientes y consentimiento (E1 · MS-01)

### Prompt D1 — Búsqueda de pacientes

```text
Sigue el Bloque base, el layout base y el design system del proyecto.

Diseña la pantalla PACIENTES:
- Buscador grande "Buscar por nombre, apellido o número de documento" (acepta búsquedas sin tildes: "nunez" encuentra "Núñez").
- Filtro "Estado del archivo": Activos (por defecto), Pasivos, Bloqueados.
- Tabla paginada (25 por página) con: N° de HC, nombre completo, documento (tipo y número, p. ej. "DNI 45781236"), edad, teléfono, última atención, estado del consentimiento (badge "Vigente" / "Sin consentimiento" / "Revocado") y acción "Abrir ficha".
- Un paciente menor muestra el badge "Menor de edad" y el nombre de su representante en texto secundario.
- Botón principal "Registrar paciente".
- Estado vacío: "No encontramos pacientes con 'Gutiérrez'. ¿Deseas registrarlo?" con botón.
```

### Prompt D2 — Registrar paciente

```text
Sigue el Bloque base, el layout base y el design system del proyecto.

Diseña el formulario REGISTRAR PACIENTE en 2 pasos:

Paso 1 "Documento": "Tipo de documento" (DNI, Carné de extranjería, Pasaporte, Carné de Permiso Temporal de Permanencia) y "Número". Botón "Continuar". Variante: si ya existe una ficha con ese documento, card informativa "Este paciente ya está registrado" con nombre, N° de HC y botón "Abrir ficha existente".

Paso 2 "Datos del paciente", en secciones con título:
- Identificación: nombres, apellido paterno, apellido materno, fecha de nacimiento (dd/MM/yyyy; muestra la edad calculada al lado), sexo.
- Contacto: teléfono, correo, dirección.
- Si la edad es menor de 18, aparece la sección obligatoria "Representante legal" con aviso "Los pacientes menores de edad requieren un representante legal": tipo y número de documento, nombres, parentesco (Madre, Padre, Tutor legal), teléfono, correo.
- Barra inferior fija con "Cancelar" y "Registrar paciente".
Errores de validación junto a cada campo, p. ej. "La fecha de nacimiento no puede ser futura". Al salir con cambios sin guardar: modal "Tienes cambios sin guardar. ¿Deseas salir?"
```

### Prompt D3 — Consentimiento de datos personales

```text
Sigue el Bloque base, el layout base y el design system del proyecto.

Diseña la pantalla OTORGAR CONSENTIMIENTO DE DATOS (Ley N° 29733), con la franja del paciente arriba.
- Card con el texto del consentimiento (versión "v1", área desplazable) y el titular: "Otorga: María Quispe Huamán (titular)". Si el paciente es menor: "Otorga: Rosa Huamán Ccori (madre, representante legal)".
- Lista de 5 finalidades, cada una con casilla, título y una línea de explicación:
  (a) Atención odontológica: OBLIGATORIA, marcada y bloqueada, con badge "Obligatoria".
  (b) Notificaciones por correo (recordatorios de citas y presupuestos): opcional, DESMARCADA por defecto.
  (c) Asistencia de IA generativa: opcional, desmarcada. Variante deshabilitada con nota "No disponible en el plan de la clínica".
  (d) Predicción de riesgo de caries: opcional, desmarcada.
  (e) Encuestas de satisfacción: opcional, desmarcada.
- Canal: "Presencial en la clínica".
- Botón "Registrar consentimiento" y, una vez registrado, card de éxito con "Descargar constancia (PDF)".
Segundo artboard: historial de consentimientos del paciente (versión, fecha, finalidades otorgadas como badges, estado "Vigente / Sustituido / Revocado").
```

---

## 6. Etapa 3 — Historia clínica, atención y odontograma (E1 · MS-02)

### Prompt E1 — Ficha del paciente (historia clínica)

```text
Sigue el Bloque base, el layout base y el design system del proyecto.

Diseña la FICHA DEL PACIENTE (/app/pacientes/<id>), rol Odontólogo.
- FRANJA FIJA SUPERIOR (siempre visible al hacer scroll, en toda pantalla clínica): nombre "María Quispe Huamán", edad "34 años", "HC 45781236", documento, badge del consentimiento ("Consentimiento vigente") y, si tiene alergias, badge rojo con ícono de alerta "Alergia: Penicilina, Látex". Botón principal a la derecha: "Iniciar atención".
- Pestañas: Resumen · Odontograma · Atenciones · Planes y presupuestos · Riesgo de caries · Consentimientos · Datos personales.
- Pestaña RESUMEN:
  - Card "Antecedentes médicos" con 4 listas: Alergias, Enfermedades, Medicamentos, Observaciones.
  - Card "Riesgo de caries vigente": badge "Riesgo alto", "Confianza 0,82", fecha, y leyenda fija "Herramienta de apoyo; no constituye diagnóstico".
  - Card "Hallazgos pendientes de tratar: 4" con enlace al plan.
  - Línea de tiempo "Últimas atenciones": fecha, odontólogo con número COP, motivo, diagnósticos CIE-10 y estado ("Cerrada", "Cerrada incompleta").
  - Card "Próxima cita".
- Recepción ve la misma ficha en solo lectura, sin el botón "Iniciar atención" y sin notas clínicas.
```

### Prompt E2 — Atención abierta con odontograma

```text
Sigue el Bloque base, el layout base y el design system del proyecto.

Diseña la pantalla ATENCIÓN EN CURSO del odontólogo, la más importante del sistema. Desktop 1440×900, con la franja fija del paciente arriba (nombre, edad, HC y el badge rojo de alergias).
- Encabezado de la atención: "Atención del 05/10/2026 · 09:15 · Dr. Luis Paredes (COP 12345)", badge "Abierta" y botón principal "Cerrar atención".
- Columna izquierda (60%): ODONTOGRAMA. Conmutador "Odontograma inicial / Estado vigente" (el inicial solo existe en la primera atención). Dos filas de dentición permanente con numeración FDI (18 a 11 | 21 a 28 arriba; 48 a 41 | 31 a 38 abajo) y, en otra pestaña o fila opcional, la temporal (55–51 | 61–65; 85–81 | 71–75). Cada pieza tiene su número y un gráfico de 5 superficies (vestibular, lingual o palatina, mesial, distal, oclusal o incisal). Los hallazgos se dibujan SOLO en azul #1D4ED8 o rojo #DC2626, con su sigla en un recuadro sobre o debajo de la pieza (ejemplo de siglas: CE, CD, R, AM, CM; se reemplazarán por el catálogo NTS 188 oficial). Una pieza ausente se marca con una X azul. La pieza seleccionada tiene un anillo de foco.
- Panel derecho del odontograma, al seleccionar la pieza 36: "Pieza 36 · Primer molar inferior izquierdo". Selector de superficies con letras (M, D, V, L, O), "Hallazgo" (buscador del catálogo NTS 188), "Estado" (define el color; el usuario NO elige el color libremente) con vista previa de sigla y color, "Observación", botón "Registrar hallazgo". Debajo: "Historial de la pieza 36" en orden cronológico; cada entrada muestra fecha, autor, origen ("Manual", "Procedimiento" o "IA" con distintivo visual) y, si fue corregida, tachado con la etiqueta "Corregida" y el motivo.
- Columna derecha (40%): NOTA CLÍNICA con "Motivo de consulta" (obligatorio), "Examen clínico", "Observaciones"; indicador "Guardado 09:42". Sección "Diagnósticos CIE-10" con buscador (p. ej. "K02.1 Caries de la dentina") y chips eliminables. Card "Plan de tratamiento" con acceso a "Crear plan desde hallazgos".
- Leyenda del odontograma debajo: "Rojo: patología o mal estado · Azul: buen estado o tratamiento realizado · Siglas según NTS N° 188".
```

### Prompt E3 — Cerrar atención y corregir un hallazgo

```text
Sigue el Bloque base, el layout base y el design system del proyecto.

Diseña 3 modales sobre la pantalla de atención:
1) "CERRAR ATENCIÓN": checklist de requisitos con ícono check o x: "Motivo de consulta registrado", "Al menos un diagnóstico CIE-10". Efectos: "La nota se firmará y no podrá editarse. El odontograma inicial quedará cerrado; los nuevos hallazgos se registrarán como evolución." Botones "Volver" y "Cerrar y firmar". Variante con un requisito faltante y el botón deshabilitado.
2) "CORREGIR HALLAZGO": muestra la entrada original (pieza 26, superficie O, "Caries de dentina", rojo, fecha y autor). Opciones "Anular la entrada" o "Reemplazar por otro hallazgo". Campo obligatorio "Motivo de la corrección" (mínimo 10 caracteres, con contador). Nota: "La entrada original se conservará marcada como corregida." Botón "Registrar corrección".
3) "AGREGAR ADENDA" para una atención cerrada incompleta: texto de la adenda y diagnóstico CIE-10 faltante.
```

---

## 7. Etapa 4 — Plan de tratamiento, presupuesto y procedimiento (E1 · MS-03)

### Prompt G1 — Plan de tratamiento

```text
Sigue el Bloque base, el layout base y el design system del proyecto.

Diseña la pantalla PLAN DE TRATAMIENTO del paciente, con la franja fija del paciente y el aviso de alergias visible arriba del plan.
- Encabezado: "Plan de tratamiento #3", badge de estado del plan (muestra el stepper: Borrador → Propuesto → Aceptado → En ejecución → Completado; y el estado terminal Cancelado) y barra de avance "2 de 5 ítems realizados · S/ 480.00 de S/ 1,350.00".
- Columna izquierda: "Hallazgos pendientes" (hallazgos rojos sin tratar): pieza, superficie, hallazgo con sigla roja, fecha. Cada uno con "Agregar al plan" y "No tratar" (pide motivo).
- Columna principal: tabla de ítems con pieza, superficies, procedimiento del catálogo (código y nombre), cantidad, precio unitario, hallazgo vinculado, estado del ítem (Propuesto, Aceptado, Realizado, Descartado) y acciones.
- Acciones del plan: "Proponer al paciente" (principal), "Crear presupuesto", "Volver a editar", "Cancelar plan" (destructivo).
- Modal "Cancelar plan" con vista previa de efectos: "Se cancelarán 3 ítems pendientes. 2 ítems ya realizados (S/ 480.00) se conservan." y motivo obligatorio.
```

### Prompt G2 — Presupuesto

```text
Sigue el Bloque base, el layout base y el design system del proyecto.

Diseña la pantalla PRESUPUESTO en 2 estados:

ESTADO BORRADOR:
- Tabla editable de líneas: procedimiento, pieza, cantidad, precio unitario, descuento % (con motivo obligatorio si es mayor que 0), subtotal. Aviso cuando el descuento supera el tope de la clínica: "Tu rol permite hasta 10 % de descuento. Un descuento mayor lo aplica el administrador."
- Resumen a la derecha: Subtotal, Descuentos, Base imponible, IGV 18 %, "Total S/ 1,593.00" grande con números tabulares. Nota "Precios con IGV incluido".
- "Vigencia: 30 días (vence el 04/11/2026 a las 23:59)".
- Botón principal "Emitir presupuesto" con modal de confirmación: "Al emitir se asignará el número correlativo, se fijarán los precios y el presupuesto ya no podrá modificarse. Para corregirlo tendrás que emitir uno nuevo."

ESTADO EMITIDO:
- Encabezado "Presupuesto P-000124", badge "Emitido", fecha de emisión y vencimiento, botones "Descargar PDF", "Registrar decisión" y "Emitir corrección".
- Muestra también los badges de los demás estados del ciclo: Aceptado, Rechazado, Vencido y Reemplazado.

Segundo artboard: modal "REGISTRAR DECISIÓN DEL PACIENTE" (presencial): opciones "Acepta" / "Rechaza", "Firmante" (titular o representante), verificación "Confirmo que verifiqué el documento de identidad del firmante (DNI 45781236)", motivo del rechazo si aplica.
```

### Prompt G3 — Procedimiento realizado y consentimiento informado

```text
Sigue el Bloque base, el layout base y el design system del proyecto.

Diseña, dentro de la atención abierta, el panel "PROCEDIMIENTOS DEL PLAN" con los ítems aceptados (procedimiento, pieza, cantidad pendiente) y el botón "Registrar realizado".

Modal "REGISTRAR PROCEDIMIENTO REALIZADO":
- Aviso rojo de alergias antes de confirmar: "Alergias registradas: Penicilina, Látex".
- Resumen del procedimiento, cantidad realizada y observación.
- Nota: "Se registrará automáticamente una entrada de evolución en el odontograma (origen: procedimiento)."
- Si el procedimiento requiere consentimiento informado y no está firmado, el botón se bloquea y aparece "Firmar consentimiento informado".

Segundo artboard: modal "CONSENTIMIENTO INFORMADO": texto de la plantilla (versión), procedimiento y pieza, riesgos y alternativas, firmante (paciente o representante) y casilla de verificación de identidad. Botón "Registrar firma". Nota: "Una vez firmado no puede modificarse; puede revocarse antes de realizar el procedimiento."
```

---

## 8. Etapa 5 — Riesgo de caries con ML (E1 · MS-05; requiere plan Pro o Enterprise)

### Prompt F1 — Variables y predicción de riesgo

```text
Sigue el Bloque base, el layout base y el design system del proyecto.

Diseña la pestaña RIESGO DE CARIES de la ficha del paciente, rol Odontólogo.

SECCIÓN "Variables" en 3 cards con indicador de completitud ("Completo" / "Faltan 2 datos"):
- Sociodemográficas (también puede llenarlas recepción): para menores, nivel educativo y situación laboral del representante y estructura familiar; para adultos, nivel educativo.
- Clínicas (solo odontólogo): índice CPOD o ceod, índice de placa, lesiones activas.
- Conductuales (solo odontólogo): consumo de azúcar entre comidas, uso de pasta fluorada, frecuencia de cepillado, frecuencia de visitas.
Fecha de captura; aviso si tiene más de 6 meses: "Las variables tienen más de 6 meses. Actualízalas para calcular."

Botón principal "Calcular riesgo". Si falta algo, está deshabilitado con la lista de lo que falta: "Falta autorización del paciente para predicción de riesgo", "Faltan variables clínicas".

SECCIÓN "Resultado":
- Nivel grande con badge y texto ("Riesgo ALTO"), "Probabilidad calibrada 0,74", "Confianza 0,82", "Modelo v1.0 · 05/10/2026 · vigente hasta 05/10/2027".
- Explicación: 2 listas de 3 factores con barras horizontales: "Factores que aumentan el riesgo" (Consumo de azúcar entre comidas, Lesiones activas, Índice de placa alto) y "Factores que lo reducen" (Uso de pasta fluorada, Cepillado 2 veces al día, Visitas regulares).
- Leyenda fija visible: "Herramienta de apoyo; no constituye diagnóstico."

Genera también estas VARIANTES del resultado:
1. Confianza baja: badge adicional "Baja confianza" (confianza 0,54).
2. Motor no disponible: card neutra "Predicción no disponible. Puedes continuar la atención con normalidad." (sin tono de error grave).
3. Vista del Administrador de Clínica: solo nivel y fecha, sin probabilidad ni explicación.
4. Historial de predicciones anteriores con estado Vigente / Reemplazada / Vencida.
```

### Prompt F2 — Alertas de riesgo alto

```text
Sigue el Bloque base, el layout base y el design system del proyecto.

Diseña la pantalla ALERTAS DE RIESGO del odontólogo: lista de alertas "Abiertas" y "Reconocidas" (paciente, edad, fecha de la predicción, badge "Alto", días abierta).
Modal "ATENDER ALERTA" con 3 acciones obligatorias en tarjetas seleccionables:
1. "Crear plan preventivo" (abre el plan con origen "alerta").
2. "Programar cita de control" (selector de fecha y hora).
3. "No actuar por ahora", con justificación obligatoria de al menos 20 caracteres.
Botón "Registrar acción".
```

---

## 9. Administración de la clínica (E1 · MS-01 y MS-04)

### Prompt I1 — Usuarios de la clínica

```text
Sigue el Bloque base, el layout base y el design system del proyecto.

Diseña la pantalla USUARIOS, rol Administrador de Clínica.
- Indicador del plan: "Plan Pro · 3 de 10 odontólogos activos".
- Tabla: nombre, correo, rol (Administrador, Odontólogo, Recepcionista), N° COP (solo odontólogos), badge "Oficial de datos personales" cuando aplica, verificación en dos pasos (Activa / No configurada), estado (Activo, Pendiente de activación, Bloqueado temporalmente, Inactivo), último acceso y menú de acciones (Editar, Reenviar invitación, Desactivar, Reactivar).
- Modal "INVITAR USUARIO": nombres, correo, rol; si el rol es Odontólogo aparecen "N° COP" (obligatorio), "Especialidad" y "N° RNE"; si es Administrador, la casilla "Designar como oficial de datos personales". Nota: "Se enviará una invitación por correo válida por 72 horas."
- Error al desactivar al último administrador: "No puedes desactivar al único administrador de la clínica."
```

### Prompt I2 — Configuración de la clínica

```text
Sigue el Bloque base, el layout base y el design system del proyecto.

Diseña la pantalla CONFIGURACIÓN DE LA CLÍNICA con navegación secundaria a la izquierda (Datos de la clínica, Presupuestos, Agenda y portal, Plan).
- Datos de la clínica: logotipo (PNG o JPG, máx. 1 MB, con vista previa), razón social, RUC (solo lectura), dirección, teléfono, correo de contacto.
- Presupuestos: "Los precios incluyen IGV" (interruptor), "Tope de descuento sin autorización del administrador (%)", "Vigencia del presupuesto (días)", "Condiciones que se imprimen en el presupuesto" (texto).
- Agenda y portal: "Horas mínimas de anticipación para cancelar desde el portal", "Permitir que los pacientes reserven desde el portal" (interruptor).
- Plan (solo lectura): "Plan Pro", funciones incluidas con check (IA generativa, Predicción de riesgo) y límites.
Botón "Guardar cambios" en una barra inferior fija, visible solo si hay cambios.
```

### Prompt I3 — Catálogo de procedimientos y horarios

```text
Sigue el Bloque base, el layout base y el design system del proyecto.

Artboard 1, CATÁLOGO DE PROCEDIMIENTOS: tabla con código, nombre, precio (S/), marcas "Requiere pieza", "Requiere superficies", "Requiere consentimiento informado", hallazgo resultante, estado (Activo / Inactivo). Botón "Nuevo procedimiento". Un procedimiento usado en planes no se elimina: su acción es "Desactivar", con el tooltip "Está en uso en planes o presupuestos".

Artboard 2, HORARIO DEL ODONTÓLOGO: selector de odontólogo, grilla semanal (lunes a sábado) con franjas editables (p. ej. 08:00–13:00 y 15:00–20:00), botón "Agregar bloqueo" (fecha, rango horario, motivo).
Modal de impacto: "Este bloqueo afecta 3 citas activas" con una fila por cita y una decisión obligatoria para cada una ("Reprogramar" con nuevo horario o "Cancelar"). Botón "Aplicar bloqueo y decisiones".
```

---

## 10. Administración de la plataforma (E1 · MS-01)

### Prompt J1 — Clínicas (Súper Administrador)

```text
Sigue el Bloque base y el design system del proyecto.

Diseña el panel del SÚPER ADMINISTRADOR (/admin), con sidebar propio: Clínicas, Planes, (más adelante) Auditoría, Incidentes, Estado del servicio. Etiqueta "Plataforma" en la barra superior. Este rol NO ve datos clínicos de ningún paciente.
- Pantalla CLÍNICAS: buscador, filtros por estado y plan, tabla con nombre, RUC, código de acceso, plan, odontólogos activos / máximo, estado (Activa, Suspendida, Cancelada, Eliminada), fecha de alta y acciones.
- Modal "REGISTRAR CLÍNICA": razón social, nombre comercial, RUC (validación con error junto al campo "RUC inválido"), dirección, teléfono, correo de contacto, código de acceso (se muestra la URL resultante denticore.pe/c/sonrisa-andina), plan, y "Correo del administrador". Nota: "El administrador recibirá una invitación para activar su cuenta. No se define contraseña aquí."
- Detalle de clínica con acciones "Suspender" (motivo obligatorio y confirmación), "Reactivar" y "Cambiar plan". Error de cambio de plan: "La clínica tiene 4 odontólogos activos y el plan Basic permite 2. Desactiva 2 antes de cambiar."
```

---

## 11. Portal del paciente (E1 · MS-06) — móvil primero

### Prompt K1 — Portal: inicio y citas

```text
Sigue el Bloque base y el design system del proyecto.

Diseña el PORTAL DEL PACIENTE (/c/sonrisa-andina/portal) para teléfono de 390px de ancho, pensado también para personas mayores: texto base de 16px o más, botones de 48px de alto, frases cortas, alto contraste.
- Encabezado: logo y nombre de la clínica. Si el usuario representa a un menor, selector "Estás viendo: Mateo Quispe (hijo)".
- Navegación inferior con 4 pestañas: Inicio, Citas, Presupuestos, Mi salud.
- INICIO: saludo "Hola, María", card "Tu próxima cita: lunes 12/10/2026, 10:30 · Dr. Luis Paredes" con botones "Confirmar" y "Reprogramar", card "Tienes 1 presupuesto por revisar", accesos a "Mis consentimientos".
- CITAS: lista de próximas y pasadas con badge de estado y texto; botón "Reservar cita". Flujo de reserva en pasos: tipo de cita, odontólogo, día (calendario) y horarios disponibles como botones grandes. Nota de política: "Puedes cancelar hasta 24 horas antes."
- Pie: enlace "Libro de Reclamaciones en Salud".
```

### Prompt K2 — Portal: presupuestos, consentimiento e historial por pieza

```text
Sigue el Bloque base y el design system del proyecto. Mismo portal móvil de 390px.

1) PRESUPUESTO: "Presupuesto P-000124", badge "Por decidir", "Vence el 04/11/2026", lista de procedimientos con pieza y monto, total grande "S/ 1,593.00 (incluye IGV)", botones "Descargar PDF", "Aceptar presupuesto" (principal) y "Rechazar". Modal de confirmación: "Al aceptar, autorizas a la clínica a realizar estos tratamientos." Si el titular es menor, los botones no aparecen y se muestra "Solo tu representante legal puede aceptar este presupuesto."
2) MIS CONSENTIMIENTOS: las 5 finalidades como filas con interruptor (la de atención odontológica bloqueada y marcada como obligatoria), explicación de una línea cada una, y "Guardar mis preferencias".
3) MI SALUD, historial por pieza: odontograma simplificado solo de consulta (azul y rojo NTS 188 con siglas y una leyenda en lenguaje sencillo). Al tocar una pieza, lista cronológica de hallazgos sin notas clínicas del odontólogo.
```

---

## 12. Pagos (E3 · MS-11, diseñar después)

### Prompt L1 — Abonos, recibo y estado de cuenta

```text
Sigue el Bloque base, el layout base y el design system del proyecto.

Diseña la pantalla ESTADO DE CUENTA del paciente, rol Recepción:
- Resumen: Total aceptado, Abonado, Saldo pendiente (grande, números tabulares).
- Tabla por presupuesto aceptado, con sus abonos: N° de recibo (R-000058), fecha, medio (Efectivo, Tarjeta, Transferencia, Yape/Plin), referencia, monto, estado (Vigente / Anulado tachado con motivo).
- Modal "REGISTRAR ABONO": presupuesto, saldo visible, monto (no puede superar el saldo), medio de pago, referencia obligatoria si no es efectivo. Al guardar: "Recibo R-000058 generado" con "Descargar recibo". Nota fija: "Recibo interno, no es comprobante tributario."
- Modal "ANULAR ABONO" (solo administrador): motivo obligatorio y nota "El número de recibo no se reutiliza."
Artboard adicional: "CAJA DEL DÍA" con total por medio de pago y lista de abonos del día.
```

---

## 13. Qué revisar en cada pantalla generada

Antes de aceptar una pantalla de Stitch, verifica:

- [ ] Todo el texto está en español del Perú, sin restos en inglés ("Dashboard", "Submit", "Save").
- [ ] No aparecen SSO, "Sign up", fotos de stock ni degradados.
- [ ] Los badges llevan texto e ícono, no solo color.
- [ ] El odontograma usa solo azul y rojo con sigla; nada de teal ni verde.
- [ ] La franja del paciente (nombre, edad, HC, alergias) está visible en toda pantalla clínica.
- [ ] Las acciones irreversibles (emitir presupuesto, cerrar atención, cancelar plan, anular abono, revocar consentimiento) tienen modal de confirmación con sus efectos.
- [ ] Montos en `S/ 1,234.56`, fechas `dd/MM/yyyy`, hora 24 h.
- [ ] Los enlaces usan `#0369A1`, no `#0EA5E9`.
- [ ] En el portal: texto ≥ 16px y botones ≥ 44px.

## 14. Trazabilidad

| Prompt | CUS | Requisitos clave | Entrega |
| :-- | :-- | :-- | :-- |
| A1–A6 | CUS-06 a CUS-09, CUS-11 | RF-032 a RF-040, DD-15, DD-29, RNF-065 | E1 |
| B1 | CUS-10, CUS-53 | RNF-065, RN-07, DD-02 | E1 |
| B2 | CUS-77 | RF-154 | E3 |
| C1–C3 | CUS-44 a CUS-51, CUS-77 | RN-46 a RN-52, RN-82, estados §5.5.4 | E1 |
| D1–D3 | CUS-13 a CUS-17 | RN-09 a RN-15, RNF-191 | E1 |
| E1–E3 | CUS-21 a CUS-27, CUS-80, CUS-81 | RN-16 a RN-25, RN-77, RNF-061, RNF-146, RNF-149, RNF-151, RNF-153 | E1 |
| G1–G3 | CUS-33 a CUS-40, CUS-82, CUS-83 | RN-26 a RN-39, RNF-063, estados §5.5.2 y §5.5.3 | E1 |
| F1–F2 | CUS-54 a CUS-58 | RN-58 a RN-65, RNF-074, §3.5 del SDD | E1 |
| I1–I3 | CUS-04, CUS-11, CUS-32, CUS-44, CUS-45 | RN-31, RN-75, RN-82, DD-16 | E1 |
| J1 | CUS-01 a CUS-03 | RF-013 a RF-023, DD-22 | E1 |
| K1–K2 | CUS-17, CUS-24, CUS-36, CUS-37, CUS-46 a CUS-49 | RNF-059, RNF-062, RNF-164, DI-13 | E1 |
| L1 | CUS-41 a CUS-43, CUS-87 | RN-40 a RN-45 | E3 |
