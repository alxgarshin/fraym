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

final class MySQLDialect implements DatabaseDialect
{
    public function getDsnOptions(): string
    {
        return ';charset=utf8mb4';
    }

    public function getNullSafeEqualOperator(): string
    {
        return '<=>';
    }

    public function getInsertReturningClause(string $fieldName): string
    {
        return '';
    }

    public function extractLastInsertId(array|false $queryResult): string|false|null
    {
        /** MySQL не поддерживает RETURNING — вернуть null, чтобы вызывающая сторона использовала PDO::lastInsertId() */
        return null;
    }

    public function getGroupFieldQuerySign(): string
    {
        return '\\"';
    }

    public function terminateConnectionsSql(string $dbName): ?string
    {
        return null;
    }

    public function checkDatabaseExistsSql(string $dbName): string
    {
        return "SHOW DATABASES LIKE '" . $dbName . "'";
    }

    public function useDatabaseSql(string $dbQuoted): string
    {
        return 'USE ' . $dbQuoted . ';';
    }

    public function checkUserExistsSql(string $user): string
    {
        return "SELECT 1 FROM mysql.user WHERE User = '" . $user . "' AND Host = '%'";
    }

    public function createUserSql(string $userQuoted, string $user, string $password): string
    {
        return "CREATE USER '" . $user . "'@'%' IDENTIFIED BY '" . $password . "'";
    }

    public function alterUserSql(string $userQuoted, string $user, string $password): string
    {
        return "ALTER USER '" . $user . "'@'%' IDENTIFIED BY '" . $password . "'";
    }

    public function createDatabaseOwnerSuffix(string $userQuoted): string
    {
        return '';
    }

    public function grantPrivilegesSql(string $dbQuoted, string $userQuoted, string $user): string
    {
        return "GRANT ALL PRIVILEGES ON " . $dbQuoted . ".* TO '" . $user . "'@'%'";
    }

    public function afterGrantSql(): string
    {
        return 'FLUSH PRIVILEGES';
    }

    public function createMigrationTableSql(): string
    {
        return "CREATE TABLE IF NOT EXISTS `migration` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration_id` varchar(100) NOT NULL,
  `migrated_at` timestamp NOT NULL,
  `migration_result` json,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;";
    }

    public function setTimezoneSql(): string
    {
        return "SET time_zone='+03:00'";
    }

    public function orderByCustomValuesSql(string $field, array $values, string $tieBreakField): array
    {
        return [
            'selectExtra' => '',
            'orderBy' => " ORDER BY FIELD (" . $field . ", '" . implode("', '", $values) . "')",
        ];
    }

    /** Для checkbox в MySQL используется Enum('0', '1') */
    public function checkboxDbValue(bool $value): string
    {
        return $value ? '1' : '0';
    }

    public function jsonContainsExpression(string $column, string $needle, bool $negate = false): string
    {
        $expr = "JSON_CONTAINS(IFNULL(NULLIF(" . $column . ", ''), '[]'), " . $needle . ")";

        return $negate ? "NOT " . $expr : $expr;
    }

    public function jsonLeftJoinFirstElement(string $fieldName): string
    {
        return "CAST(JSON_UNQUOTE(" . "IF(JSON_VALID(" . $fieldName . "), JSON_EXTRACT(" . $fieldName . ", '$[0]'), NULL)" . ") AS UNSIGNED)";
    }
}
