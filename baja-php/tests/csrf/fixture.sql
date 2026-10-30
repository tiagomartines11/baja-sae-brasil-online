-- A small made-up event, 28SU, for tests/csrf/http-test.sh (BAJAAPP-41).
-- Loaded by that script before it runs and deleted after it (deleting the
-- event deletes the rest, ON DELETE CASCADE); never part of the dev seed.
--
-- What it needs: something for every form under test to change or delete.
--
--   em_andamento = 0, ativo = 0   so it never becomes the site's default
--                                 event or shows in the archive menu
--   prova ZZA                     a plain prova with one number field, NOTA,
--                                 for the judge's entry form
--   resultado 28SU_ZZA            built from ZZA, for the resultado form
--   fila 1                        for the bulk queue access page
--   data_fim 2099-12-31           so a role given on its prova has an end
--
-- 28SU because a judge URL's event prefix must look like an event code (two
-- digits then BR, SE, SU or NE), and 29NE belongs to the results cache test.

-- data_fim far ahead: the role page's case gives a role on a prova of this
-- event with the default end, which needs it (BAJAAPP-76).
INSERT INTO evento (evento_id, titulo, nome, tipo, ano, ativo, finalizado, spoilers, em_andamento, programa_id, data_fim)
VALUES ('28SU', 'Fixture BAJAAPP-41', 'Evento de teste (CSRF)', 3, 2028, 0, 0, 0, 0, 'BAJA', '2099-12-31');

INSERT INTO equipe (evento_id, equipe_id, escola, equipe, estado) VALUES
    ('28SU', 1, 'ESCOLA DE TESTE UM', 'EQUIPE TESTE 1', 'SP'),
    ('28SU', 2, 'ESCOLA DE TESTE DOIS', 'EQUIPE TESTE 2', 'RS');

INSERT INTO prova (evento_id, prova_id, nome, status, params, totals) VALUES
    ('28SU', 'ZZA', 'Prova de teste', 1,
     '{"inputs": [{"code": "NOTA", "name": "Nota", "type": "number", "precision": 1}], "vars": {}, "pontos": {}}', '{}');

INSERT INTO resultado (resultado_id, evento_id, nome, inputs, colunas) VALUES
    ('28SU_ZZA', '28SU', 'Resultado de teste', '| ZZA |',
     '{"colunas": [{"header": "#", "val": "EquipeNum"}, {"header": "Nota", "val": "$NOTA"}]}');

-- Queue 1, for admin_fila_acesso.php's cases (c-usuarios.sh): since
-- BAJAAPP-76 that page checks fila.manage on each queue it touches, so the
-- queue must exist.
INSERT INTO fila (evento_id, fila_id, nome) VALUES ('28SU', 1, 'Fila de teste (CSRF)');
