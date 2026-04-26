import { Meta, StoryObj } from '@storybook/react'
import Botao from '.'

export default {
  title: 'Botao',
  component: Botao
} as Meta

export const Primary: StoryObj = {
  args: {
    children: 'Se inscreva',
    color: 'primary',
    size: 'medium'
  }
}

export const Secondary: StoryObj = {
  args: {
    children: 'Se inscreva',
    color: 'secondary',
    size: 'medium'
  }
}
