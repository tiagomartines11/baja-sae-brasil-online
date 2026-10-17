<?php
declare(strict_types=1);

namespace Baja\Contagem\Access;

use Baja\Contagem\Engine\Data\Source;
use Baja\Contagem\Engine\Settings;
use Baja\Model\ContagemFonte;
use Baja\Model\ContagemFonteQuery;
use Baja\Model\ContagemFonteUsuarioQuery;
use Baja\Model\Prova;
use Baja\Model\ProvaQuery;
use Baja\Model\UserQuery;
use Baja\Session;
use Propel\Runtime\Propel;

/**
 * Who may do what in the lap counting of a prova.
 *
 * Three permission codes exist, all checked through Session::hasPermission()
 * (which adds the event prefix and lets `admin` through):
 *
 *   {prova}        controller: review, race control, sources, counters,
 *                  and everything a counter can do
 *   {prova}_CONT   counter: may enter the count area. Which source they
 *                  count on is not in the code; it comes from their links
 *                  in contagem_fonte_usuario (see CounterLinks)
 *   {prova}_BOX    pit log: may log cars entering and leaving the pit lane
 *                  and the fueling station. Kept apart from _CONT on
 *                  purpose: the people at the pit lane are often not the
 *                  counters, and neither should get the other's page by
 *                  default. A controller has both.
 *
 * Every page asks this class instead of testing codes itself, so the rules
 * are written down once.
 */
final class ContagemAccess
{
    public const COUNTER_SUFFIX = '_CONT';

    public const PIT_LOG_SUFFIX = '_BOX';

    public const PROVA_TYPE = 'laps';

    /**
     * The prova with this id in the current event, if it is a laps prova.
     * Pages use it to turn the "p" request parameter into a prova, and
     * treat null as "nothing to see here".
     */
    public static function findLapsProva(string $provaId): ?Prova
    {
        if ($provaId === '') {
            return null;
        }
        $prova = ProvaQuery::create()
            ->filterByEventoId((string) ($_SERVER['REDIRECT_EVENT'] ?? ''))
            ->findOneByProvaId($provaId);
        if ($prova === null || $prova->getType() !== self::PROVA_TYPE) {
            return null;
        }
        return $prova;
    }

    public static function isController(Prova $prova): bool
    {
        return self::isInCurrentEvent($prova->getEventoId())
            && Session::hasPermission($prova->getProvaId());
    }

    public static function isCounter(Prova $prova): bool
    {
        return self::isInCurrentEvent($prova->getEventoId())
            && Session::hasPermission($prova->getProvaId() . self::COUNTER_SUFFIX);
    }

    /**
     * May the logged-in user enter the lap counting of this prova at all?
     * The one question behind the prova's line on the juiz start page and
     * the door of contagem.php: the controller, a counter, or somebody on
     * the pit log. What they find inside is entryDestinations()' business.
     */
    public static function mayEnter(Prova $prova): bool
    {
        return self::isController($prova) || self::isCounter($prova) || self::canUsePitLog($prova);
    }

    /**
     * Where somebody who is NOT the controller may go in this prova, in
     * the order the chooser lists them: each source they are linked to,
     * then the pit log if they hold its code. The controller gets the hub
     * instead and never asks this.
     *
     * The chooser is shown even with a single destination: one tap more,
     * but the person always sees where they are going, and a counter who
     * also logs the pit lane finds both pages in the same place.
     *
     * Empty for a stale counter code (no link left, no pit code): they may
     * enter, and are told they are not linked to anything.
     *
     * @return array<int, array{rotulo: string, endereco: string}> endereco is relative to juiz/, not escaped
     */
    public static function entryDestinations(Prova $prova, string $username): array
    {
        $query = '?p=' . rawurlencode($prova->getProvaId());
        $destinations = [];
        if (self::isCounter($prova)) {
            foreach (self::linkedSources($prova, $username) as $fonte) {
                $destinations[] = [
                    'rotulo' => (string) $fonte->getNome(),
                    'endereco' => 'contagem_contar.php' . $query . '&f=' . (int) $fonte->getFonteId(),
                ];
            }
        }
        if (self::canUsePitLog($prova)) {
            $destinations[] = ['rotulo' => self::pitLogLabel($prova), 'endereco' => 'contagem_box.php' . $query];
        }
        return $destinations;
    }

