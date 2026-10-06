# Plan de Pruebas — DentiCore

> **Plan de Pruebas de Software unificado (ISO 9001 + ISO/IEC 25010:2023 + ISO/IEC 25001 + ISO/IEC/IEEE 29119)**
> Abarca las cuatro entregas E1–E4 definidas en `SRS_DentiCore.md` §17.

| Campo | Valor |
| :-- | :-- |
| Código del documento | TP-DC-001 |
| Proyecto | DentiCore — Plataforma SaaS multi-clínica de odontograma evolutivo |
| Versión del plan | v1.0 |
| Fecha | 2026-09-22 |
| Línea base de requisitos | `SRS_DentiCore.md` v1.0 (§18) |
| Estándares de referencia | ISO 9001 (gobierno y mejora continua) · ISO/IEC 25010:2023 (calidad de producto) · ISO/IEC/IEEE 29119-2 y 29119-3 (procesos y artefactos de prueba) · ISO/IEC 25001/SQuaRE (métricas y evaluación) |
| Normativas verificables | REF-01 a REF-09 del SRS (Ley N° 29733, DS N° 016-2024-JUS, NTS N° 139, NTS N° 188, Ley N° 27878, Ley General de Salud, CIE-10, Directiva de la ANPD, REF-09 consumidor) |
| Equipo | Blas Puente Juancito Alexis (Líder) — Carvo Mendez Orlando Alexander — Sánchez Ramos Carlos Alonso |
| Curso | Pruebas y Calidad de Software — Universidad Continental |

---

## 0. Reglas de este documento

1. **El SRS manda.** Este plan no redefine ningún requisito. Todo umbral, perfil de carga o criterio se cita con su identificador del SRS. Si este plan y el SRS discrepan, prevalece el SRS (SRS §1.1).
2. **Alcance total:** este plan cubre **E1–E4** (201 RF, 194 RNF). La ejecución se hace por entrega, con criterios de salida independientes (§5).
3. **Trazabilidad obligatoria:** toda prueba referencia al menos un ID (`RF-`, `RNF-`, `RN-`, `CA-`, `CUS-`). Una prueba sin requisito se elimina; un requisito sin prueba bloquea la entrega (SRS RNF-005, RNF-128).
4. **Sin datos reales.** Toda ejecución usa datos sintéticos o públicos anonimizados (RES-08). Ningún dato real de pacientes entra antes de aceptar E2 (SRS §17.1).
5. **ISO 9001 en versión proporcional.** Dado RES-07 (3 personas, 12 semanas), el gobierno de calidad se aplica con roles nombrados, control de versiones y registro de cambios simplificado, sin comité formal de cambios (CAB).

---

## 1. Contexto, alcance y gobierno de calidad *(ISO 9001 Cap. 4–5 · ISO 29119-3 §1)*

### 1.1 Partes interesadas

| Parte interesada | Interés en las pruebas | Entrega que evalúa |
| :-- | :-- | :-- |
| Docente Mg. Maglioni Arana Caparachin | Evidencia de verificación de requisitos del curso | **E1** |
| Equipo de desarrollo (3 personas) | Detectar defectos antes de la entrega, regresión continua | E1–E4 |
| Futuro responsable del producto | Reproductibilidad del plan y de sus registros | E1–E4 |
| Clínica piloto (titular de datos) | Que no entren datos reales antes de E2; aislamiento y cumplimiento | **E2** |
| Pacientes (representados por la clínica) | Privacidad, consentimientos, ARCO, NTS 139 | E2 y E3 (portal) |
| Cirujano dentista validador (PQ-05) | Conformidad clínica del odontograma y de los catálogos | E1 (parcial) y **E2** |
| Proveedor de nube | Exigencia ISO/IEC 27001 al proveedor (REF-21, DD-42) | E2 |

### 1.2 Alcance del plan por entrega

| Entrega | Objetivo | RF | RNF | Pruebas que la caracterizan | Criterio de aceptación de la entrega |
| :-- | :-- | :-: | :-: | :-- | :-- |
| **E1 — Curso** | Demostrar el flujo completo con datos sintéticos | 115 | 50 | E2E del flujo cita → HC → riesgo → plan → presupuesto → procedimiento; aislamiento; desempeño principal; seguridad básica | Todos los RF en verde + todos los RNF con criterio cumplido (SRS §17.1) |
| **E2 — Piloto** | Poder tratar datos reales en una clínica | 15 | 58 | ARCO, retención, copia de HC, incidentes 48 h, **simulacro RPO/RTO**, pruebas de seguridad, contratos, **validación clínica NTS 188/139** | Checklist normativo 100 % + informe de simulacro + firma del validador |
| **E3 — Comercial** | Operar con varias clínicas y planes | 59 | 76 | Pagos, IA generativa (incl. evaluación de extracción y adversarial), portal del paciente, importación/exportación, observabilidad, usabilidad moderada | Umbrales de IA/ML cumplidos + pruebas de usabilidad con ≥ 5 participantes |
| **E4 — Mejoras** | Diferenciación | 12 | 10 | Indicadores y encuestas, lista de espera, fusión de fichas, enlace compartido | Mismo criterio estándar; regresión completa de E1–E3 en verde |
| **Total** | | **201** | **194** | | |

**Fuente de los rangos:** SRS §17.1 y §17.4.

### 1.3 Fuera del alcance de pruebas

| Exclusión | Motivo |
| :-- | :-- |
| Validación clínica prospectiva del modelo de riesgo | OUT-06: requiere estudio clínico y comité de ética. Se prueban integración, calibración, contrato y manejo de errores |
| Datos reales de pacientes en desarrollo/pruebas | RES-08 |
| Facturación SUNAT, pasarela de pago, DICOM, telemedicina, móvil nativo, WhatsApp/SMS | OUT-01 a OUT-16 (SRS §3.5): no hay código que probar |
| Certificación ISO/IEC 27001 del producto y cumplimiento HIPAA/GDPR | Excluidos por SRS §13.19 |
| Navegadores sin soporte (Internet Explorer, versiones antiguas) | Excluido por SRS §13.19 |

### 1.4 Roles de prueba *(ISO 9001 §5.3 — simplificados por RES-07)*

| Rol | Responsable | Atribuciones |
| :-- | :-- | :-- |
| Líder de QA (rotativo entre los 3 integrantes por entrega) | Aprueba la entrada y salida de cada entrega, firma el informe de cierre, gestiona la severidad de los defectos |
| Diseñador/Tester | Diseña casos a partir de RF/CA, automatiza, registra defectos |
| Administrador de configuración (rotativo) | Mantiene el pipeline en verde, versiones SemVer, datos sintéticos y entorno de pruebas |
| Validador clínico (externo, PQ-05) | Firma las listas de verificación NTS 188 y NTS 139 (RNF-160, RNF-161) |

### 1.5 Control de la información documentada *(ISO 9001 §7.5)*

