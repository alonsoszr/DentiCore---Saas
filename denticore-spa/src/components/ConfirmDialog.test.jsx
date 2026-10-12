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
})
