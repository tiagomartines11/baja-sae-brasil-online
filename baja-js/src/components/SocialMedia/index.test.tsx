import { render, screen } from '@testing-library/react'

import SocialMedia from '.'

describe('<SocialMedia />', () => {
  it('should render the heading', () => {
    const { container } = render(<SocialMedia />)

    expect(
      screen.getByRole('heading', { name: /SocialMedia/i })
    ).toBeInTheDocument()

    expect(container.firstChild).toMatchSnapshot()
  })
})