- Este plan se versiona con SemVer (`TP-DC-001 v1.0`); todo cambio se registra en la tabla de control de versiones del §12.
- Registros generados: diseños de prueba, casos, ejecuciones, defectos e informes de cierre, almacenados en el repositorio junto al código que prueban.
- Aprobación de cierre en §11.

---

## 2. Especificación cuantitativa de requisitos de calidad y métricas *(ISO/IEC 25001 / SQuaRE)*

### 2.1 Objetivos de calidad por entrega

| ID | Objetivo | Métrica | Umbral | Aplica a |
| :-- | :-- | :-- | :-- | :-- |
| OQ-01 | Cobertura funcional | % de RF *Must* con ≥ 1 prueba automatizada que cita su ID | 100 % | E1, E2 (RNF-005) |
| OQ-02 | Cobertura de criterios de aceptación | % de los 78 CA de SRS §11 automatizados | 100 % | E1, E2 (RNF-005) |
| OQ-03 | Trazabilidad de reglas | % de RN con ≥ 1 prueba que cita su ID | 100 % | E1–E4 (RNF-128) |
| OQ-04 | Cobertura de código (backend) | Líneas cubiertas / servicios críticos | ≥ 80 % general; **≥ 95 %** en aislamiento, odontograma, presupuestos, pagos, citas y riesgo | E1–E4 (RNF-126) |
| OQ-05 | Cobertura de código (frontend / ML) | Vitest / pytest | ≥ 70 % frontend; ≥ 80 % motor ML | E1–E4 (RNF-126) |
| OQ-06 | Calidad de las pruebas de servicios críticos | Índice de mutación (MSI, Infection) | ≥ 70 % | E1–E4 (RNF-127) |
| OQ-07 | Aislamiento multi-tenant | Fugas detectadas en la suite de aislamiento (100 % de endpoints con datos de clínica, trabajos en cola, exportaciones, PDF y búsquedas) | 0 | E1 (RNF-101, OB-10) |
| OQ-08 | Desempeño | p95 de lecturas bajo PC-N | ≤ 300 ms (p99 ≤ 800 ms) | E1 (RNF-006) |
| OQ-09 | Defectos al liberar | Defectos abiertos de severidad Crítica o Alta | 0 | E1–E4 (RNF-073) |
| OQ-10 | Tiempo de la suite | Unitarias + integración + arquitectura + contrato / E2E críticas | ≤ 15 min / ≤ 20 min | E1–E4 (RNF-129) |
| OQ-11 | Conformidad normativa | Checklists NTS 188, NTS 139 y Ley 29733 | 100 % cumplidos y firmados | **E2** (RNF-160 a RNF-162) |
| OQ-12 | Recuperación | Simulacro de restauración (tiempo e integridad) | RPO ≤ 15 min; RTO ≤ 4 h | **E2** (RNF-081, RNF-082, RNF-084) |
| OQ-13 | Seguridad | Hallazgos SAST/DAST de severidad Alta o Crítica | 0 abiertos al liberar | E1, E2 (REF-13, OWASP ASVS 4.0.3 nivel 2) |
| OQ-14 | Modelo ML | AUC-ROC de validación | ≥ 0,75 (meta 0,79) | E1 (RNF-165) |
| OQ-15 | Usabilidad percibida | SUS tras la prueba moderada | ≥ 68 | E3 (REF-20) |

### 2.2 Condiciones de medición *(SRS §13.2 — obligatorias para todo umbral de tiempo o capacidad)*

**Entorno de referencia (ER)** — mínimo en staging; producción no puede tener menos (DD-43):

| Componente | Capacidad mínima |
| :-- | :-- |
| API Laravel 13 (PHP 8.3 + OPcache) | 2 instancias de 2 vCPU / 4 GB detrás de balanceador |
| Workers de colas | 1 instancia de 2 vCPU / 2 GB |
| PostgreSQL 16 | 4 vCPU, 16 GB RAM, SSD ≥ 3 000 IOPS |
| Redis 7 | 1 GB |
| Motor ML (FastAPI) | 1 instancia de 2 vCPU / 2 GB |
| Generador de carga | Misma región; latencia ≤ 5 ms al balanceador |

**Conjunto de datos de referencia (CDR)** — semilla reproducible (RES-08): 50 clínicas (49 con 2 000 pacientes y 1 con 20 000; 118 000 pacientes), ~944 000 atenciones, ~2 950 000 entradas de odontograma, ~1 180 000 citas, ~236 000 presupuestos, 12 meses de bitácora.

**Perfiles de carga:**

| ID | Perfil | Definición |
| :-- | :-- | :-- |
| PC-N | Normal | 20 clínicas × 10 usuarios concurrentes (200 virtuales), reflexión 10–20 s; mezcla 40 % lectura de HC y agenda, 20 % búsqueda, 15 % registro clínico, 10 % citas, 10 % presupuestos y abonos, 5 % otras; 30 min de medición tras 5 min de calentamiento |
| PC-P | Pico por clínica | PC-N + 1 clínica con 50 usuarios concurrentes |
| PC-C | Capacidad | +100 usuarios cada 5 min hasta 1 000 o hasta superar el p95 objetivo |
| PC-R | Resistencia | PC-N sostenido 8 h |
| PC-E | Concurrencia | 50 solicitudes simultáneas sobre el mismo horario de cita o el mismo presupuesto |

**Definiciones de medición (resumen):** percentiles p95/p99 con ≥ 1 000 muestras por operación en periodo estable; disponibilidad = (minutos del mes − indisponibilidad no planificada) ÷ minutos del mes, con 2 comprobaciones consecutivas fallidas desde 2 ubicaciones; *carga normal* = PC-N. Detalle completo en SRS §13.2.4.

### 2.3 Severidad de defectos *(SRS §13.2.4)*

| Severidad | Definición | Respuesta | Resolución requerida antes de liberar |
| :-- | :-- | :-- | :-- |
| **Crítica** | Fuga de datos, pérdida de datos o plataforma inutilizable | ≤ 30 min (horario clínico) | Sí — bloquea la salida |
| **Alta** | Una función *Must* no se puede completar | ≤ 2 h | Sí — bloquea la salida (RNF-073) |
| **Media** | Hay alternativa | ≤ 1 día hábil | No; se registra y planifica |
| **Baja** | Cosmética | ≤ 5 días hábiles | No |

### 2.4 Métricas del proceso de prueba *(ISO 9001 §9.1 · ISO 25001)*

| KPI | Fórmula | Meta | Revisión |
| :-- | :-- | :-- | :-- |
| Tasa de éxito de la suite | pruebas PASS ÷ ejecutadas | 100 % al cierre de la entrega | Cada CI |
| Cobertura de RF | RF con prueba ÷ RF de la entrega | 100 % | Por entrega |
| Cobertura de CA | CA automatizados ÷ 78 | 100 % (E1/E2) | Por entrega |
| Densidad de defectos | defectos ÷ KLOC o ÷ RF probados | Tendencia decreciente entre entregas | Informe de cierre |
| Reapertura de defectos | reabiertos ÷ corregidos | ≤ 10 % | Informe de cierre |
| Retención de mutación | MSI en servicios críticos | ≥ 70 % | Semanal |

