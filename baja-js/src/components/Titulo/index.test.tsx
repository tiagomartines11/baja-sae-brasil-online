import { render, screen } from '@testing-library/react'

import Titulo from '.'

describe('<Titulo />', () => {
  it('should render the heading', () => {
    const { container } = render(<Titulo />)

    expect(
      screen.getByRole('heading', { name: /Titulo/i })
    ).toBeInTheDocument()

    expect(container.firstChild).toMatchSnapshot()
  })
})