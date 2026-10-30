<?php
declare(strict_types=1);

namespace Baja\Juiz;

use Baja\Rbac\Delegation\GrantRefused;

/**
 * Words for juiz/admin_usuario.php, the stopgap page that gives and takes
 * roles (BAJAAPP-76).
 */
final class Papeis
{
    /** Why RoleGranting said no, for the person who asked. */
    public static function recusa(GrantRefused $refused): string
    {
        return match ($refused->reason) {
            GrantRefused::NO_ROLE_GRANT => 'Você não pode dar ou tirar papéis nesse lugar.',
            GrantRefused::ESCALATION => 'Esse papel inclui permissões que você mesmo não tem nesse lugar.',
            GrantRefused::SYSTEM_ROLE => 'Esse papel é de sistema: só quem edita papéis pode dá-lo ou tirá-lo.',
            GrantRefused::ROLE_NOT_ALLOWED_HERE => 'Esse papel não pode ser dado nesse tipo de lugar.',
            GrantRefused::UNKNOWN_NODE => 'Esse lugar não existe.',
            GrantRefused::UNKNOWN_ROLE, GrantRefused::INVALID_ROLE => 'Esse papel não existe.',
            GrantRefused::UNKNOWN_USER => 'Esse usuário não existe.',
            GrantRefused::UNKNOWN_GRANT, GrantRefused::ALREADY_REVOKED => 'Esse papel já não está ativo.',
            GrantRefused::VALID_UNTIL_REQUIRED => 'O evento não tem data de término (data_fim): informe até quando, ou preencha a data em evento.php.',
            GrantRefused::VALID_UNTIL_IN_PAST => 'A data de término já passou.',
            // The core's message says until when they may grant.
            GrantRefused::VALID_UNTIL_AFTER_GRANTER => 'A data de término é depois do fim do seu próprio acesso. ' . $refused->getMessage(),
            default => 'Não foi possível: ' . $refused->getMessage(),
        };
    }
}