---

## 3. Estrategia de pruebas por atributos de calidad *(ISO/IEC 25010:2023 + categorías complementarias)*

> La clasificación sigue **ISO/IEC 25010:2023 (REF-11)**: **9 características**, incluida **Protección (*safety*)**, más **4 categorías complementarias** definidas en SRS §13.1. Cada fila indica el rango de RNF, la entrega donde se ejecuta y el método de verificación.

### 3.1 Calidad de producto (9 características)

| # | Característica (25010:2023) | RNF (SRS §13) | Eje de prueba | Método y evidencia | Entrega |
| :-- | :-- | :-- | :-- | :-- | :-- |
| 1 | **Adecuación funcional** | RNF-001 a RNF-005 | Aritmética decimal exacta (10 000 casos aleatorios); fechas y plazos con reloj simulado (fin de mes, 29-feb, cumpleaños 18); 364 combinaciones FDI × superficie; dibujo NTS 188; 100 % de RF *Must* y 78 CA | Prueba basada en propiedades en CI; reloj simulado; prueba paramétrica exhaustiva; **regresión visual automatizada + revisión clínica firmada**; informe de trazabilidad RF → prueba | E1 + revisión clínica en **E2** |
| 2 | **Eficiencia de desempeño** | RNF-006 a RNF-036 | Lecturas p95 ≤ 300 ms; listados ≤ 500 ms; escrituras ≤ 600 ms; login ≤ 1 s; HC API ≤ 1 s y extremo a extremo ≤ 3 s; presupuesto: recálculo ≤ 500 ms y emisión ≤ 2 s; PDF ≤ 30 s y copia de HC ≤ 60 s; predicción ≤ 3 s (≤ 300 ms con Circuit Breaker abierto); disponibilidad de agenda ≤ 500 ms/1,5 s; reserva ≤ 800 ms; notificaciones en su ventana; tareas programadas en su ventana; límite 1 200 req/min por clínica; CPU/memoria/pool de conexiones; Core Web Vitals LCP/INP/CLS; presupuesto de bundle ≤ 300 KB | **k6** en ER con CDR bajo PC-N/PC-P/PC-C/PC-R/PC-E; **Playwright** con trazas para tiempos de navegador; **Lighthouse CI**; monitoreo de recursos; revisión de `EXPLAIN` en CI | E1 (núcleo) · E3 (informes y panel) |
| 3 | **Compatibilidad** | RNF-042 a RNF-053 | Coexistencia (rate limit por clínica y colas separadas); contrato OpenAPI 3.1 del 100 % de endpoints; fechas ISO 8601 e importes decimales; CSV/XLSX; correos en Gmail/Outlook/Apple Mail con SPF/DKIM/DMARC; iCalendar; matriz de navegadores; 360/768/1280/1920 px sin scroll horizontal; PDF A4 imprimibles | Prueba de contrato en CI; apertura en Excel (es-PE), LibreOffice y Google Sheets; verificación DNS; **E2E con Playwright en cada navegador** | E1 (navegadores y contratos) · E3 (importación/exportación, iCalendar) |
| 4 | **Capacidad de interacción** (usabilidad) | RNF-054 a RNF-069 | Inducción ≤ 30 min; registro de hallazgo mediana ≤ 60 s y ≥ 4/5 participantes sin ayuda; reserva ≤ 60 s y ficha con consentimiento ≤ 4 min; recuento de acciones por tarea; accesibilidad **WCAG 2.1 AA** (REF-14); SUS ≥ 68; CSAT/NPS del portal | **Pruebas de usabilidad moderadas con ≥ 5 participantes** (odontólogos o estudiantes de últimos ciclos); guion de tareas; axe/Lighthouse de accesibilidad; cuestionario SUS | **E1** (hallazgo) · **E3** (recepción, portal, SUS) |
| 5 | **Fiabilidad** | RNF-070 a RNF-089 | Disponibilidad ≥ 99,5 % mensual; mantenimiento fuera de horario clínico; 5xx < 0,1 %; **tolerancia a fallos**: motor ML caído (100 % de CUS distintos de CUS-55 operativos), proveedor de IA caído, SMTP caído, Redis caído; outbox transaccional (0 efectos perdidos o huérfanos); claves de idempotencia en 5 operaciones; **RPO ≤ 15 min y RTO ≤ 4 h**; respaldos cifrados AES-256 y separados; simulacro trimestral; restauración por clínica ≤ 8 h; tareas no ejecutadas recuperadas; NTP ≤ 1 s; invariantes diarios | **Pruebas de caos en staging** (servicios detenidos); fallos inyectados entre transacción y cola; **simulacro de restauración con evidencia**; reloj simulado; prueba de invariantes con datos manipulados | E1 (tolerancia a fallos) · **E2** (RPO/RTO/respaldos) |
| 6 | **Seguridad** (*security*) | RNF-090 a RNF-118 | Cifrado AES-256 en reposo y TLS 1.2+ con HSTS; **0 fugas entre tenants** (100 % de endpoints, trabajos, exportaciones y PDF) y RLS por `tenant_id`; archivos no confiables (EICAR, ≤ 10 MB, antivirus, URL firmada ≤ 10 min); inyección SQL/HTML; consultas masivas anómalas; MFA y mínimo privilegio; separación de entornos; servicios internos fuera de internet; **0 secretos en el repositorio**; logs sin datos personales; límite de 5 intentos sobre códigos; enlaces ≥ 128 bits con hash; evidencias selladas HMAC; bitácora inmutable de solo inserción; **OWASP ASVS 4.0.3 nivel 2 (REF-13)**; plan de respuesta a incidentes con notificación ≤ 48 h | **Suite de aislamiento en CI**; SAST + escaneo de secretos en CI; DAST en staging; pruebas automatizadas por tipo de evento de auditoría; prueba con evidencia alterada; revisión de accesos | E1 (aislamiento y SAST) · **E2** (DAST, incidentes, infraestructura) |
| 7 | **Mantenibilidad** | RNF-119 a RNF-136 | Arquitectura por capas verificable (Pest `arch`); módulos M01–M13 independientes; validación clínica centralizada; **Larastan ≥ 6, ESLint, ruff, mypy estricto: 0 errores**; Pint/Prettier/ruff format: 0 diferencias; complejidad ciclomática ≤ 10 en ≥ 95 % de métodos y ninguna > 20, duplicación ≤ 3 %; logs JSON correlacionados; cobertura (OQ-04/OQ-05); MSI ≥ 70 %; **100 % de RN con prueba**; suite ≤ 15 min; **100 % de pruebas temporales con reloj simulado y 0 esperas reales**; migraciones reversibles; SemVer; 100 % de cambios con ≥ 1 revisor y CI verde; OpenAPI sincronizado | Pruebas de arquitectura y estáticas en CI; Infection; regla estática de reloj simulado; revisión de PRs; validación de OpenAPI contra respuestas reales | E1–E4 (continuo) |
| 8 | **Flexibilidad** | RNF-137 a RNF-145 | Escalado horizontal (1 vs 2 instancias, ≥ 70 % más capacidad); escalado de colas (1 vs 3 workers); nuevos canales de notificación sin cambiar eventos; cambios normativos sin tocar lógica; instalación guiada ≤ 60 min; **despliegue ≤ 15 min y reversión ≤ 10 min**; contenedores OCI; proveedores reemplazables por configuración; publicación de modelo sin desplegar backend | Pruebas de carga comparativas; ensayo de despliegue y reversión; prueba con proveedores simulados; RNF-141 con un integrante que no lo configuró | **E2** (despliegue/reversión) · E3 (reemplazabilidad) |
| 9 | **Protección** (*safety*) | RNF-146 a RNF-153 | Identidad del paciente visible en toda pantalla clínica (y aviso de otra pestaña); **0 escrituras clínicas originadas por IA sin decisión del odontólogo**; predicciones vencidas marcadas y sin alertas nuevas; aviso de alergias antes de confirmar procedimientos y al planificar; cambios concurrentes avisados ≤ 30 s; odontograma solo con simbología oficial (sin colores personalizados); integración del modelo solo si cumple su contrato y calidad mínima; origen y autor de cada dato clínico (IA distinguible) | E2E con dos sesiones; consulta de verificación de escrituras IA; inspección + regresión visual; prueba de contrato + prueba de activación del modelo | E1 |

