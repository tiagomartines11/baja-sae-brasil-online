<?php
/**
 * @api-endpoint
 * @api-summary Evento: estado, resultados e provas
 * @api-description Informações públicas de um evento e o que pode ser consultado: estado (finalizado, spoilers ativos, se é o evento padrão do site), os resultados do menu e as provas de que são feitos, indicando torneios e provas com contagem de voltas. Contrato completo em docs/results-api.md.
 * @api-method GET
 * @api-method-description Somente leitura, dados públicos. Qualquer origem (CORS *). Cache público curto durante o evento, longo depois.
 * @api-param evt string ID do evento, ex. 26BR (obrigatório)
 * @api-response 200 O evento
 * @api-response 400 Parâmetro desconhecido, repetido ou inválido
 * @api-response 404 Evento não encontrado
 * @api-response 429 Limite de requisições excedido
 */

require_once(__DIR__ . '/../../src/bootstrap.php');

use Baja\Api\Resultados\Endpoint;
use Baja\Api\Resultados\EventoEndpoint;

Endpoint::serve(EventoEndpoint::PARAMS, [EventoEndpoint::class, 'handle']);
