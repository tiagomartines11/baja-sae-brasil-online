<?php
namespace Baja\Juiz;

use Baja\Session;

Session::permissionCheck('admin');

Ajuda::printHeader("Menu do Evento", "admin_eventos.php", [
    'visao-geral' => 'Visão geral',
    'itens' => 'Tipos de item',
    'fixos' => 'Itens automáticos',
    'cuidados' => 'Cuidados',
    'exemplos' => 'Exemplos',
]);

Ajuda::secao('visao-geral', 'Visão geral');
?>
<tr><td>
    <p>O campo <strong>Menu</strong> do evento define o menu do site de resultados: uma <strong>lista JSON</strong> em que cada elemento vira um item do menu, na ordem em que aparece.</p>
    <p>Os itens apontam para <a href="ajuda_resultado.php">resultados</a> pelo <strong>ID do resultado</strong> (ex.: <code>26BR_ACE</code>); o texto mostrado é o <em>Título</em> do resultado.
    Se o ID não existir, o próprio ID aparece no menu.</p>
    <div class="aviso">Se o JSON estiver inválido, o menu é salvo como <code>null</code> sem nenhum aviso e o site fica só com os itens automáticos.
    O menu não tem backups: copie o conteúdo antes de fazer mudanças grandes.</div>
</td></tr>
<?php
Ajuda::fimSecao();

Ajuda::secao('itens', 'Tipos de item', ['Forma', 'Resultado no menu']);
Ajuda::item('"ID"', 'Item de primeiro nível que abre o resultado.' . Ajuda::exemplo('"26BR_GER"'));
Ajuda::item('{"Rótulo": ["ID", ...]}', 'Submenu com o rótulo dado, contendo os resultados listados.' . Ajuda::exemplo(<<<'JSON'
{"Segurança": ["26BR_SGE", "26BR_SGD"]}
JSON));
Ajuda::item('{"Rótulo": "URL"}', 'Item de primeiro nível que abre um link externo ou outra página.' . Ajuda::exemplo(<<<'JSON'
{"Fila": "https://fila.bajasaebrasil.net/fila.php?evento=26BR&fila=2"}
JSON));
Ajuda::item('{"Rótulo": {...}}', 'Submenu que mistura links e resultados. Valores que contêm <code>php</code> viram links com a chave como texto;
    os demais são IDs de resultado (a chave é ignorada).' . Ajuda::exemplo(<<<'JSON'
{"Enduro": {"1": "26BR_GRI", "2": "26BR_END", "3": "26BR_EPN", "Mapa": "mapa.php"}}
JSON));
?>
<tr><td colspan="2">Um mesmo objeto pode ter várias chaves (<code>{"Fila": "...", "Almanaque": "..."}</code>); cada uma vira um item, na ordem. Por clareza, prefira um objeto por item.</td></tr>
<?php
Ajuda::fimSecao();

Ajuda::secao('fixos', 'Itens automáticos');
?>
<tr><td>
    <p>Depois dos itens configurados, o site sempre acrescenta:</p>
    <ul>
        <li><strong>Notificações</strong> &mdash; inscrição nas notificações push;</li>
        <li><strong>Relógio Oficial</strong>;</li>
        <li><strong>Arquivo</strong> &mdash; todos os eventos com <em>Ativo?</em> marcado, agrupados por tipo e ano.</li>
    </ul>
    <p>Na visão de juiz dos resultados (link &quot;Resultados&quot; da tela do juiz) os mesmos itens abrem a versão de juiz de cada resultado.</p>
</td></tr>
<?php
Ajuda::fimSecao();

Ajuda::secao('cuidados', 'Cuidados');
?>
<tr><td>
    <ul>
        <li>Dentro de um <strong>submenu</strong>, só é tratado como link o valor que contém <code>php</code>. Um link para PDF ou para outro site dentro de um submenu
            seria tratado como ID de resultado e ficaria quebrado &mdash; coloque esses links no primeiro nível (<code>{"Almanaque": "https://...pdf"}</code>).</li>
        <li>Em um submenu escrito como <strong>lista</strong>, um link aparece com o número da posição como texto (&quot;0&quot;, &quot;1&quot;&hellip;). Para links com texto, use a forma de objeto.</li>
        <li>Os IDs precisam ser de resultados <strong>deste evento</strong>: o site de resultados só abre resultados do evento da URL.</li>
        <li>Ao <a href="clonar_evento.php">clonar um evento</a>, confira o menu: hoje ele é copiado sem trocar o prefixo dos IDs (ex.: continua <code>25BR_ACE</code> no evento 26BR).</li>
    </ul>
</td></tr>
<?php
Ajuda::fimSecao();

Ajuda::secao('exemplos', 'Exemplos');
?>
<tr><td>
    <?= Ajuda::exemplo(<<<'JSON'
[
  {"Segurança": ["26BR_SGE", "26BR_SGD"]},
  {"Projeto": ["26BR_REL", "26BR_RDT", "26BR_SLP", "26BR_APD", "26BR_APF", "26BR_AP1", "26BR_AP2", "26BR_PRO"]},
  {"Dinâmicas": ["26BR_ACE", "26BR_VEL", "26BR_MAN", "26BR_TRA", "26BR_SUS", "26BR_SPP", "26BR_DIN"]},
  {"Enduro": ["26BR_END", "26BR_GRI", "26BR_EPN"]},
  "26BR_GER",
  {"Fila": "https://fila.bajasaebrasil.net/fila.php?evento=26BR&fila=2"},
  {"Almanaque": "https://arquivos.saebrasil.org.br/2026/BajaNacional/AlmanaqueBajaSAE2026.pdf"}
]
JSON, 'Nacional 2026 (26BR): submenus por área, resultado geral no primeiro nível e dois links externos') ?>
    <?= Ajuda::exemplo(<<<'JSON'
[
  {"Segurança": ["25SU_BRF", "25SU_SGE"]},
  {"Dinâmicas": ["25SU_ACE", "25SU_VEL", "25SU_TRA", "25SU_MAN", "25SU_SUS", "25SU_SPR", "25SU_DIN"]},
  {"Enduro": {"1": "25SU_GRI", "2": "25SU_END", "3": "25SU_EPN", "Mapa da Pista": "mapa.php"}},
  "25SU_GER"
]
JSON, 'Exemplo inventado (baseado em 25SU): submenu Enduro com um link para a página do mapa') ?>
</td></tr>
<?php
Ajuda::fimSecao();

Ajuda::printFooter();