### 3.2 Categorías complementarias (SRS §13.13 a §13.16)

| Categoría | RNF | Eje de prueba | Método y evidencia | Entrega |
| :-- | :-- | :-- | :-- | :-- |
| **Cumplimiento normativo y privacidad** | RNF-154 a RNF-164 | Privacidad desde el diseño y por defecto; contratos de encargo; transferencias internacionales; documentación legal en español; **retención: 0 rutas de eliminación anticipada de datos clínicos** (salvo CUS-70 a los 20 años); **checklist NTS 188 firmada por cirujano dentista**; checklist NTS 139 (contenido mínimo, copia de HC ≤ 5 días); checklist Ley 29733 (consentimiento, ARCO, portabilidad, ODP, incidentes 48 h); precio total con IGV destacado; Libro de Reclamaciones | Revisión documental con checklist al 100 %; prueba automatizada de ausencia de rutas de borrado; inspección de PDF y portal; **firma del validador clínico (PQ-05)** | **E2** (listas de verificación) · E1 (RF de consentimiento y odontograma) |
| **Calidad de los componentes de IA y ML** | RNF-165 a RNF-178 | AUC-ROC ≥ 0,75 (meta 0,79); Brier ≤ 0,20 y error de calibración ≤ 0,05; equidad por subgrupo (Δ AUC ≤ 0,10); **suma SHAP + base = salida con error ≤ 1e-6 en 100 % del conjunto de validación**; determinismo en 1 000 repeticiones; reproducibilidad del entrenamiento (AUC ± 0,005); model card por versión (REF-22); deriva PSI > 0,2 alerta; rechazo de variables fuera de dominio (422); extracción IA precisión ≥ 0,80 / exhaustividad ≥ 0,70 sobre ≥ 50 notas anotadas; **seudonimización: 0 identificadores sobre ≥ 100 notas**; resistencia a prompt injection sobre ≥ 20 notas adversariales; trazabilidad de cada sugerencia | Informes de validación y de equidad por versión; **pruebas automatizadas del motor** (SHAP, determinismo, contrato); evaluación con conjunto de referencia; conjunto adversarial; proveedor simulado | E1 (motor y contrato) · **E3** (evaluación de extracción y adversarial de la IA generativa) |
| **Operación y soporte** | RNF-179 a RNF-187 | Alerta al guardia ≤ 5 min; SLA por severidad (S1: 30 min/4 h; S2: 2 h/1 día; S3: ≤ 5 días); alertas de capacidad al 70 % sostenido 15 min; retención de logs 90 días, métricas 13 meses, bitácora ≥ 5 años; entregabilidad de correo (rebotes < 5 %, spam < 0,1 %); notas de versión; **entornos dev/staging/prod separados y staging con CDR** | Pruebas de alerta simulada; registro de incidentes y casos; revisión de configuración; revisión en cada versión | **E3** · entornos en **E2** |
| **Localización, datos y documentación** | RNF-188 a RNF-194 | Español (es-PE) sin claves faltantes; `dd/mm/aaaa`, 24 h y `S/ 1,234.56`; colación ICU `es-PE`; Unicode completo (incluidos PDF con fuentes Latin Extended); **integridad en base de datos: FK y CHECK para piezas FDI, rangos, estados e inmutabilidad**; material de inducción ≤ 30 min por rol | Verificación automatizada de claves; pruebas de componentes y de ordenamiento; nombres de referencia peruanos; **escritura directa en BD para saltarse la validación de la aplicación**; validación en las pruebas de usabilidad | E1 (formatos e integridad) · E3 (inducción) |

### 3.3 Calidad en uso (ISO/IEC 25010:2023)

| Subcaracterística | Prueba | Evidencia | Entrega |
| :-- | :-- | :-- | :-- |
| **Eficacia** | El odontólogo completa el flujo completo (cita → HC → hallazgo → plan → presupuesto → procedimiento) sin ayuda | E2E de aceptación con datos sintéticos | E1 |
| **Eficiencia en el uso** | Tiempos de tarea del guion (§3.1 fila 4) | Cronometraje en la prueba moderada | E3 |
| **Satisfacción** | SUS (≥ 68) y CSAT/NPS del portal | Cuestionarios y encuesta CSAT | E3 |
| **Ausencia de riesgo** | Riesgo financiero: recibos y saldos cuadrados (diferencia 0,00 PEN); riesgo de datos: 0 fugas entre tenants; riesgo clínico: alergias avisadas y datos clínicos solo con decisión registrada | Pruebas de regla de negocio + suite de aislamiento + pruebas de *safety* | E1 |
| **Cobertura del contexto** | Diferentes perfiles de usuario (5 roles, ACT-01 a ACT-06), resoluciones 360–1920 px, horario y zona `America/Lima` | Matriz de permisos §9.3 + E2E multirrol y multiresolución | E1 |

---

## 4. Procesos dinámicos de prueba y niveles *(ISO/IEC/IEEE 29119-2)*

### 4.1 Niveles de prueba

