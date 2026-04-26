import React from 'react'
import GlobalStyles from '../src/styles/global'
import { ThemeProvider } from 'styled-components'
import theme from '../src/styles/theme'
import { INITIAL_VIEWPORTS } from '@storybook/addon-viewport'
import { customViewports } from './viewports'

export const decorators = [
  (Story) => (
    <ThemeProvider theme={theme}>
      <GlobalStyles />
      <Story />
    </ThemeProvider>
  )
]
export const tags = ['autodocs']

export const parameters = {
  viewport: {
    viewports: { ...customViewports, ...INITIAL_VIEWPORTS } // Adicione os viewports iniciais
  }
}
