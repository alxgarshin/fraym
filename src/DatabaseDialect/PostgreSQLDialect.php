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

namespace Fraym\DatabaseDialect;

use Fraym\Interface\DatabaseDialect;

final class PostgreSQLDialect implements DatabaseDialect
{
    public function getDsnOptions(): string
    {
        return '';
    }

    public function getNullSafeEqualOperator(): string
    {
        return '=';
    }

    public function getInsertReturningClause(string $fieldName): string
    {
        return ' RETURNING ' . $fieldName;
    }

    public function extractLastInsertId(array|false $queryResult): string|false
    {
        if (empty($queryResult)) {
            return false;
        }

        $firstRow = $queryResult[0] ?? $queryResult;
        $id = reset($firstRow);

        return $id !== false ? (string) $id : false;
    }

    public function getGroupFieldQuerySign(): string
    {
        return '"';
    }

    public function terminateConnectionsSql(string $dbName): string
    {
        return "SELECT pg_terminate_backend(pid)
        FROM pg_stat_activity
        WHERE datname = '" . $dbName . "' AND pid <> pg_backend_pid();";
    }

    public function checkDatabaseExistsSql(string $dbName): string
    {
        return "SELECT 1 FROM pg_database WHERE datname = '" . $dbName . "'";
    }

    public function useDatabaseSql(string $dbQuoted): ?string
    {
        return null;
    }

    public function checkUserExistsSql(string $user): string
    {
        return "SELECT 1 FROM pg_roles WHERE rolname = '" . $user . "'";
    }

    public function createUserSql(string $userQuoted, string $user, string $password): string
    {
        return "CREATE USER " . $userQuoted . " WITH PASSWORD '" . $password . "'";
    }

    public function alterUserSql(string $userQuoted, string $user, string $password): string
    {
        return "ALTER USER " . $userQuoted . " WITH PASSWORD '" . $password . "'";
    }

    public function createDatabaseOwnerSuffix(string $userQuoted): string
    {
        return ' OWNER ' . $userQuoted;
    }

    public function grantPrivilegesSql(string $dbQuoted, string $userQuoted, string $user): string
    {
        return "GRANT ALL PRIVILEGES ON DATABASE " . $dbQuoted . " TO " . $userQuoted;
    }

    public function afterGrantSql(): ?string
    {
        return null;
    }

    public function createMigrationTableSql(): string
    {
        return 'CREATE TABLE IF NOT EXISTS "migration" (
  "id" UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  "migration_id" varchar(100) NOT NULL,
  "migrated_at" timestamp NOT NULL,
  "migration_result" json
)';
    }

    public function setTimezoneSql(): string
    {
        return "SET TIME ZONE 'Europe/Moscow'";
    }

    public function orderByCustomValuesSql(string $field, array $values, string $tieBreakField): array
    {
        $count = 0;
        $caseSql = 'CASE';

        foreach ($values as $value) {
            $count++;
            $caseSql .= " WHEN " . $field . "='" . $value . "' THEN " . $count;
        }

        $caseSql .= " ELSE " . ($count + 1) . " END";

        return [
            'selectExtra' => "(" . $caseSql . ") as order_type, ",
            'orderBy' => " ORDER BY " . $caseSql . ", " . $tieBreakField,
        ];
    }

    public function checkboxDbValue(bool $value): int
    {
        return $value ? 1 : 0;
    }

    public function jsonContainsExpression(string $column, string $needle, bool $negate = false): string
    {
        $expr = "COALESCE(NULLIF(" . $column . ", ''), '[]')::jsonb @> " . $needle . "::jsonb";

        return $negate ? "NOT (" . $expr . ")" : $expr;
    }
}
