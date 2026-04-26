import { render, screen } from '@testing-library/react'

import Vaga from '.'

describe('<Vaga />', () => {
  it('should render the heading', () => {
    const { container } = render(<Vaga />)

    expect(
      screen.getByRole('heading', { name: /Vaga/i })
    ).toBeInTheDocument()

    expect(container.firstChild).toMatchSnapshot()
  })
})