| Nivel | Objetivo | Técnica principal | Herramienta | Responsable de la verificación |
| :-- | :-- | :-- | :-- | :-- |
| **Estático** | Arquitectura, tipos, estilo, complejidad | Revisión de código + análisis estático | Larastan (≥ 6), ESLint, Pint, ruff/mypy (ML), SonarQube o phpmetrics, Pest `arch` | CI en cada PR (RNF-119, RNF-122 a RNF-124) |
| **Unitario** | Reglas de negocio aisladas, una RN por prueba | Caja blanca; **100 % con reloj simulado donde hay tiempo** | **Pest/PHPUnit** (PostgreSQL de pruebas) · **Vitest + React Testing Library** · pytest en el motor ML | CI (RNF-126, RNF-128, RNF-130) |
| **Integración** | API ↔ BD ↔ colas ↔ almacenamiento; contratos | Transacciones, concurrencia, idempotencia, migraciones | Pest con HTTP tests; pruebas de contrato OpenAPI | CI (RNF-044 a RNF-046, RNF-079) |
| **Sistema** | Flujo completo por CUS, permisos por rol, multi-tenant | Caja negra sobre la API + SPA | **Playwright (E2E)** + Pest | CI/nightly (RNF-005) |
| **Aceptación** | Validación del flujo de valor con el docente y, en E2, con el validador clínico | Guion de demostración derivado de los CUS de SRS §17.2 | Playwright + guion manual | Informe de cierre por entrega |
| **No funcionales** | Atributos de §3 | Ver tabla 4.2 | k6, Lighthouse, axe, Infection, herramientas de caos | Por entrega (§5) |

### 4.2 Pruebas no funcionales por tipo

| Tipo | Prueba | Condiciones | Criterio de aceptación | Entrega |
| :-- | :-- | :-- | :-- | :-- |
| Rendimiento | Carga, pico, capacidad, resistencia, concurrencia | ER + CDR, perfiles PC-N, PC-P, PC-C, PC-R, PC-E | Umbrales p95/p99 de RNF-006 a RNF-041 (SRS §13.5) | E1 (PC-N/PC-P/PC-E) · E3 (PC-C/PC-R) |
| Seguridad | SAST, escaneo de secretos, DAST, aislamiento, archivos maliciosos | OWASP ASVS 4.0.3 nivel 2 (REF-13); EICAR | 0 hallazgos Alta/Crítica; 0 fugas (RNF-101) | E1 (SAST/aislamiento) · **E2 (DAST)** |
| Usabilidad | Prueba moderada con ≥ 5 participantes, inducción ≤ 30 min | Guion de tareas del §3.1 | Hallazgo mediana ≤ 60 s, ≥ 4/5 sin ayuda (RNF-054); SUS ≥ 68 | E1 (hallazgo) · E3 (resto) |
| Accesibilidad | Evaluación automática + revisión | WCAG 2.1 AA (REF-14) | 0 errores críticos de axe/Lighthouse | E3 |
| Compatibilidad | Matriz de navegadores y resoluciones | Chrome/Edge/Firefox (2 últimas); Safari y Chrome Android solo en portal; 360/768/1280/1920 px | 0 defectos de severidad alta (RNF-051, RNF-052) | E1 |
| Fiabilidad / caos | Servicios caídos: motor ML, IA, SMTP, Redis; fallos entre transacción y cola | Staging | RNF-074 a RNF-078: atención clínica continúa | E1 |
| Recuperación | Simulacro de restauración completo y parcial | PITR; evidencia con conteos y sumas de control | RPO ≤ 15 min; RTO ≤ 4 h; informe firmado | **E2** |
| ML | Validación, calibración, equidad, SHAP, determinismo, contrato, activación | Conjunto de validación sintético (PQ-06) | RNF-165, RNF-168, RNF-169, RNF-174, RNF-152 | E1 |
| IA generativa | Extracción, seudonimización, adversarial, trazabilidad | ≥ 50 notas anotadas, ≥ 100 con identificadores, ≥ 20 adversariales | RNF-175 a RNF-178 | **E3** |
| Mutación | Calidad de las pruebas de servicios críticos | Infection | MSI ≥ 70 % (RNF-127) | E1–E4 |
| Conformidad | Checklists NTS 188 / NTS 139 / Ley 29733 / consumidor | Con el validador clínico | 100 % cumplidos y firmados | **E2** |

### 4.3 Entornos y datos de prueba

| Entorno | Uso | Datos | Regla |
| :-- | :-- | :-- | :-- |
| Desarrollo | Pruebas unitarias y de integración en cada PR | Sintéticos mínimos | Sin credenciales de producción (RNF-107) |
| Staging | E2E, carga, caos, DAST, simulacros | **CDR sintético con semilla reproducible** | Configuración de producción (RNF-187) |
| Producción | Monitoreo sintético, A/B de despliegue | Datos reales solo después de aceptar **E2** | Nunca copiado a otros entornos (RNF-107, SRS §17.1) |

**Prácticas obligatorias:**
- **Reloj simulado** en toda prueba que toque vencimientos, inasistencias, cierres, retención, representación legal y expiraciones (RNF-130): 0 esperas reales en la suite.
- **SMTP simulado** para notificaciones (fallos y reintentos) y **proveedor de IA simulado** para la ruta de fallo.
- **Datos sintéticos** que respeten dominios del SRS (DNI válidos, CPOD 0–32, ceod 0–20, precios 0,00–99 999,99, etc.).

---

## 5. Criterios de entrada, suspensión, reanudación y salida *(ISO 29119-2)*

### 5.1 Criterios de entrada (comunes a toda entrega)

1. El alcance de la entrega está congelado en SRS §17.4 y sus RF/RNF tienen diseño de prueba asociado (§6).
2. El código compila, las migraciones se aplican y la CI está en verde en la rama base.
3. El entorno de pruebas existe, está sembrado con los datos sintéticos requeridos y con reloj simulado disponible.
4. Los artefactos externos están disponibles: catálogo NTS 188 versionado, plantilla base de procedimientos y **validador clínico identificado antes de la semana 5** (riesgo RR-02 / PQ-05).
5. Para E2: contrato de encargo, política de privacidad y proveedor de nube definidos (RNF-155, RNF-157, PQ-04).

### 5.2 Criterios de suspensión

- Un defecto **Crítico** abierto (fuga o pérdida de datos, plataforma inutilizable).
- Un defecto **Alta** en un flujo *Must* que impida continuar la ejecución de la ruta crítica.
- Indisponibilidad del entorno de pruebas o del ER por más de 2 horas.
- Detección de un dato real de paciente en el entorno de pruebas (violación de RES-08) → suspensión inmediata e investigación.
- Falta del validador clínico cuando la ejecución requiere su firma (RNF-160).

### 5.3 Criterios de reanudación

- El defecto que motivó la suspensión está corregido, verificado y reprobado en su forma.
- La CI vuelve a verde y la suite completa anterior queda PASS.
- Se registra la incidencia en el registro de no conformidades (§8.1) con causa raíz si era Crítico.

### 5.4 Criterios de salida por entrega

