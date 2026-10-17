# Guion de la demostración de E1

TASK-098. Cubre RES-08 y OB-02.

Demuestra el flujo de valor de E1 (SRS §17.1): **cita → historia clínica → riesgo → plan → presupuesto → procedimiento**.
- Solo usa datos sintéticos.
- Todo ocurre por la interfaz: nadie toca la base de datos durante la demostración.
- La misma secuencia corre en la CI como `e2e/value-flow.spec.ts` (evidencia del criterio 1 de TASK-098).

**Entorno:** el local de `INSTALACION.md`, secciones 3 a 6. Staging no existe hasta MS-10 (condición C-06 del TCR-E1).

**Duración:** unos 20 minutos.

## Antes de empezar (5 min)

1. Servicios y API según `INSTALACION.md`, con las terminales de la cola y del outbox abiertas (sección 6).
2. El modelo de riesgo 1.0.0 está activo: la sección 4 lo entrena y lo registra.
3. La demostración necesita un horario libre de Diego Odontólogo en la próxima hora: se registra la llegada desde 60 minutos antes del inicio (RN-50). Su horario de demostración es de 00:00 a 23:59 todos los días, así que solo cerca de la medianoche de Lima falta lugar.
4. Abre dos ventanas del navegador:
   - una de recepción, con `recepcion@clinica-demo.test`;
   - una del odontólogo, con `dentista@clinica-demo.test`.

   La contraseña de las dos es `password`. Cada pestaña tiene su propia sesión (RNF-094).
5. Abre la bandeja de Mailpit (http://localhost:8025) en una tercera pestaña.

**Datos sintéticos de la demostración:**

| Dato | Valor |
| :-- | :-- |
| Paciente | Elena Demostración (inventada) |
| DNI | 8 dígitos al azar, inventado (por ejemplo 45128376) |
| Nacimiento | 20/07/1985 |
| Teléfono | 987654321 |

## 1. Cita (recepción, 4 min)

| Paso | Acción | Qué se muestra | Requisito |
| :-: | :-- | :-- | :-- |
| 1.1 | *Registrar paciente*: DNI, nombres, apellidos, nacimiento, sexo y teléfono. | El aviso de ficha existente, si el DNI ya está, y la franja del paciente | CUS-14, RF-055 |
| 1.2 | *Consentimiento*: marcar (a) Atención odontológica y (d) Predicción de riesgo; confirmar con el DNI. | Las finalidades opcionales empiezan desmarcadas | CUS-17, RN-11 |
| 1.3 | *Riesgo*: nivel educativo y situación laboral; *Guardar variables*. | La recepción solo ve las variables sociodemográficas | CUS-54, §3.5 |
| 1.4 | *Agenda → Nueva cita*: buscar a la paciente, tipo *Consulta*, duración 15 min y un horario de Diego en la próxima hora; *Reservar*. | La cita *Programada* y el correo de confirmación en Mailpit, en español | CUS-47, CUS-52, RN-46 |
| 1.5 | *Registrar llegada*. | La cita pasa a *En atención* y la paciente aparece en la *Sala de espera* | CUS-50, RN-50 |

## 2. Historia clínica (odontólogo, 4 min)

| Paso | Acción | Qué se muestra | Requisito |
| :-: | :-- | :-- | :-- |
| 2.1 | *Pacientes →* la paciente *→ Historia clínica*. | La HC completa: la franja con las alergias, el odontograma vigente y la atención *Abierta* (T-170 mide este tiempo) | CUS-25, RNF-146 |
| 2.2 | Abrir la atención. En el odontograma, pieza 36, superficie oclusal: *Lesión de caries dental*, estado *CD (rojo)*; *Registrar hallazgo*. | La sigla y el color oficiales de la NTS 188 | CUS-22, RNF-151 |
| 2.3 | Abrir otra pestaña con otro paciente. | La franja avisa «Otro paciente abierto en otra pestaña» | RNF-146 |

## 3. Riesgo (odontólogo, 3 min)

| Paso | Acción | Qué se muestra | Requisito |
| :-: | :-- | :-- | :-- |
| 3.1 | *Riesgo → Variables*: CPOD 12, placa 90 %, 8 lesiones activas, azúcar 4+, sin pasta fluorada, 0 cepillados, menos de 1 visita al año; *Guardar*. | El registro queda completo para calcular | CUS-54 |
| 3.2 | *Ir al cálculo → Calcular riesgo*. | *Riesgo alto* con su probabilidad calibrada, los 3 factores que lo suben y los 3 que lo bajan, y la leyenda «no constituye diagnóstico» | CUS-55, CUS-56, RN-63 |
| 3.3 | *Alertas*. | La alerta de riesgo alto de la paciente (se reconoce con una acción) | CUS-57, CUS-58 |
| 3.4 | Opcional: `docker compose stop ml` y calcular otra vez. | «Predicción no disponible», y la atención sigue editable. Después, `docker compose start ml`. | RN-64, RNF-074 |

## 4. Plan (odontólogo, 2 min)

| Paso | Acción | Qué se muestra | Requisito |
| :-: | :-- | :-- | :-- |
| 4.1 | *Volver a la historia clínica → Pendientes*: seleccionar la caries de la 36; *Crear ítems del plan*. | El ítem enlazado al hallazgo | CUS-34, RF-111 |
| 4.2 | Título y procedimiento *RES-01 · Restauración con resina*; *Crear plan en borrador → Proponer al paciente*. | El plan *Propuesto*; el hallazgo deja de estar pendiente | CUS-33, RN-27 |

## 5. Presupuesto (odontólogo y recepción, 3 min)

| Paso | Acción | Qué se muestra | Requisito |
| :-: | :-- | :-- | :-- |
| 5.1 | *Nuevo presupuesto → Crear borrador → Emitir presupuesto*, y confirmar. | El número P-, el IGV, S/ 150.00 y el PDF generado | CUS-35, RN-29 a RN-34, RNF-063 |
| 5.2 | Recepción abre el presupuesto: *Registrar aceptación* con el DNI de la paciente y confirmar. | *Aceptado*, con la evidencia sellada | CUS-37, RN-36 |

## 6. Procedimiento (odontólogo, 3 min)

| Paso | Acción | Qué se muestra | Requisito |
| :-: | :-- | :-- | :-- |
| 6.1 | En la atención, *Procedimientos → Registrar* sobre la restauración, y confirmar. | El aviso de alergias en el diálogo y «Su evolución se agregó al odontograma» | CUS-39, RNF-149, RN-39 |
| 6.2 | *Volver a la historia clínica*, pieza 36. | La 36 restaurada (azul) y su historial con la entrada *Procedimiento* | CA-39.1, RNF-153 |
| 6.3 | *Cerrar atención*, y confirmar. | La atención *Cerrada*; la HC no se puede alterar (solo adendas) | CUS-27, RN-24 |

## Al terminar

- Nada de lo hecho requiere tocar la base de datos.
- Para repetir la demostración, basta otro DNI inventado.
- Para volver al estado inicial de la semilla: `php artisan migrate:fresh --seed --database=pgsql_migrator` y otra vez el paso del modelo de la sección 4 de `INSTALACION.md`. Así se regeneran solo datos sintéticos (RES-08).
