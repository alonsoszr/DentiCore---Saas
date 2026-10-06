Basado en ISO 9001

Este enfoque prioriza el **gobierno del proceso, el control operacional de verificación y validación, la gestión de cambios, el control documental y la mejora continua**.

📋 PLAN DE PRUEBAS DE SOFTWARE (NORMA ISO 9001)

**Código del Documento:** SGC-TP-001

**Nombre del Proyecto:** [Nombre del Sistema / Producto]

**Versión del Plan:** [Ejemplo: v1.0] *(Sujeto a Control Documental)*

**Aprobado por:** [Representante de la Dirección / Responsable de Calidad]

1. Contexto de la Organización y Alcance del Plan *(ISO 9001 - Cap. 4)*

* **1.1 Comprensión del Entorno (Interno/Externo):** Descripción del entorno operativo, técnico y regulatorio que influye en el proceso de pruebas.
* **1.2 Partes Interesadas:** Identificación de clientes, usuarios finales, equipo de desarrollo, auditores de calidad y patrocinadores del proyecto.
* **1.3 Alcance del Plan de Pruebas:** Delimitación de los módulos, componentes, sistemas o subsistemas que están incluidos y excluidos formalmente de las actividades de verificación y validación.

2. Liderazgo, Políticas y Responsabilidades *(ISO 9001 - Cap. 5)*

* **2.1 Compromiso de la Dirección y Política de Calidad:** Declaración explícita del compromiso directivo con las políticas de prueba y el aseguramiento de la satisfacción del cliente.
* **2.2 Asignación de Roles y Autoridades:** Matriz de responsabilidades del personal involucrado en las pruebas (Líder de QA, Diseñador de Pruebas, Testers, Administrador de Configuración y Auditor).

3. Planificación de Pruebas y Gestión de Riesgos *(ISO 9001 - Cap. 6)*

* **3.1 Objetivos de Calidad Cuantificables:** Definición de objetivos medibles de calidad orientados al éxito de las pruebas (tasa máxima aceptable de fallos, tiempos de resolución de incidencias, etc.).
* **3.2 Gestión de Riesgos de Implantación de Pruebas:** Matriz de identificación, evaluación de impacto/probabilidad y planes de mitigación de los riesgos que amenazan la ejecución del proceso de pruebas.
* **3.3 Planificación del Ciclo de Vida del Desarrollo/Pruebas:** Alineación de los niveles de prueba con las fases de desarrollo del proyecto.

4. Soporte, Recursos y Control de la Información Documentada *(ISO 9001 - Cap. 7)*

* **4.1 Gestión de Recursos:** Determinación de la infraestructura, laboratorios de prueba, entornos de ejecución y licencias necesarias.
* **4.2 Competencias y Formación del Personal:** Evaluación de capacidades del equipo de QA y plan de capacitación para las metodologías y herramientas de prueba.
* **4.3 Comunicación del Plan de Calidad:** Canales y frecuencia de distribución de los estados de prueba a las partes interesadas.
* **4.4 Control de la Información Documentada:** Procedimiento para la codificación, aprobación, versión, distribución y retención del propio Plan de Pruebas y sus registros de ejecución.

5. Operación: Control del Diseño, Verificación, Validación y Cambios *(ISO 9001 - Cap. 8)*

* **5.1 Gestión de Requisitos:** Control y trazabilidad de los requisitos solicitados para asegurar su verificabilidad.
* **5.2 Control de Verificación y Validación:** Definición formal de los criterios de entrada, criterios de aceptación/salida y las actividades dinámicas de prueba.
* **5.3 Procedimiento de Control de Cambios:** Flujo formal para registrar (mediante Solicitudes de Cambio - RFC), evaluar el impacto, aprobar en el Comité de Cambios (CAB) e implementar cualquier modificación en el software o en el plan de pruebas.
* **5.4 Control del Despliegue y Soporte:** Procedimientos de verificación previa antes de la liberación a entornos de prueba o producción.

6. Evaluación del Desempeño y Auditorías *(ISO 9001 - Cap. 9)*

