# Especificación Funcional: DentiCore

> Documento derivado de `SDD_DentiCore.md`. Describe QUÉ hace el sistema y para QUIÉN, en términos de negocio y usuario final. Para el CÓMO (arquitectura, esquema de datos, endpoints), ver `technical_specs.md`.

## 1. Visión general

DentiCore es una plataforma SaaS multi-clínica (multi-tenant) para consultorios dentales que digitaliza:

- El **odontograma evolutivo** del paciente (historial de estados por pieza dental, nunca se pierde información previa).
- La **generación de presupuestos** estandarizados y trazables para tratamientos.
- La **predicción de riesgo de caries** de un paciente mediante un modelo de Machine Learning, con explicación comprensible para el odontólogo (no una caja negra).
- El **diagnóstico y sugerencia de tratamiento asistidos por IA** a partir de notas clínicas en texto libre, siempre sujetos a validación humana del odontólogo.
- La **gestión de citas** sin dobles reservas.
- **Encuestas de satisfacción** y **seguimiento clínico** posterior a las predicciones de riesgo.

Cada clínica (tenant) opera de forma completamente aislada: el personal de una clínica nunca puede ver datos de otra.

## 2. Roles de usuario y qué puede hacer cada uno

| Rol | Quién es | Qué puede hacer |
| :-- | :-- | :-- |
| **super_admin** | Administrador de la plataforma (no pertenece a ninguna clínica) | Registrar nuevas clínicas, gestionar rotación de claves de cifrado, ver reportes de cumplimiento normativo y de auditoría global, monitorear degradación del servicio en toda la plataforma |
| **clinic_admin** | Administrador de una clínica | Gestiona el personal de su clínica y sus permisos, ve reportes de distribución de riesgo de sus pacientes, gestiona catálogo de tratamientos, ficha pacientes, genera y aprueba presupuestos, consulta predicciones de riesgo |
| **dentist** (odontólogo) | Profesional clínico | Diagnostica, actualiza el odontograma, valida/ajusta/rechaza sugerencias de IA (diagnóstico y tratamiento), registra variables clínicas/conductuales de riesgo, ejecuta predicciones de riesgo y ve su explicación, gestiona alertas de riesgo alto, registra seguimiento clínico posterior |
| **receptionist** (recepcionista) | Personal administrativo de clínica | Ficha pacientes, registra variables sociodemográficas de riesgo, emite presupuestos, gestiona citas (agenda, check-in), registra encuestas |
| **patient** (paciente) | Usuario final, opcional (portal propio) | Consulta su propio historial/odontograma (solo lectura), ve y acepta/rechaza sus propios presupuestos, responde encuestas |
| **ml_service** | Actor no humano (credencial de servicio) | Es el motor de ML que responde a las solicitudes de predicción; no es un rol de usuario sino un cliente autenticado por API key |

> Regla general: un usuario solo puede operar dentro de su propia clínica. El paciente solo puede ver sus propios registros. El `super_admin` es el único que trabaja fuera del contexto de una clínica.

## 3. Módulos funcionales

### 3.1 Gestión de clínicas (tenants)
- Alta de nuevas clínicas en la plataforma (solo `super_admin`).
- Cada clínica tiene su propio plan de suscripción (`basic`, `pro`, `enterprise`) y estado (`active`, `suspended`, `cancelled`).
- Cada clínica cuenta con su propia clave de cifrado, rotable, para proteger datos sensibles de sus pacientes.

### 3.2 Gestión de usuarios y permisos por clínica
- El administrador de clínica da de alta personal (odontólogos, recepcionistas) y asigna roles.
- El email de un usuario es único **dentro de su clínica**, no a nivel global (dos clínicas distintas pueden tener personal con el mismo email).

### 3.3 Ficha de paciente
- Registro de datos personales, documento de identidad (protegido), antecedentes médicos generales.
- Un paciente puede opcionalmente tener acceso a un portal propio para consultar su información.

### 3.4 Odontograma evolutivo
- Representa el estado de cada una de las piezas dentales del paciente (notación FDI, máximo 32 piezas).
- **Nunca se sobrescribe el historial**: cada cambio de estado de una pieza queda registrado permanentemente y de forma cronológica, con quién lo hizo, cuándo y por qué (diagnóstico manual o sugerido por IA).
- El odontólogo siempre puede consultar la evolución completa de una pieza dental a lo largo del tiempo.
- Solo el odontólogo puede modificar el odontograma; los demás roles solo pueden consultarlo.

### 3.5 Diagnóstico y tratamiento asistidos por IA
- El odontólogo puede ingresar una nota clínica en texto libre y recibir una sugerencia estructurada de diagnóstico (pieza, estadio, grado, extensión de la lesión).
- A partir del diagnóstico, el sistema puede sugerir un plan de tratamiento.
- **La IA nunca decide por sí sola**: toda sugerencia debe ser explícitamente aceptada, ajustada o rechazada por el odontólogo antes de tener efecto clínico.

