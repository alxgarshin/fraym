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

namespace Fraym\Interface;

/**
 * Database dialect interface (Strategy Pattern).
 *
 * Encapsulates all SQL constructs and settings specific to a particular
 * DBMS engine. To add a new dialect, create a class implementing
 * this interface and extend DbTypeEnum. All branching points by DB type
 * are strictly concentrated here.
 *
 * Access from code: DB->dialect->method()
 */
interface DatabaseDialect
{
    /** Additional options of the DSN connection string (e.g. charset for MySQL) */
    public function getDsnOptions(): string;

    /** NULL-safe comparison operator (MySQL: <=>, PostgreSQL: =) */
    public function getNullSafeEqualOperator(): string;

    /** INSERT query suffix for getting the ID of the inserted row */
    public function getInsertReturningClause(string $fieldName): string;

    /**
     * Extract the ID from the INSERT result.
     * null  — the dialect doesn't support RETURNING; PDO::lastInsertId() should be used.
     * false — RETURNING is supported, but the result is empty.
     * string — the extracted ID.
     */
    public function extractLastInsertId(array|false $queryResult): string|false|null;

    /** Wrapper character for the value in a LIKE pattern when searching inside JSON groups */
    public function getGroupFieldQuerySign(): string;

    /**
     * SQL for forcibly terminating all DB connections before dropping it.
     * null — the operation isn't required for this dialect.
     */
    public function terminateConnectionsSql(string $dbName): ?string;

    /** SQL for checking whether a database exists */
    public function checkDatabaseExistsSql(string $dbName): string;

    /**
     * SQL for switching the active DB (MySQL: USE db; PostgreSQL: not required).
     * null — the operation isn't required for this dialect.
     */
    public function useDatabaseSql(string $dbQuoted): ?string;

    /** SQL for checking whether a DB user exists */
    public function checkUserExistsSql(string $user): string;

    /** SQL for creating a DB user */
    public function createUserSql(string $userQuoted, string $user, string $password): string;

    /** SQL for changing the password of an existing DB user */
    public function alterUserSql(string $userQuoted, string $user, string $password): string;

    /**
     * CREATE DATABASE statement suffix for assigning the owner.
     * PostgreSQL: " OWNER user". MySQL: "".
     */
    public function createDatabaseOwnerSuffix(string $userQuoted): string;

    /** SQL for granting a user full privileges on a database */
    public function grantPrivilegesSql(string $dbQuoted, string $userQuoted, string $user): string;

    /**
     * SQL query executed after GRANT (MySQL: FLUSH PRIVILEGES).
     * null — no additional query is required.
     */
    public function afterGrantSql(): ?string;

    /** DDL for creating the service table that tracks migrations */
    public function createMigrationTableSql(): string;

    /** SQL for setting the connection timezone after running a migration */
    public function setTimezoneSql(): string;

    /**
     * SQL for sorting rows by a custom order of field values.
     * MySQL uses FIELD(), PostgreSQL uses CASE WHEN.
     *
     * @param string $field Column name (e.g. 'type')
     * @param string[] $values Values in the required order
     * @param string $tieBreakField Field for secondary sorting (on ties)
     * @return array{selectExtra: string, orderBy: string}
     *                                                     selectExtra — fragment added to SELECT (empty string if not needed)
     *                                                     orderBy     — full ORDER BY clause
     */
    public function orderByCustomValuesSql(string $field, array $values, string $tieBreakField): array;

    /** Checkbox field value in the DB */
    public function checkboxDbValue(bool $value): bool|int|string;

    /**
     * SQL expression "column contains a JSON element" for multiselect columns.
     * The IFNULL/NULLIF (MySQL) or COALESCE/NULLIF (PostgreSQL) wrapper ensures
     * that NULL and an empty string don't break the JSON parser.
     *
     * @param string $column Column name — already qualified if needed (e.g. "t1.tags")
     * @param string $needle Either a bind placeholder (":name") or an SQL literal with JSON inside
     * @param bool $negate If true — return a "does not contain" expression
     */
    public function jsonContainsExpression(string $column, string $needle, bool $negate = false): string;

    /** SQL expression returning the first element of a JSON array for LEFT JOIN when sorting */
    public function jsonLeftJoinFirstElement(string $fieldName): string;
}
