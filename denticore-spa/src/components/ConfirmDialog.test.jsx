import { useState } from 'react'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { ConfirmDialog } from './ConfirmDialog'

function Opener() {
  const [open, setOpen] = useState(false)
  return (
    <>
      <button type="button" onClick={() => setOpen(true)}>
        Anular
      </button>
      {open && (
        <ConfirmDialog
          title="Anular el abono"
          confirmLabel="Anular abono"
          onConfirm={() => setOpen(false)}
          onCancel={() => setOpen(false)}
        >
          <p>No se puede deshacer.</p>
        </ConfirmDialog>
      )}
    </>
  )
}

describe('ConfirmDialog', () => {
  it('moves the focus into the dialog and returns it to the opener when closed', async () => {
    render(<Opener />)

    await userEvent.click(screen.getByRole('button', { name: 'Anular' }))
    expect(screen.getByRole('button', { name: 'Anular abono' })).toHaveFocus()

    await userEvent.keyboard('{Escape}')
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Anular' })).toHaveFocus()
  })

  it('keeps the focus inside the modal dialog with Tab and Shift+Tab', async () => {
    render(<Opener />)

    await userEvent.click(screen.getByRole('button', { name: 'Anular' }))
    await userEvent.tab()
    expect(screen.getByRole('button', { name: 'Cancelar' })).toHaveFocus()
    await userEvent.tab()
    expect(screen.getByRole('button', { name: 'Anular abono' })).toHaveFocus()
    await userEvent.tab({ shift: true })
    expect(screen.getByRole('button', { name: 'Cancelar' })).toHaveFocus()
  })
})