* **6.1 Seguimiento e Indicadores (KPIs):** Mantenimiento de métricas de desempeño del proceso de pruebas (densidad de defectos, cobertura de requisitos, porcentaje de pruebas exitosas).
* **6.2 Auditorías Internas de Calidad:** Programación de revisiones periódicas para auditar si el proceso de pruebas cumple con los procedimientos y políticas normativas definidas.
* **6.3 Revisión por la Dirección:** Análisis periódico de los resultados globales de las pruebas por parte de los líderes para tomar decisiones basadas en evidencia.

7. Gestión de No Conformidades y Mejora Continua *(ISO 9001 - Cap. 10)*

* **7.1 Tratamiento de No Conformidades (Gestión de Incidencias/Defectos):** Procedimiento para registrar, clasificar por severidad, asignar y re-probar los fallos o desviaciones encontradas durante la ejecución de pruebas.
* **7.2 Acciones Correctivas y Causa Raíz:** Análisis sistemático de los defectos críticos para eliminar las causas subyacentes y prevenir su recurrencia en el proceso de desarrollo.
* **7.3 Mejora Continua:** Lecciones aprendidas y optimización continua de los procedimientos y guías de prueba del proyecto.

Pl**antilla de Plan de Pruebas de Software basada exclusivamente en el modelo ISO/IEC 25010**.

A diferencia de un plan de gestión global (como ISO 9001) o de procesos de ejecución (como ISO 29119), este plan organiza la estrategia de evaluación centrada estrictamente en el **Modelo de Calidad del Producto Software** (8 características) y el **Modelo de Calidad en Uso** (5 características).

**📋 PLAN DE PRUEBAS DE SOFTWARE (NORMA ISO/IEC 25010)**

**Código del Documento:** TP-ISO25010-001
**Sistema en Evaluación:** [Nombre del Producto / Sistema]
**Versión del Producto:** [Ejemplo: v1.0.0]
**Estándar Base:** Modelo de Calidad del Producto y Calidad en Uso (ISO/IEC 25010)

**1. Alcance de Evaluación de Calidad del Producto *(Modelo de Calidad)***

Delimitación de los módulos y componentes del sistema que serán evaluados bajo las 8 características de calidad del producto software:

* **1.1 Componentes en Alcance:** Listado de aplicaciones móviles, APIs backend, bases de datos e interfaces incluidas.
* **1.2 Criterio de Selección de Atributos:** Justificación de las subcaracterísticas prioritarias para el éxito técnico del producto.

**2. Estrategia de Pruebas de Calidad del Producto Software *(8 Características)***

**2.1 Pruebas de Adecuación Funcional *(Functional Suitability)***

* **Completitud Funcional:** Verificación de que el conjunto de funciones cubre todas las tareas especificadas.
* **Corrección Funcional:** Evaluación de la provisión de resultados o respuestas técnicamente correctas.
* **Pertinencia Funcional:** Validación de que las funciones facilitan la realización de tareas objetivo sin pasos redundantes.

**2.2 Pruebas de Eficiencia del Desempeño *(Performance Efficiency)***

* **Comportamiento Temporal:** Medición de tiempos de respuesta, latencias y procesamiento de transacciones.
* **Utilización de Recursos:** Evaluación del consumo de CPU, memoria, ancho de banda y batería.
* **Capacidad:** Determinación de los límites máximos de peticiones y usuarios concurrentes sostenidos.

**2.3 Pruebas de Compatibilidad *(Compatibility)***

* **Coexistencia:** Comprobación de que el software comparte recursos de hardware/software sin degradar otros sistemas.
* **Interoperabilidad:** Verificación de la capacidad de intercambio e integración de datos con sistemas externos.

**2.4 Pruebas de Usabilidad *(Usability)***

* **Aprendibilidad:** Facilidad con la que los nuevos usuarios comprenden y aprenden a operar el sistema.
* **Operabilidad:** Facilidad de control, navegación y configuración por parte del usuario.
* **Protección frente a Errores de Usuario:** Capacidad del sistema para prevenir fallos operativos causados por ingresos inválidos.
* **Estética de la Interfaz:** Evaluación de la consistencia visual y diseño de la interfaz de usuario.
* **Accesibilidad:** Verificación del acceso para personas con diversas capacidades (cumplimiento WCAG).

**2.5 Pruebas de Fiabilidad *(Reliability)***

