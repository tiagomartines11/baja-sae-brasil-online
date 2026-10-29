<?php
namespace Baja\Juiz;

use Baja\Session;

Session::permissionCheck('admin');

Ajuda::printHeader("Parâmetros de Prova", "admin_provas.php", [
    'visao-geral' => 'Visão geral',
    'chaves' => 'Chaves principais',
    'type' => 'type',
    'inputs' => 'inputs',
    'tipos-campo' => 'Tipos de campo',
    'pass-xor' => 'pass e xor',
    'vars' => 'vars',
    'min_max' => 'min_max',
    'pontos' => 'pontos',
    'formulas' => 'Fórmulas',
    'torneio' => 'Torneio',
    'backups' => 'Backups e recálculo',
    'exemplo-completo' => 'Exemplo completo',
]);

Ajuda::secao('visao-geral', 'Visão geral');
?>
<tr><td>
    <p>Uma <strong>prova</strong> define o que os juízes lançam para cada equipe e como esses dados viram pontos.
    Os resultados públicos (<a href="ajuda_resultado.php">ajuda de resultados</a>) leem os dados e os pontos calculados aqui.</p>
    <p><strong>Campos do formulário:</strong></p>
    <ul>
        <li><strong>ID Prova</strong> &mdash; até 3 caracteres, único no evento (ex.: <code>AVF</code>, <code>END</code>). Não pode ser alterado depois de criado.
            É também o nome da <strong>permissão</strong> que o usuário juiz precisa ter para lançar notas nesta prova,
            e o código usado no campo <em>Inputs</em> dos resultados.</li>
        <li><strong>Nome Prova</strong> &mdash; aparece na lista do juiz e no cabeçalho das telas de lançamento.</li>
        <li><strong>Parâmetros</strong> &mdash; JSON descrito nesta página.</li>
        <li><strong>Totais</strong> &mdash; somente leitura: máximos, mínimos, contagens, somas e médias calculados a partir de <a href="#min_max"><code>min_max</code></a>.</li>
    </ul>
    <p><strong>Ordem de cálculo</strong> (por equipe, a cada lançamento ou &quot;Refresh Pontos&quot;):</p>
    <?= Ajuda::codigo("lançamento do juiz (inputs)  →  vars  →  min_max  →  Totais (todas as equipes)  →  pontos") ?>
    <p>Na tela inicial do juiz, a prova só aparece se tiver pelo menos um item em <code>inputs</code> (ou for um torneio)
    e se o usuário tiver a permissão com o ID da prova. A exceção é a prova <a href="#type"><code>"laps"</code></a>, que aparece mesmo sem <code>inputs</code>
    para quem participa da contagem: o responsável (permissão com o ID da prova), os contadores e o registro de box.</p>
    <div class="aviso">Se o JSON estiver inválido (vírgula sobrando, aspas faltando&hellip;), nada é salvo: a página mostra o erro e devolve o texto para correção.
    Já um campo <strong>vazio</strong> (ou <code>null</code>) é salvo como <code>null</code> e a prova some da tela do juiz; a versão anterior fica no backup <strong>-1</strong> (veja <a href="#backups">Backups</a>).</div>
</td></tr>
<?php
Ajuda::fimSecao();

Ajuda::secao('chaves', 'Chaves principais', ['Chave', 'Tipo', 'Descrição']);
Ajuda::opcao('type', 'texto', 'Tipo de lançamento: omitido (normal), <code>"rolling"</code>, <code>"tournament"</code> ou <code>"laps"</code>. Veja <a href="#type">type</a>.');
Ajuda::opcao('inputs', 'lista', 'Campos que o juiz preenche. Veja <a href="#inputs">inputs</a>.');
Ajuda::opcao('vars', 'objeto', 'Variáveis calculadas por equipe a partir dos inputs. Veja <a href="#vars">vars</a>.');
Ajuda::opcao('min_max', 'objeto', 'Variáveis por equipe cujo máximo, mínimo, contagem, soma e média entre todas as equipes são calculados. Veja <a href="#min_max">min_max</a>.');
Ajuda::opcao('pontos', 'objeto', 'Fórmulas de pontuação. Veja <a href="#pontos">pontos</a>.');
Ajuda::opcao('seeding', 'objeto', 'Somente torneio: quem participa e como é feita a classificação. Veja <a href="#torneio">Torneio</a>.');
Ajuda::opcao('status', 'objeto', 'Somente torneio: estado da classificação (gerenciado pela tela de chaveamento). Veja <a href="#torneio">Torneio</a>.');
Ajuda::opcao('tournament', 'objeto', 'Somente torneio: disputa de 3º lugar e &quot;melhor de N&quot;. Veja <a href="#torneio">Torneio</a>.');
?>
<tr><td colspan="3">
    Qualquer outra chave é ignorada. Configurações antigas têm, por exemplo, <code>"Categoria Finalista"</code> (24NE_FIN) ou
    <code>pontos</code>/<code>excess_matches</code> em torneios &mdash; nada disso é lido pelo sistema.
