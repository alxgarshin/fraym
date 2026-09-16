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
    /** Execute a query
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

    /** Get the id of the last inserted record */
    public function lastInsertId(?string $name = null): string|false;

    /** Get the number of table objects from the last PDOStatement */
    public function selectCount(): int;

    /** Get the number of table objects with a simple count in select */
    public function count(
        string $tableName,
        ?array $criteria = null,
    ): int;

    /** Get data from a table */
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

    /** Insert data into a table */
    public function insert(
        string $tableName,
        array $data,
        string $returningIdFieldName = 'id',
    ): false|array;

    /** Update data in a table */
    public function update(
        string $tableName,
        array $data,
        array $criteria,
    ): false|array;

    /** Delete from a table */
    public function delete(
        string $tableName,
        array $criteria,
    ): false|array;

    /** Execute a data script without additional filtering: use with extreme caution */
    public function exec(string $SQL): true;

    /** Get the number of rows affected by the last database operation */
    public function rowCount(): int;

    public function beginTransaction(): bool;

    /** Commit the transaction */
    public function commit(): bool;

    /** Roll back the transaction */
    public function rollBack(): bool;

    /** Get an object by its id */
    public function findObjectById(
        string|int $objId,
        string $objType,
        bool $refresh = false,
        bool $bySid = false,
    ): ?array;

    /** Get objects by their ids */
    public function findObjectsByIds(
        array $objIds,
        string $objType,
        bool $refresh = false,
    ): ?Generator;

    /** Turn a generator of data mappings from the DB (e.g. id to name) into an array */
    public function getArrayOfItemsAsArray(
        string $query,
        string $id,
        string|array|null $fields = null,
        bool $nodata = true,
    ): array;

    /** Create an array of data mappings from the DB (e.g. id to name) */
    public function getArrayOfItems(
        string $query,
        string $id,
        string|array|null $fields = null,
        bool $nodata = true,
    ): Generator;

    /** Build an object tree from the DB based on the parent identifier */
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

    /** Remove objects from the built tree so that only the selected ids and their parents up to the top level remain. For catalog entities
     * their descendants are kept as well. */
    public function chopOffTreeOfItemsBranches(
        array $objectsTree,
        array $listOfIds,
        string $fieldWithParentId,
    ): array;
}
