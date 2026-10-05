<?php

declare(strict_types=1);

namespace NeuroCheckout\WooCommerce\Community;

use RuntimeException;

/** Bounded staging reconciliation, not a native database change-log cursor. */
final class ReconciledSourceExporter
{
    private const MAX_STATE_BYTES = 8388608;
    private const MAX_RECORDS = 256;
    private const MAX_REFERENCES = 4096;
    private string $base;
    private string $key;
    private string $binding;
    private string $shopId;
    private $snapshot;
    private $clock;

    public function __construct(string $directory, array $configuration, callable $snapshot, ?callable $clock = null)
    {
        self::privatePath($directory, true);
        if (realpath($directory) !== $directory || !in_array($configuration['environment'], ['staging', 'production'], true)
            || !in_array($configuration['platform'], ['prestashop', 'magento', 'woocommerce'], true)
            || !preg_match('/^[a-f0-9]{64}$/D', $configuration['secret'])) {
            throw new RuntimeException('source_unavailable');
        }
        $this->binding = json_encode(['source-reconcile-v1', $configuration['platform'], $configuration['nativeScope'], $configuration['shopId']], JSON_THROW_ON_ERROR);
        // Preserve existing test snapshots; production never reuses their state.
        if ($configuration['environment'] === 'production') { $this->binding .= ':production'; }
        $this->shopId = $configuration['shopId'];
        $this->key = hash_hkdf('sha256', hex2bin($configuration['secret']), 32, 'source-reconcile-encryption-v1', $this->binding);
        $this->base = $directory . '/reconcile-' . hash('sha256', $this->binding);
        $this->snapshot = $snapshot;
        $this->clock = $clock ?? static function (): int { return time(); };
    }

