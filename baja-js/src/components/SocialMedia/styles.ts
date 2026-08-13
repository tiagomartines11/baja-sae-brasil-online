import styled from 'styled-components'

export const SocialContainer = styled.div`
  padding-right: ${props => props.theme.spacings.xsmall};
  display: flex;
  justify-content: end;
  gap: 20px;
`
export const SocialLink = styled.a`
  color: ${props => props.theme.colors.primary};
  font-size: ${props => props.theme.font.sizes.xxlarge};
  transition: color 0.3s;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-direction: column;
  text-decoration: none;

  &:hover {
    color:${props => props.theme.colors.secondary};
  }
`;