import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { apiClient } from '../../api/client'
import { fieldErrors, generalError } from '../../api/errors'
import { ConfirmDialog } from '../../components/ConfirmDialog'
import { Field } from '../../components/Field'

const recovery = {
  unlock: {
    label: 'Desbloquear acceso',
    effect: 'Se eliminará el bloqueo de acceso de esta cuenta.',
    success: 'Acceso desbloqueado.',
  },
  '2fa/reset': {
    label: 'Restablecer segundo factor',
    effect: 'Se cerrarán sus sesiones y deberá configurar el segundo factor al volver a ingresar.',
    success: 'Segundo factor restablecido.',
  },
}

export function AccessRecoveryActions({ user, platform = false }) {
  const [action, setAction] = useState(null)
  const cache = useQueryClient()
  const request = useMutation({
    mutationFn: (operation) => {
      const prefix = platform ? '/platform/clinic-admins' : '/users'
      return apiClient.post(`${prefix}/${user.id}/${operation}`)
    },
    onSuccess: () => {
      setAction(null)
      cache.invalidateQueries({ queryKey: [platform ? 'tenants' : 'users'] })
    },
  })
  if (!user.id) return null
  return (
    <div className="flex flex-wrap items-center gap-2">
      {Object.entries(recovery)
        .filter(([operation]) => operation !== 'unlock' || user.status === 'bloqueado_temporal')
        .map(([operation, { label }]) => (
          <button
            type="button"
            className="btn-link"
            key={operation}
            onClick={() => {
              request.reset()
              setAction(operation)
            }}
          >
            {label}
          </button>
        ))}
      {request.isSuccess && <span role="status">{recovery[request.variables].success}</span>}
      {action && (
        <ConfirmDialog
          title={`${recovery[action].label} de ${user.name}`}
          confirmLabel="Confirmar"
          destructive={action === '2fa/reset'}
          busy={request.isPending}
          error={generalError(request.error) ?? Object.values(fieldErrors(request.error)).join(' ')}
          onConfirm={() => request.mutate(action)}
          onCancel={() => {
            if (!request.isPending) setAction(null)
          }}
        >
          <p>{recovery[action].effect}</p>
        </ConfirmDialog>
      )}
    </div>
  )
}

export function ClinicCancellation({ tenant, onUpdated }) {
  const [open, setOpen] = useState(false)
  const [reason, setReason] = useState('')
  const request = useMutation({
    mutationFn: async () =>
      (await apiClient.post(`/platform/tenants/${tenant.id}/cancel`, { reason: reason.trim() })).data.data,
    onSuccess: (updated) => {
      setOpen(false)
      setReason('')
      onUpdated(updated)
    },
  })
  return (
    <section className="card" aria-label="Cancelación de clínica">
      <h2>Cancelación de clínica</h2>
      {tenant.status === 'cancelada' || request.isSuccess ? (
        <p role="status">Clínica cancelada.</p>
      ) : (
        <button
          type="button"
          className="btn btn-danger"
          onClick={() => {
            request.reset()
            setReason('')
            setOpen(true)
          }}
        >
          Cancelar clínica
        </button>
      )}
      {open && (
        <ConfirmDialog
          title={`¿Cancelar «${tenant.name}»?`}
          confirmLabel="Confirmar cancelación"
          cancelLabel="Volver"
          destructive
          busy={request.isPending}
          error={generalError(request.error)}
          confirmDisabled={reason.trim().length < 3}
          onConfirm={() => request.mutate()}
          onCancel={() => {
            if (!request.isPending) setOpen(false)
          }}
        >
          <p>
            El personal perderá el acceso operativo. La clínica tendrá 90 días para solicitar una exportación de sus
            datos.
          </p>
          <Field
            label="Motivo de la cancelación"
            name="cancel-clinic-reason"
            maxLength={500}
            value={reason}
            error={fieldErrors(request.error).reason}
            onChange={(event) => setReason(event.target.value)}
          />
        </ConfirmDialog>
      )}
    </section>
  )
}
