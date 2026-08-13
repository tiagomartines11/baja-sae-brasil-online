import styled from 'styled-components'
import { DefaultTheme } from 'styled-components/dist/types'
import { Props } from '.'

const SizeModifiers = {
  small: (theme: DefaultTheme) => `
    font-size: ${theme.font.sizes.xxlarge};
    `,
  medium: (theme: DefaultTheme) => `
    font-size: ${theme.font.sizes.xxxlarge};
    `,
  large: (theme: DefaultTheme) => `
    font-size: ${theme.font.sizes.huge};
    `
}

const ColorModifiers = {
  primary: (theme: DefaultTheme) => `
    color: ${theme.colors.primary};
    `,
  secondary: (theme: DefaultTheme) => `
    color: ${theme.colors.secondary};
    `
}
export const Title = styled.h1<Props>`
  font-family: ${props => props.theme.font.familyUrzeit};
  font-weight: normal;
  font-display: swap;
  margin: 0;
  padding: 0;
  ${props => SizeModifiers[props.size || 'medium'](props.theme)}
  ${props => ColorModifiers[props.color || 'primary'](props.theme)}
`