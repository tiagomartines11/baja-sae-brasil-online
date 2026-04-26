'use client'
import styled from 'styled-components'
export const Page = styled.section`
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  align-items: center;
`
export const Main = styled.main`
  display: flex;
  justify-content: center;
  flex-direction: column;
  align-items: center;
  background-color: ${(props) => props.theme.colors.background};
  min-height: 100vh;
  width: 100%;
  padding-top: ${(props) => props.theme.spacings.xxlarge};
`

export const ContainerVagas = styled.div`
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  width: 100%;
  max-width: ${(props) => props.theme.grid.container};
  padding: ${(props) => props.theme.spacings.medium};
`

export const Error = styled.div`
  display: flex;
  justify-content: center;
  align-items: center;
  flex-direction: column;
  width: 100%;
  max-width: ${(props) => props.theme.grid.container};
  padding: ${(props) => props.theme.spacings.medium};
  color: ${(props) => props.theme.colors.secondary};
`

export const Message = styled.p`
  font-family: ${(props) => props.theme.font.family};
  font-size: ${(props) => props.theme.font.sizes.medium};
  color: ${(props) => props.theme.colors.white};
  margin: ${(props) => props.theme.spacings.xsmall} 0;
  text-align: center;
  padding: 0 ${(props) => props.theme.spacings.xsmall};
  line-height: 1.5;
  font-weight: 400;
`
