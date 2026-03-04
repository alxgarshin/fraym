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

namespace Fraym\Proxy;

use Fraym\Container;
use Fraym\Interface\Database;
use Generator;

/**
 * Прокси-объект для константы DB.
 * Делегирует все вызовы в Container::make('db'), что позволяет
 * подменять реализацию (тесты, persistent workers) без изменения кода.
 */
final class DatabaseProxy implements Database
{
    /** Проброс обращений к публичным свойствам (dialect, dbType и др.) */
    public function __get(string $name): mixed
    {
        return Container::make('db')->$name;
    }

    public function query(
        ?string $query,
        array $data,
        bool $oneResult = false,
    ): false|array {
        return Container::make('db')->query($query, $data, $oneResult);
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return Container::make('db')->lastInsertId($name);
    }

    public function selectCount(): int
    {
        return Container::make('db')->selectCount();
    }

    public function count(
        string $tableName,
        ?array $criteria = null,
    ): int {
        return Container::make('db')->count($tableName, $criteria);
    }

    public function select(
        string $tableName,
        ?array $criteria = null,
        bool $oneResult = false,
        ?array $order = null,
        ?int $limit = null,
        ?int $offset = null,
        bool $onlyCount = false,
        ?array $fieldsSet = null,
    ): false|array {
        return Container::make('db')->select($tableName, $criteria, $oneResult, $order, $limit, $offset, $onlyCount, $fieldsSet);
    }

    public function insert(
        string $tableName,
        array $data,
        string $returningIdFieldName = 'id',
    ): false|array {
        return Container::make('db')->insert($tableName, $data, $returningIdFieldName);
    }

    public function update(
        string $tableName,
        array $data,
        array $criteria,
    ): false|array {
        return Container::make('db')->update($tableName, $data, $criteria);
    }

    public function delete(
        string $tableName,
        array $criteria,
    ): false|array {
        return Container::make('db')->delete($tableName, $criteria);
    }

    public function exec(string $SQL): true
    {
        return Container::make('db')->exec($SQL);
    }

    public function rowCount(): int
    {
        return Container::make('db')->rowCount();
    }

    public function beginTransaction(): bool
    {
        return Container::make('db')->beginTransaction();
    }

    public function commit(): bool
    {
        return Container::make('db')->commit();
    }

    public function rollBack(): bool
    {
        return Container::make('db')->rollBack();
    }

    public function findObjectById(
        string|int $objId,
        string $objType,
        bool $refresh = false,
        bool $bySid = false,
    ): ?array {
        return Container::make('db')->findObjectById($objId, $objType, $refresh, $bySid);
    }

    public function findObjectsByIds(
        array $objIds,
        string $objType,
        bool $refresh = false,
    ): ?Generator {
        return Container::make('db')->findObjectsByIds($objIds, $objType, $refresh);
    }

    public function getArrayOfItemsAsArray(
        string $query,
        string $id,
        string|array|null $fields = null,
        bool $nodata = true,
    ): array {
        return Container::make('db')->getArrayOfItemsAsArray($query, $id, $fields, $nodata);
    }

    public function getArrayOfItems(
        string $query,
        string $id,
        string|array|null $fields = null,
        bool $nodata = true,
    ): Generator {
        return Container::make('db')->getArrayOfItems($query, $id, $fields, $nodata);
    }

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
    ): array {
        return Container::make('db')->getTreeOfItems($empty, $table, $where, $whereequal, $and, $order, $level, $id, $fieldName, $maxlevel, $nodata, $andQueryParams);
    }

    public function chopOffTreeOfItemsBranches(
        array $objectsTree,
        array $listOfIds,
        string $fieldWithParentId,
    ): array {
        return Container::make('db')->chopOffTreeOfItemsBranches($objectsTree, $listOfIds, $fieldWithParentId);
    }
}
