import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { renderWithTheme } from '@/utils/tests/helpers'

import Botao from '.'

describe('<Botao />', () => {
  it('should render the children', () => {
    renderWithTheme(<Botao>Inscrever-se</Botao>)

    expect(
      screen.getByRole('button', { name: /Inscrever-se/i })
    ).toBeInTheDocument()
  })

  it('should call onClick when clicked', async () => {
    const onClick = jest.fn()
    renderWithTheme(<Botao onClick={onClick}>Inscrever-se</Botao>)

    await userEvent.click(screen.getByRole('button', { name: /Inscrever-se/i }))

    expect(onClick).toHaveBeenCalledTimes(1)
  })

  it('should not call onClick when disabled', async () => {
    const onClick = jest.fn()
    renderWithTheme(
      <Botao onClick={onClick} disabled>
        Inscrever-se
      </Botao>
    )

    const button = screen.getByRole('button', { name: /Inscrever-se/i })
    await userEvent.click(button)

    expect(button).toBeDisabled()
    expect(onClick).not.toHaveBeenCalled()
  })
})
