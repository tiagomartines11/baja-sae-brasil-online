<?php

namespace Baja\Api;

/**
 * API Documentation Configuration
 *
 * Provides metadata and category configuration for API documentation.
 * Edit this file to customize API documentation output.
 */
class ApiDocsConfig
{
    /**
     * Get the complete configuration
     */
    public static function getConfig(): array
    {
        return [
            'meta' => self::getMeta(),
            'categories' => self::getCategories(),
        ];
    }

    /**
     * API metadata
     */
    public static function getMeta(): array
    {
        return [
            'title' => 'Baja SAE Brasil API',
            'version' => '1.0.0',
            'description' => 'API para gerenciamento de eventos, equipes e inscrições dos programas estudantis da SAE BRASIL.',
            'base_url' => '/api',
        ];
    }

    /**
     * Category configuration
     * Keys should match directory names in /api/
     */
    public static function getCategories(): array
    {
        return [
            'auth' => [
                'name' => 'Autenticacao',
                'description' => 'Endpoints para login, logout e gerenciamento de sessao.',
                'order' => 1,
            ],
            'eventos' => [
                'name' => 'Eventos',
                'description' => 'Gerenciamento de eventos e competicoes.',
                'order' => 2,
            ],
            'equipes' => [
                'name' => 'Equipes Permanentes',
                'description' => 'Gerenciamento de equipes, membros e convites.',
                'order' => 3,
            ],
            'inscricoes' => [
                'name' => 'Inscricoes',
                'description' => 'Gerenciamento de inscricoes em eventos.',
                'order' => 4,
            ],
            'instituicoes' => [
                'name' => 'Instituicoes',
                'description' => 'Gerenciamento de instituicoes de ensino.',
                'order' => 5,
            ],
            'campi' => [
                'name' => 'Campi',
                'description' => 'Gerenciamento de campi universitarios.',
                'order' => 6,
            ],
            'programas' => [
                'name' => 'Programas',
                'description' => 'Listagem de programas (Baja, Formula, etc).',
                'order' => 7,
            ],
            'voluntarios' => [
                'name' => 'Voluntarios',
                'description' => 'Gerenciamento de voluntarios e suas inscricoes.',
                'order' => 8,
            ],
            'alocacoes' => [
                'name' => 'Alocacoes',
                'description' => 'Gerenciamento de alocacoes de voluntarios.',
                'order' => 9,
            ],
            'atividades' => [
                'name' => 'Atividades',
                'description' => 'Gerenciamento de atividades de eventos.',
                'order' => 10,
            ],
            'admin' => [
                'name' => 'Administracao',
                'description' => 'Endpoints administrativos (requer permissao admin).',
                'order' => 99,
            ],
        ];
    }
}