* **Madurez:** Evaluación de la frecuencia de fallos en condiciones normales de operación.
* **Disponibilidad:** Porcentaje de tiempo en que el sistema se encuentra totalmente operativo y accesible.
* **Tolerancia a Fallos:** Capacidad del sistema para mantener la operatividad ante fallos de componentes.
* **Recuperabilidad:** Tiempo y capacidad de restauración de datos tras una interrupción de servicio.

**2.6 Pruebas de Seguridad *(Security)***

* **Confidencialidad:** Protección contra el acceso a datos por parte de entidades no autorizadas.
* **Integridad:** Prevención de modificaciones o corrupciones no autorizadas en archivos y datos.
* **No Repudio:** Capacidad de demostrar acciones o transacciones realizadas sin posibilidad de negación.
* **Responsabilidad y Autenticidad:** Trazabilidad de acciones a usuarios identificados e inspección de identidades.

**2.7 Pruebas de Mantenibilidad *(Maintainability)***

* **Modularidad:** Análisis de la independencia entre componentes para acoplamiento y cohesión.
* **Reusabilidad:** Evaluación del diseño para utilizar activos de código en otros módulos.
* **Analizabilidad y Modificabilidad:** Inspección de la facilidad para diagnosticar deficiencias y aplicar cambios.
* **Testabilidad (Capacidad de prueba):** Facilidad para diseñar e instalar pruebas automatizadas sobre el código.

**2.8 Pruebas de Portabilidad *(Portability)***

* **Adaptabilidad:** Capacidad de adaptación a distintos entornos operativos o de hardware.
* **Instalabilidad:** Evaluación de los procedimientos de instalación, actualización y despliegue.
* **Reemplazabilidad:** Facilidad para sustituir otro producto software por este en el mismo entorno.

**3. Estrategia de Pruebas de Calidad en Uso *(Entorno Real)***

* **3.1 Pruebas de Eficacia:** Evaluación del grado en que los usuarios logran completar las tareas con precisión.
* **3.2 Pruebas de Eficiencia de Uso:** Medición de los recursos y tiempo requeridos por el usuario para alcanzar sus objetivos.
* **3.3 Evaluación de Satisfacción:** Encuestas y métricas sobre el nivel de agrado, usabilidad percibida y confianza.
* **3.4 Pruebas de Libertad de Riesgo:** Verificación de que el uso del software minimiza riesgos financieros, operativos o de datos para el cliente.
* **3.5 Pruebas de Cobertura del Contexto:** Evaluación del funcionamiento correcto en diversos perfiles de usuario y condiciones ambientales.

**4. Matriz de Criterios de Aceptación Métricos *(ISO 25010)***

|  |  |  |  |
| --- | --- | --- | --- |
| **Atributo de Calidad (ISO 25010)** | **Subcaracterística Evaluada** | **Métrica / Indicador Cuantitativo** | **Criterio de Aceptación (Umbral)** |
| **Adecuación Funcional** | Completitud | % de requisitos funcionales validados | \(= 100%\) |
| **Eficiencia del Desempeño** | Comportamiento temporal | Tiempo de respuesta de API REST | \(\le 200\text{ ms}\) |
| **Fiabilidad** | Disponibilidad | Tiempo de actividad en staging (*Uptime*) | \(\ge 99.9%\) |
| **Seguridad** | Confidencialidad e Integridad | Análisis SAST / DAST de vulnerabilidades | \(0\) hallazgos de severidad Alta/Crítica |
| **Mantenibilidad** | Testabilidad y Modularidad | Cobertura de código unitario e índice de complejidad | Cobertura \(\ge 80%\), Complejidad Ciclomática \(\le 10\) |

Esta es una **plantilla de Plan de Pruebas de Software basada exclusivamente en el estándar internacional ISO/IEC/IEEE 29119**.

A diferencia de las normas de gestión de calidad (ISO 9001) o de atributos del producto (ISO 25010), el estándar **ISO 29119** define de manera específica los **procesos de prueba organizacionales, de gestión y dinámicos (Parte 2)**, junto con la **estructura documental estandarizada de artefactos y trazabilidad (Parte 3)**.

**📋 PLAN DE PRUEBAS DE SOFTWARE (NORMA ISO/IEC/IEEE 29119)**

**Identificador del Documento:** TP-ISO29119-001
**Nombre del Proyecto / Sistema:** [Nombre del Proyecto]
**Nivel de Prueba / Tipo de Plan:** [Plan de Pruebas de Proyecto (Project Test Plan) / Plan de Pruebas por Nivel (Level Test Plan)]
**Estatus:** [Borrador / Aprobado / En Revisión]

