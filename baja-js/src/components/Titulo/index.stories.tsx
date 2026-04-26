import { Meta, StoryObj } from '@storybook/react'
import Titulo from '.'

export default {
  title: 'Titulo',
  component: Titulo
} as Meta

export const Primary: StoryObj = {
  args: {
    children: 'ESTAMOS RECRUTANDO',
    size: 'medium',
    color: 'primary'
  }
}

export const Secondary: StoryObj = {
  args: {
    children: 'ESTAMOS RECRUTANDO',
    size: 'medium',
    color: 'secondary'
  }
}
