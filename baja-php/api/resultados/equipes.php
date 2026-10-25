<?php
/**
 * @api-endpoint
 * @api-summary Equipes de um evento
 * @api-description Número, nome e nome curto, escola e nome curto, cidade, estado e desclassificação de cada equipe do evento. Nenhum dado pessoal. Contrato completo em docs/results-api.md.
 * @api-method GET
 * @api-method-description Somente leitura, dados públicos. Qualquer origem (CORS *). Cache público curto durante o evento, longo depois.
 * @api-param evt string ID do evento, ex. 26BR (obrigatório)
 * @api-response 200 As equipes, por número
 * @api-response 400 Parâmetro desconhecido, repetido ou inválido
 * @api-response 404 Evento não encontrado
 * @api-response 429 Limite de requisições excedido
 */

require_once(__DIR__ . '/../../src/bootstrap.php');

use Baja\Api\Resultados\Endpoint;
use Baja\Api\Resultados\EquipesEndpoint;

Endpoint::serve(EquipesEndpoint::PARAMS, [EquipesEndpoint::class, 'handle']);