**1. Alcance, Conformidad y Referencias Normativas *(ISO 29119-3)***

* **1.1 Alcance (*Scope*):** Delimitación precisa de los elementos del sistema, características y niveles de prueba incluidos y excluidos en este plan.
* **1.2 Declaración de Conformidad (*Conformance*):** Especificación de las partes de la norma ISO/IEC/IEEE 29119 que se cumplen mediante la ejecución de este plan.
* **1.3 Referencias Normativas y Términos (*Normative References & Vocabulary*):** Alineación con los conceptos, términos y definiciones estandarizados en la Parte 1 de la norma.

**2. Procesos de Gestión de Pruebas (*Test Management Processes - ISO 29119-2*)**

* **2.1 Contexto del Proyecto y Estrategia Global:** Relación de este plan con la política de pruebas organizativa (*Test Policy*) y la estrategia global de la empresa (*Test Strategy*).
* **2.2 Identificación y Control de Riesgos de Prueba:** Evaluación de los riesgos del proyecto de prueba (probabilidad e impacto) y definición de planes de contingencia.
* **2.3 Estimación, Recursos y Cronograma:** Asignación de tiempos, presupuesto, personal técnico (testers, diseñadores de pruebas) y roles involucrados.
* **2.4 Medidas y Directivas de Control (*Test Measures & Control Directives*):** Indicadores cuantitativos para supervisar el progreso de las pruebas y aplicar medidas correctivas en caso de desviaciones.

**3. Procesos Dinámicos de Pruebas y Niveles (*Dynamic Test Processes - ISO 29119-2*)**

Organización de los subprocesos de diseño, entorno, ejecución y registro para cada nivel de prueba planificado:

* **3.1 Pruebas de Unidad (*Unit Testing*):** Verificación del código a nivel de componentes aislados.
* **3.2 Pruebas de Integración (*Integration Testing*):** Evaluación de la interacción entre módulos, servicios y APIs.
* **3.3 Pruebas de Sistema (*System Testing*):** Verificación del comportamiento global del software e interfaces externas.
* **3.4 Pruebas de Aceptación (*Acceptance Testing*):** Validación final con los usuarios para la liberación del producto.
* **3.5 Pruebas Dinámicas Específicas:** Pruebas no funcionales dinámicas (rendimiento, seguridad, usabilidad, etc.) integradas en el flujo dinámico.

**4. Criterios de Entrada, Suspensión, Reanudación y Salida**

* **4.1 Criterios de Entrada (*Start Criteria*):** Requisitos y condiciones previas necesarias para autorizar el inicio de la ejecución de pruebas (ej. disponibilidad del entorno, código compilado sin errores de build).
* **4.2 Criterios de Suspensión y Reanudación:** Condiciones bajo las cuales se detiene la ejecución (ej. presencia de fallos bloqueantes de severidad crítica) y requisitos para reanudar.
* **4.3 Criterios de Salida/Cierre (*Completion Criteria*):** Umbrales cuantitativos que determinan la finalización exitosa de la fase de pruebas (ej. 100% de casos críticos ejecutados y aprobados).

**5. Estructura de Artefactos Documentales de Prueba (*Test Documentation - ISO 29119-3*)**

Definición y organización de los documentos estandarizados que se generarán durante el proceso:

* **5.1 Especificación de Diseño de Pruebas (*Test Design Specification*):** Derivación de condiciones y escenarios de prueba a partir de los requisitos.
* **5.2 Especificación de Casos de Prueba (*Test Case Specification*):** Definición detallada de precondiciones, entradas, acciones y resultados esperados para cada caso.
* **5.3 Especificación de Procedimientos de Prueba (*Test Procedure Specification*):** Secuencia paso a paso y scripts para la ejecución manual o automatizada.
* **5.4 Registros de Prueba y Reportes de Incidentes (*Test Logs & Defect Reports*):** Evidencia documental del resultado de las ejecuciones y desviaciones detectadas.
* **5.5 Reporte de Finalización de Pruebas (*Level/Project Test Completion Report*):** Informe de cierre que resume los resultados, métricas de cobertura y lecciones aprendidas.

