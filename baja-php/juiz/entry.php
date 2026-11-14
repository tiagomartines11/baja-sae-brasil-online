<?php
namespace Baja\Juiz;

use Baja\Auth\Csrf;
use Baja\Contagem\Access\ContagemAccess;
use Baja\Contagem\Review\ManualScoreFields;
use Baja\Model\EquipeQuery;
use Baja\Model\EventoQuery;
use Baja\Model\InputQuery;
use Baja\Model\ProvaQuery;
use Baja\Session;
use Baja\Site\Html;

if (!isset($_REQUEST['p'])) { header("Location: index.php"); exit; }

$_page = $_REQUEST['p'];
$_team = $_REQUEST['t'];

Session::permissionCheck($_page);

$currentEventId = EventoQuery::getCurrentEvent()->getEventoId();
$equipe = EquipeQuery::create()->filterByEventoId($currentEventId)->findOneByEquipeId($_team);
if (!$equipe) { header("Location: dashboard.php?p=$_page"); exit; }

$prova = ProvaQuery::create()->filterByEventoId($currentEventId)->findOneByProvaId($_page);

$nota = InputQuery::create()->filterByEventoId($currentEventId)->filterByProvaId($prova->getProvaId())->findOneByEquipeId($equipe->getEquipeId());

Template::printHeader($prova->getNome(), false);
// theme.blue.css gives every judge page a body of at least 520 px, which on
// a phone (360 px) only adds sideways scrolling: this form is 250 px wide.
echo '<style>body { min-width: 0; }</style>';

// ?p= and ?t= come from the URL: escaped like the stored names, or a crafted
// link would run a script in the judge's browser.
$entryQuery = 'p=' . rawurlencode((string)$_page) . '&t=' . rawurlencode((string)$_team);

echo '
<table class="tablesorter-blue" style="margin: 0 auto; width: 250px; border: solid 2px black; border-collapse: collapse">
    <thead>
    <tr>
        <th colspan="2" style="border: solid 2px black; padding-bottom: 20px;" class="sorter-false">
        <span style="font-size: 28px; line-height: 34px;">'.Html::e($prova->getNome()).'</span> <br />
        <span style="font-size: 40px; line-height: 56px;">Carro #'.Html::e($equipe->getEquipeId()).'</span> <br />
        <span style="font-size: 10px">'.Html::e($equipe->getEquipe()).'</span> <br />
        <span style="font-size: 18px">'.Html::e($equipe->getEscola()).'</span> <br />
        </th>
        </tr>
        <form action="save_entry.php?'.Html::e($entryQuery).'" method="POST">
        '.Csrf::field().'
    </thead>
';

// Set by save_entry.php when it refused to save what this form sent.
if (!empty($entradaRecusada)) {
    echo '<tr><td colspan="2" style="color: red; font-weight: bold;">Nada foi salvo. Corrija e salve de novo:<ul>';
    foreach ($entradaRecusada as $erro) {
        echo '<li>' . Html::e($erro) . '</li>';
    }
    echo '</ul></td></tr>';
}

$fields = $prova->getParamsInputs();
// In a laps prova, VOLTAS, LAST and BEST are written by the lap counting
// engine after every upload. They are shown as plain text: a value typed
// here would be overwritten within seconds, and a correction belongs on the
// review screen, where it is recorded as a decision.
// With publishing switched off ("desligada") the engine writes none of them:
// the list is empty and all three are ordinary fields again, for whoever
// copies them from the spreadsheet.
$isLapsProva = $prova->getType() == ContagemAccess::PROVA_TYPE;
$engineOwnedFields = ManualScoreFields::engineOwnedFields($prova);
// What each field shows: what is stored or, after a refused save, what the
// judge typed (nothing was saved). A row created by the engine has no value
// yet for the fields people type.
$values = [];
foreach ($fields as $field) {
    $code = $field->getCode();
    $values[$code] = $nota ? ($nota->getDados()->{$code} ?? null) : null;
    if (!empty($entradaRecusada) && !in_array($code, $engineOwnedFields, true)) {
        $values[$code] = EntryValidator::typedValue($_POST, $code);
    }
}
// Repeated groups (BAJAAPP-103): one block per repetition, see EntryBlocks.
$blockStates = EntryBlocks::states($fields, array_map(fn($value) => $value !== null && $value !== '', $values));
if ($blockStates !== []) {
    echo EntryBlocks::styles();
}
foreach ($fields as $k=>$field) {
    $value = $values[$field->getCode()];
    $repetition = $field->getRepetition();
    if ($repetition !== null && $repetition->firstCode() === $field->getCode()) {
        echo EntryBlocks::opening($repetition, $blockStates[$repetition->anchor()]);
    }
    if (in_array($field->getCode(), $engineOwnedFields, true)) {
        echo '<tr><td>'.Html::e($field->getName()).'</td><td style="width: 80px; font-size: 20px;" title="Calculado pela contagem de voltas">'.($value !== null ? Html::e($value) : '&ndash;').'</td></tr>';
    } else {
        echo '<tr '.(($field->getPass() >= 0 && (isset($fields[$k+1]) && $fields[$k+1]->getPass() == ($field->getPass()+1))) ? 'style="border-bottom: solid 2px black;' : '').' ">'. $field->printSelf($value) . '</tr>';
    }
    if ($repetition !== null && $repetition->lastCode() === $field->getCode()) {
        echo EntryBlocks::closing($repetition, $blockStates);
    }
}
// The passes whose Salvar rule the script applies: 1 to 10, as always, and
// every pass of a repeated group, however high; save_entry.php checks the same.
$scriptGroups = EntryValidator::entryPasses($fields);

