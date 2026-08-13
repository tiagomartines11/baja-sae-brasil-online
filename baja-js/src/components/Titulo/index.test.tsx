import { screen } from '@testing-library/react'

import { renderWithTheme } from '@/utils/tests/helpers'

import Titulo from '.'

describe('<Titulo />', () => {
  it('should render the heading', () => {
    renderWithTheme(<Titulo>Baja SAE Brasil</Titulo>)

    expect(
      screen.getByRole('heading', { name: /Baja SAE Brasil/i })
    ).toBeInTheDocument()
  })
})