**6. Matriz de Trazabilidad de Pruebas (*Test Traceability*)**

Enlace bidireccional ininterrumpido que conecta los artefactos del proceso de pruebas:

|  |  |  |  |  |  |
| --- | --- | --- | --- | --- | --- |
| **ID Requisito del Sistema** | **ID Condición / Escenario de Prueba** | **ID Especificación de Caso de Prueba** | **ID Procedimiento de Ejecución** | **Estado de Ejecución (PASS/FAIL)** | **ID Reporte de Defecto** |
| REQ-01 | COND-PRIV-01 | TC\_29119\_01 | PROC\_PRIV\_01 | **PASS** | N/A |
| REQ-02 | COND-PERF-01 | TC\_29119\_02 | PROC\_PERF\_01 | **FAIL** | DEF-29119-03 |

Sí, aquí tienes la **Plantilla Unificada de Plan de Pruebas de Software**, articulada en una estructura coherente donde cada uno de los cuatro estándares aporta su propósito fundamental:

* **ISO 9001:** El marco de **gobierno, gestión de procesos, control operacional y mejora continua**.
* **ISO 25001 (SQuaRE):** La especificación cuantitativa de **requisitos medibles, organización de la evaluación y gestión de métricas/herramientas**.
* **ISO/IEC/IEEE 29119:** El **ciclo de vida de pruebas dinámicas, los artefactos documentales estandarizados y la matriz de trazabilidad**.

📋 PLANTILLA UNIFICADA DE PLAN DE PRUEBAS DE SOFTWARE

*(Integración ISO 9001 + ISO 25010 + ISO 25001 + ISO/IEC/IEEE 29119)*

1. Contexto, Alcance y Gobierno de Calidad *(Enfoque ISO 9001 & ISO 29119 Parte 3)*

* **1.1 Contexto de la Organización y Partes Interesadas:** Descripción del proyecto, cliente, equipo de desarrollo, usuarios finales e identificadores de cumplimiento regulatorio.
* **1.2 Alcance del Plan y Criterios de Conformidad:** Delimitación formal de los componentes, módulos, funcionalidades e interfaces incluidas y excluidas de la evaluación.
* **1.3 Política de Calidad, Roles y Responsabilidades:** Asignación explícita de autoridades y funciones en el equipo (Líder de QA, Diseñador de Pruebas, Tester, Administrador de Configuración y Comité de Cambios/CAB).

2. Especificación Cuantitativa de Requisitos de Calidad y Métricas *(Enfoque ISO 25001 / SQuaRE)*

* **2.1 Requisitos de Calidad Cuantificables:** Definición de metas numéricas objetivas antes de probar (ej. tiempo de respuesta, porcentaje de cobertura de código, tasa máxima de defectos permisibles).
* **2.2 Estrategia de Medición y Métodos:** Procedimientos, fórmulas y herramientas seleccionadas para capturar métricas internas, externas y de uso.
* **2.3 Trazabilidad de Resultados de Evaluación:** Registro de las mediciones ejecutadas para garantizar la verificabilidad y auditabilidad de los resultados de calidad.

3. Estrategia Técnica por Atributos de Calidad *(Enfoque ISO 25010 / 9126 & ISO 29119 Parte 2)*

* **3.1 Pruebas de Calidad del Producto (Atributos ISO 25010):**
  + **Adecuación Funcional:** Pruebas de completitud, corrección y pertinencia funcional.
  + **Eficiencia del Desempeño:** Pruebas de comportamiento temporal, uso de recursos y capacidad (carga/estrés).
  + **Compatibilidad:** Pruebas de coexistencia e interoperabilidad entre sistemas.
  + **Usabilidad:** Pruebas de aprendizaje, operabilidad, estética y accesibilidad.
  + **Fiabilidad:** Pruebas de madurez, disponibilidad, tolerancia a fallos y recuperabilidad.
  + **Seguridad:** Pruebas de confidencialidad, integridad, autenticidad y no repudio.
  + **Mantenibilidad:** Análisis estático de modularidad, reusabilidad y testabilidad.
  + **Portabilidad:** Pruebas de adaptabilidad, instalabilidad y sustituibilidad.
