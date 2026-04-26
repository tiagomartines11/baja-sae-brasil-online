import SocialMedia from '../SocialMedia'
import * as S from './styles'
export type HeaderProps = {
  redesSociais?: {
    instagram?: string
    facebook?: string
    twitter?: string
    linkedin?: string
    youtube?: string
  }
  logo?: string
  logoAlt?: string
}
const Header = (props: HeaderProps) => {
  return (
    <S.HeaderContainer>
      <S.logoContainer>
        {props.logo && <S.Logo src={props.logo} alt={props.logoAlt} />}
      </S.logoContainer>
      <S.Nav>
        <a href="https://resultados.bajasaebrasil.net/">Resultados</a>
        <a href="https://forum.bajasaebrasil.net/">Fórum</a>
        <a href="https://certificado.bajasaebrasil.net/">Certificados</a>
      </S.Nav>
      <SocialMedia redesSociais={props.redesSociais} />
    </S.HeaderContainer>
  )
}

export default Header
