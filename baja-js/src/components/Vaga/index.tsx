import { IVaga } from '@/types/Vagas'
import * as S from './styles'
import { useState } from 'react'
import Botao from '../Botao'
export type Props = {
  data: IVaga
  isFirst?: boolean
}
const Vaga = ({ data, isFirst = false }: Props) => {
  const [expanded, setExpanded] = useState(isFirst)
  const arrowIcon = expanded ? '▲' : '▼'

  const handleClick = () => {
    setExpanded(!expanded)
  }

  const redirectTo = () => {
    window.open(data.link, '_blank')
  }

  return (
    <S.Container>
      <S.Header onClick={handleClick}>
        <S.Titulo>{data.titulo}</S.Titulo>
        <S.Seta>{arrowIcon}</S.Seta>
      </S.Header>
      <S.Main expanded={expanded}>
        <S.SubTitulo>Atividades</S.SubTitulo>
        <S.Lista>
          {data.atividades.map((atividade, index) => (
            <S.Item key={index}>{atividade}</S.Item>
          ))}
        </S.Lista>
        <S.SubTitulo>Requisitos</S.SubTitulo>
        <S.Lista>
          {data.requisitos.map((requisito, index) => (
            <S.Item key={index}>{requisito}</S.Item>
          ))}
        </S.Lista>
        <S.BotaoContainer>
          <Botao onClick={redirectTo} color="secondary" size="small">
            Inscrever-se
          </Botao>
        </S.BotaoContainer>
      </S.Main>
    </S.Container>
  )
}

export default Vaga