* **3.2 Pruebas de Calidad en Uso:** Pruebas de aceptación (UAT) para evaluar eficacia, eficiencia, satisfacción, libertad de riesgo y cobertura del contexto de uso.
* **3.3 Niveles de Prueba Dinámicos (ISO 29119-2):** Organización de la ejecución en niveles de Pruebas Unitarias, de Integración, de Sistema y de Aceptación.

4. Infraestructura, Herramientas y Control de Configuración *(Enfoque ISO 9001, GCS & ISO 25001)*

* **4.1 Identificación de Ítems de Configuración (CIs) y CMDB:** Inventario bajo control de versiones (código fuente, scripts DB, interfaz UI, configuraciones de servidor, casos de prueba y documentos).
* **4.2 Esquema de Versionado Semántico (SemVer):** Aplicación de nomenclatura MAJOR.MINOR.PATCH para la línea base del software.
* **4.3 Flujo de Control de Cambios (RFC / CAB):** Procedimiento formal para registrar Solicitudes de Cambio (RFC), evaluar impacto y someter a aprobación ante el Comité de Control de Cambios (CAB).
* **4.4 Herramientas de Prueba e Integración Continua (CI/CD):** Selección de la tecnología para repositorios Git, pipelines de automatización (GitHub Actions/Jenkins) y escáneres de calidad.

5. Proceso Dinámico de Pruebas, Artefactos y Trazabilidad *(Enfoque ISO/IEC/IEEE 29119 Partes 2 y 3)*

* **5.1 Artefactos Estándar de Pruebas:**
  + **Especificación de Diseño de Pruebas (*Test Design Specification*):** Identificación de condiciones y escenarios.
  + **Especificación de Casos de Prueba (*Test Case Specification*):** Entradas, acciones y resultados esperados.
  + **Procedimientos de Prueba (*Test Procedure Specification*):** Secuencias de pasos de ejecución.
* **5.2 Criterios de Entrada, Suspensión, Reanudación y Cierre:** Reglas que condicionan el paso entre fases o el fin de la ejecución.
* **5.3 Matriz de Trazabilidad Bidireccional:** Vinculación directa entre *Requisito ↔ RFC ↔ Ítem de Configuración (CI) ↔ Caso de Prueba (ISO 29119) ↔ Registro de Defecto*.

6. Evaluación del Desempeño, Auditorías y Mejora Continua *(Enfoque ISO 9001 & ISO 25001)*

* **6.1 Seguimiento de KPIs de Pruebas e Informe de Cierre (*Test Completion Report*):** Análisis de cobertura, densidad de fallos y tasa de éxito para justificar la liberación.
* **6.2 Gestión de No Conformidades (Defectos) y Acciones Correctivas:** Registro de incidentes, análisis de causa raíz y ajustes al proceso para evitar reincidencias.
* **6.3 Auditorías de Configuración y Revisiones por la Dirección:** Comprobación de que la versión publicada es reproducible y cumple con los estándares exigidos.

A continuación, tienes un **ejemplo resumido de Documento de Plan de Pruebas de Software** redactado como un artefacto real de proyecto. Muestra de forma concreta el tipo de contenido y el nivel de detalle que contiene cada sección al integrar los estándares **ISO 9001, ISO 25010, ISO 25001 e ISO 29119**.

**📄 PLAN DE PRUEBAS DE SOFTWARE (MÓDULO DE PRIVACIDAD V3.0)**

**Código del Documento:** MTP-WA-2025-V1
**Proyecto:** Sistema de Mensajería Instantánea — Módulo de Visibilidad y Estado
**Línea Base / Versión:** v3.0.0 (Versionado Semántico)
**Estándares de Referencia:** ISO 9001, ISO/IEC 25010, ISO/IEC 25001, ISO/IEC/IEEE 29119

**1. Contexto, Alcance y Gobierno de Calidad *(ISO 9001 & ISO 29119-3)***

* **1.1 Alcance de las Pruebas:** Verificación y validación de la nueva funcionalidad para ocultar el estado "En línea" a contactos específicos. Incluye la interfaz de la App móvil (Android/iOS), los servicios backend y la capa de persistencia. Quedan fuera del alcance las funciones no relacionadas con la privacidad de presencia (ej. llamadas de voz).
* **1.2 Matriz de Roles y Responsabilidades:**
  + **Líder de QA:** Administra la estrategia de pruebas, aprueba los informes de cierre y participa en el Comité de Control de Cambios (CAB).
  + **Diseñador de Pruebas / Tester:** Diseña los escenarios, automatiza los casos de prueba y registra las no conformidades.
  + **Administrador de Configuración (CM Manager):** Garantiza la integridad de la CMDB y audita las líneas base del código.

