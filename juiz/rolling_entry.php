<?php
namespace Baja\Juiz;

use Baja\Model\EquipeQuery;
use Baja\Model\EventoQuery;
use Baja\Model\InputQuery;
use Baja\Model\ProvaQuery;

if (!isset($_REQUEST['p'])) header("Location: index.php");

$_page = $_REQUEST['p'];

Session::permissionCheck($_page);

$currentEventId = EventoQuery::getCurrentEvent()->getEventoId();
$equipes = EquipeQuery::create()->filterByEventoId($currentEventId)->filterByPresente(true)->find();

$prova = ProvaQuery::create()->filterByEventoId($currentEventId)->findOneByProvaId($_page);

Template::printHeader($prova->getNome(), false);

echo '
<table class="tablesorter-blue" style="margin: 0 auto; width: 250px; border: solid 2px black; border-collapse: collapse">
    <thead>
    <tr>
        <th colspan="2" style="border: solid 2px black; padding-bottom: 20px;" class="sorter-false">
        <span style="font-size: 28px; line-height: 34px;">'.$prova->getNome().'</span> <br />
        </th>
        </tr>
        <form action="save_rolling_entry.php?p='.$_page.'" method="POST">
    </thead>
    <tr>
    <td><label for="team">Equipe</label></td>
    <td><select style="width: 100%; font: 20px/20px \'Trebuchet MS\', Arial , Sans-serif; border: 0; margin: 0; padding: 0" name="team"><option value="" />';

foreach ($equipes as $e) {
    echo '<option value="'.$e->getEquipeId().'">'.$e->getEquipeId().'</option>';
}
echo '</select></td></tr>';

$fields = $prova->getParamsInputs();
foreach ($fields as $k=>$field) {
    echo '<tr '.(($field->getPass() == 1 && ($fields[$k+1] && $fields[$k+1]->getPass() == 2)) ? 'style="border-bottom: solid 2px black;' : '').' ">'. $field->printSelf() . '</tr>';
}

?>
    <tfoot>
    <tr style="border: solid 2px black; height: 60px">
        <th colspan="2">
            <input type="submit" name="submit" id="submit1" value="Salvar" disabled /></th>
    </tr>
    <tr>
        <th colspan="2">
            <a href="index.php" style="color: white; font-size: 12px;">&nbsp;Voltar</a></th>
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
            $("#submit1").prop('disabled', emptyGroup.length > 0)
            if (group == 1) $('[data-group=2]').prop('group-disabled', emptyGroup.length > 0).computeDisable();
        }).trigger('input');

        $("#submit1").prop('disabled', true)
    </script>

    <?php
Template::printFooter();