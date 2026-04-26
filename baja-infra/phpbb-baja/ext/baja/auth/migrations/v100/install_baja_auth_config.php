<?php
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
