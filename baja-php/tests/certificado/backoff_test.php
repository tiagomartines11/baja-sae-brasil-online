<?php

use Baja\Certificado\Backoff;

T::group('backoff');

if (getenv('REDIS_HOST') === false) {
    T::skip('per-document backoff', 'REDIS_HOST is not set');
    return;
}

// A document nobody will have tried. Distinct per run so a leftover counter
// from an earlier run cannot make this pass or fail spuriously.
$documento = '999' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);

Backoff::clear($documento);
T::ok('a fresh document is allowed', Backoff::allows($documento));

for ($i = 0; $i < 4; $i++) {
    Backoff::recordFailure($documento);
}
T::ok('four failures do not lock the document', Backoff::allows($documento));

Backoff::recordFailure($documento);
T::ok('the fifth failure locks it', !Backoff::allows($documento));

// The lockout must not extend to anybody else.
$other = '888' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
Backoff::clear($other);
T::ok('another document is unaffected', Backoff::allows($other));

Backoff::clear($documento);
T::ok('a successful lookup clears the count', Backoff::allows($documento));

// Punctuation must not create a second bucket, or the limit is trivially
// bypassed by typing the same CPF a different way.
$punctuated = substr($documento, 0, 3) . '.' . substr($documento, 3, 3) . '.'
    . substr($documento, 6, 3) . '-' . substr($documento, 9, 2);
for ($i = 0; $i < 5; $i++) {
    Backoff::recordFailure($punctuated);
}
T::ok('a punctuated form shares the plain form\'s counter', !Backoff::allows($documento));
Backoff::clear($documento);

// The document must never be recoverable from the key.
$redis = new \Redis();
$redis->connect((string) getenv('REDIS_HOST'), 6379, 1.0);
Backoff::recordFailure($documento);
$keys = $redis->keys('cert_backoff:*');
$leaked = false;
foreach ($keys as $key) {
    if (str_contains($key, $documento) || str_contains($key, ltrim($documento, '0'))) {
        $leaked = true;
    }
}
T::ok('no Redis key contains the document number', !$leaked, implode(', ', array_slice($keys, 0, 3)));
T::ok(
    'keys are a keyed digest, not a bare one',
    !in_array('cert_backoff:' . hash('sha256', $documento), $keys, true),
    'an unkeyed sha256 of an 11-digit space is reversible by lookup'
);
Backoff::clear($documento);
