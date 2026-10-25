-- A small made-up event, 98FE, for tests/FormulaCompare/Db/CompareFixtureTest.php.
-- Loaded by that test before the run and deleted after it; never part of the
-- dev seed. Three teams, and one formula of each kind the comparison sorts:
--
--   prova APR         points: the nested ternary of 18SU's APR. eval() kills
--                     PHP on it; SafeEngine gives null. DELIBERATE.
--   prova OFF         points: strtoupper(), a function SafeEngine does not
--                     allow. eval() gives the text, SafeEngine null. DIFFERENT.
--   prova CLK         points: compares time() with a deadline. Both engines
--                     get the same fixed now: no difference.
--   resultado 98FE_MIX  one healthy column, one that shows PHP_VERSION (a
--                     constant SafeEngine does not allow: DIFFERENT), and one
--                     that is just "die" (eval() stops PHP: DELIBERATE).
--
-- Not finished and not active, so the results snapshots never see it.
-- Deleting the event deletes everything else here (ON DELETE CASCADE).

INSERT INTO evento (evento_id, titulo, nome, tipo, ano, ativo, finalizado, spoilers)
VALUES ('98FE', 'Fixture BAJAAPP-35', 'Evento de teste (motores de fórmula)', 0, 2098, 0, 0, 0);

INSERT INTO equipe (evento_id, equipe_id, escola, equipe, estado) VALUES
    ('98FE', 1, 'ESCOLA DE TESTE UM', 'EQUIPE TESTE 1', 'SP'),
    ('98FE', 2, 'ESCOLA DE TESTE DOIS', 'EQUIPE TESTE 2', 'MG'),
    ('98FE', 3, 'ESCOLA DE TESTE TRES', 'EQUIPE TESTE 3', 'RJ');

INSERT INTO prova (evento_id, prova_id, nome, status, params, totals) VALUES
    ('98FE', 'APR', 'Apresentação', 1,
     '{"inputs": [{"code": "DTA", "name": "Dias de atraso", "type": "number"}], "pontos": {"APRPTS": "($DTA == 1 ?  0 : ($DTA == 2 ?  -30 : $DTA == 3 ?  -45 : -90))"}}',
     '{}'),
    ('98FE', 'OFF', 'Fora da lista', 1,
     '{"inputs": [{"code": "NOME", "name": "Nome", "type": "enum", "options": ["abc", "def"]}], "pontos": {"OFFPTS": "strtoupper($NOME)"}}',
     '{}'),
    ('98FE', 'CLK', 'Relógio', 1,
     '{"inputs": [{"code": "NOTA", "name": "Nota", "type": "number"}], "pontos": {"CLKPTS": "time() >= 1700000000 ? \'Atrasado\' : \'No prazo\'"}}',
     '{}');

INSERT INTO input (evento_id, prova_id, equipe_id, dados, vars, pontos) VALUES
    ('98FE', 'APR', 1, '{"DTA": 1}', '{}', '{"APRPTS": 0}'),
    ('98FE', 'APR', 2, '{"DTA": 2}', '{}', '{"APRPTS": -45}'),
    ('98FE', 'APR', 3, '{"DTA": 3}', '{}', '{"APRPTS": -45}'),
    ('98FE', 'OFF', 1, '{"NOME": "abc"}', '{}', '{"OFFPTS": "ABC"}'),
    ('98FE', 'OFF', 2, '{"NOME": "def"}', '{}', '{"OFFPTS": "DEF"}'),
    ('98FE', 'CLK', 1, '{"NOTA": 80}', '{}', '{"CLKPTS": "Atrasado"}'),
    ('98FE', 'CLK', 2, '{"NOTA": 90}', '{}', '{"CLKPTS": "Atrasado"}'),
    ('98FE', 'CLK', 3, '{"NOTA": 70}', '{}', '{"CLKPTS": "Atrasado"}');

INSERT INTO resultado (resultado_id, evento_id, nome, inputs, colunas) VALUES
    ('98FE_MIX', '98FE', 'Mistura', '| CLK |',
     '{"pos": "Nota", "colunas": [{"val": "Pos", "header": "Pos"}, {"val": "EquipeNum", "header": "#"}, {"val": "Equipe", "header": "Equipe"}, {"val": "ROUND($NOTA, 1)", "header": "Nota"}, {"val": "PHP_VERSION", "header": "Versão"}, {"val": "die", "header": "Fim"}]}');