</td></tr>
<?php
Ajuda::fimSecao();

Ajuda::secao('type', 'type — tipo de lançamento', ['Valor', 'Tela do juiz', 'Comportamento']);
Ajuda::opcao('(omitido) ou "normal"', 'Lista de equipes', 'O juiz vê todas as equipes presentes, com os valores já lançados (em vermelho os vazios), e abre um formulário por equipe.
    Existe <strong>uma nota por equipe</strong>: salvar de novo sobrescreve, e é possível <em>Deletar Nota</em>.
    Cada alteração gera log e envia a notificação push &quot;Pontuação Publicada&quot; (exceto campos <a href="#inputs"><code>sensitive</code></a>).');
Ajuda::opcao('"rolling"', 'Formulário único', 'O juiz escolhe a equipe e preenche o formulário; cada envio <strong>acrescenta</strong> uma nova entrada (ex.: penalizações do enduro).
    Cada entrada recebe <code>ts</code> = hora do lançamento (timestamp Unix). Não há edição nem exclusão pela tela do juiz, e não há notificação push.
    No resultado, cada entrada vira uma linha.');
Ajuda::opcao('"tournament"', 'Chaveamento', 'Mata-mata. Não usa <code>inputs</code>. Veja <a href="#torneio">Torneio</a>.');
Ajuda::opcao('"laps"', 'Contagem de voltas', 'Corrida com contagem de voltas (ex.: enduro). O link da prova na tela do juiz abre a central da contagem, e não um formulário:
    lá ficam as fontes, os usuários e o controle da corrida. Configuração completa em
    <a href="https://github.com/tiagomartines11/baja-sae-brasil-online/blob/develop/docs/contagem.md#before-the-event" target="_blank">docs/contagem.md, &quot;Before the event&quot;</a>.
    <br/><strong>Inputs obrigatórios</strong> (<code>number</code>), preenchidos pela contagem em cada carro:
    <code>VOLTAS</code> (voltas contadas), <code>LAST</code> (segundos do início da corrida até a última passagem oficial)
    e <code>BEST</code> (melhor volta válida em segundos; 0 se não houver).
    <br/><strong>Opcional:</strong> um campo de classificação, ex.: <code>APTO</code> (enum <code>SIM</code>/<code>NÃO</code>), escolhido em
    <em>Fontes e configurações &rarr; campo de classificação</em>; só correm os carros presentes com <code>SIM</code>.
    <strong>Nunca use <code>DESCLAS</code></strong> (nem outro campo lido pelas fórmulas) para isso: no Enduro, <code>DESCLAS = SIM</code> zera os pontos.');
?>
<tr><td colspan="3">
    <div class="aviso">Sem <code>type</code> (ou com ele vazio) a prova é <code>"normal"</code>. O valor é comparado exatamente, maiúsculas incluídas:
    na tela do juiz, qualquer valor diferente de <code>"normal"</code>, <code>"tournament"</code> e <code>"laps"</code>
    (inclusive erros de digitação, como <code>"Laps"</code> ou <code>"lap"</code>) abre o formulário de <code>"rolling"</code>.</div>
    <?= Ajuda::exemplo(<<<'JSON'
{
  "type": "rolling",
  "inputs": [
    {"code": "POSTO", "name": "Posto", "type": "enum", "options": ["PRÉ", "A", "B", "C", "D"]},
    {"code": "PENA", "name": "Penalidade", "type": "enum", "options": [
      "Não respeitar bandeira amarela (5 min)",
      "Passar em área não autorizada (2 min)",
      "Atitude antidesportiva (DQ)"
    ]}
  ]
}
JSON, 'Exemplo: Penalizações Enduro (baseado em 26BR_EPN, lista de opções encurtada)') ?>
    <?= Ajuda::exemplo(<<<'JSON'
{
  "type": "laps",
  "inputs": [
    {"code": "VOLTAS", "name": "Voltas", "type": "number", "precision": 0},
    {"code": "LAST", "name": "Última passagem (s)", "type": "number", "precision": 0},
    {"code": "BEST", "name": "Melhor volta (s)", "type": "number", "precision": 0},
    {"code": "DESCLAS", "name": "Desclassificado", "type": "enum", "options": ["SIM", "NÃO"]}
  ],
  "pontos": {"ENDPTS": "$DESCLAS=='SIM'?0:(400 * $VOLTAS / $Vmax)"},
  "min_max": {"V": "$VOLTAS"}
}
JSON, 'Exemplo: Enduro com contagem de voltas. DESCLAS é lido pela fórmula, por isso não serve como campo de classificação') ?>
</td></tr>
<?php
Ajuda::fimSecao();

Ajuda::secao('inputs', 'inputs — campos do juiz', ['Chave', 'Tipo', 'Descrição']);
Ajuda::opcao('code', 'texto, obrigatório', 'Nome da variável. Nas fórmulas vira <code>$CODE</code> e é a chave gravada nos dados da equipe; também é o cabeçalho da coluna no painel do juiz.
    Use só letras, números e <code>_</code>, sem começar com número. Não repita códigos na mesma prova e evite repetir códigos de outras provas que entram no mesmo resultado
    (o último sobrescreve). Evite os nomes reservados <code>NUM</code>, <code>EQUIPE</code>, <code>ESCOLA</code>, <code>ESTADO</code>, <code>DESCLASSIFICADO</code>, <code>ts</code> e <code>entries</code>.');
Ajuda::opcao('name', 'texto, obrigatório', 'Rótulo mostrado ao lado do campo no formulário do juiz.');
Ajuda::opcao('type', 'texto', '<code>"number"</code> (padrão), <code>"enum"</code>, <code>"enum-assoc"</code> ou <code>"time"</code>. Qualquer outro valor (ex.: <code>"num"</code>) vira <code>number</code>. Veja <a href="#tipos-campo">Tipos de campo</a>.');
Ajuda::opcao('precision', 'inteiro', 'Somente <code>number</code>: casas decimais aceitas. Padrão 0 (inteiro); <code>3</code> aceita 0,001. As configurações existentes usam <code>12</code> para notas &quot;livres&quot;.');
Ajuda::opcao('negative', 'booleano', 'Somente <code>number</code>: <code>true</code> aceita apenas valores &le; 0 (penalidades); caso contrário apenas &ge; 0.
    Use <code>true</code>/<code>false</code> sem aspas &mdash; o texto <code>"false"</code> é considerado verdadeiro.');
Ajuda::opcao('options', 'lista', '<code>enum</code>: lista de textos. <code>enum-assoc</code>: lista de pares <code>[rótulo, valor]</code>.');
Ajuda::opcao('multiple', 'booleano', 'Somente <code>enum</code>: permite selecionar várias opções. O valor é gravado como texto separado por vírgula (ex.: <code>"A,C"</code>).');
Ajuda::opcao('pass', 'inteiro 1&ndash;10', 'Grupo de preenchimento. Padrão 1. Veja <a href="#pass-xor">pass e xor</a>.');
Ajuda::opcao('xor', '"A" ou "B"', 'Campos mutuamente exclusivos. Veja <a href="#pass-xor">pass e xor</a>.');
Ajuda::opcao('sensitive', 'booleano', 'Se <code>true</code>, uma alteração neste campo <strong>não</strong> dispara a notificação push &quot;Pontuação Publicada&quot;. Útil para notas que não devem ser anunciadas (ex.: presença em briefings, notas de projeto).');
?>
<tr><td colspan="3">
    <p>Valores gravados: <code>number</code> como número; os demais como texto; campo vazio como <code>null</code>.
    Textos numéricos (ex.: valor de um <code>enum-assoc</code>) funcionam normalmente em contas.</p>
    <?= Ajuda::exemplo(<<<'JSON'
{
  "inputs": [
    {"code": "BRF_QUI", "name": "Quinta", "pass": 2, "type": "enum", "options": ["SIM", "NÃO"], "sensitive": true},
    {"code": "PEN_GERAL", "name": "Penalidade", "type": "number", "negative": true, "precision": 0}
  ]
}
JSON, 'Exemplo: campo sensível (25BR_BRF) e penalidade negativa (22BR_PEN)') ?>
</td></tr>
<?php
Ajuda::fimSecao();

Ajuda::secao('tipos-campo', 'Tipos de campo', ['type', 'Campo na tela', 'Exemplo']);
Ajuda::opcao('number', 'Caixa numérica', 'Respeita <code>precision</code> e <code>negative</code>.' . Ajuda::exemplo(<<<'JSON'
{"code": "ACET_1", "name": "Aceleração 1 [s]", "pass": 1, "type": "number", "precision": 3}
JSON));
Ajuda::opcao('enum', 'Lista de opções', 'Grava o texto escolhido.' . Ajuda::exemplo(<<<'JSON'
{"code": "FREIO_1", "name": "Frenagem 1", "pass": 1, "type": "enum", "options": ["OK", "NOK"]}
JSON) . 'Com <code>multiple</code> (exemplo inventado &mdash; não há nenhum em uso):' . Ajuda::exemplo(<<<'JSON'
{"code": "CONES", "name": "Cones derrubados", "type": "enum", "multiple": true, "options": ["1", "2", "3", "4", "5"]}
JSON));
Ajuda::opcao('enum-assoc', 'Lista de opções', 'Mostra o rótulo e grava o valor associado. Ideal para checklists que já valem pontos.' . Ajuda::exemplo(<<<'JSON'
{"code": "CAT1", "name": "Suspensão, Direção e Freio", "type": "enum-assoc", "options": [["Sim", 1], ["Não", 0]]}
JSON) . 'Exemplo inventado com notas parciais:' . Ajuda::exemplo(<<<'JSON'
{"code": "ERGO_5", "name": "Ergonomia do Banco", "type": "enum-assoc", "options": [["Atende", 1.2], ["Atende parcialmente", 0.6], ["Não atende", 0]]}
JSON));
Ajuda::opcao('time', 'Hora (hh:mm)', 'Grava o texto da hora, ex.: <code>"14:35"</code>.' . Ajuda::exemplo(<<<'JSON'
{"code": "HORA", "name": "Approval Time [hh:mm:ss]", "type": "time"}
JSON));
Ajuda::fimSecao();

Ajuda::secao('pass-xor', 'pass e xor — fluxo de preenchimento');
?>
<tr><td>
    <p><strong><code>pass</code></strong> divide o formulário em grupos (1 a 10; sem <code>pass</code> = grupo 1). Na tela do juiz:</p>
    <ul>
        <li>Os botões <em>Salvar</em> só habilitam quando <strong>todos os campos do grupo que está sendo editado</strong> estão preenchidos.</li>
        <li>Os campos do <strong>grupo 2</strong> ficam bloqueados até que todo o grupo 1 esteja preenchido (ex.: 2ª tentativa só depois da 1ª). Grupos 3 em diante não são bloqueados.</li>
        <li>Uma linha mais grossa separa grupos consecutivos (1 &rarr; 2, 2 &rarr; 3&hellip;).</li>
    </ul>
    <p><strong><code>xor</code></strong> (<code>"A"</code> ou <code>"B"</code>, apenas essas duas letras): ao preencher um campo de um grupo xor, os <strong>outros campos vazios do mesmo grupo xor</strong> ficam bloqueados.
    Serve para &quot;um ou outro&quot;, como na Tração: ou o carro completa e se lança o tempo, ou não completa e se lança a distância.</p>
    <?= Ajuda::exemplo(<<<'JSON'
{
  "inputs": [
    {"xor": "A", "code": "T_1", "name": "Tempo 1 [s]", "pass": 1, "type": "number", "precision": 3},
    {"xor": "A", "code": "D_1", "name": "Distância 1 [m]", "pass": 1, "type": "number", "precision": 2},
    {"xor": "B", "code": "T_2", "name": "Tempo 2 [s]", "pass": 2, "type": "number", "precision": 3},
    {"xor": "B", "code": "D_2", "name": "Distância 2 [m]", "pass": 2, "type": "number", "precision": 2}
  ]
}
JSON, 'Exemplo: Tração (22BR_TRA) — 1ª tentativa no grupo 1 (xor A), 2ª tentativa no grupo 2 (xor B)') ?>
</td></tr>
<?php
Ajuda::fimSecao();

Ajuda::secao('vars', 'vars — variáveis por equipe');
?>
<tr><td>
    <p>Objeto <code>{"NOME": fórmula}</code>. Calculado para cada equipe usando <strong>apenas os inputs</strong> dessa equipe
    (uma variável de <code>vars</code> não enxerga outra). O valor pode ser uma constante, útil para parâmetros do regulamento.</p>
    <p>As variáveis ficam disponíveis em <code>min_max</code>, <code>pontos</code> e nos resultados.</p>
    <?= Ajuda::exemplo(<<<'JSON'
{
  "vars": {
    "ACE_1": "($FREIO_1=='OK') ? $ACET_1 : null",
    "ACE_2": "($FREIO_2=='OK') ? $ACET_2 : null",
    "VEL_1": "($FREIO_1=='OK') ? $VELO_1 : null",
    "VEL_2": "($FREIO_2=='OK') ? $VELO_2 : null"
  }
}
JSON, 'Exemplo: Aceleração/Velocidade (26BR_AVF) — a tentativa só vale se a frenagem foi OK') ?>
    <?= Ajuda::exemplo(<<<'JSON'
{"vars": {"DISTANCIA_MAX": 30}}
JSON, 'Exemplo: constante (22BR_TRA)') ?>
</td></tr>
<?php
Ajuda::fimSecao();

Ajuda::secao('min_max', 'min_max — valores comparados entre equipes');
?>
<tr><td>
    <p>Objeto <code>{"NOME": fórmula}</code>. Para cada equipe, a fórmula é calculada (enxerga inputs, <code>vars</code> e os <code>min_max</code> anteriores) e guardada junto das vars.
    Depois, considerando todas as equipes <strong>não desclassificadas</strong>, o sistema calcula os <strong>Totais</strong>:</p>
    <ul>
        <li><code>$NOMEmax</code> &mdash; maior valor, ignorando vazios e <code>9999</code>;</li>
        <li><code>$NOMEmin</code> &mdash; menor valor, ignorando vazios e <code>0</code>;</li>
        <li><code>$NOMEcom</code> &mdash; metade da fração de equipes com valor <code>9999</code> (use 9999 para &quot;não completou&quot;);</li>
        <li><code>$NOMEcount</code> &mdash; quantas equipes têm valor;</li>
        <li><code>$NOMEsum</code> &mdash; soma dos valores (0 se nenhuma equipe tem valor);</li>
        <li><code>$NOMEavg</code> &mdash; média dos valores (vazio se nenhuma equipe tem valor).</li>
    </ul>
    <p>Para <code>count</code>, <code>sum</code> e <code>avg</code>, &quot;ter valor&quot; é não estar vazio e não ser <code>9999</code>.
    O <code>0</code> é um valor e conta; valores negativos também.</p>
    <p><strong>Contar ou somar só algumas equipes:</strong> não precisa de sintaxe especial. Faça uma variável em <code>min_max</code> que dá vazio
    (<code>null</code>) para as equipes que não entram. Por exemplo, com o tempo <code>T</code> (um input ou uma var) valendo 9999 para quem não completou:</p>
    <?= Ajuda::exemplo(<<<'JSON'
{
  "min_max": {
    "FIN": "$T !== null && $T < 9999 ? 1 : null",
    "TFIN": "$T !== null && $T < 9999 ? $T : null"
  }
}
JSON, 'Exemplo: $FINcount é o número de equipes que completaram; $TFINsum soma só os tempos delas (um SOMASE) e $TFINavg é o tempo médio delas.') ?>
    <div class="aviso">Não tire o <code>$T !== null</code>: para o PHP, vazio é menor que 9999, e sem ele uma equipe com <code>T</code> vazio contaria como se tivesse completado.</div>
    <p>Os Totais aparecem no campo &quot;Totais&quot; da prova e só podem ser usados em <code>pontos</code> (não ficam disponíveis nos resultados).</p>
    <?= Ajuda::exemplo(<<<'JSON'
{
  "min_max": {
    "ACE": "(count(array_diff([$ACE_1, $ACE_2], [null])) > 0) ? min(array_diff([$ACE_1, $ACE_2], [null])) : null",
    "VEL": "(count(array_diff([$VEL_1, $VEL_2], [null])) > 0) ? max(array_diff([$VEL_1, $VEL_2], [null])) : null"
  }
}
JSON, 'Exemplo (26BR_AVF): melhor tempo de aceleração e melhor velocidade de cada equipe. Gera $ACEmin, $ACEmax, $ACEcount..., $VELmin, $VELmax, $VELcount...') ?>
    <?= Ajuda::codigo(<<<'TXT'
"Totais" resultantes (ilustrativo):
{"ACEmax": 6.512, "ACEmin": 4.103, "ACEcom": 0, "ACEcount": 40, "ACEsum": 201.4, "ACEavg": 5.035,
 "VELmax": 52.3, "VELmin": 38.9, "VELcom": 0, "VELcount": 40, "VELsum": 1820, "VELavg": 45.5}
TXT) ?>
</td></tr>
<?php
Ajuda::fimSecao();

Ajuda::secao('pontos', 'pontos — pontuação');
?>
<tr><td>
    <p>Objeto <code>{"NOME": fórmula}</code>. Enxerga inputs, <code>vars</code>, <code>min_max</code> da equipe e os Totais (<code>$NOMEmax</code>, <code>$NOMEmin</code>, <code>$NOMEcom</code>, <code>$NOMEcount</code>, <code>$NOMEsum</code>, <code>$NOMEavg</code>).</p>
    <ul>
        <li>As fórmulas são calculadas em <strong>ordem alfabética do nome</strong>, e cada resultado fica disponível para as seguintes.
            Ex.: <code>SGE_STATUS</code> pode usar <code>$SGEPTS</code>, mas não o contrário.</li>
        <li>Não são calculados para equipes desclassificadas.</li>
        <li>Podem retornar texto (ex.: <code>'APROVADO'</code>).</li>
        <li>O primeiro valor (em ordem alfabética) aparece na notificação push &quot;Pontuação Publicada&quot;.</li>
        <li>Por convenção o nome termina em <code>PTS</code> (<code>ACEPTS</code>, <code>ENDPTS</code>) &mdash; é o nome usado nos resultados.</li>
    </ul>
    <?= Ajuda::exemplo(<<<'JSON'
{
  "pontos": {
    "ACEPTS": "($ACE === null || $ACEmin === null || $ACEmax === null || (MIN($ACEmax, 1.5 * $ACEmin) - $ACEmin) == 0) ? 0 : MAX(0, 30 * (MIN($ACEmax, 1.5 * $ACEmin) - $ACE) / (MIN($ACEmax, 1.5 * $ACEmin) - $ACEmin))"
  }
}
JSON, 'Exemplo (26BR_AVF): 30 pontos para o melhor tempo, caindo linearmente até 1,5× o melhor tempo') ?>
    <?= Ajuda::exemplo(<<<'JSON'
{
  "pontos": {
    "SGEPTS": "0-$SGE_PEN_DOC_1-$SGE_PEN_DOC_2-$SGE_PEN_DOC_3-$SGE_PEN_ICTS",
    "SGE_STATUS": "$SGESTATUS?($SGESTATUS=='SIM'?'APROVADO':'REPROVADO'):''"
  }
}
JSON, 'Exemplo (26BR_SGE): soma de penalidades e um status em texto') ?>
    <?= Ajuda::exemplo(<<<'JSON'
{"pontos": {"ENDPTS": "$DESCLAS=='SIM'?0:(400 * $VOLTAS / $Vmax)"}, "min_max": {"V": "$VOLTAS"}}
JSON, 'Exemplo (26BR_END): proporcional às voltas da equipe que mais andou') ?>
</td></tr>
<?php
Ajuda::fimSecao();

Ajuda::secao('formulas', 'Fórmulas');
?>
<tr><td>
    <p>As fórmulas (em <code>vars</code>, <code>min_max</code>, <code>pontos</code> e nas colunas dos resultados) são <strong>expressões PHP</strong> escritas dentro de uma string JSON.</p>
    <ul>
        <li>Variáveis: <code>$CODIGO</code>. Uma variável que não existe ou não foi lançada vale <code>null</code> (em contas, conta como 0).</li>
        <li>Operadores: <code>+ - * /</code>, comparação <code>== != &lt; &gt; ===</code>, lógicos <code>&amp;&amp; ||</code>, ternário <code>cond ? a : b</code>.</li>
        <li>Texto entre aspas simples: <code>'SIM'</code>. Para juntar textos use <strong>ponto</strong>: <code>'&lt;b&gt;'.$X.'&lt;/b&gt;'</code> &mdash; <code>+</code> com texto dá erro.</li>
        <li>Funções do PHP: <code>ROUND($x, 2)</code>, <code>MIN(...)</code>, <code>MAX(...)</code>, <code>ABS()</code>, <code>count()</code>, <code>array_diff()</code>&hellip; (maiúsculas ou minúsculas tanto faz).</li>
        <li>Qualquer erro (divisão por zero, sintaxe, <code>min()</code> de lista vazia) faz a fórmula valer <code>null</code>, sem mensagem. Resultados infinitos ou NaN também viram <code>null</code>.</li>
        <li>Aspas duplas dentro da fórmula precisam de escape no JSON (<code>\"</code>); prefira aspas simples.</li>
    </ul>
    <p>Padrões úteis:</p>
    <?= Ajuda::codigo(<<<'TXT'
min(array_diff([$T_1, $T_2], [null]))          menor valor ignorando tentativas vazias
max(array_diff([$D_1, $D_2], [null]))          maior valor ignorando tentativas vazias
$X === null ? 0 : $X                           tratar "não lançado"
$FREIO_1=='OK' ? $ACET_1 : null                anular tentativa inválida
TXT) ?>
    <div class="aviso">As fórmulas são executadas como código no servidor. Não cole fórmulas de origem desconhecida.</div>
</td></tr>
<?php
Ajuda::fimSecao();

Ajuda::secao('torneio', 'Torneio (type: "tournament")', ['Chave', 'Tipo', 'Descrição']);
Ajuda::opcao('seeding.able.inputs', 'lista de IDs de prova', 'Provas cujos dados são carregados para decidir quem participa.');
Ajuda::opcao('seeding.able.formula', 'fórmula', 'Deve resultar exatamente no número <code>1</code> para a equipe participar (use <code>cond ? 1 : 0</code>; <code>true</code> não serve).');
Ajuda::opcao('seeding.classification', 'lista', 'Critérios de classificação, em ordem de prioridade (o primeiro decide; os seguintes desempatam). Cada item:
    <ul>
        <li><code>title</code> &mdash; cabeçalho da coluna na tela de chaveamento;</li>
        <li><code>inputs</code> &mdash; provas cujos dados são usados;</li>
        <li><code>formula</code> &mdash; fórmula, ou <code>"random"</code> para sorteio;</li>
        <li><code>order</code> &mdash; <code>"desc"</code>: <strong>maior é melhor</strong> (pontos); <code>"asc"</code>: <strong>menor é melhor</strong> (tempo, posição). Sem <code>order</code>, vale <code>"desc"</code>.</li>
    </ul>
    <p>Cada critério usa a sua própria ordem; o seguinte só é usado quando todos os anteriores empatam. Equipe com fórmula sem valor (<code>null</code>) fica por último no critério, em qualquer ordem.</p>
    <div class="aviso">Escreva a fórmula com o valor como ele é (ex.: <code>$TEMPO</code> com <code>"asc"</code>). Não inverta o sinal: com <code>-$TEMPO</code> e <code>"asc"</code>, o tempo maior ficaria em primeiro.</div>');
Ajuda::opcao('status', 'objeto', 'Gerenciado pela tela de chaveamento; pode ficar de fora (é criado ao congelar). Se escrever, use <code>{"frozen": false, "classification": false}</code>:
    <code>frozen</code> indica classificação congelada e <code>classification</code> guarda a lista de carros na ordem classificada.');
Ajuda::opcao('tournament.third_place_match', 'booleano', 'Cria a disputa de 3º lugar.');
Ajuda::opcao('tournament.bestof', 'objeto', 'Opcional. <code>{"matches": 3, "from_round": 1}</code>: confrontos &quot;melhor de 3&quot; a partir da rodada 1 (semifinais) até a final, incluindo a disputa de 3º.
    Rodadas: <code>0</code> final, <code>1</code> semifinais, <code>2</code> quartas, <code>3</code> oitavas, <code>4</code> 16 avos&hellip; <code>-1</code> disputa de 3º.');
?>
<tr><td colspan="3">
    <p><strong>Passo a passo</strong> (link &quot;Administrar Chaveamento&quot; no rodapé da prova):</p>
    <ul>
        <li>A tela mostra a classificação ao vivo, recalculada a cada acesso (com <code>"random"</code>, o sorteio muda a cada acesso).</li>
        <li><em>Congelar Classificação</em> grava a ordem atual em <code>status.classification</code>.</li>
        <li><em>Criar Chaveamento</em> monta o mata-mata (máximo 128 equipes) com cabeças de chave: o 1º e o 2º só podem se encontrar na final; quem fica sem adversário avança direto (WO).</li>
        <li>Os juízes com permissão na prova lançam os vencedores. <em>Destruir Chaveamento</em> apaga todos os confrontos para recomeçar.</li>
    </ul>
    <p>Para publicar o chaveamento, crie um resultado com <em>Inputs</em> = ID desta prova e colunas <code>{"type": "tournament"}</code> (veja a <a href="ajuda_resultado.php#torneio">ajuda de resultados</a>).</p>
    <?= Ajuda::exemplo(<<<'JSON'
{
  "type": "tournament",
  "status": {"frozen": false, "classification": false},
  "seeding": {
    "able": {
      "inputs": ["SGE", "SGD"],
      "formula": "($SGE_STATUS=='APROVADO'&&$SGD_STATUS=='APROVADO')?1:0"
    },
    "classification": [
      {"order": "desc", "title": "Dinâmicas", "inputs": ["AVF", "TRA", "MAN", "SUS"], "formula": "$ACEPTS+$VELPTS+$TRAPTS+$MANPTS+$SUSPTS"},
      {"title": "Aleatório", "formula": "random"}
    ]
  },
  "tournament": {
    "bestof": {"matches": 3, "from_round": 1},
    "third_place_match": true
  }
}
JSON, 'Exemplo: Super Prime (26BR_SPR, com status zerado) — aprovados em segurança, classificados pela soma das dinâmicas, desempate por sorteio') ?>
</td></tr>
<?php
Ajuda::fimSecao();

Ajuda::secao('backups', 'Backups e recálculo');
?>
<tr><td>
    <ul>
        <li><strong>Atualizar</strong> salva nome e parâmetros. Se os parâmetros mudaram, a versão anterior vai para o backup <strong>-1</strong> (e as anteriores descem até <strong>-5</strong>).</li>
        <li><strong>Código Atual / -1&hellip;-5 / Backups Nomeados</strong> apenas carregam a versão no editor; é preciso clicar em <em>Atualizar</em> para aplicá-la.</li>
        <li><strong>💾 Salvar Backup com Nome</strong> guarda o conteúdo atual do editor com um nome; <strong>❌</strong> apaga o backup nomeado selecionado.</li>
        <li><strong>Refresh Pontos ao salvar</strong> (marcado por padrão) recalcula vars, Totais e pontos de todas as equipes ao atualizar. O botão <strong>Refresh Pontos</strong> faz o mesmo sem salvar.
            Cada lançamento de juiz também recalcula a prova inteira.</li>
        <li>Renomear um <code>code</code> não renomeia os dados já lançados: as notas antigas ficam com o código antigo.</li>
    </ul>
</td></tr>
<?php
Ajuda::fimSecao();

Ajuda::secao('exemplo-completo', 'Exemplo completo');
?>
<tr><td>
    <?= Ajuda::exemplo(<<<'JSON'
{
  "vars": {"DISTANCIA_MAX": 30},
  "inputs": [
    {"xor": "A", "code": "T_1", "name": "Tempo 1 [s]", "pass": 1, "type": "number", "precision": 3},
    {"xor": "A", "code": "D_1", "name": "Distância 1 [m]", "pass": 1, "type": "number", "precision": 2},
    {"xor": "B", "code": "T_2", "name": "Tempo 2 [s]", "pass": 2, "type": "number", "precision": 3},
    {"xor": "B", "code": "D_2", "name": "Distância 2 [m]", "pass": 2, "type": "number", "precision": 2}
  ],
  "min_max": {
    "D": "($T_1 === null && $T_2 === null) ? max(array_diff([$D_1, $D_2], [null])) : null",
    "T": "min(array_diff([$T_1, $T_2], [null]))"
  },
  "pontos": {
    "TRAPTS": "($Tmax === null) ? 50 * ($D - $Dmin)/($Dmax - $Dmin) : (($Dmax === null) ? 50 * ($Tmax - $T)/($Tmax - $Tmin) : ($T ? (50 * $Tmin/$T) : (50 * $Tmin/$Tmax * $D / $DISTANCIA_MAX)))"
  }
}
JSON, 'Tração (22BR_TRA): duas tentativas; quem completa pontua pelo tempo, quem não completa pela distância') ?>
</td></tr>
<?php
Ajuda::fimSecao();

Ajuda::printFooter();
