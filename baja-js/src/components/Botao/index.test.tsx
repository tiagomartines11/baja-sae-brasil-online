import { render, screen } from '@testing-library/react'

import Botao from '.'

describe('<Botao />', () => {
  it('should render the heading', () => {
    const { container } = render(<Botao />)

    expect(
      screen.getByRole('heading', { name: /Botao/i })
    ).toBeInTheDocument()

    expect(container.firstChild).toMatchSnapshot()
  })
})