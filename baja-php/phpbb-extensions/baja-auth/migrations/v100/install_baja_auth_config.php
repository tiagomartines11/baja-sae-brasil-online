<?php
/**
 * ============================================================================
 * THIS FILE IS BAKED INTO THE phpbb-baja CONTAINER AT BUILD TIME.
 * ============================================================================
 * Editing this file does NOT take effect in a running container.
 *
 * To apply changes:
 *     cd baja-infra && docker compose down -v && docker compose build phpbb-baja && docker compose up -d
 *
 * The down -v is required: the phpbb_baja_html volume retains its initial
 * content, and rebuilding the image alone won't propagate changes. See
 * baja-php/docs/baja-auth-extension.md "Operating notes" for context.
 * ============================================================================
 */

namespace baja\auth\migrations\v100;

class install_baja_auth_config extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return isset($this->config['baja_auth_allowed_domain_suffix'])
            && isset($this->config['baja_auth_default_redirect']);
    }

    public static function depends_on()
    {
        return ['\phpbb\db\migration\data\v330\v330'];
    }

    public function update_data()
    {
        // Initial values come from env vars at extension-enable time; the
        // entrypoint refreshes them via `config:set` on every boot, so these
        // hardcoded fallbacks only matter if the env vars were unset.
        $suffix   = getenv('BAJA_AUTH_ALLOWED_DOMAIN_SUFFIX') ?: '.baja.local';
        $redirect = getenv('BAJA_AUTH_DEFAULT_REDIRECT')      ?: 'http://baja.local/';
        return [
            ['config.add', ['baja_auth_allowed_domain_suffix', $suffix]],
            ['config.add', ['baja_auth_default_redirect',      $redirect]],
        ];
    }
}
