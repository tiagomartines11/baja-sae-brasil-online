import { Meta, StoryObj } from '@storybook/nextjs'
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
