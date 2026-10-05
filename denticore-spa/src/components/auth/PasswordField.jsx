import { useState } from 'react'
import { EyeIcon, EyeOffIcon } from '../../ui/icons'
import { Field } from '../Field'

/**
 * Campo de contraseña con botón mostrar/ocultar (docs/design/screens/README.md). Permite
 * pegar y autocompletar: no bloquea gestores de contraseñas (WCAG 2.2, 3.3.8).
 */
export function PasswordField({ disabled, ...props }) {
  const [visible, setVisible] = useState(false)

  return (
    <Field
      {...props}
      disabled={disabled}
      type={visible ? 'text' : 'password'}
      endAdornment={
        <button
          type="button"
          className="field-toggle"
          onClick={() => setVisible((value) => !value)}
          aria-label={visible ? 'Ocultar contraseña' : 'Mostrar contraseña'}
          disabled={disabled}
        >
          {visible ? <EyeOffIcon /> : <EyeIcon />}
        </button>
      }
    />
  )
}
