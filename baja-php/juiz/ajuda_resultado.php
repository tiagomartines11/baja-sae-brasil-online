<?php
namespace Baja\Juiz;

use Baja\Session;

Session::permissionCheck('admin');

Ajuda::printHeader("Configuração de Resultado", "admin_resultados.php", [
    'visao-geral' => 'Visão geral',
    'inputs' => 'Inputs',
    'variaveis' => 'Variáveis disponíveis',
    'chaves' => 'Chaves principais',
    'colunas' => 'colunas',
    'classificacao' => 'Classificação',
    'spoilers' => 'Spoilers',
    'filter' => 'filter',
    'torneio' => 'Torneio',
    'exemplos' => 'Exemplos',
    'backups' => 'Backups',
]);

Ajuda::secao('visao-geral', 'Visão geral');
?>
<tr><td>
    <p>Um <strong>resultado</strong> é uma tabela pública no site de resultados. Ele junta os dados de uma ou mais <a href="ajuda_prova.php">provas</a>
    e define quais colunas mostrar e como classificar as equipes.</p>
    <p><strong>Campos do formulário:</strong></p>
    <ul>
        <li><strong>ID Resultado</strong> &mdash; até 8 caracteres, <strong>único entre todos os eventos</strong>. Por convenção, o ID do evento + <code>_</code> + código:
            <code>26BR_ACE</code>, <code>26BR_GER</code>. É o ID usado no <a href="ajuda_menu.php">menu do evento</a> e na URL (<code>prova.php?id=26BR_ACE</code>). Não pode ser alterado depois de criado.</li>
        <li><strong>Título Resultado</strong> &mdash; até 45 caracteres; aparece no menu e no título da página.</li>
        <li><strong>Inputs</strong> &mdash; IDs das provas, separados por vírgula. Veja <a href="#inputs">Inputs</a>.</li>
        <li><strong>Colunas</strong> &mdash; JSON descrito nesta página.</li>
    </ul>
    <p><strong>Como a tabela é montada:</strong> uma linha por equipe que tenha dados em pelo menos uma das provas de <em>Inputs</em>
    (provas <code>"rolling"</code> geram uma linha por lançamento). As linhas aparecem em ordem de número do carro; o público pode clicar no cabeçalho para ordenar.
    Se o resultado usa uma única prova, o rodapé mostra o status dela (Parcial/Final, alterado em <a href="admin_provas.php">Administrar provas</a>) e a hora da última atualização.</p>
    <div class="aviso">Se o JSON estiver inválido, as colunas são salvas como <code>null</code> sem nenhum aviso e a página pública passa a mostrar o aviso de &quot;sem resultados&quot;.
    A versão anterior fica no backup <strong>-1</strong>.</div>
</td></tr>
<?php
Ajuda::fimSecao();

Ajuda::secao('inputs', 'Inputs');
?>
<tr><td>
    <p>Lista de IDs de prova (os de 3 letras, sem o prefixo do evento), separados por vírgula. Espaços são removidos.</p>
    <?= Ajuda::codigo("AVF                                   Aceleração e Velocidade usam a mesma prova\nAVF, MAN, TRA, SUS, SPP               Dinâmicas\nAVF, MAN, TRA, SUS, SPP, END, SGE, PEN    Geral (encurtado)") ?>
    <p>Os dados de todas as provas listadas são juntados por equipe. Se duas provas tiverem um campo com o mesmo <code>code</code>, vale o da prova carregada por último &mdash;
    por isso os códigos de prova devem ser distintos entre provas que entram no mesmo resultado.</p>
</td></tr>
<?php
Ajuda::fimSecao();

