import { screen } from '@testing-library/react'

import { renderWithTheme } from '@/utils/tests/helpers'

import Header from '.'

describe('<Header />', () => {
  it('should render the navigation links', () => {
    renderWithTheme(<Header />)

    expect(screen.getByRole('link', { name: /Resultados/i })).toHaveAttribute(
      'href',
      'https://resultados.bajasaebrasil.net/'
    )
    expect(screen.getByRole('link', { name: /Fórum/i })).toHaveAttribute(
      'href',
      'https://forum.bajasaebrasil.net/'
    )
    expect(screen.getByRole('link', { name: /Certificados/i })).toHaveAttribute(
      'href',
      'https://certificado.bajasaebrasil.net/'
    )
  })

  it('should render the logo when provided', () => {
    renderWithTheme(<Header logo="/img/logo.png" logoAlt="Baja SAE Brasil" />)

    expect(screen.getByRole('img', { name: /Baja SAE Brasil/i })).toHaveAttribute(
      'src',
      '/img/logo.png'
    )
  })

  it('should not render the logo when not provided', () => {
    renderWithTheme(<Header />)

    expect(screen.queryByRole('img')).not.toBeInTheDocument()
  })
})
