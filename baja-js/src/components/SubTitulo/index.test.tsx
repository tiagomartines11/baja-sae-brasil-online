import { render, screen } from '@testing-library/react'

import SubTitulo from '.'

describe('<SubTitulo />', () => {
  it('should render the heading', () => {
    const { container } = render(<SubTitulo />)

    expect(
      screen.getByRole('heading', { name: /SubTitulo/i })
    ).toBeInTheDocument()

    expect(container.firstChild).toMatchSnapshot()
  })
})