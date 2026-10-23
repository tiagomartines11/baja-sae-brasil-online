// Tests for what "Editar manualmente" opens on a flag of the review screen
// (round 4f).
//
// Background for someone new to this. Three counters type the number of
// every car that crosses the line. When one of them types a wrong number,
// the system sees a LONE entry for one car at the very moment the other two
// recorded another car, and raises the flag "troca provável" (probable
// swap). What settles that flag is saying which car the lone entry really
// belongs to. The decision for that is "reatribuir" (move the entry).
//
// "Editar manualmente" is the button for doing by hand what no suggestion
// covers.
//
// Run with:  node --test baja-php/tests/js/

import test from 'node:test';
import assert from 'node:assert/strict';

import { passagensParaDescartar, rotuloDeDescartar } from '../../default/js/contagem/revisao-alertas.js';
import { decisaoReatribuir, pedidoParaAlerta } from '../../default/js/contagem/revisao-decisoes.js';
import { edicaoManualDoAlerta, problemaAoMover, registroParaMover } from '../../default/js/contagem/revisao-edicao-manual.js';

const LARGADA = 1_760_000_000_000;

const ESTADO = {
  ultima_decisao_id: 9,
  fontes: [{ id: 1, nome: 'Contagem 1' }, { id: 2, nome: 'Contagem 2' }, { id: 3, nome: 'Contagem 3' }],
  carros_validos: [{ numero: 14 }, { numero: 41 }, { numero: 7 }],
  carros: [
    { carro: 14, presente: true, classificado: true },
    { carro: 41, presente: true, classificado: true },
    { carro: 9, presente: true, classificado: false },
  ],
};

// Car 41 crossed. Counters 1 and 2 typed 41; counter 3 typed 14 (entry 103).
const TROCA = {
  id: 'troca_provavel:103', tipo: 'troca_provavel', carros: [14, 41], passagem_ids: [103], fonte_id: 3,
  ts_ms: LARGADA + 600000, sugestao: { tipo: 'reatribuir', passagem_id: 103, equipe_numero: 41 }, assinatura: 'sig',
};

// SITUATION: the flag above. The reviewer does not press "Aceitar sugestão"
// but "Editar manualmente", and then the form's button.
//
// WHAT WENT WRONG: the only form there was "Adicionar passagem", filled in
// with the flag's first car and the flag's time. The first car of a "troca
// provável" is the car of the lone entry: 14, the number typed WRONG.
// Pressing "Adicionar" gave car 14 a lap it never drove, and said nothing
// about entry 103: the flag closed for the wrong reason, and another opened
// on car 14's next real pass. Typing 41 instead added a pass on top of one
// that already counted: nothing changed, and the flag stayed open.
//
// EXPECTED: on this flag the manual edit is first of all "Mover o registro
// do contador Contagem 3 para o carro [41]": the stray entry, whose it is,
// and the suggestion's car offered. It sends the same "reatribuir" decision
// as "Aceitar sugestão". "Adicionar passagem" is still there, apart, with
// the time filled in and NO car.
test('on a probable swap the manual edit moves the stray entry, and "Adicionar" has no car filled in', () => {
  const edicao = edicaoManualDoAlerta(TROCA, ESTADO);

  assert.deepEqual(edicao.mover, { passagemId: 103, carroAtual: 14, fonteNome: 'Contagem 3', carroSugerido: 41 });
  assert.deepEqual(edicao.adicionar, { carro: null, tsMs: LARGADA + 600000 }, 'a hurried "Adicionar" cannot give car 14 a lap');

  assert.equal(problemaAoMover(edicao.mover, 41, ESTADO), null);
  const pedido = pedidoParaAlerta(decisaoReatribuir(edicao.mover.passagemId, 41), TROCA, ESTADO, 'sig');
  assert.deepEqual(pedido.decisao, TROCA.sugestao, 'the very decision "Aceitar sugestão" stores');
  assert.deepEqual(pedido.passagem_ids, [103]);
});

// EXPECTED: the form refuses, with a sentence, the car the entry already
// has (that would store a correction that corrects nothing and leave the
// flag open), a number that is not a number, a car that is present but not
// classified, and a number no present team has. The server checks again.
test('the stray entry cannot be moved to its own car, to an unclassified car or to no car', () => {
  const mover = registroParaMover(TROCA, ESTADO);

  assert.match(problemaAoMover(mover, 14, ESTADO), /já é do carro 14/);
  assert.match(problemaAoMover(mover, null, ESTADO), /Informe o número do carro/);
  assert.match(problemaAoMover(mover, 1.5, ESTADO), /Informe o número do carro/);
  assert.match(problemaAoMover(mover, 9, ESTADO), /não está classificado/);
  assert.match(problemaAoMover(mover, 999, ESTADO), /não é de uma equipe presente/);
  assert.equal(problemaAoMover(mover, 7, ESTADO), null, 'another classified car than the suggested one is the reviewer\'s call');
});

