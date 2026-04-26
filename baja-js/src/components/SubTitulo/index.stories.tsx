import { Meta, StoryObj } from '@storybook/react'
import SubTitulo from '.'

export default {
  title: 'SubTitulo',
  component: SubTitulo
} as Meta

export const Default: StoryObj = {
  args: {
    children:
      'O Comitê Técnico Baja SAE BRASIL está buscando voluntários para posições dos subcomitês:'
  }
}
