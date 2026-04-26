import * as S from './styles'
export type Props = {
  children: React.ReactNode
}
const SubTitulo = ({ children }: Props) => <S.SubTitle>{children}</S.SubTitle>

export default SubTitulo