| Criterio | Umbral | Referencia |
| :-- | :-- | :-- |
| RF de la entrega con ≥ 1 prueba automatizada que cita su ID | 100 % | RNF-005, RNF-128 |
| CA aplicables automatizados (78 en total para E1/E2) | 100 % | RNF-005 |
| RN de alcance con prueba que cita su ID | 100 % | RNF-128 |
| Cobertura backend / servicios críticos / frontend / ML | ≥ 80 % / ≥ 95 % / ≥ 70 % / ≥ 80 % | RNF-126 |
| MSI en servicios críticos | ≥ 70 % | RNF-127 |
| Defectos abiertos Críticos o Altos | 0 | RNF-073 |
| Umbrales de los RNF de la entrega | 100 % cumplidos, con evidencia | SRS §17.1 |
| Suite (unitarias+integración+arquitectura+contrato / E2E críticas) | ≤ 15 min / ≤ 20 min | RNF-129 |
| **E2 además:** checklists normativos al 100 % firmados + informe de simulacro RPO/RTO dentro de umbral | 100 % / RPO ≤ 15 min, RTO ≤ 4 h | RNF-160 a RNF-162, RNF-081 a RNF-084 |

**Regla de liberación global (SRS §17.1):** una entrega se acepta solo si todos sus RF tienen sus pruebas en verde y todos sus RNF cumplen su criterio. Ningún dato real de pacientes entra a la plataforma antes de aceptar E2.

---

## 6. Artefactos de prueba y trazabilidad *(ISO/IEC/IEEE 29119-3)*

### 6.1 Artefactos

| Artefacto | Identificador | Contenido mínimo | Plantilla |
| :-- | :-- | :-- | :-- |
| Especificación de diseño de pruebas | `TD-{Módulo}-{nn}` | Requisitos de partida, técnicas seleccionadas, cobertura a lograr | §Anexo D |
| Especificación de caso de prueba | `TC_{CA o RF}_{nn}` | Precondiciones, datos, pasos, resultado esperado, IDs (RF, RN, CUS, CA) | **Anexo A** |
| Procedimiento de prueba | Embebido en el caso o script en el repo | Secuencia de ejecución manual o automatizada, comando de CI | — |
| Registro de ejecución | `RUN-{fecha}-{entrega}` | Versión, entorno, perfil de carga, PASS/FAIL por caso, evidencias | — |
| Reporte de defecto | `DEF-{nnn}` | Severidad (§2.3), pasos de reproducción, RF/CA afectado, estado, causa raíz si es Crítico | **Anexo B** |
| Informe de cierre de entrega | `TCR-E{n}` | KPI de §2.4, cobertura, defectos, RNF cumplidos, decisión de liberación | §Anexo D |

### 6.2 Matriz de trazabilidad bidireccional

Esquema obligatorio (la matriz completa vive en SRS §14; este plan solo la consume):

```
NN / OB  →  RN  →  CUN  →  CUS  →  RF / RNF  →  CA (§11)  →  TD  →  TC  →  RUN (PASS/FAIL)  →  DEF
```

| ID Requisito | ID Criterio (CA) | ID Caso de prueba | ID Procedimiento/Script | Estado (PASS/FAIL) | ID Defecto |
| :-- | :-- | :-- | :-- | :-- | :-- |
| RF-001 | CA-06.1 | TC_RF001_01 | `tests/Feature/TenantScopeTest.php` | PASS | N/A |
| RF-147 | CA-47.2 | TC_RF147_02 | `tests/Feature/AppointmentNoOverlapTest.php` | FAIL | DEF-014 |
| RNF-006 | — | TC_RNF006_01 | `k6/reads_pc_n.js` | PASS | N/A |

**Reglas:**
1. Todo RF *Must* y todo CA de §11 tienen al menos un caso (RNF-005).
2. Toda prueba cita su requisito en el nombre o en metadatos (RNF-128).
3. Un FAIL sin `DEF-` es un registro inválido; un `DEF-` sin ID de requisito no se acepta.
4. Cobertura de CA por CUS crítico: ver **Anexo C** (78 criterios, 15 CUS).

---

## 7. Infraestructura, herramientas y control de cambios *(ISO 9001 Cap. 7–8)*

### 7.1 Herramientas

| Área | Herramienta | Uso |
| :-- | :-- | :-- |
| Control de versiones | Git + **SemVer** | Línea base por entrega; etiquetas `vMAJOR.MINOR.PATCH` (RNF-133) |
| CI/CD | GitHub Actions (o equivalente) | Pipeline: formato → estático → unitarias → integración → arquitectura → contrato → E2E → informes |
| Backend | **Pest / PHPUnit** sobre PostgreSQL de pruebas | Unitarias, integración, HTTP, transacciones, propiedad |
| Frontend | **Vitest + React Testing Library** | Componentes y estados |
| E2E | **Playwright** | Flujos críticos, multirrol, multiresolución, multinavegador, trazas de rendimiento |
| Carga | **k6** | PC-N, PC-P, PC-C, PC-R, PC-E en ER |
| Rendimiento web | **Lighthouse CI** | Core Web Vitals (RNF-025, RNF-026) |
| Accesibilidad | axe-core (integrado en Playwright/Lighthouse) | WCAG 2.1 AA (REF-14) |
| Calidad de código | SonarQube o phpmetrics, Larastan, ESLint, Pint, ruff, mypy | RNF-119, RNF-122 a RNF-124 |
| Mutación | Infection (PHP), mutmut (Python) | MSI ≥ 70 % (RNF-127) |
| Seguridad | Escaneo de secretos, SAST (reglas OWASP ASVS), DAST en staging, EICAR | OQ-13, RNF-100, RNF-103, RNF-104 |
| Contrato | Validador OpenAPI 3.1 en CI | RNF-044 |
| Regresión visual | Captura comparativa del odontograma vs anexo de la NTS 188 | RNF-004, RNF-151 |
| Datos | Generador sintético con semilla (CDR) + reloj simulado (`Carbon::setTestNow`, `freezegun`, `vi.useFakeTimers`) | RES-08, RNF-130 |

### 7.2 Pipeline (etapas en orden)

1. Formato y análisis estático (falla el PR si hay errores) → RNF-122, RNF-123, RNF-136.
2. Pruebas de arquitectura (`Pest arch`) → RNF-119.
3. Unitarias + integración + propiedad (PostgreSQL de pruebas) → RNF-126, RNF-128.
4. Pruebas de contrato OpenAPI → RNF-044, RNF-045.
5. Suite de aislamiento multi-tenant → RNF-101, OQ-07.
6. E2E críticas (≤ 20 min) → RNF-005, RNF-129.
7. Escaneo de secretos y SAST → OQ-13.
8. Informes: cobertura, MSI, trazabilidad RF/RN → CA → TC.

### 7.3 Control de cambios (simplificado, RES-07)

