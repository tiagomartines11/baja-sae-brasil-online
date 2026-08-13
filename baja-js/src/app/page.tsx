'use client'
import Titulo from '@/components/Titulo'
import * as S from './styles'
import { useEffect, useState, useRef } from 'react'
import Vaga from '@/components/Vaga'
import Footer from '@/components/Footer'
import SubTitulo from '@/components/SubTitulo'
import Header from '@/components/Header'
import Loading from '@/components/Loading'

const instagram = 'https://www.instagram.com/bajasaebrasil/'
const youtube = 'https://www.youtube.com/@bajasaebrasil'

export default function Home() {
  const [vagas, setVagas] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(false)
  const hasFetched = useRef(false) // Ref para evitar múltiplos fetches

  useEffect(() => {
    if (hasFetched.current) return // Evita múltiplas chamadas
    hasFetched.current = true

    const fetchVagas = async () => {
      try {
        const response = await fetch('api/vagas')
        if (!response.ok) {
          throw new Error('Network response was not ok')
        }
        const data = await response.json()
        setVagas(data)
      } catch {
        setError(true)
      } finally {
        await new Promise((resolve) => setTimeout(resolve, 2000))
        setLoading(false)
      }
    }
    fetchVagas()
  }, [])

  return (
    <S.Page>
      <Header
        logo="/img/Logo_Branca.png"
        redesSociais={{ instagram, youtube }}
      />
      <S.Main>
        <Titulo size="large" color="primary">
          ESTAMOS RECRUTANDO
        </Titulo>
        <SubTitulo>
          O Comitê Técnico Baja SAE BRASIL está buscando voluntários para
          posições dos subcomitês:
        </SubTitulo>
        {loading ? (
          <Loading />
        ) : error ? (
          <S.Error>Ocorreu um erro ao carregar as vagas</S.Error>
        ) : !vagas || vagas.length === 0 ? (
          <S.Message>Sem Sem vagas disponivéis no momento!</S.Message>
        ) : (
          <S.ContainerVagas>
            {vagas.map((vaga, index) => (
              <Vaga key={index} data={vaga} isFirst={index === 0} />
            ))}
          </S.ContainerVagas>
        )}
        <Footer>© 2025 Baja SAE BRASIL. Todos os direitos reservados.</Footer>
      </S.Main>
    </S.Page>
  )
}
