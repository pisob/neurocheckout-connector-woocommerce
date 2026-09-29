<?php

declare(strict_types=1);

namespace NeuroCheckout\WooCommerce\Community;

use RuntimeException;
use Throwable;

/** Staging boundary. No exporter means 503, never a fabricated complete page. */
final class SourcePullGateway
{
    public static function handle(string $platform, int $nativeScope, string $webRoot, string $method,
        string $uri, array $headers, string $raw, bool $https, ?callable $exporter = null,
        ?array $automaticConfiguration = null, ?string $automaticStateDirectory = null): array
    {
        try {
            if ($automaticConfiguration !== null) {
                $configuration = self::validateConfiguration($automaticConfiguration);
                $directory = self::automaticDirectory((string) $automaticStateDirectory, $webRoot);
            } else {
                $path = (string) getenv('NC_COMMUNITY_SOURCE_CONFIG');
                if ($path === '') {
                    return self::error(404, 'not_found');
                }
                $configuration = self::configuration($path, $webRoot);
                $directory = dirname($path);
            }
            if ($configuration['enabled'] !== true || $configuration['environment'] !== 'staging') {
                return self::error(404, 'not_found');
            }
            if ($configuration['platform'] !== $platform || $configuration['nativeScope'] !== $nativeScope
                || $nativeScope < 1 || SourcePullProtocol::platformForPath($uri) !== $platform || !$https) {
                return self::error(403, 'source_forbidden');
            }
            $statePath = $directory . '/source-' . hash('sha256', $platform . ':' . $nativeScope);
            self::guard($statePath, null, 0);
            $input = SourcePullProtocol::authenticate($method, $uri, $headers, $raw,
                $configuration['shopId'], $configuration['secret'], (int) floor(microtime(true) * 1000),
                static function (string $nonce, int $ttl) use ($statePath): bool {
                    return self::guard($statePath, $nonce, $ttl);
                }, true, 'staging');
            if ($exporter === null) {
                return self::error(503, 'source_export_not_ready');
            }
            $page = $exporter($input, $nativeScope, $configuration, $directory);
            self::validatePage($page, $input);
            $body = json_encode($page, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return [200, self::headers() + [
                'X-NC-Source-Response' => SourcePullProtocol::responseSignature($configuration['secret'], $headers['x-nc-source-nonce'], $body),
            ], $body];
        } catch (Throwable $error) {
            $codes = ['source_unauthorized' => 401, 'source_replay' => 409, 'source_invalid' => 400, 'source_rate_limited' => 429,
                'source_resync_required' => 409, 'source_snapshot_capacity' => 503, 'source_snapshot_timeout' => 503,
                'source_snapshot_not_transactional' => 503, 'source_scope_inconsistent' => 503,
                'source_schema_unavailable' => 503, 'source_shop_unavailable' => 503];
            $code = $error->getMessage();
            return self::error($codes[$code] ?? 503, isset($codes[$code]) ? $code : 'source_unavailable');
        }
    }

    private static function configuration(string $path, string $webRoot): array
    {
        $root = realpath($webRoot);
        if (!$root || $path === '' || $path[0] !== '/' || realpath($path) !== $path
            || strpos($path, rtrim($root, '/') . '/') === 0) {
            throw new RuntimeException('source_private_configuration_required');
        }
        self::privatePath(dirname($path), true);
        self::privatePath($path, false);
        $raw = @file_get_contents($path, false, null, 0, 4097);
        if (!is_string($raw) || strlen($raw) > 4096) {
            throw new RuntimeException('source_private_configuration_required');
        }
        $value = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        return self::validateConfiguration($value);
    }

    private static function validateConfiguration($value): array
    {
        $keys = is_array($value) ? array_keys($value) : []; sort($keys);
        $expected = ['enabled', 'environment', 'nativeScope', 'platform', 'secret', 'shopId']; sort($expected);
        if ($keys !== $expected || !is_bool($value['enabled']) || !is_int($value['nativeScope'])
            || !is_string($value['shopId']) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/D', $value['shopId'])
            || !is_string($value['secret']) || !preg_match('/^[a-f0-9]{64}$/D', $value['secret'])) {
            throw new RuntimeException('source_private_configuration_required');
        }
        return $value;
    }

    private static function automaticDirectory(string $path, string $webRoot): string
    {
        if ($path === '' || $path[0] !== '/') {
            throw new RuntimeException('source_private_configuration_required');
        }
        if (!is_dir($path) && (!@mkdir($path, 0700, true) || !@chmod($path, 0700))) {
            throw new RuntimeException('source_private_configuration_required');
        }
        self::privatePath($path, true);
        $root = realpath($webRoot);
        $resolved = realpath($path);
        if (!$root || !$resolved || $resolved === $root) {
            throw new RuntimeException('source_private_configuration_required');
        }
        return $resolved;
    }

    private static function privatePath(string $path, bool $directory): void
    {
        $stat = @lstat($path);
        if (!$stat || is_link($path) || ($directory ? !is_dir($path) : !is_file($path))
            || ($stat['mode'] & 0077) !== 0 || !function_exists('posix_geteuid') || $stat['uid'] !== posix_geteuid()) {
            throw new RuntimeException('source_private_configuration_required');
        }
    }

    /** Durable rate/nonces under a stable lock. Corruption fails closed. */
    private static function guard(string $path, ?string $nonce, int $ttl): bool
    {
        $lockPath = $path . '.lock';
        $newLock = false;
        if (!file_exists($lockPath)) {
            $initial = @fopen($lockPath, 'x+b');
            if ($initial !== false) { chmod($lockPath, 0600); fclose($initial); $newLock = true; }
        }
        self::privatePath($lockPath, false);
        $lock = @fopen($lockPath, 'r+b');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) { fclose($lock); }
            throw new RuntimeException('source_unavailable');
        }
        $temporary = null;
        try {
            $now = time();
            $state = ['window' => $now, 'count' => 0, 'nonces' => []];
            if (file_exists($path . '.json')) {
                self::privatePath($path . '.json', false);
                $raw = @file_get_contents($path . '.json', false, null, 0, 131073);
                if (!is_string($raw) || strlen($raw) > 131072) { throw new RuntimeException('source_unavailable'); }
                $state = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
                if (!is_array($state) || !isset($state['window'], $state['count'], $state['nonces'])
                    || !is_int($state['window']) || !is_int($state['count']) || !is_array($state['nonces'])) {
                    throw new RuntimeException('source_unavailable');
                }
            } elseif (!$newLock) { throw new RuntimeException('source_unavailable'); }
            foreach ($state['nonces'] as $key => $expires) {
                if (!is_int($expires)) { throw new RuntimeException('source_unavailable'); }
                if ($expires < $now) { unset($state['nonces'][$key]); }
            }
            if ($nonce === null) {
                if ($now - $state['window'] >= 60) { $state['window'] = $now; $state['count'] = 0; }
                if ($state['count'] >= 120) { throw new RuntimeException('source_rate_limited'); }
                $state['count']++;
            } else {
                if (isset($state['nonces'][$nonce])) { return false; }
                if (count($state['nonces']) >= 1200) { throw new RuntimeException('source_rate_limited'); }
                $state['nonces'][$nonce] = $now + $ttl;
            }
            $temporary = $path . '.' . bin2hex(random_bytes(12)) . '.tmp';
            $stream = @fopen($temporary, 'x+b');
            if (!$stream) { throw new RuntimeException('source_unavailable'); }
            try {
                chmod($temporary, 0600);
                $serialized = json_encode($state, JSON_THROW_ON_ERROR);
                if (fwrite($stream, $serialized) !== strlen($serialized) || !fflush($stream)) {
                    throw new RuntimeException('source_unavailable');
                }
                if (function_exists('fsync') && !fsync($stream)) { throw new RuntimeException('source_unavailable'); }
            } finally { fclose($stream); }
            if (!@rename($temporary, $path . '.json')) { throw new RuntimeException('source_unavailable'); }
            return true;
        } finally {
            if ($temporary !== null && is_file($temporary)) { unlink($temporary); }
            flock($lock, LOCK_UN); fclose($lock);
        }
    }

    private static function validatePage($page, array $input): void
    {
        $keys = is_array($page) ? array_keys($page) : []; sort($keys);
        $expected = ['schema', 'shopId', 'streamId', 'cursor', 'nextCursor', 'complete', 'generatedAt', 'records']; sort($expected);
        if ($keys !== $expected || $page['schema'] !== 1 || $page['shopId'] !== $input['shopId']
            || $page['cursor'] !== $input['cursor'] || !is_string($page['streamId']) || !preg_match('/^[a-f0-9]{32}$/D', $page['streamId'])
            || ($input['streamId'] !== null && $page['streamId'] !== $input['streamId'])
            || !is_string($page['nextCursor']) || !preg_match('/^[a-f0-9]{64}$/D', $page['nextCursor'])
            || !is_bool($page['complete']) || !is_array($page['records']) || count($page['records']) > 8
            || !is_string($page['generatedAt']) || substr($page['generatedAt'], -1) !== 'Z'
            || abs(time() - (strtotime($page['generatedAt']) ?: 0)) > 30
            || ((!$page['complete'] || $page['records']) && $page['cursor'] === $page['nextCursor'])) {
            throw new RuntimeException('source_unavailable');
        }
        if (strlen(json_encode($page, JSON_THROW_ON_ERROR)) > SourcePullProtocol::MAX_RESPONSE_BYTES) {
            throw new RuntimeException('source_unavailable');
        }
    }

    private static function headers(): array
    {
        return ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff'];
    }

    private static function error(int $status, string $code): array
    {
        return [$status, self::headers(), json_encode(['error' => $code])];
    }

    public static function serverHeaders(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }
        if (isset($server['CONTENT_TYPE'])) { $headers['content-type'] = $server['CONTENT_TYPE']; }
        return $headers;
    }

    /** Read at most one byte beyond the protocol limit, including chunked input. */
    public static function requestBody(): string
    {
        $stream = @fopen('php://input', 'rb');
        if (!$stream) { return ''; }
        try {
            $body = stream_get_contents($stream, SourcePullProtocol::MAX_REQUEST_BYTES + 1);
            return is_string($body) ? $body : '';
        } finally { fclose($stream); }
    }
}
