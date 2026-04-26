import { render, screen } from '@testing-library/react'

import Loading from '.'

describe('<Loading />', () => {
  it('should render the heading', () => {
    const { container } = render(<Loading />)

    expect(
      screen.getByRole('heading', { name: /Loading/i })
    ).toBeInTheDocument()

    expect(container.firstChild).toMatchSnapshot()
  })
})