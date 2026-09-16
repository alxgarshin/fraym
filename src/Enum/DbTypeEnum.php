<?php

/*
 * This file is part of the Fraym package.
 *
 * (c) Alex Garshin <alxgarshin@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Fraym\Enum;

enum DbTypeEnum: string
{
    case POSTGRESQL = 'pgsql';
    case MYSQL = 'mysql';

    public static function init(): self
    {
        return self::tryFrom($_ENV['DATABASE_TYPE']) ?? self::POSTGRESQL;
    }

    /** Safe quoting of an identifier (table or field) */
    public function quoteIdentifier(string $identifier): string
    {
        $char = match ($this) {
            self::POSTGRESQL => '"',
            self::MYSQL => '`',
        };

        $parts = explode('.', $identifier);

        $escapedParts = array_map(
            static fn ($part) => $char . str_replace($char, $char . $char, $part) . $char,
            $parts,
        );

        return implode('.', $escapedParts);
    }

    /** Get regex words */
    public function getRegexpWords(): array
    {
        return match ($this) {
            self::POSTGRESQL => ["~*", "!~*"],
            self::MYSQL => ['REGEXP', 'NOT REGEXP'],
        };
    }

    /** Get the database name for the root user */
    public function getRootTableName(): ?string
    {
        return match ($this) {
            self::POSTGRESQL => 'postgres',
            self::MYSQL => null,
        };
    }

    /** Get the root user name */
    public function getRootUser(): string
    {
        return match ($this) {
            self::POSTGRESQL => 'postgres',
            self::MYSQL => 'root',
        };
    }
}
