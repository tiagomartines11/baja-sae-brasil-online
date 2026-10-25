<?php
/**
 * @api-endpoint
 * @api-summary Corrida ao vivo (contagem de voltas)
 * @api-description Uma prova com contagem de voltas como está agora: posições, voltas, última passagem, tempos de volta, situação e paradas de cada carro, e o relógio da corrida. Vem do feed público da contagem, que nunca recalcula a corrida. Contrato completo em docs/results-api.md.
 * @api-method GET
 * @api-method-description Somente leitura, dados públicos. Qualquer origem (CORS *). Cache público de 2 segundos.
 * @api-param evt string ID do evento, ex. 26BR (obrigatório)
 * @api-param p string ID da prova, ex. END (obrigatório)
 * @api-response 200 A corrida
 * @api-response 400 Parâmetro desconhecido, repetido ou inválido
 * @api-response 404 Evento ou prova não encontrada, ou a prova não tem contagem de voltas
 * @api-response 429 Limite de requisições excedido
 */

require_once(__DIR__ . '/../../src/bootstrap.php');

use Baja\Api\Resultados\CorridaEndpoint;
use Baja\Api\Resultados\Endpoint;

Endpoint::serve(CorridaEndpoint::PARAMS, [CorridaEndpoint::class, 'handle']);
