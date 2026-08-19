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

// Every way of writing the same document must share one bucket, or the limit
// is bypassed by rewriting it. Leading zeros are the dangerous one: there is
// no bound on how many can be prepended, so a per-spelling counter hands out
// unlimited attempts.
$punctuated = substr($documento, 0, 3) . '.' . substr($documento, 3, 3) . '.'
    . substr($documento, 6, 3) . '-' . substr($documento, 9, 2);
foreach ([
    'punctuated'         => $punctuated,
    'zero padded'        => '00' . $documento,
    'more zeros'         => '00000' . $documento,
    'letters prefixed'   => 'AB' . $documento,
    'spaced'             => substr($documento, 0, 5) . ' ' . substr($documento, 5),
] as $label => $variant) {
    Backoff::clear($documento);
    for ($i = 0; $i < 5; $i++) {
        Backoff::recordFailure($variant);
    }
    T::ok("a $label form shares the plain form's counter", !Backoff::allows($documento));
}
Backoff::clear($documento);

// --- the wait is reported, so the page can explain itself --------------------

T::same(null, Backoff::retryAfterSeconds($documento), 'an unlocked document reports no wait');
for ($i = 0; $i < 5; $i++) {
    Backoff::recordFailure($documento);
}
$wait = Backoff::retryAfterSeconds($documento);
T::ok('a locked document reports a wait', $wait !== null && $wait > 0, var_export($wait, true));
T::ok('the wait is within the window', $wait !== null && $wait <= 900, var_export($wait, true));
T::same('15 minutos', \Baja\Certificado\Busca::describeWait(900), 'a full window reads as minutes');
T::same('1 minuto', \Baja\Certificado\Busca::describeWait(60), 'one minute is singular');
T::same('2 minutos', \Baja\Certificado\Busca::describeWait(61), 'a partial minute rounds up, never down');
T::same('alguns segundos', \Baja\Certificado\Busca::describeWait(5), 'under a minute is vague on purpose');
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

// --- a throttled document on file looks like a throttled invented one -------
//
// The page says "muitas tentativas" rather than "not found", which is only
// safe because this state is reached identically either way. Nothing here
// consults the database — failures are counted for whatever was submitted —
// so a document that exists and one that never could hit the wall alike.

$onFile   = '00000000191';                                       // the dev seed row
$invented = '777' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);

foreach ([$onFile, $invented] as $value) {
    Backoff::clear($value);
    for ($i = 0; $i < 5; $i++) {
        Backoff::recordFailure($value);
    }
}

T::ok('a document on file throttles', !Backoff::allows($onFile));
T::ok('an invented document throttles too', !Backoff::allows($invented));
T::ok(
    'and both report the same wait',
    abs((Backoff::retryAfterSeconds($onFile) ?? -1) - (Backoff::retryAfterSeconds($invented) ?? -2)) <= 2,
    'the wait must not distinguish a real document from an invented one'
);

// Leave nothing locked behind — the seed row is what a developer searches for
// by hand, and a fifteen-minute lockout after a test run is a nasty surprise.
Backoff::clear($onFile);
Backoff::clear($invented);
Backoff::clear($documento);