// SITUATION: the reverse reading. Counters 1 and 2 typed 14 (entries 101,
// 102) and counter 3 typed 41 (entry 103), and the engine thinks the two
// are the ones who are wrong: its suggestion is to move THEIR entries to 41
// ("reatribuir_grupo"). The flag's first entry is still the lone one, 103.
//
// EXPECTED: the manual form still offers to move the lone entry (that is
// the other reading, and the reviewer may know better), but with NO car
// offered: the engine's suggestion is not about this entry. And no car on
// "Adicionar".
test('on the reverse reading the lone entry can be moved by hand, with no car offered', () => {
  const reversa = {
    id: 'troca_provavel:103', tipo: 'troca_provavel', carros: [41, 14], passagem_ids: [103, 101, 102], fonte_id: 3, ts_ms: LARGADA + 600000,
    sugestao: { tipo: 'reatribuir_grupo', passagem_ids: [101, 102], equipe_numero: 41 },
  };
  const edicao = edicaoManualDoAlerta(reversa, ESTADO);

  assert.deepEqual(edicao.mover, { passagemId: 103, carroAtual: 41, fonteNome: 'Contagem 3', carroSugerido: null });
  assert.equal(edicao.adicionar.carro, null);
  // The same with no suggestion at all (both readings plausible).
  assert.equal(registroParaMover({ ...reversa, sugestao: null }, ESTADO).carroSugerido, null);
});

// SITUATION: the "knot": every counter who typed car 8 skipped another car
// the others saw. The flag names several cars and has NO lone entry (its id
// is "troca_provavel:grupo-…"); which of its entries is wrong is exactly
// what nobody knows.
//
// EXPECTED: no "move" form (there is no one stray entry; each entry is moved
// from its own chip on the timeline), and "Adicionar passagem" with no car:
// the flag's first car is no better a guess than the others.
test('a swap flag with no lone entry offers no "move" form and no car on "Adicionar"', () => {
  const no = { id: 'troca_provavel:grupo-201', tipo: 'troca_provavel', carros: [8, 9, 7], passagem_ids: [201, 202], fonte_id: null, ts_ms: LARGADA + 900000, sugestao: null };
  const edicao = edicaoManualDoAlerta(no, ESTADO);

  assert.equal(edicao.mover, null);
  assert.deepEqual(edicao.adicionar, { carro: null, tsMs: LARGADA + 900000 });
});

// What "Adicionar passagem" starts with on the other kinds of flag. The car
// is only filled in where adding that car's pass at that moment is what the
// flag is about:
//   - a lone entry, a held pass: adding makes that same pass count, once,
//     and the flag closes (checked against the engine);
//   - counters far apart (the pass counts already by the two who agree):
//     adding at the stray entry's time counted the car TWICE: no car;
//   - a pass while logged in the pit lane: an added pass always counts, so
//     it went round the hold and left the car logged inside: no car;
//   - an unknown number: the server refuses a pass for it: no car;
//   - an exit the engine assumed: the pass counts already: no car;
//   - an entry of unknown time: the car, but no time.
test('"Adicionar passagem" fills in the car only where adding it is what the flag is about', () => {
  const carroDe = (tipo) => edicaoManualDoAlerta({ tipo, carros: [23], passagem_ids: [5], ts_ms: LARGADA + 1000 }, ESTADO).adicionar.carro;

  for (const tipo of ['solitaria', 'passagem_proxima_demais', 'primeira_passagem_tardia', 'volta_curta']) {
    assert.equal(carroDe(tipo), 23, tipo);
  }
  for (const tipo of ['troca_provavel', 'dispersao_alta', 'no_box', 'no_abastecimento', 'carro_desconhecido', 'saida_presumida']) {
    assert.equal(carroDe(tipo), null, tipo);
  }
  assert.deepEqual(edicaoManualDoAlerta({ tipo: 'tempo_desconhecido', carros: [23], passagem_ids: [5], ts_ms: 123 }, ESTADO).adicionar,
    { carro: 23, tsMs: null });
  assert.equal(edicaoManualDoAlerta({ tipo: 'solitaria', carros: [23], passagem_ids: [5], ts_ms: 1 }, ESTADO).mover, null, 'only a probable swap has a stray entry to move');
});

// SITUATION: a reviewer added a pass for car 5 by hand, 29 s after a pass
// the counters agree on. The engine counts what a reviewer says, and then
// flags the two as a "volta curta", with the ADDED pass as one of the two.
// An added pass is no counter's entry: on the screen its id is below zero.
//
// EXPECTED: the reviewer can get out from that flag. "Descartar a segunda
// passagem" names the added pass and says what it does to it (it removes
// the addition), and the request is built like any other.
test('a short lap made by a pass a reviewer added can be undone from the flag', () => {
  const voltaCurta = {
    tipo: 'volta_curta', carros: [5], passagem_ids: [-77, 60, 61, 62], contagem_em_duvida: 'a_mais',
    passagens: [{ passagem_ids: [60, 61, 62], ts_ms: LARGADA + 421000 }, { passagem_ids: [-77], ts_ms: LARGADA + 450000 }],
  };
  const passagens = passagensParaDescartar(voltaCurta);

  assert.equal(rotuloDeDescartar(passagens[0], LARGADA), 'Descartar a primeira passagem (0:07:01, anula os 3 registros)');
  assert.equal(rotuloDeDescartar(passagens[1], LARGADA), 'Descartar a segunda passagem (0:07:30, remove a passagem adicionada pelo revisor)');
  const pedido = pedidoParaAlerta({ tipo: 'descartar_passagem', passagem_id: passagens[1].passagemId }, voltaCurta, ESTADO, 'sig');
  assert.equal(pedido.decisao.passagem_id, -77);
});