Ajuda::secao('variaveis', 'Variáveis disponíveis nas fórmulas', ['Variável', 'Origem', 'Descrição']);
Ajuda::opcao('$NUM', 'equipe', 'Número do carro.');
Ajuda::opcao('$EQUIPE', 'equipe', 'Nome da equipe.');
Ajuda::opcao('$ESCOLA', 'equipe', 'Nome da escola.');
Ajuda::opcao('$ESTADO', 'equipe', 'UF da equipe.');
Ajuda::opcao('$DESCLASSIFICADO', 'equipe', 'Verdadeiro se a equipe foi desclassificada.');
Ajuda::opcao('$CODIGO', 'prova', 'Cada <code>code</code> dos <code>inputs</code>, cada <code>vars</code>, cada <code>min_max</code> e cada <code>pontos</code> das provas listadas.
    Os Totais (<code>$Xmax</code>, <code>$Xmin</code>) <strong>não</strong> estão disponíveis aqui.');
Ajuda::opcao('$ts', 'prova rolling', 'Hora do lançamento (timestamp Unix) de cada entrada.');
Ajuda::opcao('$Cabecalho', 'colunas anteriores', 'O valor já calculado de cada coluna anterior, pelo nome do <code>header</code> &mdash; só para cabeçalhos sem espaços, como <code>Pontos</code> ou <code>Segurança</code>.
    <div class="aviso">Funciona na página de resultados, mas <strong>não nas premiações</strong>, e enquanto houver spoiler a coluna anterior pode valer <code>\'SPOILERS\'</code>.
    Prefira repetir a fórmula (ex.: <code>ROUND($ACEPTS, 2)</code>) em vez de referenciar outra coluna.</div>');
?>
<tr><td colspan="3">As regras de escrita das fórmulas estão na <a href="ajuda_prova.php#formulas">ajuda de provas</a>. Nos resultados as fórmulas podem devolver HTML (negrito, cores, quebras de linha).</td></tr>
<?php
Ajuda::fimSecao();

Ajuda::secao('chaves', 'Chaves principais', ['Chave', 'Tipo', 'Descrição']);
Ajuda::opcao('colunas', 'lista', 'Colunas da tabela, na ordem em que aparecem. Veja <a href="#colunas">colunas</a>.');
Ajuda::opcao('pos', 'texto', '<code>header</code> da coluna usada para classificar. Sem <code>pos</code>, não há classificação e a coluna <code>Pos</code> fica vazia. Veja <a href="#classificacao">Classificação</a>.');
Ajuda::opcao('pos_order', 'texto', '<code>"desc"</code> (padrão, maior é melhor) ou <code>"asc"</code> (menor é melhor, ex.: posição no grid).');
Ajuda::opcao('tiebreak', 'fórmula', 'Critério de desempate quando o valor de <code>pos</code> é igual. Segue o mesmo sentido de <code>pos_order</code>.');
Ajuda::opcao('filter', 'nome de variável', 'Só mostra as equipes em que essa variável é verdadeira/preenchida. Veja <a href="#filter">filter</a>.');
Ajuda::opcao('authorized_users', 'lista', 'Usuários que veem os valores reais enquanto a proteção de spoiler está ligada. Veja <a href="#spoilers">Spoilers</a>.');
Ajuda::opcao('type', 'texto', '<code>"tournament"</code> para mostrar um chaveamento. Veja <a href="#torneio">Torneio</a>.');
?>
<tr><td colspan="3">As premiações usam as mesmas chaves <code>pos</code>, <code>pos_order</code>, <code>tiebreak</code> e <code>filter</code> para montar os pódios.</td></tr>
<?php
Ajuda::fimSecao();

Ajuda::secao('colunas', 'colunas', ['Chave', 'Tipo', 'Descrição']);
Ajuda::opcao('header', 'texto, obrigatório', 'Cabeçalho da coluna. Também é o nome usado em <code>pos</code> e como variável para as colunas seguintes.
    Os cabeçalhos <code>Pos</code> (troféu) e <code>Equipe</code> (Equipe/Escola) têm desenho especial.');
Ajuda::opcao('val', 'fórmula, lista ou especial', '<ul>
        <li><code>"Pos"</code> &mdash; posição na classificação, com medalhas para 1º, 2º e 3º;</li>
        <li><code>"EquipeNum"</code> &mdash; número do carro;</li>
        <li><code>"Equipe"</code> &mdash; nome da equipe, escola e a marca &quot;Desclassificada&quot; quando for o caso;</li>
        <li>uma fórmula &mdash; ex.: <code>"ROUND($ACEPTS, 2)"</code>;</li>
        <li>uma lista de fórmulas &mdash; cada valor em uma linha da mesma célula (ex.: 1ª e 2ª tentativas).</li>
    </ul>');
Ajuda::opcao('highlight', 'fórmula', 'Somente com <code>val</code> em lista: o valor da lista igual ao resultado desta fórmula aparece em <strong>negrito</strong> (ex.: a tentativa que valeu).');
Ajuda::opcao('val_spoilers', 'fórmula ou lista', 'Mostrado no lugar de <code>val</code> enquanto a proteção de spoiler está ativa. Veja <a href="#spoilers">Spoilers</a>.');
Ajuda::opcao('sort', 'texto', 'Como o público ordena a coluna ao clicar no cabeçalho:
    <ul>
        <li><code>"duasmenor"</code> &mdash; para células com dois valores (lista): ordena pelo menor; vazio conta como 999;</li>
        <li><code>"duasmaior"</code> &mdash; ordena pelo maior dos dois; vazio conta como 0;</li>
        <li><code>"false"</code> &mdash; desliga a ordenação da coluna;</li>
        <li>outros ordenadores do tablesorter: <code>"text"</code>, <code>"digit"</code>&hellip;</li>
    </ul>');
Ajuda::opcao('size', 'número', 'Largura do cabeçalho em pixels (opcional).');
Ajuda::opcao('hidden', 'booleano', 'Esconde apenas o <strong>cabeçalho</strong>; as células continuam aparecendo e a tabela fica desalinhada. Não usado hoje &mdash; evite.');
?>
<tr><td colspan="3">
    <?= Ajuda::exemplo(<<<'JSON'
{
  "pos": "Pontos",
  "colunas": [
    {"val": "Pos", "header": "Pos"},
    {"val": "EquipeNum", "header": "#"},
    {"val": "Equipe", "header": "Equipe"},
    {
      "val": ["$ACE_1 ? ROUND($ACE_1, 3) : '-'", "$ACE_2 ? ROUND($ACE_2, 3) : '-'"],
      "sort": "duasmenor",
      "header": "Aceleração",
      "highlight": "$ACE ? ROUND($ACE, 3) : -1"
    },
    {"val": "ROUND($ACEPTS, 2)", "header": "Pontos"}
  ]
}
JSON, 'Exemplo: Aceleração (22BR_ACE, Inputs: AVF) — duas tentativas na mesma célula, a melhor em negrito') ?>
</td></tr>
<?php
Ajuda::fimSecao();

Ajuda::secao('classificacao', 'Classificação: pos, pos_order e tiebreak');
?>
<tr><td>
    <ul>
        <li><code>pos</code> aponta para o <strong>header</strong> de uma coluna (não para uma variável). O valor da coluna é lido como número, ignorando HTML (<code>&lt;b&gt;812.4&lt;/b&gt;</code> vale 812.4).</li>
        <li>Empates recebem a mesma posição e a seguinte é pulada: 1º, 1º, 3º.</li>
        <li><code>tiebreak</code> é calculado por equipe e desempata no mesmo sentido de <code>pos_order</code>
            (com o padrão <code>"desc"</code>, maior tiebreak fica na frente).</li>
    </ul>
    <?= Ajuda::exemplo(<<<'JSON'
{
  "pos": "Pontos",
  "colunas": [
    {"val": "Pos", "header": "Pos"},
    {"val": "EquipeNum", "header": "#"},
    {"val": "Equipe", "header": "Equipe"},
    {"val": "$DESCLAS=='SIM'?'<s>'.$VOLTAS.'</s><br>Desclassificado':$VOLTAS", "header": "Voltas"},
    {"val": "ROUND($ENDPTS, 2)", "header": "Pontos"}
  ],
  "tiebreak": "-$LAST"
}
JSON, 'Exemplo: Enduro (26BR_END) — empate em pontos é decidido por quem passou primeiro pela última vez (menor $LAST, por isso o sinal negativo)') ?>
    <?= Ajuda::exemplo(<<<'JSON'
{
  "pos": "Posição no Grid",
  "pos_order": "asc",
  "colunas": [
    {"val": "Pos", "header": "Pos"},
    {"val": "EquipeNum", "header": "#"},
    {"val": "Equipe", "header": "Equipe"},
    {"val": "$GRI", "header": "Posição no Grid"}
  ]
}
JSON, 'Exemplo: Grid (26BR_GRI) — menor é melhor') ?>
</td></tr>
<?php
Ajuda::fimSecao();

Ajuda::secao('spoilers', 'Spoilers: val_spoilers e authorized_users');
?>
<tr><td>
    <p>A proteção de spoiler fica ativa quando o evento está com <strong>Spoilers?</strong> marcado (no cadastro do evento ou no botão <em>Toggle</em> em Administrar provas)
    e ainda não está <strong>Finalizado</strong>. Enquanto ativa, as colunas que têm <code>val_spoilers</code> mostram esse valor no lugar de <code>val</code>.</p>
    <ul>
        <li>Colunas sem <code>val_spoilers</code> continuam mostrando o valor real.</li>
        <li>A coluna <code>Pos</code> continua mostrando a posição real &mdash; retire-a enquanto houver spoiler se a classificação não puder vazar.</li>
        <li><code>authorized_users</code> (nomes de usuário) veem os valores reais apenas na visão de juiz dos resultados (link &quot;Resultados&quot; da tela do juiz).
            A página pública não identifica o usuário e sempre aplica a proteção.</li>
    </ul>
    <?= Ajuda::exemplo(<<<'JSON'
{
  "pos": "Pontos",
  "colunas": [
    {"val": "Pos", "header": "Pos"},
    {"val": "EquipeNum", "header": "#"},
    {"val": "Equipe", "header": "Equipe"},
    {
      "val": ["$ACE_1 ? ROUND($ACE_1, 3) : '-'", "$ACE_2 ? ROUND($ACE_2, 3) : '-'"],
      "header": "Aceleração",
      "highlight": "$ACE ? ROUND($ACE, 3) : -1",
      "val_spoilers": "'SPOILERS'"
    },
    {"val": "ROUND($ACEPTS, 2)", "header": "Pontos", "val_spoilers": "'SPOILERS'"}
  ],
  "authorized_users": ["jbresolin", "RicardoXV"]
}
JSON, 'Exemplo: Aceleração com spoiler (baseado em 25SU_ACE)') ?>
</td></tr>
<?php
Ajuda::fimSecao();

Ajuda::secao('filter', 'filter');
?>
<tr><td>
    <p>Nome de uma variável (sem <code>$</code>). Equipes em que ela está vazia, é <code>0</code>, <code>false</code> ou não existe são removidas da tabela.
    Útil para mostrar só finalistas ou só quem participou de uma etapa. Não há exemplo em uso &mdash; este é inventado:</p>
    <?= Ajuda::exemplo(<<<'JSON'
{
  "pos": "Nota Final",
  "filter": "FINALISTA",
  "colunas": [
    {"val": "Pos", "header": "Pos"},
    {"val": "EquipeNum", "header": "#"},
    {"val": "Equipe", "header": "Equipe"},
    {"val": "ROUND($NOTA_FIN, 2)", "header": "Nota Final"}
  ]
}
JSON, 'Exemplo: só as equipes com FINALISTA preenchido (ex.: uma var "FINALISTA": "$FIN_PWT==\'SIM\' ? 1 : 0" na prova)') ?>
    <div class="aviso"><code>filter</code> só aceita o nome de uma variável, não uma fórmula. Se precisar de uma condição, crie uma variável em <code>vars</code> ou <code>pontos</code> na prova.</div>
</td></tr>
<?php
Ajuda::fimSecao();

Ajuda::secao('torneio', 'Torneio (type: "tournament")');
?>
<tr><td>
    <p>Para publicar o chaveamento de uma <a href="ajuda_prova.php#torneio">prova do tipo torneio</a>, use em <em>Inputs</em> o ID da prova e, em <em>Colunas</em>, apenas:</p>
    <?= Ajuda::exemplo(<<<'JSON'
{"type": "tournament"}
JSON, 'Exemplo: Super Prime (26BR_SPR, Inputs: SPR)') ?>
    <p>A página <code>prova.php</code> redireciona automaticamente para a visualização do chaveamento, que se atualiza sozinha a cada 5 segundos. Resultados de torneio não podem ser usados em premiações.</p>
</td></tr>
<?php
Ajuda::fimSecao();

Ajuda::secao('exemplos', 'Mais exemplos');
?>
<tr><td>
    <?= Ajuda::exemplo(<<<'JSON'
{
  "colunas": [
    {"val": "(new \\DateTime('now', new \\DateTimeZone('America/Sao_Paulo')))->setTimestamp($ts)->format('H:i:s')", "header": "Hora Lançada"},
    {"val": "EquipeNum", "header": "#"},
    {"val": "Equipe", "header": "Equipe"},
    {"val": "$POSTO", "header": "Onde"},
    {"val": "$PENA", "header": "Penalização"}
  ]
}
JSON, 'Penalizações Enduro (26BR_EPN, Inputs: EPN) — prova rolling, uma linha por lançamento, com a hora formatada') ?>
    <?= Ajuda::exemplo(<<<'JSON'
{
  "colunas": [
    {"val": "EquipeNum", "header": "#"},
    {"val": "Equipe", "header": "Equipe"},
    {"val": "$SGEPTS", "header": "Penalidades Totais"},
    {"val": "'<span style=\\'color:'.($SGE_STATUS == 'APROVADO' ? 'green' : 'red').'; font-weight:bold\\'>'.$SGE_STATUS.'</span>'", "header": "Status"}
  ]
}
JSON, 'Segurança (baseado em 26BR_SGE, Inputs: SGE) — sem classificação, status colorido com HTML') ?>
    <?= Ajuda::exemplo(<<<'JSON'
{
  "pos": "Total",
  "colunas": [
    {"val": "Pos", "header": "Pos"},
    {"val": "EquipeNum", "header": "#"},
    {"val": "Equipe", "header": "Equipe"},
    {"val": "$ESTADO", "header": "Região"},
    {"val": "ROUND($PENPTS, 2)", "header": "Penalidade"},
    {"val": "ROUND($SGEPTS, 2)", "header": "Segurança"},
    {"val": "ROUND($ACEPTS,2) + ROUND($VELPTS,2) + ROUND($SUSPTS,2) + ROUND($MANPTS,2) + ROUND($TRAPTS,2)", "header": "Dinâmicas"},
    {"val": "ROUND($ENDPTS, 2)", "header": "Enduro"},
    {"val": "'<b>'.MAX(0, ROUND($PENPTS,2) + ROUND($SGEPTS,2) + ROUND($ACEPTS,2) + ROUND($VELPTS,2) + ROUND($SUSPTS,2) + ROUND($MANPTS,2) + ROUND($TRAPTS,2) + ROUND($ENDPTS,2)).'</b>'", "header": "Total"}
  ]
}
JSON, 'Geral (simplificado a partir de 26BR_GER, Inputs: AVF, MAN, TRA, SUS, END, SGE, PEN) — o Total repete as somas para funcionar também nas premiações') ?>
</td></tr>
<?php
Ajuda::fimSecao();

Ajuda::secao('backups', 'Backups');
?>
<tr><td>
    <p>Iguais aos da prova: <strong>Atualizar</strong> guarda a versão anterior das colunas em <strong>-1</strong> (até <strong>-5</strong>); os botões só carregam a versão no editor
    e é preciso clicar em <em>Atualizar</em> para aplicar; <strong>💾</strong> guarda um backup com nome e <strong>❌</strong> apaga o backup nomeado selecionado.
    Os backups cobrem apenas as Colunas, não os Inputs nem o título.</p>
</td></tr>
<?php
Ajuda::fimSecao();

Ajuda::printFooter();
