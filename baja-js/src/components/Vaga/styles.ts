import styled from 'styled-components'

export const Container = styled.section`
  display: flex;
  flex-direction: column;
  justify-content: center;
  border-radius: ${props => props.theme.border.radius};
  background-color: ${props => props.theme.colors.cardBackground};
  border: 1px solid ${props => props.theme.colors.border};
  width: 100%;
  max-width: ${props => props.theme.grid.container};
  margin-bottom: ${props => props.theme.spacings.xsmall};
  `

export const Titulo = styled.h1`
  font-family: ${props => props.theme.font.family};
  font-weight: 500;
  font-size: ${props => props.theme.font.sizes.large};
  margin: 0;
  padding: 0;
  color: ${props => props.theme.colors.heading};
`
export const SubTitulo = styled.p`
  font-family: ${props => props.theme.font.family};
  font-weight: 500;
  margin: 0;
  padding: 0;
  font-size: ${props => props.theme.font.sizes.medium};
  color: ${props => props.theme.colors.primary};`
export const Main = styled.div<{ expanded: boolean }>`
  opacity: ${props => (props.expanded ? '1' : '0')};
  max-height: ${props => (props.expanded ? '1000px' : '0')};
  overflow: hidden;
  transition: all 0.5s ease;
  padding-top: ${props => (props.expanded ? props.theme.spacings.xsmall : '0')};
  margin: 0 ${props => props.theme.spacings.medium};
`
export const Header = styled.div`
  display: flex;
  justify-content: space-between;
  align-items: center;
  cursor: pointer;
  padding: ${props => props.theme.spacings.medium};`
export const Seta = styled.span`
  font-size: ${props => props.theme.font.sizes.medium};
  color: ${props => props.theme.colors.primary};
  padding-left: ${props => props.theme.spacings.xsmall};
  `
export const Lista = styled.ul`
  padding: 0 ${props => props.theme.spacings.medium} 0;
  margin: ${props => props.theme.spacings.xxsmall} 0 ${props => props.theme.spacings.small};`
export const Item = styled.li`
  font-family: ${props => props.theme.font.family};
  font-weight: 300;
  font-size: ${props => props.theme.font.sizes.small};
  color: ${props => props.theme.colors.white};
  margin-bottom: ${props => props.theme.spacings.xxsmall};`
export const BotaoContainer = styled.div`
  display: flex;
  justify-content: end;
  align-items: center;
  width: 100%;
  margin-bottom: ${props => props.theme.spacings.medium}`