?>
<tfoot>
<tr style="border: solid 2px black; height: 60px">
    <th colspan="2">
        <input type="submit" name="submit" id="submit1" value="Salvar" disabled /> &nbsp&nbsp&nbspOU&nbsp&nbsp&nbsp <input type="submit" name="submit" id="submit2" value="Salvar e Avançar" disabled /></th>
</tr>
<tr style="border: solid 2px black; height: 60px">
    <?php if ($isLapsProva) { // laps prova: the lap count in this row is not this page's to delete ?>
        <th colspan="2">
            <input type="submit" name="delete" id="delete" value="Limpar campos manuais"
                   onclick="return confirm('Limpar os campos digitados deste carro? As voltas contadas não são alteradas.');" /><br />
            <span style="font-size: 10px">Voltas, última passagem e melhor volta são da contagem de voltas e não são apagadas aqui.</span>
        </th>
    <?php } else { ?>
        <th colspan="2"><input type="submit" name="delete" id="delete" value="Deletar Nota" /></th>
    <?php } ?>
</tr>
<tr>
    <th colspan="2">
        <a href="dashboard.php?p=<?= Html::e(rawurlencode((string)$_page)) ?>" style="color: white; font-size: 12px;">&nbsp;Voltar</a></th>
</tr>
</tfoot>
</form>
</table>
<script type="text/javascript">
    (function( $ ){
        $.fn.computeDisable = function() {
            this.each(function(i) {
                var t = $(this);
                t.prop('disabled', !!(t.prop('xor-disabled') || t.prop('group-disabled') || t.prop('filter-disabled') || t.prop('rep-disabled')))
            });
            return this;
        };
    })( jQuery );

    // "A" and "B", and inside a repeated group the same letters scoped to
    // one repetition ("A_3"); any other xor does nothing (Field::hasActiveXor).
    var activeXor = function () { return /^[AB](_[1-9][0-9]*)?$/.test($(this).attr('data-xor')); };
    var scriptGroups = <?= Html::js($scriptGroups) ?>;

    $('[data-xor]').filter(activeXor).on('input',function(e){
        var xorFields = $('[data-xor="' + $(this).attr('data-xor') + '"]');
        var notFilled = xorFields.filter(function () {
            return $(this).val() == "";
        });
        notFilled.prop('xor-disabled', notFilled.length != xorFields.length).computeDisable();
    }).trigger('input');

    $('[data-filter]').on('input',function(e){
        var filterFields = $(this).attr('data-filter').split(",");
        var completed = $.isNumeric($(this).val()) ? parseInt($(this).val()) : -1;
        for (var f = 0; f < filterFields.length; f++) {
            for (var i = 1; i <= 3; i++) {
                $("input#"+filterFields[f]+i+"").prop('filter-disabled', i > completed).computeDisable();
            }
        }
    }).trigger('input');

    $('[data-group]').filter(function () { return scriptGroups.indexOf($(this).attr('data-group')) >= 0; }).on('input',function(e){
        var group = $(this).attr('data-group');
        var emptyGroup = $('[data-group="'+group+'"]').filter(function() { return $(this).val() == "" && $(this).prop('disabled') == false; });
        $("#submit1,#submit2,.salvar-bloco").prop('disabled', emptyGroup.length > 0)
        if (group == 1) $('[data-group=2]').prop('group-disabled', emptyGroup.length > 0).computeDisable();
    }).trigger('input');

    $("#submit1,#submit2,.salvar-bloco").prop('disabled', true)
    $("#delete").prop('disabled',  <?= $nota == null ?>)
</script>
<?php if ($blockStates !== []) {
    // The blocks' states and locks while the judge types (EntryBlocks).
    // /js/ is cached for 30 days: the version in the URL is the file's date.
    $blocksScript = dirname(__DIR__) . '/default/js/repeticoes.js';
    ?>
<script type="module" src="/js/repeticoes.js?v=<?= (int)@filemtime($blocksScript) ?>"></script>
<?php } ?>

<?php
Template::printFooter();