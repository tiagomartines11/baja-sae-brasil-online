<?php
namespace Baja\Juiz;

use Baja\Model\EquipeQuery;
use Baja\Model\EventoQuery;
use Baja\Model\InputQuery;
use Baja\Model\ProvaQuery;

if (!isset($_REQUEST['p'])) header("Location: index.php");

$_page = $_REQUEST['p'];
$_team = $_REQUEST['t'];

Session::permissionCheck($_page);

$currentEventId = EventoQuery::getCurrentEvent()->getEventoId();
$equipe = EquipeQuery::create()->filterByEventoId($currentEventId)->findOneByEquipeId($_team);
if (!$equipe) header("Location: dashboard.php?p=$_page");

$prova = ProvaQuery::create()->filterByEventoId($currentEventId)->findOneByProvaId($_page);

$nota = InputQuery::create()->filterByEventoId($currentEventId)->filterByProvaId($prova->getProvaId())->findOneByEquipeId($equipe->getEquipeId());

Template::printHeader($prova->getNome(), false);

echo '
<table class="tablesorter-blue" style="margin: 0 auto; width: 250px; border: solid 2px black; border-collapse: collapse">
    <thead>
    <tr>
        <th colspan="2" style="border: solid 2px black; padding-bottom: 20px;" class="sorter-false">
        <span style="font-size: 28px; line-height: 34px;">'.$prova->getNome().'</span> <br />
        <span style="font-size: 40px; line-height: 56px;">Carro #'.$equipe->getEquipeId().'</span> <br />
        <span style="font-size: 10px">'.$equipe->getEquipe().'</span> <br />
        <span style="font-size: 18px">'.$equipe->getEscola().'</span> <br />
        </th>
        </tr>
        <form action="save_entry.php?p='.$_page.'&amp;t='.$_team.'" method="POST">
    </thead>
';

$fields = $prova->getParamsInputs();
foreach ($fields as $k=>$field) {
    echo '<tr '.(($field->getPass() == 1 && ($fields[$k+1] && $fields[$k+1]->getPass() == 2)) ? 'style="border-bottom: solid 2px black;' : '').' ">'. $field->printSelf($nota ? $nota->getDados()->{$field->getCode()} : null) . '</tr>';
}

?>
<tfoot>
<tr style="border: solid 2px black; height: 60px">
    <th colspan="2">
        <input type="submit" name="submit" value="Salvar" disabled /> &nbsp&nbsp&nbspOU&nbsp&nbsp&nbsp <input type="submit" name="submit" value="Salvar e Avançar" disabled /></th>
</tr>
<tr>
    <th colspan="2">
        <a href="dashboard.php?p=<?php echo $_page; ?>" style="color: white; font-size: 12px;">&nbsp;Voltar</a></th>
</tr>
</tfoot>
</form>
</table>
<script type="text/javascript">
    (function( $ ){
        $.fn.computeDisable = function() {
            this.each(function(i) {
                var t = $(this);
                t.prop('disabled', !!(t.prop('xor-disabled') || t.prop('group-disabled') || t.prop('filter-disabled')))
            });
            return this;
        };
    })( jQuery );

    $('[data-xor="A"],[data-xor="B"]').on('input',function(e){
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

    $('[data-group=1],[data-group=2]').on('input',function(e){
        var group = $(this).attr('data-group');
        var emptyGroup = $('[data-group='+group+']').filter(function() { return $(this).val() == "" && $(this).prop('disabled') == false; });
        $("[type='submit']").prop('disabled', emptyGroup.length > 0)
        if (group == 1) $('[data-group=2]').prop('group-disabled', emptyGroup.length > 0).computeDisable();
    }).trigger('input');

    $("[type='submit']").prop('disabled', true);
</script>

<?php
Template::printFooter();