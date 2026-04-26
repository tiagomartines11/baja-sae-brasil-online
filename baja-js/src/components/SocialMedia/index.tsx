import * as S from './styles'
import {
  FaFacebook,
  FaInstagram,
  FaTwitter,
  FaLinkedin,
  FaYoutube
} from 'react-icons/fa'
export type SocialMediaProps = {
  redesSociais?: {
    instagram?: string
    facebook?: string
    twitter?: string
    linkedin?: string
    youtube?: string
  }
}
const SocialMedia = (props: SocialMediaProps) => (
  <S.SocialContainer>
    {props.redesSociais?.instagram && (
      <S.SocialLink
        href={props.redesSociais?.instagram}
        target="_blank"
        rel="noopener noreferrer"
      >
        <FaInstagram />
      </S.SocialLink>
    )}
    {props.redesSociais?.facebook && (
      <S.SocialLink
        href={props.redesSociais?.facebook}
        target="_blank"
        rel="noopener noreferrer"
      >
        <FaFacebook />
      </S.SocialLink>
    )}
    {props.redesSociais?.twitter && (
      <S.SocialLink
        href={props.redesSociais?.twitter}
        target="_blank"
        rel="noopener noreferrer"
      >
        <FaTwitter />
      </S.SocialLink>
    )}
    {props.redesSociais?.linkedin && (
      <S.SocialLink
        href={props.redesSociais?.linkedin}
        target="_blank"
        rel="noopener noreferrer"
      >
        <FaLinkedin />
      </S.SocialLink>
    )}
    {props.redesSociais?.youtube && (
      <S.SocialLink
        href={props.redesSociais?.youtube}
        target="_blank"
        rel="noopener noreferrer"
      >
        <FaYoutube />
      </S.SocialLink>
    )}
  </S.SocialContainer>
)

export default SocialMedia
