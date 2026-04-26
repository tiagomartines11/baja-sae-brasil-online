import styled from 'styled-components'

export const Footer = styled.footer`
display: flex;
justify-content: center;
align-items: center;
width: 100%;
font-family: ${props => props.theme.font.family};
font-weight: 300;
font-size: ${props => props.theme.font.sizes.xsmall};
color: ${props => props.theme.colors.white};
height: 100px;
opacity: 0.7;`