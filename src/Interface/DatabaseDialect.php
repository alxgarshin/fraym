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
 * Интерфейс диалекта базы данных (Strategy Pattern).
 *
 * Инкапсулирует все SQL-конструкции и настройки, специфичные для конкретного
 * движка СУБД. При добавлении нового диалекта — создать класс, реализующий
 * этот интерфейс, и расширить DbTypeEnum. Все точки ветвления по типу БД
 * строго сосредоточены здесь.
 *
 * Доступ из кода: DB->dialect->method()
 */
interface DatabaseDialect
{
    /** Дополнительные опции строки DSN-подключения (например, charset для MySQL) */
    public function getDsnOptions(): string;

    /** Оператор сравнения с учётом NULL (MySQL: <=>, PostgreSQL: =) */
    public function getNullSafeEqualOperator(): string;

    /** Суффикс INSERT-запроса для получения ID вставленной строки */
    public function getInsertReturningClause(string $fieldName): string;

    /**
     * Извлечение ID из результата INSERT.
     * null  — диалект не поддерживает RETURNING; следует использовать PDO::lastInsertId().
     * false — RETURNING поддерживается, но результат пуст.
     * string — извлечённый ID.
     */
    public function extractLastInsertId(array|false $queryResult): string|false|null;

    /** Символ-обёртка значения в LIKE-паттерне при поиске внутри JSON-групп */
    public function getGroupFieldQuerySign(): string;

    /**
     * SQL для принудительного отключения всех соединений к БД перед её удалением.
     * null — операция не требуется для данного диалекта.
     */
    public function terminateConnectionsSql(string $dbName): ?string;

    /** SQL для проверки существования базы данных */
    public function checkDatabaseExistsSql(string $dbName): string;

    /**
     * SQL для переключения активной БД (MySQL: USE db; PostgreSQL: не требуется).
     * null — операция не требуется для данного диалекта.
     */
    public function useDatabaseSql(string $dbQuoted): ?string;

    /** SQL для проверки существования пользователя БД */
    public function checkUserExistsSql(string $user): string;

    /** SQL для создания пользователя БД */
    public function createUserSql(string $userQuoted, string $user, string $password): string;

    /** SQL для изменения пароля существующего пользователя БД */
    public function alterUserSql(string $userQuoted, string $user, string $password): string;

    /**
     * Суффикс оператора CREATE DATABASE для назначения владельца.
     * PostgreSQL: " OWNER user". MySQL: "".
     */
    public function createDatabaseOwnerSuffix(string $userQuoted): string;

    /** SQL для выдачи пользователю полных привилегий на базу данных */
    public function grantPrivilegesSql(string $dbQuoted, string $userQuoted, string $user): string;

    /**
     * SQL-запрос, выполняемый после GRANT (MySQL: FLUSH PRIVILEGES).
     * null — дополнительный запрос не требуется.
     */
    public function afterGrantSql(): ?string;

    /** DDL для создания служебной таблицы учёта миграций */
    public function createMigrationTableSql(): string;

    /** SQL для установки часового пояса соединения после выполнения миграции */
    public function setTimezoneSql(): string;

    /**
     * SQL для сортировки строк по пользовательскому порядку значений поля.
     * MySQL использует FIELD(), PostgreSQL — CASE WHEN.
     *
     * @param string $field Имя колонки (например, 'type')
     * @param string[] $values Значения в нужном порядке
     * @param string $tieBreakField Поле для вторичной сортировки (при равенстве)
     * @return array{selectExtra: string, orderBy: string}
     *                                                     selectExtra — фрагмент, добавляемый в SELECT (пустая строка, если не нужен)
     *                                                     orderBy     — полная фраза ORDER BY
     */
    public function orderByCustomValuesSql(string $field, array $values, string $tieBreakField): array;

    /** Значение checkbox-поля в БД */
    public function checkboxDbValue(bool $value): bool|int|string;

    /**
     * SQL-выражение «колонка содержит JSON-элемент» для multiselect-колонок.
     * Обёртка IFNULL/NULLIF (MySQL) или COALESCE/NULLIF (PostgreSQL) гарантирует,
     * что NULL и пустая строка не ломают JSON-парсер.
     *
     * @param string $column Имя колонки — при необходимости уже квалифицированное (напр. "t1.tags")
     * @param string $needle Либо bind-плейсхолдер (":name"), либо SQL-литерал с JSON внутри
     * @param bool $negate Если true — вернуть выражение «не содержит»
     */
    public function jsonContainsExpression(string $column, string $needle, bool $negate = false): string;

    /** SQL-выражение, возвращающее первый элемент JSON-массива для LEFT JOIN при сортировке */
    public function jsonLeftJoinFirstElement(string $fieldName): string;
}