- Todo cambio entra por **solicitud con ≥ 1 revisor y CI en verde** (RNF-135); sin excepciones.
- Cambio que altera un RF, RNF o CA: se actualiza primero el SRS (control de versiones + matriz §14) y después el caso de prueba; se registra en el §12 de este plan.
- Cambio que altera un umbral de calidad: requiere justificación explícita y rechaza la entrada de la entrega hasta alinearse con el SRS.
- Actualizaciones de dependencias: soporte vigente y menores ≤ 30 días (RNF-134).
- No se crea CAB: la aprobación de salida de entrega la firma el Líder de QA (§1.4).

---

## 8. Evaluación, no conformidades y mejora continua *(ISO 9001 Cap. 9–10)*

### 8.1 Tratamiento de no conformidades (defectos)

| Paso | Acción | Regla |
| :-- | :-- | :-- |
| 1 | Registrar con `DEF-`, severidad (§2.3), RF/CA afectado y pasos de reproducción | Sin requisito asociado, no se acepta |
| 2 | Asignar y corregir | Crítico/Alta: mismo día; Media: ≤ 1 día hábil; Baja: ≤ 5 días |
| 3 | Reprobar el caso original | El defecto pasa a `cerrado` solo con su TC en PASS |
| 4 | Regresión | La suite completa debe quedar en verde |
| 5 | **Causa raíz** (solo severidad Crítica y en defectos repetidos) | Anotar causa y acción correctiva en el registro; verificar que no reaparece |

### 8.2 Revisiones y auditorías

| Actividad | Frecuencia | Salida |
| :-- | :-- | :-- |
| Revisión de estado de pruebas (cobertura, KPI de §2.4) | Semanal | Ajuste del plan de la semana siguiente |
| Auditoría de configuración de la entrega (etiqueta SemVer, artefactos, informes) | Al cierre de cada entrega | Checklist firmada |
| Revisión de resultados y decisión de liberación | Al cierre de cada entrega (E1–E4) | Informe de cierre `TCR-E{n}` |
| Revisión de lecciones aprendidas y actualización de este plan | Entre entregas | Nueva versión de TP-DC-001 |

### 8.3 Mejora continua

- KPI con tendencia negativa (reapertura de defectos > 10 %, MSI < 70 %, suite > 15 min) activa una acción correctiva registrada.
- Entre entregas se recalibran técnicas y coberturas; los cambios se versionan en §12.
- La tasa de aceptación de sugerencias de IA (OB-09) y la reducción de inasistencias (OB-12) se miden en producción, fuera de este plan, y alimentan la revisión del producto.

---

## 9. Cronograma de pruebas por entrega

### 9.1 E1 (curso, 12 semanas — SRS §17.3)

| Paso | Semana | Actividad de prueba |
| :-: | :-- | :-- |
| 1 | 3 | Pruebas unitarias y de integración de fundamentos: RUC, invitación, 2FA, bloqueo, COP, representante, consentimiento de datos (CUS-01…04, 06…11, 13…17) |
| 2 | 4–5 | Atención y odontograma: validación FDI exhaustiva, inmutabilidad en BD, regresión visual NTS 188, notas CIE-10, adendas (CUS-21…27, 80, 81). **Activar validador clínico (RR-02)** |
| 3 | 6–7 | Catálogo, plan, presupuesto: propiedad aritmética (10 000 casos), emisión atómica, inmutabilidad, consentimiento informado (CUS-32…40, 82, 83) |
| 4 | 8 | Agenda: restricción de exclusión (PC-E), disponibilidad, check-in, inasistencias con reloj simulado, notificaciones con SMTP simulado (CUS-44…53, 77) |
| 5 | 9–10 | Motor ML: contrato, SHAP, determinismo, Circuit Breaker y caos con motor caído (CUS-54…58) |
| **6** | **11** | **Verificación de E1:** carga (PC-N, PC-P, PC-E), suite de aislamiento, seguridad (SAST + secretos), usabilidad del hallazgo, matriz de navegadores, trazabilidad RF→TC, mutación |
| 7 | 12 | Cierre: informe `TCR-E1`, documentación, demostración de aceptación con el docente |

### 9.2 E2, E3 y E4 (posteriores a E1)

| Entrega | Secuencia de pruebas recomendada |
| :-- | :-- |
| **E2** | 1) Checklists normativas con el validador (NTS 188, NTS 139, Ley 29733). 2) ARCO y portabilidad con reloj simulado (plazos 10 días hábiles / 48 h). 3) Retención y bloqueo (0 rutas de borrado). 4) **DAST** completo. 5) **Simulacro de restauración** (RPO ≤ 15 min, RTO ≤ 4 h) y respaldos cifrados. 6) Despliegue y reversión cronometrados. 7) Contratos de encargo y entornos separados. |
| **E3** | 1) Pagos (serialización, idempotencia, saldo 0,00 PEN, anulaciones). 2) IA generativa: seudonimización, extracción ≥ 50 notas, adversarial ≥ 20 notas, trazabilidad. 3) Portal del paciente (360 px, Safari, representantes). 4) Importación/exportación con CSV/XLSX y validación previa de filas. 5) Carga PC-C y PC-R; informes de caja y antigüedad de deuda. 6) Usabilidad de recepción y SUS; accesibilidad WCAG. 7) Observabilidad: alertas simuladas y entregabilidad de correo. |
| **E4** | 1) Indicadores y encuestas (CSAT/NPS). 2) Lista de espera (evento de cancelación → aviso). 3) Fusión de fichas (recuento = suma de ambas). 4) Enlace compartido y código de 6 dígitos (expiración 10 min). 5) **Regresión completa de E1–E3 en verde** antes de liberar. |

---

## 10. Riesgos del plan de pruebas

| ID | Riesgo | Probabilidad | Impacto | Mitigación |
| :-- | :-- | :-- | :-- | :-- |
| RR-P01 | **E1 tiene 115 RF y solo una parte está implementada** (SRS RR-01) | Alta | No llegar a la demostración en la semana 12 | Seguir el orden de SRS §17.3; si se atrasa, reducir CUS-77 a la vista de día y mover a E2 los RNF de E1 que no sean de corrección, aislamiento ni seguridad. **Las reglas de negocio no se relajan** |
| RR-P02 | No hay cirujano dentista validador (PQ-05) | Media | No se cierran los RNF de conformidad NTS 188/139 ni E2 | Identificar al validador antes de la semana 5 (RR-02 del SRS) |
| RR-P03 | Modelo entrenado con datos sintéticos (PQ-06) | Alta | Un AUC alto no demuestra validez clínica | Declararlo en la model card; validación clínica fuera de alcance (OUT-06); el plan solo exige las pruebas de contrato, calibración e integración |
| RR-P04 | El ER con el CDR completo no está disponible a tiempo (costo de infraestructura sin definir, PQ-04) | Media | Los RNF de desempeño no se pueden cerrar | Usar el supuesto de trabajo del SRS §16; ejecutar carga en staging con CDR reducido y declarar la extrapolación en el informe |
| RR-P05 | SDD y especificaciones técnicas desactualizados frente al SRS (SRS RR-04) | Media | Se prueba sobre un diseño que no corresponde | Actualizar el SDD antes del paso 2 de SRS §17.3 |
| RR-P06 | La suite E2E excede 20 min y enmascara regresiones | Media | RNF-129 incumplido | Paralelizar, etiquetar por módulo y mantener el núcleo crítico ≤ 20 min |
| RR-P07 | La prueba de caos afecta entornos compartidos | Baja | Falsos positivos en desarrollo | Aislar el caos en staging (RNF-187) y prohibirlo en desarrollo |
| RR-P08 | El plan se desincroniza del SRS tras un cambio de requisitos | Media | Trazabilidad rota | Actualizar este plan en §12 ante cada cambio del SRS (§7.3) |

