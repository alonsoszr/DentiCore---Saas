import { useRef } from 'react'

const LENGTH = 6

/**
 * Código TOTP de 6 dígitos en casillas (docs/design/screens/README.md › OtpInput): admite
 * pegar los 6 dígitos, avanza solo y llama a `onComplete` al llenarse.
 */
export function OtpInput({ value, onChange, onComplete, disabled, label = 'Código de verificación', invalid }) {
  const inputs = useRef([])
  const digits = Array.from({ length: LENGTH }, (_, index) => value[index] ?? '')

  const update = (next, focusIndex) => {
    const clean = next.replace(/\D/g, '').slice(0, LENGTH)
    onChange(clean)
    inputs.current[Math.min(focusIndex, LENGTH - 1)]?.focus()
    if (clean.length === LENGTH) onComplete?.(clean)
  }

  const handleChange = (index, event) => {
    const typed = event.target.value.replace(/\D/g, '')
    if (!typed) return
    // Pegar varios dígitos en una casilla los reparte desde ella.
    const next = (value.slice(0, index) + typed).slice(0, LENGTH)
    update(next, index + typed.length)
  }

  const handleKeyDown = (index, event) => {
    if (event.key !== 'Backspace') return
    event.preventDefault()
    const target = digits[index] ? index : Math.max(0, index - 1)
    update(value.slice(0, target), target)
  }

  return (
    <fieldset className="otp" disabled={disabled}>
      <legend className="sr-only">{label}</legend>
      <div className="flex gap-2">
        {digits.map((digit, index) => (
          <input
            // Las casillas son posiciones fijas del código.
            key={index}
            ref={(element) => {
              inputs.current[index] = element
            }}
            className="otp-digit"
            aria-label={`Dígito ${index + 1} de ${LENGTH}`}
            aria-invalid={invalid ? true : undefined}
            inputMode="numeric"
            autoComplete={index === 0 ? 'one-time-code' : 'off'}
            maxLength={LENGTH}
            value={digit}
            onChange={(event) => handleChange(index, event)}
            onKeyDown={(event) => handleKeyDown(index, event)}
            onFocus={(event) => event.target.select()}
          />
        ))}
      </div>
    </fieldset>
  )
}
