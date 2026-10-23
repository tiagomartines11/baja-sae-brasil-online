<?php

namespace Baja\Api;

/**
 * API Documentation Generator
 *
 * Auto-discovers PHP endpoints in /api/ and generates JSON documentation.
 * Parses PHPDoc headers including @api-* annotations.
 *
 * Usage: php -r "require 'src/bootstrap.php'; Baja\Api\GenerateDocs::generate();"
 */
class GenerateDocs
{
    private static string $apiDir;
    private static string $outputFile;

    /**
     * Patterns to exclude from discovery
     */
    private static array $excludePatterns = [
        '/^index\.php$/',      // The documentation endpoint itself
        '/^_/',                // Files/dirs starting with underscore
        '/^\./',               // Hidden files
        '/test/',              // Test directories
    ];

    /**
     * Generate API documentation and save to ApiDocs.json
     */
    public static function generate(): void
    {
        self::$apiDir = realpath(__DIR__ . '/../../../api');
        self::$outputFile = __DIR__ . '/ApiDocs.json';

        $endpoints = self::discoverEndpoints();
        $config = ApiDocsConfig::getConfig();

        $docs = [
            'meta' => $config['meta'],
            'generated_at' => date('c'),
            'total_endpoints' => count($endpoints),
            'categories' => self::groupByCategory($endpoints, $config['categories']),
        ];

        $json = json_encode($docs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents(self::$outputFile, $json);

        echo "Generated API documentation with " . count($endpoints) . " endpoints.\n";
        echo "Output: " . self::$outputFile . "\n";
    }

    /**
     * Recursively discover all PHP endpoints
     */
    private static function discoverEndpoints(): array
    {
        $endpoints = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::$apiDir),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = str_replace(self::$apiDir . '/', '', $file->getPathname());

            if (self::shouldExclude($relativePath)) {
                continue;
            }

            $endpointKey = preg_replace('/\.php$/', '', $relativePath);
            $endpoints[$endpointKey] = self::parseEndpointFile($file->getPathname(), $endpointKey);
        }

