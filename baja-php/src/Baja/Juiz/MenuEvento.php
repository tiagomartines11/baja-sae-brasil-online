<?php
namespace Baja\Juiz;

/**
 * Helpers for the event menu (the "Menu" field of an evento, see ajuda_menu.php).
 */
class MenuEvento
{
    /**
     * Points a copied menu at the new event's results.
     *
     * Result IDs start with the event ID and an underscore (25BR_ACE). When an
     * event is cloned into 26BR, every menu value that starts with "25BR_" is
     * rewritten to start with "26BR_" instead (25BR_ACE becomes 26BR_ACE).
     *
     * Only values that START with the prefix change. Labels (object keys) and
     * links that merely contain it somewhere in the middle are kept as they are.
     *
     * Links get the same rule for their query string: a parameter whose value
     * is exactly the old event ID is changed (fila.php?evento=25BR becomes
     * fila.php?evento=26BR), see trocarEventoNaQueryString().
     *
     * @param mixed  $menu         the menu as Evento::getMenu() returns it: a list
     *                             of strings and objects, or null when empty
     * @param string $eventoAntigo ID of the event being copied, e.g. "25BR"
     * @param string $eventoNovo   ID of the new event, e.g. "26BR"
     * @return mixed the same structure with the IDs replaced
     */
    static function trocarPrefixoEvento($menu, $eventoAntigo, $eventoNovo) {
        $prefixoAntigo = $eventoAntigo . '_';

        if (is_string($menu)) {
            if (str_starts_with($menu, $prefixoAntigo)) {
                return $eventoNovo . '_' . substr($menu, strlen($prefixoAntigo));
            }
            return self::trocarEventoNaQueryString($menu, $eventoAntigo, $eventoNovo);
        }

        // A list (or submenu list): replace inside every element, keeping the order.
        if (is_array($menu)) {
            return array_map(fn($item) => self::trocarPrefixoEvento($item, $eventoAntigo, $eventoNovo), $menu);
        }

        // An object such as {"Segurança": [...]}: keep the keys, replace inside the values.
        if (is_object($menu)) {
            $copia = new \stdClass();
            foreach (get_object_vars($menu) as $rotulo => $valor) {
                $copia->{$rotulo} = self::trocarPrefixoEvento($valor, $eventoAntigo, $eventoNovo);
            }
            return $copia;
        }

        // null, numbers, booleans: nothing to replace.
        return $menu;
    }

    /**
     * In a link, changes every query string parameter whose value is EXACTLY
     * the old event ID: "fila.php?evento=25BR&fila=2" becomes
     * "fila.php?evento=26BR&fila=2". Everything else is left as it is,
     * including a value that only contains the ID (?arquivo=25BR_regras.pdf,
     * ?x=125BR), the ID in the path (/25BR/mapa.php) and the part after "#".
     *
     * @param string $link         any menu value; without "?" it comes back unchanged
     * @param string $eventoAntigo e.g. "25BR"
     * @param string $eventoNovo   e.g. "26BR"
     * @return string
     */
    private static function trocarEventoNaQueryString($link, $eventoAntigo, $eventoNovo) {
        $inicioQuery = strpos($link, '?');
        if ($inicioQuery === false) {
            return $link;
        }
        // The query string runs from after "?" up to "#" (or the end).
        $fimQuery = strpos($link, '#', $inicioQuery);
        if ($fimQuery === false) {
            $fimQuery = strlen($link);
        }
        $query = substr($link, $inicioQuery + 1, $fimQuery - $inicioQuery - 1);

        $parametros = explode('&', $query);
        foreach ($parametros as $i => $parametro) {
            $nomeEValor = explode('=', $parametro, 2);
            if (count($nomeEValor) === 2 && rawurldecode($nomeEValor[1]) === $eventoAntigo) {
                $parametros[$i] = $nomeEValor[0] . '=' . rawurlencode($eventoNovo);
            }
        }

        return substr($link, 0, $inicioQuery + 1) . implode('&', $parametros) . substr($link, $fimQuery);
    }
}
