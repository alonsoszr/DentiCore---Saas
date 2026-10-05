<?php

namespace App\Modules\Scheduling\Enums;

/**
 * Catálogo de eventos de notificación (SDD §4.8, DD-10). Cada evento tiene su plantilla de
 * correo en `resources/views/mail/notifications/<evento>(.text).blade.php`; las plantillas se
 * agregan en la tarea que emite el evento.
 */
enum NotificationEvent: string
{
    case InvitacionActivacion = 'invitacion_activacion';
    case RestablecimientoContrasena = 'restablecimiento_contrasena';
    case CuentaBloqueada = 'cuenta_bloqueada';
    case CambioSeguridad = 'cambio_seguridad';
    case ClinicaSuspendida = 'clinica_suspendida';
    case ClinicaReactivada = 'clinica_reactivada';
    case ClinicaCancelada = 'clinica_cancelada';
    case ConsentimientoConstancia = 'consentimiento_constancia';
    case CitaCreada = 'cita_creada';
    case CitaModificada = 'cita_modificada';
    case CitaCancelada = 'cita_cancelada';
    case CitaRecordatorio = 'cita_recordatorio';
    case PresupuestoEmitido = 'presupuesto_emitido';
    case PresupuestoAceptado = 'presupuesto_aceptado';
    case PresupuestoPorVencer = 'presupuesto_por_vencer';
    case OtpPresupuesto = 'otp_presupuesto';
    case AlertaRiesgoAlto = 'alerta_riesgo_alto';
    case AtencionCierreIncompleto = 'atencion_cierre_incompleto';
    case ControlPeriodicoRecordatorio = 'control_periodico_recordatorio';
    case ListaEsperaCoincidencia = 'lista_espera_coincidencia';
    case EncuestaSatisfaccion = 'encuesta_satisfaccion';
    case RespuestaArco = 'respuesta_arco';
    case ArcoPlazoProximo = 'arco_plazo_proximo';
    case IncidentePlazo = 'incidente_plazo';
    case CuotaIa80 = 'cuota_ia_80';
    case AlertaDesempeno = 'alerta_desempeno';
    case ConsultaMasiva = 'consulta_masiva';
}
