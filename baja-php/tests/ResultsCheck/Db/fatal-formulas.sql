-- A small made-up event, 99FX, for tests/ResultsCheck/Db/FatalFormulaTest.php.
-- Loaded by that test before the run and deleted after it; never part of the
-- dev seed. Shaped like a seed event, with three teams:
--
--   prova APR       its points formula is the nested ternary that killed the
--                   check on production (18SU, BAJAAPP-63): PHP 8 refuses to
--                   compile it, a FATAL error
--   prova REL       healthy
--   resultado 99FX_APR  shows the same ternary in its "Pontos" column: FATAL
--   resultado 99FX_REL  healthy
--   resultado 99FX_SLP  joins text with "+": an ordinary error, with a fix
--
-- The resultados are checked in id order, so the two after 99FX_APR show
-- whether the check carried on after the fatal one.
--
-- Not finished (finalizado = 0) and not active, so the results snapshots,
-- which only look at finished events, never see it. Deleting the event
-- deletes everything else here with it (ON DELETE CASCADE).

INSERT INTO evento (evento_id, titulo, nome, tipo, ano, ativo, finalizado, spoilers)
VALUES ('99FX', 'Fixture BAJAAPP-63', 'Evento de teste (fórmula fatal)', 0, 2099, 0, 0, 0);

INSERT INTO equipe (evento_id, equipe_id, escola, equipe, estado) VALUES
    ('99FX', 1, 'ESCOLA DE TESTE UM', 'EQUIPE TESTE 1', 'SP'),
    ('99FX', 2, 'ESCOLA DE TESTE DOIS', 'EQUIPE TESTE 2', 'MG'),
    ('99FX', 3, 'ESCOLA DE TESTE TRES', 'EQUIPE TESTE 3', 'RJ');

INSERT INTO prova (evento_id, prova_id, nome, status, params, totals) VALUES
    ('99FX', 'APR', 'Apresentação', 1,
     '{"inputs": [{"code": "DTA", "name": "Dias de atraso", "type": "number", "precision": 0}], "pontos": {"APRPTS": "($DTA == 1 ?  0 : ($DTA == 2 ?  -30 : $DTA == 3 ?  -45 : -90))"}}',
     '{}'),
    ('99FX', 'REL', 'Relatório', 1,
     '{"inputs": [{"code": "NOTA", "name": "Nota", "type": "number", "precision": 2}], "pontos": {"RELPTS": "ROUND($NOTA * 0.7, 2)"}}',
     '{}');

-- The points as PHP 7 stored them (team 2: -45, not -30; see the test).
INSERT INTO input (evento_id, prova_id, equipe_id, dados, vars, pontos) VALUES
    ('99FX', 'APR', 1, '{"DTA": 1}', '{}', '{"APRPTS": 0}'),
    ('99FX', 'APR', 2, '{"DTA": 2}', '{}', '{"APRPTS": -45}'),
    ('99FX', 'APR', 3, '{"DTA": 3}', '{}', '{"APRPTS": -45}'),
    ('99FX', 'REL', 1, '{"NOTA": 80}', '{}', '{"RELPTS": 56}'),
    ('99FX', 'REL', 2, '{"NOTA": 90}', '{}', '{"RELPTS": 63}'),
    ('99FX', 'REL', 3, '{"NOTA": 70}', '{}', '{"RELPTS": 49}');

INSERT INTO resultado (resultado_id, evento_id, nome, inputs, colunas) VALUES
    ('99FX_APR', '99FX', 'Apresentação', '| APR |',
     '{"pos": "Pontos", "colunas": [{"val": "Pos", "header": "Pos"}, {"val": "EquipeNum", "header": "#"}, {"val": "Equipe", "header": "Equipe"}, {"val": "$DTA", "header": "Dias de atraso"}, {"val": "($DTA == 1 ?  0 : ($DTA == 2 ?  -30 : $DTA == 3 ?  -45 : -90))", "header": "Pontos"}]}'),
    ('99FX_REL', '99FX', 'Relatório', '| REL |',
     '{"pos": "Pontos", "colunas": [{"val": "Pos", "header": "Pos"}, {"val": "EquipeNum", "header": "#"}, {"val": "Equipe", "header": "Equipe"}, {"val": "ROUND($RELPTS,2)", "header": "Pontos"}]}'),
    ('99FX_SLP', '99FX', 'Texto com +', '| REL |',
     '{"pos": "Pontos", "colunas": [{"val": "Pos", "header": "Pos"}, {"val": "EquipeNum", "header": "#"}, {"val": "Equipe", "header": "Equipe"}, {"val": "ROUND($RELPTS,2)", "header": "Pontos"}, {"val": "\'<b>\'+ROUND($RELPTS,2)+\'</b>\'", "header": "Destaque"}]}');
