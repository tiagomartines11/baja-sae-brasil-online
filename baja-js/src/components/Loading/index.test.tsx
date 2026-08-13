import { screen } from '@testing-library/react'

import { renderWithTheme } from '@/utils/tests/helpers'

import Loading from '.'

describe('<Loading />', () => {
  it('should render the heading', () => {
    renderWithTheme(<Loading />)

    expect(
      screen.getByRole('heading', { name: /Loading/i })
    ).toBeInTheDocument()
  })
})
