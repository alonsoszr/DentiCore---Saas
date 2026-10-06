import { useEffect, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import QRCode from 'qrcode'
import { useNavigate } from 'react-router-dom'
import { apiClient } from '../../api/client'
import { generalError } from '../../api/errors'
import { formatCountdown, retryAfterSeconds } from '../../api/retryAfter'
import { homePathFor, loginPathFor } from '../../auth/paths'
import { useAuth } from '../../auth/useAuth'
import { Alert } from '../../components/Alert'
import { OtpInput } from '../../components/auth/OtpInput'
import { Spinner } from '../../ui/icons'
import { groupKey } from './authHelpers'

const ROLE_LABELS = { super_admin: 'Súper Administrador', clinic_admin: 'Administrador de Clínica' }
const STEPS = ['Escanea', 'Confirma', 'Códigos']

/**
 * Configuración del segundo factor (A4; CUS-08; RF-038, CA-06.4). Ruta /admin/seguridad/2fa o
 * /c/:slug/app/seguridad/2fa (PEND-07), fuera del layout de la app. Al confirmar, la API
 * devuelve los 10 códigos de recuperación y un token `full` (PEND-04).
 */
export function TwoFactorSetupPage() {
  const { user, completeSession, logout } = useAuth()
  const navigate = useNavigate()
  const [step, setStep] = useState(0)
  const [qr, setQr] = useState(null)
  const [code, setCode] = useState('')
  const [session, setSession] = useState(null)
  const [saved, setSaved] = useState(false)
  const [lockSeconds, setLockSeconds] = useState(0)

  const setup = useMutation({
    mutationFn: async () => (await apiClient.post('/auth/2fa/setup')).data,
  })
  const confirm = useMutation({
    mutationFn: async (value) => (await apiClient.post('/auth/2fa/confirm', { code: value })).data,
    onSuccess: (data) => {
      completeSession(data)
      setSession(data)
      setStep(2)
    },
    onError: (error) => {
      setCode('')
      if (error?.response?.status === 429) setLockSeconds(retryAfterSeconds(error.response))
    },
  })

  const { mutate: startSetup } = setup
  useEffect(() => {
    startSetup()
  }, [startSetup])

  const otpauthUrl = setup.data?.otpauth_url
  useEffect(() => {
    if (!otpauthUrl) return
    // El QR se genera en el cliente a partir de la URI otpauth:// (no se pide una imagen).
    QRCode.toDataURL(otpauthUrl, { margin: 1, width: 200 })
      .then(setQr)
      .catch(() => setQr(null))
  }, [otpauthUrl])

  useEffect(() => {
    if (lockSeconds <= 0) return undefined
    const timer = setTimeout(() => setLockSeconds((value) => value - 1), 1000)
    return () => clearTimeout(timer)
  }, [lockSeconds])

  // Los códigos no se vuelven a mostrar: avisar antes de salir sin finalizar.
  useEffect(() => {
    if (step !== 2 || saved) return undefined
    const warn = (event) => {
      event.preventDefault()
      event.returnValue = ''
    }
    window.addEventListener('beforeunload', warn)
    return () => window.removeEventListener('beforeunload', warn)
  }, [step, saved])

  const slug = user?.tenant?.slug
  const codes = session?.recovery_codes ?? []
  const codesText = [
    'DentiCore · Códigos de recuperación',
    `Cuenta: ${user?.email ?? ''}`,
    user?.tenant?.name ? `Clínica: ${user.tenant.name}` : null,
    '',
    ...codes.map((item, index) => `${String(index + 1).padStart(2, '0')}. ${item}`),
  ]
    .filter((line) => line !== null)
    .join('\n')

  const signOut = async () => {
    await logout().catch(() => {})
    navigate(loginPathFor(slug), { replace: true })
  }

  const download = () => {
    const url = URL.createObjectURL(new Blob([codesText], { type: 'text/plain' }))
    const link = document.createElement('a')
    link.href = url
    link.download = 'denticore-codigos-de-recuperacion.txt'
    link.click()
    URL.revokeObjectURL(url)
  }

  const confirmStatus = confirm.error?.response?.status

  return (
    <div className="min-h-dvh bg-surface-container-low px-4 py-8">
      <header className="mx-auto mb-6 flex max-w-[560px] items-center justify-between">
        <p className="m-0 text-title-lg font-bold text-primary">DentiCore</p>
        <button type="button" className="btn-link" onClick={signOut}>
          Cerrar sesión
        </button>
      </header>

      <main className="card mx-auto max-w-[560px]">
        {user && (
          <div className="mb-5 flex flex-wrap items-center gap-2 border-b border-outline-variant pb-4">
            <span className="text-body-md">{user.email}</span>
            <span className="badge">{ROLE_LABELS[user.role] ?? user.role}</span>
            {user.tenant?.name && <span className="muted">{user.tenant.name}</span>}
          </div>
        )}

        <ol className="mb-5 flex list-none gap-4 p-0 text-label-md" aria-label="Pasos">
          {STEPS.map((label, index) => (
            <li key={label} aria-current={index === step ? 'step' : undefined}>
              <span className={index === step ? 'font-bold text-primary' : 'text-on-surface-variant'}>
                {index + 1}. {label}
              </span>
            </li>
          ))}
        </ol>

        {step === 0 && (
          <>
            <h1 className="text-headline-md">Configuración del segundo factor</h1>
            <Alert tone="info" className="my-4">
              Tu rol requiere verificación en dos pasos para proteger los datos de los pacientes. No podrás continuar
              hasta completarla.
            </Alert>
            {setup.isError && <Alert tone="error">{generalError(setup.error)}</Alert>}
            {setup.isPending && <p className="empty">Generando…</p>}
            {qr && <img src={qr} alt="Código QR para tu aplicación de autenticación" width={200} height={200} />}
            <p className="text-body-md">Usa Google Authenticator, Microsoft Authenticator u otra app compatible.</p>
            {setup.data && (
              <details>
                <summary>¿No puedes escanear el código? Clave de configuración manual</summary>
                <p className="mono" data-testid="manual-key">
                  {groupKey(setup.data.secret)}
                </p>
                <button
                  type="button"
                  className="btn btn-secondary"
                  onClick={() => navigator.clipboard?.writeText(setup.data.secret)}
                >
                  Copiar
                </button>
              </details>
            )}
            <div className="form-actions">
              <button type="button" className="btn w-full" disabled={!setup.data} onClick={() => setStep(1)}>
                Continuar a confirmación
              </button>
            </div>
          </>
        )}

        {step === 1 && (
          <form
            onSubmit={(event) => {
              event.preventDefault()
              if (code.length === 6) confirm.mutate(code)
            }}
            noValidate
          >
            <h1 className="text-headline-md">Confirma la configuración</h1>
            <p className="text-body-md">Ingresa el código de 6 dígitos que muestra tu aplicación.</p>
            {confirmStatus === 422 && (
              <Alert tone="error" className="mb-4">
                Código incorrecto. Revisa que la hora de tu teléfono sea correcta e inténtalo de nuevo.
              </Alert>
            )}
            {confirmStatus === 429 && lockSeconds > 0 && (
              <Alert tone="error" className="mb-4">
                Demasiados intentos. Espera {formatCountdown(lockSeconds)}.
              </Alert>
            )}
            <OtpInput
              value={code}
              onChange={setCode}
              disabled={confirm.isPending || lockSeconds > 0}
              invalid={confirmStatus === 422}
            />
            <p className="muted">
              ¿Problemas de sincronización? Verifica que la hora de tu teléfono esté en modo automático.
            </p>
            <div className="form-actions">
              <button type="button" className="btn btn-secondary" onClick={() => setStep(0)}>
                Atrás
              </button>
              <button
                type="submit"
                className="btn"
                disabled={code.length !== 6 || confirm.isPending || lockSeconds > 0}
              >
                {confirm.isPending ? <Spinner /> : null}
                Confirmar
              </button>
            </div>
          </form>
        )}

        {step === 2 && (
          <>
            <h1 className="text-headline-md">Guarda tus códigos de recuperación</h1>
            <p className="text-body-md">
              Si pierdes acceso a tu aplicación de autenticación, estos códigos te permitirán iniciar sesión.
            </p>
            <Alert tone="warning" className="mb-4">
              Guárdalos antes de continuar. Cada código se usa una sola vez. No volverás a verlos.
            </Alert>
            <ol className="recovery-codes mono" aria-label="Códigos de recuperación">
              {codes.map((item, index) => (
                <li key={item}>
                  {String(index + 1).padStart(2, '0')}. {item}
                </li>
              ))}
            </ol>
            <div className="form-actions">
              <button type="button" className="btn btn-secondary" onClick={download}>
                Descargar
              </button>
              <button
                type="button"
                className="btn btn-secondary"
                onClick={() => navigator.clipboard?.writeText(codesText)}
              >
                Copiar
              </button>
            </div>
            <label className="checkbox">
              <input type="checkbox" checked={saved} onChange={(event) => setSaved(event.target.checked)} />
              Guardé mis códigos de recuperación
            </label>
            <div className="form-actions">
              <button
                type="button"
                className="btn w-full"
                disabled={!saved}
                onClick={() => navigate(homePathFor(session.user), { replace: true })}
              >
                Finalizar
              </button>
            </div>
          </>
        )}
      </main>
    </div>
  )
}
