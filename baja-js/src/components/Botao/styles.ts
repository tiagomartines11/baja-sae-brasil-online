import styled from 'styled-components'
import { DefaultTheme } from 'styled-components/dist/types'
import { Props } from '.'
const colorModifiers = {
  primary: (theme: DefaultTheme) => `
    background-color: ${theme.colors.primary};
    color: ${theme.colors.white};
    &:hover {
      background-color: ${theme.colors.lightPrimary};
    `,
  secondary: (theme: DefaultTheme) => `
    background-color: ${theme.colors.secondary};
    color: ${theme.colors.black};
    &:hover {
      background-color: ${theme.colors.lightSecondary};
      `
}

const sizerModifiers = {
  small: (theme: DefaultTheme) => `
    font-size: ${theme.font.sizes.small};
    padding: ${theme.spacings.xxsmall} ${theme.spacings.xsmall};
    `,
  medium: (theme: DefaultTheme) => `
    font-size: ${theme.font.sizes.medium};
    padding: ${theme.spacings.xsmall} ${theme.spacings.small};
    `,
  large: (theme: DefaultTheme) => `
    font-size: ${theme.font.sizes.large};
    padding: ${theme.spacings.small} ${theme.spacings.medium};
    `
}
export const Button = styled.button<Props>`
  font-family: ${props => props.theme.font.family};
  font-weight: 300;
  border: none;
  border-radius: ${props => props.theme.border.radiusSmall};
  cursor: pointer;
  ${props => sizerModifiers[props.size || 'medium'](props.theme)}
  ${props => colorModifiers[props.color || 'primary'](props.theme)}
  transition: all 0.3s ease;

`;