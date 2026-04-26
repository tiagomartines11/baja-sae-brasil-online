'use client'
import { PropsWithChildren } from 'react'
import theme from '@/styles/theme'

import GlobalStyles from '@/styles/global'
import { ThemeProvider } from 'styled-components'

export function Providers({ children }: PropsWithChildren) {
  return (
    <ThemeProvider theme={theme}>
      <GlobalStyles />
      {children}
    </ThemeProvider>
  )
}
