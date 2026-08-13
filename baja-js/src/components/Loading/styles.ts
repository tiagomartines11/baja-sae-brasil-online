import styled, { keyframes } from 'styled-components'

const spin = keyframes`
  0% {
    transform: rotate(0deg);
  }
  100% {
    transform: rotate(360deg);
  }
`

export const Wrapper = styled.div`
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  height: 30vh;
`

export const Spinner = styled.div`
  width: 5.0rem;
  height: 5.0rem;
  border: 0.5rem solid ${props => props.theme.colors.white};
  border-top: 0.5rem solid ${props => props.theme.colors.primary};
  border-radius: 50%;
  animation: ${spin} 1s linear infinite;
`

export const Title = styled.h1`
  margin-top: ${props => props.theme.spacings.xsmall};
  font-family: ${props => props.theme.font.family};
  font-size: ${props => props.theme.font.sizes.medium};
  color: ${props => props.theme.colors.white};
`
