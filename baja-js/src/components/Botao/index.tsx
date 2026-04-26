import * as S from './styles'

export type Props = {
  children: React.ReactNode
  onClick?: () => void
  disabled?: boolean
  color?: 'primary' | 'secondary'
  size?: 'small' | 'medium' | 'large'
}
const Botao = ({
  children,
  onClick,
  disabled,
  color = 'primary',
  size = 'medium'
}: Props) => (
  <S.Button color={color} onClick={onClick} disabled={disabled} size={size}>
    {children}
  </S.Button>
)

export default Botao
