-- A small made-up event, 29NE, for tests/results-caching/http-test.sh.
-- Loaded by that script before it runs and deleted after it (deleting the
-- event deletes the rest, ON DELETE CASCADE); never part of the dev seed.
--
-- What it needs to show: whether a request saw past the spoilers.
--
--   spoilers = 1, finalizado = 0   spoilers are on: the public sees "ZZOCULTO"
--                                  in the Pontos column instead of 87.5
--   em_andamento = 0, ativo = 0    so it never becomes the site's default
--                                  event or shows in the archive menu
--   resultado 29NE_SPL             authorized_users: ZZStaffAutorizado only,
--                                  the one user who sees 87.5
--
-- 29NE because a results URL's event prefix must look like an event code
-- (two digits then BR, SE, SU or NE): /29NE/prova.php?id=29NE_SPL.

INSERT INTO evento (evento_id, titulo, nome, tipo, ano, ativo, finalizado, spoilers, em_andamento)
VALUES ('29NE', 'Fixture BAJAAPP-23', 'Evento de teste (cache dos resultados)', 3, 2029, 0, 0, 1, 0);

INSERT INTO equipe (evento_id, equipe_id, escola, equipe, estado) VALUES
    ('29NE', 1, 'ESCOLA DE TESTE UM', 'EQUIPE TESTE 1', 'SP');

INSERT INTO prova (evento_id, prova_id, nome, status, params, totals) VALUES
    ('29NE', 'SPL', 'Prova com spoilers', 1, '{"inputs": [], "pontos": {}}', '{}');

INSERT INTO input (evento_id, prova_id, equipe_id, dados, vars, pontos) VALUES
    ('29NE', 'SPL', 1, '{}', '{}', '{"PONTOS": 87.5}');

INSERT INTO resultado (resultado_id, evento_id, nome, inputs, colunas) VALUES
    ('29NE_SPL', '29NE', 'Resultado com spoilers', '| SPL |',
     '{"authorized_users": ["ZZStaffAutorizado"], "colunas": [
         {"header": "#", "val": "EquipeNum"},
         {"header": "Pontos", "val": "$PONTOS", "val_spoilers": "\\"ZZOCULTO\\""}
     ]}');
