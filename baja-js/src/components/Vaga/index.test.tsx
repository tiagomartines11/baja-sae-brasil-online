import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { renderWithTheme } from '@/utils/tests/helpers'
import { IVaga } from '@/types/Vagas'

import Vaga from '.'

const data: IVaga = {
  titulo: 'Desenvolvedor Front-end',
  atividades: ['Manter o site', 'Criar componentes'],
  requisitos: ['React', 'TypeScript'],
  link: 'https://bajasaebrasil.net/vagas/front-end'
}

describe('<Vaga />', () => {
  it('should render the title, activities and requirements', () => {
    renderWithTheme(<Vaga data={data} />)

    expect(
      screen.getByRole('heading', { name: /Desenvolvedor Front-end/i })
    ).toBeInTheDocument()

    data.atividades.forEach(atividade =>
      expect(screen.getByText(atividade)).toBeInTheDocument()
    )
    data.requisitos.forEach(requisito =>
      expect(screen.getByText(requisito)).toBeInTheDocument()
    )
  })

  it('should start collapsed and expand when clicked', async () => {
    renderWithTheme(<Vaga data={data} />)

    expect(screen.getByText('▼')).toBeInTheDocument()

    await userEvent.click(
      screen.getByRole('heading', { name: /Desenvolvedor Front-end/i })
    )

    expect(screen.getByText('▲')).toBeInTheDocument()
  })

  it('should start expanded when isFirst is true', () => {
    renderWithTheme(<Vaga data={data} isFirst />)

    expect(screen.getByText('▲')).toBeInTheDocument()
  })

  it('should open the job link in a new tab', async () => {
    const open = jest.spyOn(window, 'open').mockImplementation(() => null)
    renderWithTheme(<Vaga data={data} isFirst />)

    await userEvent.click(screen.getByRole('button', { name: /Inscrever-se/i }))

    expect(open).toHaveBeenCalledWith(data.link, '_blank')

    open.mockRestore()
  })
})