    public function page(array $input): array
    {
        if (($input['shopId'] ?? null) !== $this->shopId) { throw new RuntimeException('source_resync_required'); }
        $created = false;
        if (!file_exists($this->base . '.lock')) {
            $handle = @fopen($this->base . '.lock', 'x+b');
            if ($handle) { chmod($this->base . '.lock', 0600); fclose($handle); $created = true; }
        }
        self::privatePath($this->base . '.lock', false);
        $lock = @fopen($this->base . '.lock', 'r+b');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) { fclose($lock); }
            throw new RuntimeException('source_unavailable');
        }
        try {
            $now = ($this->clock)();
            $state = $this->load($created, $now);
            if ($now < $state['updatedAt'] || $now - $state['updatedAt'] >= 28 * 86400) {
                throw new RuntimeException('source_resync_required');
            }
            if ($input['streamId'] !== null && $input['streamId'] !== $state['streamId']) {
                throw new RuntimeException('source_resync_required');
            }
            if ($state['lastPage'] !== null && $input['cursor'] === $state['lastInput']) {
                // The response may have been lost. Replay its exact records and
                // cursor, but never renew an old claim that the source is current.
                $page = $state['lastPage'];
                $page['complete'] = false;
                $page['generatedAt'] = gmdate('Y-m-d\TH:i:s\Z', $now);
                return $page;
            }
            if ($input['cursor'] !== $state['cursor']) { throw new RuntimeException('source_resync_required'); }
            $captured = false;
            if (!$state['pending']) {
                $started = microtime(true);
                $snapshot = ($this->snapshot)();
                if (microtime(true) - $started > 5) { throw new RuntimeException('source_snapshot_timeout'); }
                $this->reconcile($state, $snapshot, $now);
                $captured = true;
            }
            $records = array_splice($state['pending'], 0, 8);
            $page = ['schema' => 1, 'shopId' => $input['shopId'], 'streamId' => $state['streamId'],
                'cursor' => $input['cursor'], 'nextCursor' => bin2hex(random_bytes(32)),
                'complete' => $captured && !$state['pending'],
                'generatedAt' => gmdate('Y-m-d\TH:i:s\Z', ($this->clock)()), 'records' => $records];
            $state['lastInput'] = $input['cursor'];
            $state['lastPage'] = $page;
            $state['cursor'] = $page['nextCursor'];
            $state['updatedAt'] = $now;
            $this->save($state);
            return $page;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function reconcile(array &$state, $snapshot, int $now): void
    {
        if (!is_array($snapshot) || count($snapshot) > self::MAX_RECORDS
            || ($snapshot && array_keys($snapshot) !== range(0, count($snapshot) - 1))) {
            throw new RuntimeException('source_snapshot_capacity');
        }
        $seen = [];
        foreach ($snapshot as $item) {
            if (!is_array($item) || count($item) !== 3 || !isset($item['kind'], $item['sourceId'], $item['payload'])
                || !in_array($item['kind'], ['product', 'cart'], true) || !is_string($item['sourceId'])
                || $item['sourceId'] === '' || strlen($item['sourceId']) > 256
                || (!is_array($item['payload']) && !is_object($item['payload']))) {
                throw new RuntimeException('source_snapshot_invalid');
            }
            $encoded = json_encode($item['payload'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded[0] !== '{' || strlen($encoded) > 16384) { throw new RuntimeException('source_snapshot_capacity'); }
            $id = hash_hmac('sha256', $item['kind'] . ':' . $item['sourceId'], $this->key);
            if (isset($seen[$id])) { throw new RuntimeException('source_snapshot_invalid'); }
            $seen[$id] = true;
            $digest = hash('sha256', $encoded);
            $previous = $state['known'][$id] ?? null;
            if ($previous !== null && !$previous['deleted'] && $previous['digest'] === $digest
                && $now - $previous['observedAt'] < 86400) { continue; }
            $revision = $previous === null ? 1 : $previous['revision'] + 1;
            $state['known'][$id] = ['kind' => $item['kind'], 'sourceId' => $item['sourceId'], 'revision' => $revision,
                'digest' => $digest, 'deleted' => false, 'observedAt' => $now];
            $state['pending'][] = ['kind' => $item['kind'], 'sourceId' => $item['sourceId'], 'revision' => $revision,
                'operation' => 'upsert', 'observedAt' => gmdate('Y-m-d\TH:i:s\Z', $now), 'payload' => $item['payload']];
        }
        foreach ($state['known'] as $id => &$previous) {
            if (isset($seen[$id]) || $previous['deleted']) { continue; }
            $previous['deleted'] = true; $previous['revision']++; $previous['observedAt'] = $now;
            $state['pending'][] = ['kind' => $previous['kind'], 'sourceId' => $previous['sourceId'], 'revision' => $previous['revision'],
                'operation' => 'delete', 'observedAt' => gmdate('Y-m-d\TH:i:s\Z', $now), 'payload' => (object) []];
        }
        unset($previous);
        if (count($state['known']) > self::MAX_REFERENCES) { throw new RuntimeException('source_snapshot_capacity'); }
    }

    private function load(bool $created, int $now): array
    {
        if (!file_exists($this->base . '.bin')) {
            if (!$created) { throw new RuntimeException('source_resync_required'); }
            $state = ['schema' => 1, 'streamId' => bin2hex(random_bytes(16)), 'cursor' => '',
                'lastInput' => null, 'lastPage' => null, 'pending' => [], 'known' => [], 'updatedAt' => $now];
            $this->save($state);
            return $state;
        }
        self::privatePath($this->base . '.bin', false);
        $bytes = @file_get_contents($this->base . '.bin', false, null, 0, self::MAX_STATE_BYTES + 29);
        if (!is_string($bytes) || strlen($bytes) < 28 || strlen($bytes) > self::MAX_STATE_BYTES + 28) {
            throw new RuntimeException('source_unavailable');
        }
        $plain = openssl_decrypt(substr($bytes, 28), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA,
            substr($bytes, 0, 12), substr($bytes, 12, 16), $this->binding);
        if ($plain === false) { throw new RuntimeException('source_unavailable'); }
        // Objects are retained for empty delete payloads; decode structural state
        // to arrays separately so {} never becomes [] on response reserialization.
        $state = json_decode($plain, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($state) || ($state['schema'] ?? null) !== 1) { throw new RuntimeException('source_unavailable'); }
        $objects = json_decode($plain, false, 64, JSON_THROW_ON_ERROR);
        foreach ($state['pending'] as $index => &$record) { $record['payload'] = $objects->pending[$index]->payload; } unset($record);
        if ($state['lastPage'] !== null) {
            foreach ($state['lastPage']['records'] as $index => &$record) { $record['payload'] = $objects->lastPage->records[$index]->payload; } unset($record);
        }
        return $state;
    }

    private function save(array $state): void
    {
        $plain = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($plain) > self::MAX_STATE_BYTES) { throw new RuntimeException('source_snapshot_capacity'); }
        $iv = random_bytes(12); $tag = '';
        $encrypted = openssl_encrypt($plain, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag, $this->binding);
        if ($encrypted === false) { throw new RuntimeException('source_unavailable'); }
        $path = $this->base . '.' . bin2hex(random_bytes(12)) . '.tmp';
        $file = @fopen($path, 'x+b');
        if (!$file) { throw new RuntimeException('source_unavailable'); }
        try {
            chmod($path, 0600); $bytes = $iv . $tag . $encrypted;
            if (fwrite($file, $bytes) !== strlen($bytes) || !fflush($file)) { throw new RuntimeException('source_unavailable'); }
            if (function_exists('fsync') && !fsync($file)) { throw new RuntimeException('source_unavailable'); }
            fclose($file); $file = null;
            if (!@rename($path, $this->base . '.bin')) { throw new RuntimeException('source_unavailable'); }
        } finally {
            if (is_resource($file)) { fclose($file); }
            if (is_file($path)) { unlink($path); }
        }
    }

    private static function privatePath(string $path, bool $directory): void
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!$stat || is_link($path) || ($directory ? !is_dir($path) : !is_file($path))
            || ($stat['mode'] & 0077) !== 0 || !function_exists('posix_geteuid') || $stat['uid'] !== posix_geteuid()) {
            throw new RuntimeException('source_unavailable');
        }
    }
}
