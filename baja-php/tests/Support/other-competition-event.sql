-- A small made-up event, 97AR, for the tests that check the command-line
-- tools accept an event of any competition, not only BR, SE, SU and NE
-- (BAJAAPP-74). The dev seed has events of those four only; production also
-- has AR, FA, FO, EQ and H2 ones. Loaded by tests/Support/OtherCompetitionEvent.php
-- before each test class and deleted after it; never part of the dev seed.
--
-- 97, a year no real event has, so that deleting it can never delete one.
-- Three teams, one prova with one formula, one resultado showing it: enough
-- for results-snapshot.php, check-results-configs.php and
-- formula-engine-compare.php each to have something to do. Nothing in it is
-- broken: every tool must find it ordinary.
--
-- Not finished and not active, so a results snapshot run without --events
-- never sees it. Deleting the event deletes everything else here with it
-- (ON DELETE CASCADE).

INSERT INTO evento (evento_id, titulo, nome, tipo, ano, menu, ativo, finalizado, spoilers)
VALUES ('97AR', 'Fixture BAJAAPP-74', 'Evento de teste (outra competição)', 0, 2097,
        '[{"Projeto": ["97AR_REL"]}]', 0, 0, 0);

INSERT INTO equipe (evento_id, equipe_id, escola, equipe, estado) VALUES
    ('97AR', 1, 'ESCOLA DE TESTE UM', 'EQUIPE TESTE 1', 'SP'),
    ('97AR', 2, 'ESCOLA DE TESTE DOIS', 'EQUIPE TESTE 2', 'MG'),
    ('97AR', 3, 'ESCOLA DE TESTE TRES', 'EQUIPE TESTE 3', 'RJ');

INSERT INTO prova (evento_id, prova_id, nome, status, params, totals) VALUES
    ('97AR', 'REL', 'Relatório', 1,
     '{"inputs": [{"code": "NOTA", "name": "Nota", "type": "number", "precision": 2}], "pontos": {"RELPTS": "ROUND($NOTA * 0.7, 2)"}}',
     '{}');

INSERT INTO input (evento_id, prova_id, equipe_id, dados, vars, pontos) VALUES
    ('97AR', 'REL', 1, '{"NOTA": 80}', '{}', '{"RELPTS": 56}'),
    ('97AR', 'REL', 2, '{"NOTA": 90}', '{}', '{"RELPTS": 63}'),
    ('97AR', 'REL', 3, '{"NOTA": 70}', '{}', '{"RELPTS": 49}');

INSERT INTO resultado (resultado_id, evento_id, nome, inputs, colunas) VALUES
    ('97AR_REL', '97AR', 'Relatório', '| REL |',
     '{"pos": "Pontos", "colunas": [{"val": "Pos", "header": "Pos"}, {"val": "EquipeNum", "header": "#"}, {"val": "Equipe", "header": "Equipe"}, {"val": "ROUND($RELPTS,2)", "header": "Pontos"}]}');
