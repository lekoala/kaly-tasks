<?php

declare(strict_types=1);

namespace Kaly\Tasks\Store;

use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Values crossing the SQL boundary, shared by the stores. Each store keeps its
 * own SQL.
 *
 * @internal
 */
final class StoreValues
{
    // Bytes of an error kept in last_error
    private const MAX_ERROR_LENGTH = 4096;

    /**
     * Durations and limits are bound as SQL INTEGER.
     */
    public static function assertDuration(int $value, string $what, int $min = 0): void
    {
        if ($value < $min || $value > 2_147_483_647) {
            throw new InvalidArgumentException("{$what} must be between {$min} and 2147483647, got {$value}");
        }
    }

    /**
     * Errors often embed outside data: invalid UTF-8 would make the settlement
     * fail, and an unbounded message would bloat the table.
     */
    public static function error(string $error): string
    {
        if (preg_match('//u', $error) !== 1) {
            $error = (string) preg_replace('/[\x80-\xFF]/', '?', $error);
        }
        $error = str_replace("\0", '', $error);
        if (strlen($error) <= self::MAX_ERROR_LENGTH) {
            return $error;
        }
        $error = substr($error, 0, self::MAX_ERROR_LENGTH - strlen('…'));
        // Drops a multibyte character cut in half
        while (preg_match('//u', $error) !== 1) {
            $error = substr($error, 0, -1);
        }
        return $error . '…';
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function reserved(array $row, string $owner): ReservedTask
    {
        return new ReservedTask(
            self::int($row['id']),
            self::string($row['name']),
            self::string($row['payload']),
            self::int($row['attempts']),
            $owner,
        );
    }

    /**
     * @param array<string,mixed> $row
     * @return array{id:int,queue:string,name:string,payload:string,attempts:int,error:string|null,created_at:int,finished_at:int}
     */
    public static function failed(array $row): array
    {
        return [
            'id' => self::int($row['id']),
            'queue' => self::string($row['queue']),
            'name' => self::string($row['name']),
            'payload' => self::string($row['payload']),
            'attempts' => self::int($row['attempts']),
            'error' => $row['last_error'] === null ? null : self::string($row['last_error']),
            'created_at' => self::int($row['created_at']),
            'finished_at' => self::int($row['finished_at']),
        ];
    }

    /**
     * Drivers return integers as int or numeric string.
     */
    private static function int(mixed $value): int
    {
        if (is_int($value) || is_string($value) && is_numeric($value)) {
            return (int) $value;
        }
        throw new UnexpectedValueException('Expected an integer column, got ' . get_debug_type($value));
    }

    private static function string(mixed $value): string
    {
        if (!is_string($value)) {
            throw new UnexpectedValueException('Expected a text column, got ' . get_debug_type($value));
        }
        return $value;
    }
}