**2. Especificación Cuantitativa de Requisitos de Calidad y Métricas *(ISO 25001 / SQuaRE)***

* **2.1 Objetivos Técnicos Cuantificables:**
  + **Eficiencia del Desempeño:** Tiempos de respuesta de la API de estado \(\le 150\text{ ms}\) bajo una carga nominal de 1,000 req/seg.
  + **Mantenibilidad:** Cobertura mínima del \(85%\) en pruebas unitarias automáticas del componente principal.
  + **Fiabilidad y Seguridad:** \(0\) defectos críticos o bloqueantes de severidad alta antes de la liberación a producción.

**3. Estrategia Técnica por Atributos de Calidad *(ISO 25010 & ISO 29119-2)***

* **3.1 Pruebas de Adecuación Funcional:** Escenarios para confirmar la correcta visibilidad del estado según reglas de privacidad configuradas.
* **3.2 Pruebas de Eficiencia del Desempeño:** Pruebas de carga y estrés para validar el comportamiento temporal y la utilización de recursos en el servidor.
* **3.3 Pruebas de Seguridad:** Validación del cifrado de extremo a extremo, tokens de autenticación y prevención de fugas de datos de presencia.
* **3.4 Pruebas de Mantenibilidad:** Análisis estático de código mediante linters para verificar modularidad y reusabilidad previo al merge de código.

**4. Infraestructura, Control de Configuración y Herramientas *(ISO 9001 & GCS)***

* **4.1 Elementos de Configuración Bajo Control (CIs):**
  + ChatModule.java (v3.0) — Lógica de visualización y orden de mensajes.
  + user\_messages.db — Esquema de base de datos de preferencias.
  + config.yaml — Archivo de configuración de endpoints.
  + TC\_PRIV\_01 a TC\_PRIV\_05 — Especificación de casos de prueba.
* **4.2 Procedimiento de Control de Cambios:** Todas las modificaciones deben registrarse mediante la Solicitud de Cambio CHG-WA-2025-01, someterse a evaluación de impacto (Riesgo: Medio/Alto) y ser aprobadas por el CAB antes de su integración.
* **4.3 Herramientas:** Git para control de versiones, GitHub Actions para ejecución de CI/CD y SonarQube para auditoría de mantenibilidad.

**5. Proceso Dinámico de Pruebas y Trazabilidad *(ISO/IEC/IEEE 29119 Partes 2 y 3)***

* **5.1 Matriz de Trazabilidad Bidireccional:**

|  |  |  |  |  |
| --- | --- | --- | --- | --- |
| **Requisito de Negocio** | **Solicitud de Cambio (RFC)** | **Elemento de Configuración (CI)** | **Caso de Prueba (ISO 29119)** | **Resultado Esperado / Estado** |
| **REQ-PRIV-01:** Ocultar estado a contactos seleccionados | CHG-WA-2025-01 | ChatModule.java (v3.0) | TC\_PRIV\_01 | El estado "En línea" no es visible para usuarios restringidos. **[Exitoso]** |
| **REQ-PERF-01:** Latencia de sincronización < 150ms | CHG-WA-2025-01 | config.yaml (v3.0) | TC\_PERF\_01 | Tiempo de respuesta promedio en staging: \(110\text{ ms}\). **[Exitoso]** |

* **5.2 Criterios de Cierre y Suspensión:** Las pruebas se suspenden si se detecta una vulnerabilidad que exponga el estado a usuarios no autorizados.

**6. Evaluación del Desempeño y Cierre del Plan *(ISO 9001 & ISO 25001)***

* **6.1 Resumen del Informe de Cierre (*Test Completion Report*):**
  + Casos ejecutados: 25 / Aprobados: 25 (\(100%\) de éxito).
  + No conformidades: 2 hallazgos menores corregidos y re-probados.
* **6.2 Auditoría de Configuración y Liberación:** Confirmación de que el tag v3.0.0 en Git contiene todos los artefactos aprobados, la documentación actualizada y la firma de conformidad para el despliegue.