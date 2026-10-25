<?php
/**
 * @api-endpoint
 * @api-summary Chaveamento de um torneio
 * @api-description O chaveamento publicado por um resultado do tipo torneio: rodadas e confrontos, com as equipes de cada lado (ou WO, ou o confronto de onde virá), o vencedor, as vitórias numa melhor de N e a posição de cada equipe na classificação que gerou o chaveamento. Contrato completo em docs/results-api.md.
 * @api-method GET
 * @api-method-description Somente leitura, dados públicos. Qualquer origem (CORS *). Cache público curto durante o evento, longo depois.
 * @api-param evt string ID do evento, ex. 26BR (obrigatório)
 * @api-param id string ID do resultado do torneio, ex. 26BR_SPR (obrigatório)
 * @api-response 200 O chaveamento
 * @api-response 400 Parâmetro desconhecido, repetido ou inválido
 * @api-response 404 Evento ou resultado não encontrado, ou o resultado não é um torneio
 * @api-response 429 Limite de requisições excedido
 */

require_once(__DIR__ . '/../../src/bootstrap.php');

use Baja\Api\Resultados\Endpoint;
use Baja\Api\Resultados\TorneioEndpoint;

Endpoint::serve(TorneioEndpoint::PARAMS, [TorneioEndpoint::class, 'handle']);
