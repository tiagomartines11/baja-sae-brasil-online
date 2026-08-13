import * as S from './styles'
export type Props = {
  children: React.ReactNode
  size?: 'small' | 'medium' | 'large'
  color?: 'primary' | 'secondary'
}
const Titulo = ({ children, size = 'medium', color = 'primary' }: Props) => (
  <S.Title color={color} size={size}>
    {children}
  </S.Title>
)

export default Titulo
