import { useMutation } from '@tanstack/react-query'
import { useParams } from 'react-router-dom'
import { apiClient } from '../../api/client'
import { generalError } from '../../api/errors'
import { usePublicClinic } from '../../auth/usePublicClinic'
import { Alert } from '../../components/Alert'
import { AuthLayout } from '../../components/auth/AuthLayout'
import { ClinicHeader } from '../../components/auth/ClinicHeader'
import { formatDate, formatTime } from '../../ui/format'
import { Spinner } from '../../ui/icons'

/**
 * Confirmación de la cita por el enlace del correo (/c/:slug/confirmar/:token; CUS-49; RF-150): sin
 * sesión. La cita se confirma solo al pulsar el botón, para que la vista previa de un correo no
 * consuma el enlace. Un enlace vencido, usado o inexistente responde 404 (RNF-112).
 */
export function AppointmentConfirmPage() {
  const { slug, token } = useParams()
  const clinic = usePublicClinic(slug)
  const confirm = useMutation({
    mutationFn: async () => (await apiClient.post(`/public/appointment-confirmations/${token}`)).data.data,
  })

  const status = confirm.error?.response?.status
  const appointment = confirm.data

  return (
    <AuthLayout variant="clinic" clinicName={appointment?.clinic ?? clinic.name}>
      <ClinicHeader name={appointment?.clinic ?? clinic.name} />

      {appointment ? (
        <>
          <h1 className="text-headline-lg-mobile sm:text-headline-lg">Tu cita está confirmada</h1>
          <div className="card mt-5">
            <p className="m-0 text-title-md">
              {formatDate(appointment.starts_at, appointment.timezone)} a las{' '}
              {formatTime(appointment.starts_at, appointment.timezone)}
            </p>
            <p className="m-0 muted">
              {appointment.type} con {appointment.dentist}
            </p>
          </div>
          <p className="text-body-md text-on-surface-variant">Si no puedes asistir, comunícate con la clínica.</p>
        </>
      ) : status === 404 ? (
        <>
          <h1 className="text-headline-lg-mobile sm:text-headline-lg">Este enlace ya no es válido</h1>
          <p className="mt-1.5 mb-5 text-body-md text-on-surface-variant">
            El enlace se usa una sola vez y vence al inicio de la cita. Si necesitas confirmar o cambiar tu cita,
            comunícate con la clínica.
          </p>
        </>
      ) : (
        <>
          <h1 className="text-headline-lg-mobile sm:text-headline-lg">Confirma tu cita</h1>
          <p className="mt-1.5 mb-5 text-body-md text-on-surface-variant">
            Pulsa el botón para avisar a la clínica que asistirás.
          </p>
          {confirm.isError && (
            <Alert tone="error" className="mb-5">
              {generalError(confirm.error)}
            </Alert>
          )}
          <button
            type="button"
            className="btn min-h-12 w-full"
            onClick={() => confirm.mutate()}
            disabled={confirm.isPending}
          >
            {confirm.isPending && <Spinner />}
            Confirmar mi cita
          </button>
        </>
      )}
    </AuthLayout>
  )
}
