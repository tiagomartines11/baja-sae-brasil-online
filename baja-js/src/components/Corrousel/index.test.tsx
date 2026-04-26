import { render, screen } from '@testing-library/react'

import Corrousel from '.'

describe('<Corrousel />', () => {
  it('should render the heading', () => {
    const { container } = render(<Corrousel />)

    expect(
      screen.getByRole('heading', { name: /Corrousel/i })
    ).toBeInTheDocument()

    expect(container.firstChild).toMatchSnapshot()
  })
})