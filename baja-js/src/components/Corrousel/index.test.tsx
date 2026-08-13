import { screen } from '@testing-library/react'

import { renderWithTheme } from '@/utils/tests/helpers'

import Corrousel from '.'

describe('<Corrousel />', () => {
  it('should render the heading', () => {
    renderWithTheme(<Corrousel />)

    expect(
      screen.getByRole('heading', { name: /Corrousel/i })
    ).toBeInTheDocument()
  })
})
