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

use Generator;

interface Database
{
    /** Исполнение запроса
     *
     * @param array<int, array{0: string, 1: mixed, 2: ?array}> $data
     *
     * @return false|array<int, array>|array<string|int>
     */
    public function query(
        ?string $query,
        array $data,
        bool $oneResult = false,
    ): false|array;

    /** Получение id последней добавленной записи */
    public function lastInsertId(?string $name = null): string|false;

    /** Получение количества объектов таблицы из последнего PDOStatement'а */
    public function selectCount(): int;

    /** Получение количества объектов таблицы простым count в select'e */
    public function count(
        string $tableName,
        ?array $criteria = null,
    ): int;

    /** Получение данных из таблицы */
    public function select(
        string $tableName,
        ?array $criteria = null,
        bool $oneResult = false,
        ?array $order = null,
        ?int $limit = null,
        ?int $offset = null,
        bool $onlyCount = false,
        ?array $fieldsSet = null,
    ): false|array;

    /** Вставка данных в таблицу */
    public function insert(
        string $tableName,
        array $data,
        string $returningIdFieldName = 'id',
    ): false|array;

    /** Изменение данных в таблице */
    public function update(
        string $tableName,
        array $data,
        array $criteria,
    ): false|array;

    /** Удаление из таблицы */
    public function delete(
        string $tableName,
        array $criteria,
    ): false|array;

    /** Исполнение скрипта данных без дополнительной фильтрации: использовать крайне осторожно */
    public function exec(string $SQL): true;

    /** Получение количества строк, задетых последней операцией в базе данных */
    public function rowCount(): int;

    public function beginTransaction(): bool;

    /** Коммит транзакции */
    public function commit(): bool;

    /** Откат транзакции */
    public function rollBack(): bool;

    /** Получение объекта на основе его id */
    public function findObjectById(
        string|int $objId,
        string $objType,
        bool $refresh = false,
        bool $bySid = false,
    ): ?array;

    /** Получение объектов на основе их id */
    public function findObjectsByIds(
        array $objIds,
        string $objType,
        bool $refresh = false,
    ): ?Generator;

    /** Превращение в массив генератора соотношений данных из БД (id к name, например) */
    public function getArrayOfItemsAsArray(
        string $query,
        string $id,
        string|array|null $fields = null,
        bool $nodata = true,
    ): array;

    /** Создание массива соотношений данных из БД (id к name, например) */
    public function getArrayOfItems(
        string $query,
        string $id,
        string|array|null $fields = null,
        bool $nodata = true,
    ): Generator;

    /** Создание дерева объектов из БД на основе родительского идентификатора */
    public function getTreeOfItems(
        bool $empty,
        string $table,
        string $where,
        string|int|null $whereequal,
        ?string $and,
        ?string $order,
        int $level,
        string $id,
        string $fieldName,
        int $maxlevel,
        bool $nodata = true,
        array $andQueryParams = [],
    ): array;

    /** Удаление объектов из созданного дерева, чтобы остались только отобранные id и их parent'ы до верхнего уровня. При этом у каталоговых сущностей
     * оставляем их наследников также. */
    public function chopOffTreeOfItemsBranches(
        array $objectsTree,
        array $listOfIds,
        string $fieldWithParentId,
    ): array;
}
