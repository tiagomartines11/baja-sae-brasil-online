import styled from 'styled-components'

export const Logo = styled.img`
  width: 100%;
  height: auto; 
  max-width: 10rem;
  `

export const HeaderContainer = styled.header`
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  width: 100%;
  align-items: center;
  padding: ${props => props.theme.spacings.xsmall};
  border-bottom: 1px solid ${props => props.theme.colors.border};
  background-color: ${props => props.theme.colors.background};
`

export const logoContainer = styled.div`
  display: flex;
  align-items: center;
  justify-content: start;
  margin-right: 20px;`

export const Nav = styled.nav`
  display: flex;
  justify-content: center;
  gap: 20px;
  font-size: ${props => props.theme.font.sizes.medium};
  font-family: ${props => props.theme.font.family};
  font-weight: bold;
  color: ${props => props.theme.colors.white};

  & a {
    text-decoration: none;
    color: inherit;
    transition: color 0.3s ease;

    &:hover {
      color: ${props => props.theme.colors.primary};
    }
  }
`