        ksort($endpoints);
        return $endpoints;
    }

    /**
     * Check if a path should be excluded
     */
    private static function shouldExclude(string $path): bool
    {
        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            foreach (self::$excludePatterns as $pattern) {
                if (preg_match($pattern, $segment)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Parse a PHP endpoint file and extract documentation
     */
    private static function parseEndpointFile(string $filePath, string $key): array
    {
        $content = file_get_contents($filePath);
        $info = [
            'path' => '/api/' . $key . '.php',
            'key' => $key,
            'category' => explode('/', $key)[0],
            'summary' => null,
            'description' => null,
            'methods' => [],
        ];

        // Extract PHPDoc block at start of file
        if (preg_match('/^<\?php\s*\n\/\*\*(.*?)\*\//s', $content, $matches)) {
            $docBlock = $matches[1];
            $info = array_merge($info, self::parseDocBlock($docBlock));
        }

        // Extract HTTP methods from Access-Control-Allow-Methods header
        if (preg_match("/header\(['\"]Access-Control-Allow-Methods:\s*([^'\"]+)['\"]\\)/", $content, $matches)) {
            $headerMethods = array_map('trim', explode(',', $matches[1]));
            $headerMethods = array_filter($headerMethods, fn($m) => $m !== 'OPTIONS');

            // Merge with parsed methods, keeping docblock info
            foreach ($headerMethods as $method) {
                if (!isset($info['methods'][$method])) {
                    $info['methods'][$method] = ['description' => null];
                }
            }
        }

        // Fallback: extract from REQUEST_METHOD checks
        if (empty($info['methods'])) {
            preg_match_all("/\\\$_SERVER\['REQUEST_METHOD'\]\s*[!=]==?\s*'(GET|POST|PUT|DELETE|PATCH)'/", $content, $matches);
            if (!empty($matches[1])) {
                foreach (array_unique($matches[1]) as $method) {
                    $info['methods'][$method] = ['description' => null];
                }
            }
        }

        // Generate summary if not provided
        if (empty($info['summary'])) {
            $info['summary'] = self::generateSummary($key);
        }

        return $info;
    }

    /**
     * Parse PHPDoc block and extract @api-* annotations
     */
    private static function parseDocBlock(string $docBlock): array
    {
        $result = [
            'summary' => null,
            'description' => null,
            'category' => null,
            'methods' => [],
        ];

        $lines = explode("\n", $docBlock);
        $currentMethod = null;

        foreach ($lines as $line) {
            $line = trim(preg_replace('/^\s*\*\s?/', '', $line));
            if (empty($line)) continue;

            // @api-endpoint marker (optional, just confirms it's an API endpoint)
            if ($line === '@api-endpoint') {
                continue;
            }

            // @api-summary
            if (preg_match('/@api-summary\s+(.+)/', $line, $m)) {
                $result['summary'] = trim($m[1]);
                continue;
            }

            // @api-description
            if (preg_match('/@api-description\s+(.+)/', $line, $m)) {
                $result['description'] = trim($m[1]);
                continue;
            }

            // @api-category
            if (preg_match('/@api-category\s+(\S+)/', $line, $m)) {
                $result['category'] = trim($m[1]);
                continue;
            }

            // @api-method - starts a new method block
            if (preg_match('/@api-method\s+(GET|POST|PUT|DELETE|PATCH)/', $line, $m)) {
                $currentMethod = $m[1];
                if (!isset($result['methods'][$currentMethod])) {
                    $result['methods'][$currentMethod] = [
                        'description' => null,
                        'params' => [],
                        'permissions' => [],
                        'responses' => [],
                    ];
                }
                continue;
            }

            // @api-method-description
            if (preg_match('/@api-method-description\s+(.+)/', $line, $m) && $currentMethod) {
                $result['methods'][$currentMethod]['description'] = trim($m[1]);
                continue;
            }

            // @api-param field type description
            if (preg_match('/@api-param\s+(\S+)\s+(\S+)\s+(.*)/', $line, $m) && $currentMethod) {
                $result['methods'][$currentMethod]['params'][] = [
                    'name' => $m[1],
                    'type' => $m[2],
                    'description' => trim($m[3]),
                ];
                continue;
            }

            // @api-permission-resource
            if (preg_match('/@api-permission-resource\s+(\S+)/', $line, $m) && $currentMethod) {
                $result['methods'][$currentMethod]['permissions']['resource'] = trim($m[1]);
                continue;
            }

            // @api-permission-action
            if (preg_match('/@api-permission-action\s+(\S+)/', $line, $m) && $currentMethod) {
                $result['methods'][$currentMethod]['permissions']['action'] = trim($m[1]);
                continue;
            }

            // @api-permission-scope
            if (preg_match('/@api-permission-scope\s+(.+)/', $line, $m) && $currentMethod) {
                if (!isset($result['methods'][$currentMethod]['permissions']['scope'])) {
                    $result['methods'][$currentMethod]['permissions']['scope'] = [];
                }
                $result['methods'][$currentMethod]['permissions']['scope'][] = trim($m[1]);
                continue;
            }

            // @api-response code description
            if (preg_match('/@api-response\s+(\d+)\s+(.+)/', $line, $m) && $currentMethod) {
                $result['methods'][$currentMethod]['responses'][$m[1]] = trim($m[2]);
                continue;
            }
        }

        // Clean up empty arrays
        foreach ($result['methods'] as $method => &$data) {
            if (empty($data['params'])) unset($data['params']);
            if (empty($data['permissions'])) unset($data['permissions']);
            if (empty($data['responses'])) unset($data['responses']);
        }

        return $result;
    }

    /**
     * Generate a summary from the endpoint path
     */
    private static function generateSummary(string $key): string
    {
        $parts = explode('/', $key);
        $filename = array_pop($parts);

        $actionMap = [
            'list' => 'Listar',
            'manage' => 'Gerenciar',
            'get' => 'Obter',
            'create' => 'Criar',
            'upload' => 'Upload de',
            'download' => 'Download de',
            'delete' => 'Remover',
            'review' => 'Revisar',
            'login' => 'Login',
            'logout' => 'Logout',
            'process' => 'Processar',
            'accept' => 'Aceitar',
            'request' => 'Solicitar',
            'me' => 'Usuário atual',
            'permissions' => 'Permissões',
            'roles' => 'Papéis',
        ];

        $action = $actionMap[$filename] ?? ucfirst($filename);
        $resource = !empty($parts) ? implode(' ', array_map('ucfirst', $parts)) : '';

        return trim("$action $resource") ?: $key;
    }

    /**
     * Group endpoints by category
     */
    private static function groupByCategory(array $endpoints, array $categoryConfig): array
    {
        $grouped = [];

        foreach ($endpoints as $key => $endpoint) {
            $cat = $endpoint['category'];

            if (!isset($grouped[$cat])) {
                $grouped[$cat] = [
                    'name' => $categoryConfig[$cat]['name'] ?? ucfirst($cat),
                    'description' => $categoryConfig[$cat]['description'] ?? null,
                    'order' => $categoryConfig[$cat]['order'] ?? 100,
                    'endpoints' => [],
                ];
            }

            // Remove category from endpoint (redundant)
            unset($endpoint['category']);

            $grouped[$cat]['endpoints'][] = $endpoint;
        }

        // Sort categories by order
        uasort($grouped, fn($a, $b) => $a['order'] <=> $b['order']);

        // Remove order from output
        foreach ($grouped as &$cat) {
            unset($cat['order']);
        }

        return $grouped;
    }
}
