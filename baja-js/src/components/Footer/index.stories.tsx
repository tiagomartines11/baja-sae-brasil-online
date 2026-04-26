import { Meta, StoryObj } from '@storybook/react'
import Footer from '.'

export default {
  title: 'Footer',
  component: Footer
} as Meta

export const Default: StoryObj = {
  args: {
    children: '© 2025 Baja SAE BRASIL. Todos os direitos reservados.'
  }
}
