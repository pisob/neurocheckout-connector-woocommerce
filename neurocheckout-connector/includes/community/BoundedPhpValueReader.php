<?php
declare(strict_types=1);

namespace NeuroCheckout\WooCommerce\Community;

use RuntimeException;

/**
 * Reads the scalar/array subset written by WooCommerce sessions.
 * Deliberately never calls unserialize(), autoloaders or WordPress filters.
 * Objects, custom serialization, references and trailing bytes are refused.
 */
final class BoundedPhpValueReader
{
    private string $input;
    private int $offset = 0;
    private int $nodes = 0;

    private function __construct(string $input)
    {
        if ($input === '' || strlen($input) > 65536) { self::fail(); }
        $this->input = $input;
    }

    /** @return mixed Scalars, null or arrays only. */
    public static function decode(string $input)
    {
        $reader = new self($input);
        $value = $reader->value(0);
        if ($reader->offset !== strlen($input)) { self::fail(); }
        return $value;
    }

    private function value(int $depth)
    {
        if ($depth > 12 || ++$this->nodes > 4096) { self::fail(); }
        $type = $this->input[$this->offset] ?? '';
        $this->offset++;
        if ($type === 'N') { $this->expect(';'); return null; }
        $this->expect(':');
        if ($type === 'b') {
            $token = $this->token(';', 1);
            if ($token !== '0' && $token !== '1') { self::fail(); }
            return $token === '1';
        }
        if ($type === 'i') {
            $token = $this->token(';', 20);
            if (!preg_match('/^(?:0|-?[1-9][0-9]*)$/D', $token) || (string) (int) $token !== $token) { self::fail(); }
            return (int) $token;
        }
        if ($type === 'd') {
            $token = $this->token(';', 64);
            if (!preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[Ee][+-]?[0-9]+)?$/D', $token)
                || !is_finite((float) $token)) { self::fail(); }
            return (float) $token;
        }
        if ($type === 's') {
            $length = $this->length(65536);
            $this->expect('"');
            if ($length > strlen($this->input) - $this->offset) { self::fail(); }
            $value = substr($this->input, $this->offset, $length);
            $this->offset += $length;
            $this->expect('";');
            return $value;
        }
        if ($type === 'a') {
            $count = $this->length(512);
            $this->expect('{');
            $value = [];
            for ($i = 0; $i < $count; $i++) {
                $key = $this->value($depth + 1);
                if (!is_int($key) && !is_string($key)) { self::fail(); }
                // Also rejects string/integer keys which PHP would coerce to
                // the same key. Silent overwrite would make parsing ambiguous.
                if (array_key_exists($key, $value)) { self::fail(); }
                $value[$key] = $this->value($depth + 1);
            }
            $this->expect('}');
            return $value;
        }
        self::fail();
    }

    private function length(int $maximum): int
    {
        $token = $this->token(':', 6);
        if (!preg_match('/^(?:0|[1-9][0-9]*)$/D', $token) || (int) $token > $maximum) { self::fail(); }
        return (int) $token;
    }

    private function token(string $delimiter, int $maximum): string
    {
        $end = strpos($this->input, $delimiter, $this->offset);
        if ($end === false || $end - $this->offset > $maximum) { self::fail(); }
        $value = substr($this->input, $this->offset, $end - $this->offset);
        $this->offset = $end + strlen($delimiter);
        return $value;
    }

    private function expect(string $literal): void
    {
        if (substr($this->input, $this->offset, strlen($literal)) !== $literal) { self::fail(); }
        $this->offset += strlen($literal);
    }

    private static function fail(): void { throw new RuntimeException('source_session_invalid'); }
}
