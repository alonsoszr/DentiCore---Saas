import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { apiClient } from '../../api/client'
import { fieldErrors, generalError } from '../../api/errors'
import { useAuth } from '../../auth/useAuth'
import { ConfirmDialog } from '../../components/ConfirmDialog'
import { Field } from '../../components/Field'
import { PageHeader } from '../../components/PageHeader'
import { PasswordField } from '../../components/auth/PasswordField'

const passwordFields = [
  ['current_password', 'Contraseña actual', 'current-password'],
  ['password', 'Nueva contraseña', 'new-password'],
  ['password_confirmation', 'Confirma la nueva contraseña', 'new-password'],
]
const mandatoryRoles = ['super_admin', 'clinic_admin']

function usePrivateRequest() {
  const [state, setState] = useState({})
  return {
    ...state,
    reset: () => setState({}),
    run: async (request, onSuccess) => {
      setState({ isPending: true })
      try {
        onSuccess(await request())
        setState({ isSuccess: true })
      } catch (error) {
        const { status, data } = error.response ?? {}
        setState({
          error: status ? { response: { status, data: { detail: data?.detail, errors: data?.errors } } } : {},
        })
      }
    },
  }
}

function MutationFeedback({ mutation, success }) {
  const error = generalError(mutation.error)
  return (
    <>
      {error && (
        <p role="alert" className="alert alert-error">
          {error}
        </p>
      )}
      {mutation.isSuccess && <p role="status">{success}</p>}
    </>
  )
}

function TwoFactorSettings({ mandatory }) {
  const [action, setAction] = useState(null)
  const [code, setCode] = useState('')
  const [codes, setCodes] = useState([])
  const [disabled, setDisabled] = useState(false)
  const mutation = usePrivateRequest()
  const confirm = () =>
    mutation.run(
      async () => {
        if (action === 'disable') {
          await apiClient.delete('/auth/2fa', { data: { code } })
          return []
        }
        return (await apiClient.post('/auth/2fa/recovery-codes', { code })).data.recovery_codes
      },
      (freshCodes) => {
        setDisabled(action === 'disable')
        setCodes(freshCodes)
        setCode('')
        setAction(null)
      },
    )
  const open = (next) => {
    mutation.reset()
    setDisabled(false)
    setCodes([])
    setCode('')
    setAction(next)
  }
  return (
    <section className="card" aria-label="Segundo factor">
      <h2>Segundo factor</h2>
      <div className="form-actions">
        <button type="button" className="btn btn-secondary" onClick={() => open('regenerate')}>
          Regenerar códigos de recuperación
        </button>
        {!mandatory && (
          <button type="button" className="btn btn-secondary" onClick={() => open('disable')}>
            Desactivar segundo factor
          </button>
        )}
      </div>
      {mutation.isSuccess && disabled && <p role="status">Segundo factor desactivado.</p>}
      {codes.length > 0 && (
        <div className="form-section">
          <p role="status">Guarda estos códigos de recuperación. Se muestran una sola vez.</p>
          <ul>
            {codes.map((item) => (
              <li key={item}>
                <code>{item}</code>
              </li>
            ))}
          </ul>
          <button
            type="button"
            className="btn btn-secondary"
            onClick={() => {
              setCodes([])
              mutation.reset()
            }}
          >
            Ocultar códigos
          </button>
        </div>
      )}
      {action && (
        <ConfirmDialog
          title={action === 'disable' ? 'Desactivar segundo factor' : 'Regenerar códigos de recuperación'}
          confirmLabel="Confirmar"
          destructive
          busy={mutation.isPending}
          error={generalError(mutation.error)}
          confirmDisabled={!/^\d{6}$/.test(code)}
          onCancel={() => {
            if (mutation.isPending) return
            setCode('')
            setAction(null)
          }}
          onConfirm={confirm}
        >
          <p>
            {action === 'disable'
              ? 'Tu cuenta dejará de pedir este segundo factor al ingresar.'
              : 'Los códigos anteriores dejarán de funcionar.'}
          </p>
          <Field
            label="Código de la aplicación"
            name="profile-totp"
            inputMode="numeric"
            autoComplete="one-time-code"
            value={code}
            error={fieldErrors(mutation.error).code}
            maxLength={6}
            onChange={(event) => setCode(event.target.value.replace(/\D/g, ''))}
          />
        </ConfirmDialog>
      )}
    </section>
  )
}

export function ProfilePage() {
  const { user } = useAuth()
  const cache = useQueryClient()
  const [name, setName] = useState(user.name)
  const [passwords, setPasswords] = useState({})
  const profile = useMutation({
    mutationFn: async (name) => (await apiClient.patch('/me/profile', { name })).data.data,
    onSuccess: (updated) => {
      cache.setQueryData(['auth', 'me'], updated)
      setName(updated.name)
    },
  })
  const password = usePrivateRequest()
  return (
    <>
      <PageHeader title="Mi perfil" description={user.email} />
      <section className="card mb-4" aria-label="Datos de la cuenta">
        <h2>Datos de la cuenta</h2>
        <form
          onSubmit={(event) => {
            event.preventDefault()
            profile.mutate(name.trim())
          }}
        >
          <Field
            name="profile-name"
            label="Nombre"
            value={name}
            minLength={3}
            maxLength={150}
            required
            error={fieldErrors(profile.error).name}
            onChange={(event) => setName(event.target.value)}
          />
          <button type="submit" className="btn" disabled={profile.isPending || name.trim().length < 3}>
            Guardar nombre
          </button>
        </form>
        <MutationFeedback mutation={profile} success="Nombre actualizado." />
      </section>
      <section className="card mb-4" aria-label="Cambiar contraseña">
        <h2>Cambiar contraseña</h2>
        <form
          onSubmit={(event) => {
            event.preventDefault()
            if (!password.isPending)
              password.run(
                () => apiClient.put('/me/password', passwords),
                () => setPasswords({}),
              )
          }}
        >
          <div className="form-grid">
            {passwordFields.map(([key, label, autoComplete]) => (
              <PasswordField
                key={key}
                name={key}
                label={label}
                autoComplete={autoComplete}
                required
                maxLength={128}
                value={passwords[key] ?? ''}
                error={fieldErrors(password.error)[key]}
                onChange={(event) => setPasswords({ ...passwords, [key]: event.target.value })}
              />
            ))}
          </div>
          <button type="submit" className="btn" disabled={password.isPending}>
            Cambiar contraseña
          </button>
        </form>
        <MutationFeedback mutation={password} success="Contraseña actualizada. Las demás sesiones se cerraron." />
      </section>
      <TwoFactorSettings mandatory={mandatoryRoles.includes(user.role)} />
    </>
  )
}