### 3.6 Presupuestos
- Se genera un presupuesto a partir de los tratamientos requeridos para un paciente, usando los precios vigentes del catálogo de la clínica.
- **Una vez emitido, un presupuesto es inmutable**: su monto y líneas no cambian aunque el precio del catálogo cambie después. Cualquier corrección implica emitir un presupuesto nuevo.
- El paciente (o la recepción en su nombre) puede aceptar o rechazar el presupuesto.
- Se mide el tiempo que toma generar un presupuesto, como indicador de eficiencia operativa.
- El presupuesto puede descargarse como PDF.

### 3.7 Predicción de riesgo de caries
- A partir de variables sociodemográficas (edad, ingreso familiar, educación de los padres, situación laboral, estructura familiar), clínicas (placa, severidad de caries) y conductuales (consumo de azúcar, uso de pasta fluorada, frecuencia de visitas) del paciente, el sistema predice un nivel de riesgo (`bajo`, `medio`, `alto`).
- Cada predicción incluye:
  - Un **puntaje de riesgo**.
  - Un **indicador de confianza**, que siempre se muestra junto al resultado — la predicción **no es un diagnóstico definitivo**, es una herramienta de apoyo.
  - Una **explicación comprensible** de qué factores influyeron más en el resultado (para que el odontólogo entienda el "por qué", no solo el "qué").
- Si el sistema de predicción no está disponible o falla, la atención clínica **nunca se bloquea**: el odontólogo puede seguir trabajando con normalidad y el sistema simplemente informa que la predicción no está disponible por el momento.
- Si el riesgo predicho es **alto**, se genera automáticamente una alerta dirigida al odontólogo, que debe reconocerla. Para riesgo bajo o medio no se genera alerta.
- Existe seguimiento clínico posterior para registrar si las predicciones se cumplieron en la realidad (nuevas lesiones o tratamientos reales), lo cual sirve para evaluar la calidad del modelo con el tiempo.
- La clínica puede consultar reportes de distribución de riesgo de sus pacientes.

### 3.8 Gestión de citas
- Programación de citas entre paciente y odontólogo.
- **No se permite doble reserva**: un mismo odontólogo no puede tener dos citas superpuestas en el mismo horario; el sistema rechaza el intento con un mensaje de conflicto.
- Registro de check-in y estado de la cita (programada, en curso, completada, cancelada, no-show).
- Registro de si el pago de la cita fue confirmado.

### 3.9 Encuestas de satisfacción
- Se pueden aplicar encuestas sobre percepción del historial clínico y sobre satisfacción con el presupuesto recibido.
- Las responde el paciente (o la recepción en su nombre).

### 3.10 Auditoría y cumplimiento
- Se registra un historial de accesos y acciones relevantes (quién hizo qué, cuándo, desde dónde).
- El `super_admin` y el `clinic_admin` (limitado a su propia clínica) pueden consultar esta auditoría.
- Existe un reporte de cumplimiento normativo disponible solo para `super_admin`.

### 3.11 Monitoreo de desempeño
- Se registran alertas cuando algún indicador de desempeño del servicio se degrada más allá de un umbral (por ejemplo, tiempos de respuesta), visibles para `super_admin` y, de forma limitada a su clínica, para `clinic_admin`.

## 4. Reglas de negocio clave (vista funcional)

1. **Aislamiento total entre clínicas**: ningún usuario de una clínica puede ver o modificar datos de otra clínica, sin excepción. Es un requisito no negociable.
2. **Trazabilidad del odontograma**: el historial de cada pieza dental es permanente; nada se borra ni se sobrescribe.
3. **Presupuestos inmutables**: un presupuesto emitido refleja el precio del momento en que se emitió, para siempre.
4. **La IA asiste, no decide**: toda sugerencia de diagnóstico o tratamiento generada por IA requiere validación explícita del odontólogo.
5. **La predicción de riesgo nunca es bloqueante**: si el servicio de IA falla, la atención al paciente continúa con normalidad.
6. **Transparencia del riesgo**: toda predicción de riesgo se acompaña de su nivel de confianza y una explicación de los factores que la determinaron.
7. **Sin dobles reservas**: la agenda de un odontólogo no admite solapamientos.
8. **Cada acción sensible queda auditada.**

## 5. Expectativas no funcionales (vistas desde el usuario)

- Un presupuesto debe generarse en un tiempo percibido como instantáneo (objetivo: ≤ 2 segundos en el 95% de los casos).
- La predicción de riesgo debe responder rápido o, si no puede, avisar de inmediato que no está disponible (no dejar al usuario esperando indefinidamente).
- Los datos sensibles del paciente (documento de identidad, contacto) deben estar protegidos en todo momento, tanto almacenados como en tránsito.
- El sistema debe seguir funcionando con normalidad para las tareas clínicas del día a día aunque el componente de IA esté caído.
