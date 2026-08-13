import { screen } from '@testing-library/react'

import { renderWithTheme } from '@/utils/tests/helpers'

import SocialMedia from '.'

describe('<SocialMedia />', () => {
  it('should render a link for each social network provided', () => {
    renderWithTheme(
      <SocialMedia
        redesSociais={{
          instagram: 'https://instagram.com/bajasaebrasil',
          youtube: 'https://youtube.com/bajasaebrasil'
        }}
      />
    )

    const links = screen.getAllByRole('link')

    expect(links).toHaveLength(2)
    expect(links[0]).toHaveAttribute(
      'href',
      'https://instagram.com/bajasaebrasil'
    )
    expect(links[1]).toHaveAttribute(
      'href',
      'https://youtube.com/bajasaebrasil'
    )
  })

  it('should not render any link when no social network is provided', () => {
    renderWithTheme(<SocialMedia />)

    expect(screen.queryByRole('link')).not.toBeInTheDocument()
  })
})