---

## 11. Aprobación del plan

| Rol | Nombre | Firma | Fecha |
| :-- | :-- | :-- | :-- |
| Líder de QA / Líder del proyecto | Blas Puente Juancito Alexis | | |
| Integrante | Carvo Mendez Orlando Alexander | | |
| Integrante | Sánchez Ramos Carlos Alonso | | |
| Docente | Mg. Maglioni Arana Caparachin | | |

---

## 12. Control de versiones del plan

| Versión | Fecha | Cambios |
| :-- | :-- | :-- |
| v1.0 | 2026-09-22 | Plan unificado (ISO 9001 + ISO/IEC 25010:2023 + ISO/IEC 25001 + ISO/IEC/IEEE 29119) derivado de `SRS_DentiCore.md` v1.0. Alcance E1–E4 (201 RF, 194 RNF) |

---

# Anexos

## Anexo A — Plantilla de caso de prueba

| Campo | Contenido |
| :-- | :-- |
| **ID** | `TC_{RF o CA}_{nn}` |
| **Diseño de prueba** | `TD-{Módulo}-{nn}` |
| **Requisitos** | RF-xxx / RNF-xxx · RN-xx · CUS-xx · CA-xx.x |
| **Nivel** | Unitario · Integración · Sistema/E2E · No funcional |
| **Tipo** | Funcional · Rendimiento · Seguridad · Usabilidad · Caos · Conformidad |
| **Precondiciones** | Rol autenticado, datos de prueba, estado del sistema |
| **Datos** | Conjunto sintético específico (semilla) |
| **Pasos** | 1. … 2. … 3. … |
| **Resultado esperado** | Condición observable y medible (sin términos prohibidos del SRS §1.3) |
| **Criterio de aceptación** | Umbral exacto del SRS (ej.: p95 ≤ 300 ms bajo PC-N) |
| **Entorno / perfil** | ER + CDR + PC-xx o desarrollo/staging |
| **Automatización** | Sí/No · Script en CI: ruta del archivo |
| **Resultado** | PASS / FAIL / NO EJECUTADA · `RUN-id` · Evidencia |

## Anexo B — Plantilla de reporte de defecto

| Campo | Contenido |
| :-- | :-- |
| **ID** | `DEF-{nnn}` |
| **Título** | Descripción breve |
| **Severidad** | Crítica · Alta · Media · Baja (definición en §2.3) |
| **Requisito afectado** | RF-/RNF-/CA- obligatorio |
| **Entrega** | E1 · E2 · E3 · E4 |
| **Pasos de reproducción** | 1… n, con datos exactos |
| **Resultado esperado / observado** | |
| **Entorno** | Desarrollo / Staging / Producción · versión SemVer |
| **Estado** | Abierto · En corrección · Reprobando · Cerrado · Reabierto |
| **Causa raíz** | Obligatoria si la severidad es Crítica |
| **Verificación** | TC reejecutado + fecha + `RUN-id` |

## Anexo C — Cobertura de criterios de aceptación por CUS crítico (78 CA)

| CUS | CA | Reglas verificadas | Entrega |
| :-- | :-: | :-- | :-- |
| CUS-01 Registrar clínica | 5 | RN-04, RN-05; DD-22 | E1 |
| CUS-06 Iniciar sesión | 6 | RN-01, RN-05, RN-07; DD-15 | E1 |
| CUS-14 Registrar paciente | 5 | RN-01, RN-09, RN-10, RN-12; DD-04 | E1 |
| CUS-17 Consentimiento de datos | 5 | RN-10, RN-11, RN-12, RN-15 | E1 |
| CUS-22 Hallazgos en el odontograma | 6 | RN-10, RN-16, RN-18, RN-19, RN-21, RN-22 | E1 |
| CUS-23 Corrección de un hallazgo | 4 | RN-22, RN-23, RN-24 | E1 |
| CUS-28 Sugerencia de hallazgos por IA | 5 | RN-53 a RN-57 | E3 |
| CUS-30 Decidir sobre una sugerencia de IA | 4 | RN-55, RN-56 | E3 |
| CUS-35 Emitir presupuesto | 6 | RN-29 a RN-34; DD-23 | E1 |
| CUS-37 Decisión sobre el presupuesto | 5 | RN-03, RN-35, RN-36, RN-37 | E1 |
| CUS-39 Registrar procedimiento realizado | 4 | RN-38, RN-39 | E1 |
| CUS-41 Registrar abono | 5 | RN-41, RN-42, RN-44, RN-45 | E3 |
| CUS-47 Reservar cita | 6 | RN-46, RN-47, RN-48, RN-52 | E1 |
| CUS-55 Calcular riesgo de caries | 7 | RN-58, RN-59, RN-61, RN-63, RN-64; RES-04 | E1 |
| CUS-64 Atender solicitud ARCO | 5 | RN-68, RN-69; DD-26 | **E2** |
| **Total** | **78** | | |

## Anexo D — Plantilla de diseño de prueba y de informe de cierre

**Diseño de prueba (`TD-{Módulo}-{nn}`)**
1. Requisitos de partida (lista de RF/RNF/CA de la sección del SRS).
2. Técnicas seleccionadas y justificación (equivalencia de clases, valores límite, transiciones de estado, pares combinados, propiedad, mutación…).
3. Cobertura a logurar y cómo se evidenciará.
4. Datos y entorno necesarios (perfil de carga, reloj simulado, servicios simulados).
5. Riesgos y dependencias.

**Informe de cierre (`TCR-E{n}`)**
1. Alcance ejecutado (RF/RNF/CA de la entrega) y versiones probadas.
2. KPI de §2.4 con valores y meta.
3. Resultados por nivel y por tipo de prueba no funcional, con evidencia (`RUN-id`).
4. Defectos: abiertos/cerrados por severidad, densidad, reaperturas, causa raíz de los Críticos.
5. RNF de la entrega: cumplido / no cumplido, con su evidencia.
6. Desviaciones respecto al plan y su justificación.
7. Decisión: **ACEPTAR / RECHAZAR / ACEPTAR CON CONDICIONES** (firmada por el Líder de QA).
