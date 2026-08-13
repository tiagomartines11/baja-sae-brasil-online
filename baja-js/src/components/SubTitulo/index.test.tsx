import { screen } from '@testing-library/react'

import { renderWithTheme } from '@/utils/tests/helpers'

import SubTitulo from '.'

describe('<SubTitulo />', () => {
  it('should render the children', () => {
    renderWithTheme(<SubTitulo>Etapa Nacional</SubTitulo>)

    expect(screen.getByText(/Etapa Nacional/i)).toBeInTheDocument()
  })
})
