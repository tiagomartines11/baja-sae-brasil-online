<?php
/**
 * @api-endpoint
 * @api-summary Tabela de um resultado
 * @api-description A tabela pública de um resultado (classificação geral ou de uma prova), como a página de resultados a calcula: colunas, posição de cada equipe (null quando não há) e o valor bruto de cada célula; com display=1, também o HTML exibido. spoilers_active diz quando valores e posições estão escondidos. Contrato completo em docs/results-api.md.
 * @api-method GET
 * @api-method-description Somente leitura, dados públicos. Qualquer origem (CORS *). Cache público curto durante o evento, longo depois.
 * @api-param evt string ID do evento, ex. 26BR (obrigatório)
 * @api-param id string ID do resultado, ex. 26BR_GER (obrigatório)
 * @api-param display string "1" para incluir o HTML exibido de cada célula (opcional)
 * @api-response 200 A tabela
 * @api-response 400 Parâmetro desconhecido, repetido ou inválido
 * @api-response 404 Evento ou resultado não encontrado, ou o resultado é um torneio
 * @api-response 429 Limite de requisições excedido
 */

require_once(__DIR__ . '/../../src/bootstrap.php');

use Baja\Api\Resultados\Endpoint;
use Baja\Api\Resultados\ResultadoEndpoint;

Endpoint::serve(ResultadoEndpoint::PARAMS, [ResultadoEndpoint::class, 'handle']);