    /** "Registro de box", naming the fueling station too when the prova logs it. */
    public static function pitLogLabel(Prova $prova): string
    {
        $settings = Settings::fromArray($prova->getParams()->contagem ?? null);
        return 'Registro de box' . ($settings->abastecimento ? ' e abastecimento' : '');
    }

    /** Ends the request with the application's standard refusal unless the user is a controller. */
    public static function requireController(Prova $prova): void
    {
        if (!self::isController($prova)) {
            die("Você não tem acesso a essa página");
        }
    }

    /**
     * The active manual sources of this prova the user is linked to, in
     * display order. They are offered on the chooser of contagem.php
     * (see entryDestinations()).
     *
     * @return ContagemFonte[]
     */
    public static function linkedSources(Prova $prova, string $username): array
    {
        $statement = Propel::getReadConnection('resultados')->prepare(
            'SELECT f.fonte_id
               FROM contagem_fonte f
               JOIN contagem_fonte_usuario u ON u.fonte_id = f.fonte_id
              WHERE f.evento_id = :evento AND f.prova_id = :prova
                AND f.tipo = :tipo AND f.ativa = 1
                AND u.usuario = :usuario
           ORDER BY f.ordem, f.fonte_id'
        );
        $statement->execute([
            ':evento' => $prova->getEventoId(),
            ':prova' => $prova->getProvaId(),
            ':tipo' => Source::TIPO_MANUAL,
            ':usuario' => $username,
        ]);

        $sources = [];
        foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $fonteId) {
            $source = ContagemFonteQuery::create()->findPk((int) $fonteId);
            if ($source !== null) {
                $sources[] = $source;
            }
        }
        return $sources;
    }

    /**
     * May the logged-in user open the count page of this source?
     * A controller may open any manual source ("Contar como..."); anyone
     * else only a source they were linked to.
     */
    public static function canCountOn(ContagemFonte $fonte): bool
    {
        if ($fonte->getTipo() !== Source::TIPO_MANUAL || !self::isInCurrentEvent($fonte->getEventoId())) {
            return false;
        }
        if (Session::hasPermission($fonte->getProvaId())) {
            return true;
        }

        // The link alone decides, not the _CONT code: a stale code without
        // a link must not open anything.
        $link = ContagemFonteUsuarioQuery::create()
            ->filterByFonteId($fonte->getFonteId())
            ->filterByUsuario(Session::getCurrentUser()->getUsername())
            ->findOne();
        return $link !== null;
    }

    public static function canUsePitLog(Prova $prova): bool
    {
        return self::isController($prova)
            || (self::isInCurrentEvent($prova->getEventoId()) && Session::hasPermission($prova->getProvaId() . self::PIT_LOG_SUFFIX));
    }

    /**
     * May this user STILL log stops on this prova? Asked by the upload
     * endpoint, which has no session: only the device's user name. A pit
     * phone keeps its token after its user's access is removed (and a
     * browser keeps the offline copy of the page), so without this a
     * person whose pit access was taken away could go on logging stops.
     *
     * The same codes as canUsePitLog(), read from the user's row:
     * `admin`, the controller code, or the pit log code.
     */
    public static function userMayStillLogStops(string $username, string $eventoId, string $provaId): bool
    {
        $user = UserQuery::create()->findOneByUsername($username);
        if ($user === null) {
            return false;
        }
        $controllerCode = $eventoId . '_' . $provaId;
        return $user->hasPermission('admin')
            || $user->hasPermission($controllerCode)
            || $user->hasPermission($controllerCode . self::PIT_LOG_SUFFIX);
    }

    /**
     * Session::hasPermission() always prefixes the code with the event of
     * the current request. Asking it about a prova of another event would
     * silently check the wrong code, so that case is refused up front.
     */
    private static function isInCurrentEvent(string $eventoId): bool
    {
        return $eventoId === ($_SERVER['REDIRECT_EVENT'] ?? null);
    }
}
