<?php
header('Content-Type: text/html; charset=utf-8');

use Baja\Juiz\Template;
use Baja\Model\EventoQuery;

$eventos = EventoQuery::create()
    ->filterByTemCertificado(true)
    ->orderByTitulo()
    ->find();

Template::printHeader('Baja SAE BRASIL - Certificados', false, false);
?>

<div style="max-width:400px; margin: 0 auto">
    <table class="tablesorter">
        <thead>
            <tr class="tablesorter-ignoreRow">
                <th class="sorter-false">
                    <span style="float:left; width:30%; ; text-align:left; line-height:40px">
					    <img src="img/baja_grande.png" class="logo">
                    </span>
                    <span style="float:right; height:30%; text-align:right">
                        <img src="img/sae.png" class="logo" width="200px">
                    </span>
                </th>
            </tr>
            <tr class="tablesorter-ignoreRow" style="height: 40px">
                <th class="sorter-false" style="line-height: 22px;">Emissão de certificados das competições de Baja SAE BRASIL</th>
            </tr>
        </thead>
<tr>
<td>
<br /><br /> <form action="c/novo/certificado" method="post">
        <label for="evt">Selecione o evento</label><br />
			<select name="evt">
			<?php if (count($eventos) > 0): ?>
				<?php foreach ($eventos as $evento): ?>
					<option value="<?= htmlspecialchars($evento->getEventoId(), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($evento->getTitulo(), ENT_QUOTES, 'UTF-8') ?></option>
				<?php endforeach; ?>
			<?php else: ?>
				<option disabled>0 results</option>
			<?php endif; ?>
			</select>

		<br /><br />

        <label for="cpf">CPF (Somente números)</label><br />
        <input type="number" id="cpf" name="cpf" size="11" />
        <br /><br />
        <input type="submit" value="Obter Certificado"/>
		</form>
<?php Template::printFooter(); ?>
