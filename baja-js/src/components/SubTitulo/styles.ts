import styled from 'styled-components'
import { Props } from '.'

export const SubTitle = styled.p<Props>`
  font-family: ${props => props.theme.font.family};
  font-weight: 400;
  margin-top: ${props => props.theme.spacings.xsmall};
  margin-bottom: ${props => props.theme.spacings.xsmall};
  padding: 0;
  font-size: ${props => props.theme.font.sizes.medium};
  color: ${props => props.theme.colors.white};
`