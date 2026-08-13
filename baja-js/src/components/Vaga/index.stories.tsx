import { Meta, StoryObj } from '@storybook/react'
import Vaga from '.'

export default {
  title: 'Vaga',
  component: Vaga
} as Meta

export const Default: StoryObj = {
  args: {
    data: {
      titulo: 'Subcomitê de Segurança Estática',
      atividades: [
        'Auxiliar na manutenção do regulamento (RATBSB).',
        'Propor novas regras para atualização do RATBSB.',
        'Auxiliar na resposta rápida das perguntas enviadas no fórum Baja SAE BRASIL.',
        'Avaliar documentação enviada pelas equipes.',
        'Auxiliar na padronização e documentação das atividades do subcomitê.',
        'Auxiliar na disseminação do conhecimento sobre o RATBSB com voluntários e equipes.'
      ],
      requisitos: [
        'Proatividade!',
        'Ter participado como voluntário graduado.',
        'Preocupação e responsabilidade com a construção e manutenção do RATBSB.',
        'Experiência com leitura e escrita de normas é diferencial.',
        'Disponibilidade para participação presencial nas competições Baja SAE BRASIL.',
        'Disponibilidade para reuniões online ocasionais.'
      ],
      link: 'https://docs.google.com/forms/d/e/1FAIpQLSfqX0v2g3q4r7x5J6m8k5G9z4Zl7Qe1Yw8f8g5f5f5f5f5f5g/viewform'
    },
    isFirst: true
  }
}
