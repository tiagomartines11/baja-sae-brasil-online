import { screen } from '@testing-library/react'

import { renderWithTheme } from '@/utils/tests/helpers'

import Footer from '.'

describe('<Footer />', () => {
  it('should render the children', () => {
    renderWithTheme(<Footer>Baja SAE Brasil</Footer>)

    expect(screen.getByRole('contentinfo')).toHaveTextContent(
      'Baja SAE Brasil'
    )
  })
})
