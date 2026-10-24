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
            return $menu;
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
}
