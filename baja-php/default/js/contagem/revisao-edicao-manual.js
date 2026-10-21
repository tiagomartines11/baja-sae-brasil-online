// What "Editar manualmente" opens on a flag of the review screen: which
// forms, and what each starts filled in with. Pure functions, tested with
// `node --test`.

// ---- "Editar manualmente" ---------------------------------------------------------
//
// The button opens what a reviewer can do by hand on a flag. It used to open
// one form on every flag, "Adicionar passagem", filled in with the flag's
// first car and the flag's time. On several kinds of flag that prefill was a
// trap: pressing "Adicionar" did something else than settle the flag.

// Flags on which "Adicionar passagem" is offered WITHOUT a car filled in,
// because the flag's first car at the flag's time is not a pass anybody
// should add in a hurry:
//   troca_provavel     the first car is the car of the LONE entry, which on
//                      an ordinary typo is the number typed WRONG: adding
//                      gave that car a lap and left the stray entry as it was
//   dispersao_alta     the pass already counts by the counters who agree;
//                      adding at the stray entry's time counted it twice
//   no_box, no_abastecimento
//                      an added pass always counts: it went round the hold
//                      and left the car logged inside, with the flag open
//   carro_desconhecido the number is no team of the event: the server
//                      refuses a pass for it
//   saida_presumida    the pass counts already (the engine assumed the exit);
//                      there is nothing to add for that car at that moment
//   numero_ilegivel, numero_atribuido
//                      the first car is only a candidate: what settles it is
//                      giving the "??" its car; a pass added for it as well
//                      would count that crossing twice
const TIPOS_SEM_CARRO_AO_ADICIONAR = [
  'troca_provavel', 'dispersao_alta', 'no_box', 'no_abastecimento', 'carro_desconhecido', 'saida_presumida',
  'numero_ilegivel', 'numero_atribuido',
];

// A "troca provável" with no lone entry: every counter of one pass skipped
// another car (the engine gives such a flag the id "troca_provavel:grupo-N").
// There is no single stray entry to move there.
const ehTrocaSemRegistroSolitario = (alerta) => String(alerta?.id ?? '').startsWith('troca_provavel:grupo-');

// The stray entry a "troca provável" is about, for the form "Mover o
// registro … para o carro [N]". Null on every other flag.
//   passagemId     the entry (the flag's first)
//   carroAtual     the car it was typed for
//   fonteNome      whose entry it is, or null when the state does not say
//   carroSugerido  the car to offer: the suggestion's, when the suggestion
//                  is the ordinary one (move THIS entry). Null otherwise:
//                  on the reverse reading ("reatribuir_grupo") the engine
//                  thinks this entry is the one that is right, and with no
//                  suggestion it does not know. The reviewer then types it.
export function registroParaMover(alerta, estado) {
  const ids = alerta?.passagem_ids ?? [];
  if (alerta?.tipo !== 'troca_provavel' || ehTrocaSemRegistroSolitario(alerta) || ids.length === 0 || ids[0] <= 0) {
    return null;
  }
  const sugestao = alerta.sugestao;
  const fonte = (estado?.fontes ?? []).find((linha) => linha.id === alerta.fonte_id);
  return {
    passagemId: ids[0],
    carroAtual: (alerta.carros ?? [])[0] ?? null,
    fonteNome: fonte?.nome ?? null,
    carroSugerido: sugestao?.tipo === 'reatribuir' && sugestao.passagem_id === ids[0] ? sugestao.equipe_numero : null,
  };
}

// What "Editar manualmente" opens on a flag:
//   mover      see registroParaMover(); null when the flag has no stray entry
//   adicionar  what the "Adicionar passagem" form starts with: {carro, tsMs},
//              each null when it must be typed
export function edicaoManualDoAlerta(alerta, estado) {
  const semCarro = TIPOS_SEM_CARRO_AO_ADICIONAR.includes(alerta?.tipo);
  return {
    mover: registroParaMover(alerta, estado),
    adicionar: {
      carro: semCarro ? null : (alerta?.carros ?? [])[0] ?? null,
      // The time of an entry of unknown time is its phone's own guess.
      tsMs: alerta?.tipo === 'tempo_desconhecido' ? null : alerta?.ts_ms ?? null,
    },
  };
}

// May the stray entry be moved to this car? Returns the sentence to show,
// or null when it is fine. The server checks again.
export function problemaAoMover(mover, carroNovo, estado) {
  if (!Number.isInteger(carroNovo) || carroNovo <= 0) {
    return 'Informe o número do carro para o qual o registro deve ser movido.';
  }
  if (carroNovo === mover.carroAtual) {
    return `Este registro já é do carro ${carroNovo}. Informe o carro que realmente passou; se o registro está certo, use "Contar esta passagem".`;
  }
  if ((estado?.carros_validos ?? []).some((carro) => carro.numero === carroNovo)) {
    return null;
  }
  const naTabela = (estado?.carros ?? []).find((linha) => linha.carro === carroNovo);
  return naTabela?.presente
    ? `O carro ${carroNovo} não está classificado para esta prova: uma passagem dele não contaria.`
    : `O carro ${carroNovo} não é de uma equipe presente neste evento.`;
}
