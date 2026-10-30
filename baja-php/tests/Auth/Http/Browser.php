<?php
declare(strict_types=1);

namespace Baja\Tests\Auth\Http;

/**
 * Just enough of a web browser to follow a login across the forum and the
 * judge area: it keeps cookies the way a browser does and can follow
 * redirects from one site to the other, writing down every stop.
 *
 * Why a cookie jar and not a fixed list of cookies: the forum sets its
 * cookies for every site under the parent domain (".baja.local"), and the
 * judge area sets its own for itself only. What the test needs to see is
 * exactly what a browser would send on each stop of the trip juiz -> forum
 * -> juiz, including cookies replaced or deleted on the way.
 *
 * Every request goes to the web server ("nginx", as the app container
 * reaches it) with the site's name in the Host header, which is how nginx
 * picks the site.
 */
final class Browser
{
    private const WEB_SERVER = 'http://nginx';
    /**
     * phpBB ties a session to the browser's User-Agent (browser_check), so
     * every request of one "browser" must send the same one.
     */
    private const USER_AGENT = 'BajaAuthHttpTest/1.0';

    /** @var array<string, array{name: string, value: string, domain: string, hostOnly: bool}> */
    private array $cookies = [];

    /**
     * One request, no redirect followed.
     *
     * @param string[] $headers
     * @return array{url: string, status: int, location: string, body: string, setCookies: array<string, string>}
     */
    public function request(string $method, string $url, ?string $body = null, array $headers = []): array
    {
        $parts = parse_url($url);
        $host = strtolower((string)($parts['host'] ?? ''));
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

        $send = ['Host: ' . $host, 'User-Agent: ' . self::USER_AGENT];
        $cookieHeader = $this->cookieHeaderFor($host);
        if ($cookieHeader !== '') {
            $send[] = 'Cookie: ' . $cookieHeader;
        }

        $location = '';
        $setCookies = [];
        $curl = curl_init(self::WEB_SERVER . $path);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => array_merge($send, $headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$location, &$setCookies, $host): int {
                if (stripos($line, 'Location:') === 0) {
                    $location = trim(substr($line, 9));
                } elseif (stripos($line, 'Set-Cookie:') === 0) {
                    [$name, $value] = $this->store($host, trim(substr($line, 11)));
                    $setCookies[$name] = $value;
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $responseBody = curl_exec($curl);
        if ($responseBody === false) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new \RuntimeException('O servidor web (' . self::WEB_SERVER . ') não respondeu: ' . $error);
        }
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        return [
            'url' => $url,
            'status' => $status,
            'location' => $location === '' ? '' : $this->absolute($url, $location),
            'body' => (string)$responseBody,
            'setCookies' => $setCookies,
        ];
    }

    /**
     * Opens a page and follows redirects, as a browser does when you type an
     * address. Returns every stop, the last one being where the browser
     * ended up. Stops after $maxStops, so that a redirect loop shows up as a
     * failed assertion on the number of stops instead of a hung test.
     *
     * @return list<array{url: string, status: int, location: string, body: string, setCookies: array<string, string>}>
     */
    public function open(string $url, int $maxStops = 8): array
    {
        $stops = [];
        while (count($stops) < $maxStops) {
            $response = $this->request('GET', $url);
            $stops[] = $response;
            if ($response['status'] < 300 || $response['status'] >= 400 || $response['location'] === '') {
                break;
            }
            $url = $response['location'];
        }
        return $stops;
    }

    /** The value of a cookie this browser would send to $host, or null. */
    public function cookie(string $name, string $host): ?string
    {
        foreach ($this->cookies as $cookie) {
            if ($cookie['name'] === $name && $this->matches($cookie, $host)) {
                return $cookie['value'];
            }
        }
        return null;
    }

    /** Puts a cookie in the jar by hand, as if a site had set it. */
    public function setCookie(string $name, string $value, string $domain, bool $hostOnly = false): void
    {
        $this->cookies[$name . '@' . $domain] = ['name' => $name, 'value' => $value, 'domain' => $domain, 'hostOnly' => $hostOnly];
    }

    /** @return array<string, array{name: string, value: string, domain: string, hostOnly: bool}> */
    public function allCookies(): array
    {
        return $this->cookies;
    }

    /** @param array<string, array{name: string, value: string, domain: string, hostOnly: bool}> $cookies */
    public function replaceCookies(array $cookies): void
    {
        $this->cookies = $cookies;
    }

    /**
     * Keeps a Set-Cookie line the way a browser would: for the site that sent
     * it only, or for every site under its Domain; deleted when it expires in
     * the past, which is how sites remove a cookie.
     *
     * @return array{0: string, 1: string} name and value
     */
    private function store(string $host, string $line): array
    {
        $attributes = array_map('trim', explode(';', $line));
        [$name, $value] = array_pad(explode('=', (string)array_shift($attributes), 2), 2, '');
        $name = trim($name);
        $value = trim($value);

        $domain = $host;
        $hostOnly = true;
        $expired = false;
        foreach ($attributes as $attribute) {
            [$key, $attrValue] = array_pad(explode('=', $attribute, 2), 2, '');
            $key = strtolower(trim($key));
            if ($key === 'domain' && $attrValue !== '') {
                $domain = ltrim(strtolower(trim($attrValue)), '.');
                $hostOnly = false;
            } elseif ($key === 'max-age') {
                $expired = (int)$attrValue <= 0;
            } elseif ($key === 'expires') {
                $time = strtotime($attrValue);
                $expired = $time !== false && $time <= time();
            }
        }

        $id = $name . '@' . $domain;
        if ($expired) {
            unset($this->cookies[$id]);
        } else {
            $this->cookies[$id] = ['name' => $name, 'value' => $value, 'domain' => $domain, 'hostOnly' => $hostOnly];
        }
        return [$name, $expired ? '' : $value];
    }

    private function cookieHeaderFor(string $host): string
    {
        $pairs = [];
        foreach ($this->cookies as $cookie) {
            if ($this->matches($cookie, $host)) {
                $pairs[] = $cookie['name'] . '=' . $cookie['value'];
            }
        }
        return implode('; ', $pairs);
    }

    /** @param array{name: string, value: string, domain: string, hostOnly: bool} $cookie */
    private function matches(array $cookie, string $host): bool
    {
        if ($cookie['hostOnly']) {
            return $host === $cookie['domain'];
        }
        return $host === $cookie['domain'] || str_ends_with($host, '.' . $cookie['domain']);
    }

    private function absolute(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location) === 1) {
            return $location;
        }
        $parts = parse_url($base);
        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $dir = preg_replace('#/[^/]*$#', '/', $parts['path'] ?? '/');
        return $origin . $dir . $location;
    }
}
