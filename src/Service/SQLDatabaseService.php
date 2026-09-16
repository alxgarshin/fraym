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

namespace Fraym\Service;

use Fraym\DatabaseDialect\{MySQLDialect, PostgreSQLDialect};
use Fraym\Enum\{DbTypeEnum, OperandEnum};
use Fraym\Exception\{DatabaseConnectionException, DatabaseQueryException};
use Fraym\Helper\{DataHelper, LocaleHelper, MultiselectSqlHelper};
use Fraym\Interface\{Database, DatabaseDialect};
use Generator;
use PDO;
use PDOException;
use PDOStatement;

final class SQLDatabaseService implements Database
{
    public DbTypeEnum $dbType;

    /** Dialect of the current DBMS — encapsulates all engine-specific SQL constructs */
    public readonly DatabaseDialect $dialect;

    private PDO $DB;

    private array $lastQuery = [
        'stmt' => null,
        'query' => '',
        'data' => [],
        'result' => null,
        'fromAndWhere' => null,
    ];

    private array $preparedQueriesCache = [];

    /** Connection constructor */
    private function __construct()
    {
        $this->dbType = DbTypeEnum::init();

        $this->dialect = match ($this->dbType) {
            DbTypeEnum::MYSQL      => new MySQLDialect(),
            DbTypeEnum::POSTGRESQL => new PostgreSQLDialect(),
        };

        try {
            $this->DB = new PDO(
                $_ENV['DATABASE_TYPE'] . ':' .
                    ($_ENV['DATABASE_NAME'] !== '' ? 'dbname=' . $_ENV['DATABASE_NAME'] . ';' : '') .
                    'host=' . $_ENV['DATABASE_HOST'] .
                    ';port=' . $_ENV['DATABASE_PORT'] .
                    $this->dialect->getDsnOptions(),
                $_ENV['DATABASE_USER'],
                $_ENV['DATABASE_PASSWORD'],
            );
        } catch (PDOException $e) {
            throw new DatabaseConnectionException(
                "PDO initialize error: " . $e->getMessage(),
                (int) $e->getCode(),
                $e,
            );
        }

        $this->DB->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $this->DB->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /** Create or get the connection into a constant. Default: DB */
    public static function getInstance(string $constName = 'DB'): self
    {
        if (defined($constName)) {
            return constant($constName);
        } else {
            return self::forceCreate();
        }
    }

    /** Forced connection creation. Recommended only when you need to handle two connections at once. */
    public static function forceCreate(): self
    {
        return new self();
    }

    /** Execute a query
     *
     * @param array<int, array{0: string, 1: mixed, 2?: ?array}> $data
     */
    public function query(
        ?string $query,
        array $data,
        bool $oneResult = false,
    ): false|array {
        if (!is_null($query)) {
            $preparedData = [];

            foreach ($data as $i => $dataBlock) {
                $key = $dataBlock[0];
                $value = $dataBlock[1] ?? null;
                $fieldParams = $dataBlock[2] ?? [];

                if (preg_match('#:' . $key . '\b#', $query)) {
                    if (is_array($value)) {
                        if (in_array(OperandEnum::JSON, $fieldParams)) {
                            $preparedData[$key] = DataHelper::filterInput($value, $fieldParams);
                        } else {
                            $in = "";
                            $i = 0;

                            foreach ($value as $item) {
                                $keyForIn = $key . $i++;
                                $in .= ($in ? ", " : "") . ':' . $keyForIn;
                                $preparedData[$keyForIn] = DataHelper::filterInput($item, $fieldParams);
                            }
                            $query = str_replace(':' . $key, $in, $query);
                        }
                    } else {
                        $preparedData[$key] = DataHelper::filterInput($value, $fieldParams);
                    }
                } else {
                    unset($data[$i]);
                }
            }

            $stmt = $this->prepare($query);

            $this->execute($stmt, $preparedData);

            $result = $oneResult ? $stmt->fetch() : $stmt->fetchAll();

            $this->lastQuery = [
                'stmt' => $stmt,
                'query' => $query,
                'data' => $preparedData,
                'result' => $result,
            ];

            return $result;
        }

        return [];
    }

    /** Get the id of the last inserted record */
    public function lastInsertId(?string $name = null): string|false
    {
        $id = $this->dialect->extractLastInsertId($this->lastQuery['result'] ?? false);

        /** null means: the dialect doesn't support RETURNING, use PDO::lastInsertId() */
        return $id !== null ? $id : $this->DB->lastInsertId($name);
    }

    /** Get the number of rows affected by the last database operation */
    public function rowCount(): int
    {
        $stmt = $this->lastQuery['stmt'] ?? null;

        return $stmt instanceof PDOStatement ? $stmt->rowCount() : 0;
    }

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
    ): false|array {
        $tableName = $this->dbType->quoteIdentifier($tableName);

        [$whereQuery, $params] = $this->constructWhere($criteria);

        $orderQuery = "";

        if (!is_null($order)) {
            $orderQuery = " ORDER BY " . implode(", ", array_filter($order));
        }

        $limitQuery = "";

        if (!is_null($limit)) {
            $limitQuery = " LIMIT " . $limit;
        }

        $offsetQuery = "";

        if (!is_null($offset)) {
            $offsetQuery = " OFFSET " . $offset;
        }

        $fieldsSetQuery = "*";

        if (!is_null($fieldsSet)) {
            $fieldsSetQuery = implode(', ', $fieldsSet);
        }

        $result = $this->query(
            query: "SELECT " . ($onlyCount ? "COUNT(*)" : $fieldsSetQuery) . " FROM " . $tableName . $whereQuery . $orderQuery . $limitQuery . $offsetQuery,
            data: $params,
            oneResult: $onlyCount ? true : $oneResult,
        );

        $this->lastQuery['fromAndWhere'] = $tableName . $whereQuery;

        return $result;
    }

    /** Get the number of table objects from the last PDOStatement */
    public function selectCount(): int
    {
        if ($this->lastQuery['fromAndWhere'] ?? false) {
            $stmt = $this->prepare("SELECT COUNT(*) FROM " . $this->lastQuery['fromAndWhere']);

            $this->execute($stmt, $this->lastQuery['data']);

            return (int) $stmt->fetchColumn();
        }

        if ($this->lastQuery['query'] ?? false) {
            $query = $this->lastQuery['query'];

            if (preg_match("#SELECT#i", $query)) {
                $query = preg_replace("#SELECT (.*) FROM#i", "SELECT COUNT(*) FROM", $query);

                if (preg_match("# ORDER BY #i", $query)) {
                    $query = preg_replace("# ORDER BY (.*)$#i", "", $query);
                }

                if (preg_match("# LIMIT [\d+]#i", $query)) {
                    $query = preg_replace("# LIMIT [\d+](.*)$#i", "", $query);
                }

                if (preg_match("# OFFSET [\d+]#i", $query)) {
                    $query = preg_replace("# OFFSET [\d+](.*)$#i", "", $query);
                }

                $stmt = $this->prepare($query);

                $this->execute($stmt, $this->lastQuery['data']);

                return (int) $stmt->fetchColumn();
            }
        }

        return 0;
    }

    /** Get the number of table objects with a simple count in select */
    public function count(
        string $tableName,
        ?array $criteria = null,
    ): int {
        return $this->select(
            tableName: $tableName,
            criteria: $criteria,
            oneResult: false,
            order: null,
            limit: null,
            offset: null,
            onlyCount: true,
        )[0];
    }

    /** Insert data into a table */
    public function insert(
        string $tableName,
        array $data,
        string $returningIdFieldName = 'id',
    ): false|array {
        if (count($data) > 0) {
            $tableName = $this->dbType->quoteIdentifier($tableName);

            $params = [];
            $keys = [];

            foreach ($data as $key => $value) {
                if (is_array($value)) {
                    $params[] = [$value[0], $value[1], $value[2] ?? null];
                    $keys[] = $value[0];
                } else {
                    $params[] = [$key, $value];
                    $keys[] = $key;
                }
            }

            $paramsListSql = implode(", ", $keys);
            $dataSql = ":" . implode(", :", $keys);
            $query = "INSERT INTO " . $tableName . " (" . $paramsListSql . ") VALUES (" . $dataSql . ")" .
                $this->dialect->getInsertReturningClause($returningIdFieldName);

            return $this->query(
                query: $query,
                data: $params,
            );
        }

        return [];
    }

    /** Update data in a table */
    public function update(
        string $tableName,
        array $data,
        array $criteria,
    ): false|array {
        if (count($data) > 0) {
            $tableName = $this->dbType->quoteIdentifier($tableName);

            $params = [];
            $keys = [];

            foreach ($data as $key => $value) {
                if (is_array($value)) {
                    $params[] = [$value[0], $value[1], $value[2] ?? null];
                    $keys[] = $value[0] . "=:" . $value[0];
                } else {
                    $params[] = [$key, $value];
                    $keys[] = $key . "=:" . $key;
                }
            }

            $dataSql = implode(", ", $keys);

            [$whereQuery, $criteria] = $this->constructWhere($criteria);
            $params = array_merge($params, $criteria);

            $query = "UPDATE " . $tableName . " SET " . $dataSql . $whereQuery;

            return $this->query(
                query: $query,
                data: $params,
            );
        }

        return [];
    }

    /** Delete from a table */
    public function delete(
        string $tableName,
        array $criteria,
    ): false|array {
        if (count($criteria) > 0) {
            $tableName = $this->dbType->quoteIdentifier($tableName);

            [$whereQuery, $params] = $this->constructWhere($criteria);

            /** @noinspection SqlWithoutWhere */
            $query = "DELETE FROM " . $tableName . $whereQuery;

            return $this->query(
                query: $query,
                data: $params,
            );
        }

        return [];
    }

    /** Execute a data script without additional filtering: use with extreme caution */
    public function exec(string $SQL): true
    {
        $this->DB->exec($SQL);

        return true;
    }

    /** Begin a transaction */
    public function beginTransaction(): bool
    {
        return $this->DB->beginTransaction();
    }

    /** Commit the transaction */
    public function commit(): bool
    {
        return $this->DB->commit();
    }

    /** Roll back the transaction */
    public function rollBack(): bool
    {
        return $this->DB->rollBack();
    }

    /** Get an object by its id */
    public function findObjectById(
        string|int $objId,
        string $objType,
        bool $refresh = false,
        bool $bySid = false,
    ): ?array {
        $objType = DataHelper::clearBraces($objType);

        if ($objId) {
            $useTableColumn = $bySid ? 'sid' : 'id';

            if (!$refresh) {
                $checkData = CACHE->getFromCache($objType, $objId, $useTableColumn);

                if (!is_null($checkData)) {
                    return $checkData;
                }
            }

            $objData = $this->select(
                tableName: $objType,
                criteria: [
                    $useTableColumn => $objId,
                ],
                oneResult: true,
            );

            if ($objData === false) {
                $objData = null;
            }

            if ($objData['id'] ?? false) {
                CACHE->setToCache($objType, $objData['id'], $objData);

                if ($bySid) {
                    CACHE->setToCache($objType, $objData['sid'], $objData, $useTableColumn);
                }
            }

            return $objData;
        }

        return null;
    }

    /** Get objects by their ids */
    public function findObjectsByIds(
        array $objIds,
        string $objType,
        bool $refresh = false,
    ): ?Generator {
        $objType = DataHelper::clearBraces($objType);

        if (count($objIds) > 0) {
            if (!$refresh) {
                foreach ($objIds as $key => $objId) {
                    $checkData = CACHE->getFromCache($objType, $objId);

                    if (!is_null($checkData)) {
                        yield $checkData;
                        unset($objIds[$key]);
                    }
                }
            }

            if (count($objIds) > 0) {
                $result = $this->select(
                    tableName: $objType,
                    criteria: [
                        'id' => $objIds,
                    ],
                );

                foreach ($result as $objData) {
                    CACHE->setToCache($objType, $objData['id'], $objData);
                    yield $objData;
                }
            }
        }

        return null;
    }

    /** Turn a generator of data mappings from the DB (e.g. id to name) into an array */
    public function getArrayOfItemsAsArray(
        string $query,
        string $id,
        string|array|null $fields = null,
        bool $nodata = true,
    ): array {
        return iterator_to_array($this->getArrayOfItems($query, $id, $fields, $nodata));
    }

    /** Create an array of data mappings from the DB (e.g. id to name) */
    public function getArrayOfItems(
        string $query,
        string $id,
        string|array|null $fields = null,
        bool $nodata = true,
    ): Generator {
        $level = 0;

        $data = $this->query(
            query: "SELECT * FROM " . $query,
            data: [],
        );

        foreach ($data as $item) {
            $key = DataHelper::checkNumeric($item[$id]);

            if (is_null($fields)) {
                $value = [$key];
            } elseif (!is_array($fields)) {
                $value = [
                    $key,
                    DataHelper::checkNumeric($item[$fields]),
                    $level,
                    ($nodata ? null : $item),
                ];
            } else {
                $whicher = [];

                foreach ($fields as $field) {
                    $whicher[] = (isset($item[$field]) ? DataHelper::checkNumeric($item[$field]) : $field);
                }

                $value = [
                    $key,
                    implode(' ', $whicher),
                    $level,
                    ($nodata ? null : $item),
                ];
            }

            yield $key => $value;
        }
    }

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
    ): array {
        $LOCALE = LocaleHelper::getLocale(['fraym', 'basefunc']);

        $objectsTree = [];

        if ($empty) {
            $objectsTree[] = [null, $LOCALE['top_level'], 0];
        }

        /** Protection against an empty IN without identifiers that came in and */
        if (mb_stripos($and, 'IN ()') === false) {
            //if there is a limit, take it away so the results can be filtered manually later
            $limitFrom = false;
            $limitCount = false;

            if (str_contains($order, 'LIMIT')) {
                preg_match('#LIMIT\s*(\d*)\s*OFFSET\s*(\d*)#', $order, $match);

                if (isset($match[1])) {
                    $limitFrom = (int) $match[2];
                } else {
                    preg_match('#LIMIT\s*(\d*)#', $order, $match);
                    $limitFrom = 0;
                }
                $limitCount = (int) $match[1];
                $order = preg_replace('# LIMIT\s*\d*\s*OFFSET\s*\d*#', '', $order);
                $order = preg_replace('# LIMIT\s*\d*#', '', $order);
            }

            $and = $and !== '' ? " WHERE " . (str_starts_with($and, ' AND ') ? preg_replace('# AND #', '', $and, 1) : $and) : "";

            $clearedTableName = str_replace(' AS t1', '', $table);

            $query = "SELECT " . (str_contains($table, " AS t1") ? "t1." : "") . "* FROM " . $this->dbType->quoteIdentifier($clearedTableName) . (str_contains($table, " AS t1") ? " AS t1" : "") . $and . " ORDER BY " . $order;

            $fullDataArray = [];

            $data = $this->query(
                query: $query,
                data: $andQueryParams,
            );

            foreach ($data as $item) {
                if ($item[$id] === '') {
                    $item[$id] = $LOCALE['not_set'];
                }

                if ($item[$fieldName] === '') {
                    $item[$fieldName] = $LOCALE['not_set'];
                }
                $fullDataArray[] = $item;
            }

            /** Collect the top-level data */
            $mainDataFound = 0;

            foreach ($fullDataArray as $key => $fullData) {
                if ($fullData[$where] === $whereequal) {
                    if (
                        /** @phpstan-ignore-next-line */
                        ($limitFrom === false && $limitCount === false) ||
                        /** @phpstan-ignore-next-line */
                        ($limitFrom !== false && $limitCount !== false && $mainDataFound >= $limitFrom && $mainDataFound < $limitFrom + $limitCount)
                    ) {
                        $objectsTree[] = [
                            DataHelper::checkNumeric($fullData[$id]),
                            DataHelper::checkNumeric($fullData[$fieldName]),
                            $level,
                            ($nodata ? null : $fullData),
                        ];
                    }
                    $mainDataFound++;
                    unset($fullDataArray[$key]);
                }
            }

            $level++;

            /** Children index by the parent's (type+value): keeps the strict === matching,
             *  but avoids a full pass over fullDataArray for every parent on every level. */
            $childrenByParent = [];

            foreach ($fullDataArray as $fullData) {
                $childrenByParent[gettype($fullData[$where]) . ':' . $fullData[$where]][] = $fullData;
            }

            /** Build the required tree */
            while ($level <= $maxlevel) {
                $noObjectsFoundOnLevel = true;

                $insertedRows = 0;

                foreach ($objectsTree as $parentKey => $parentData) {
                    if ($parentData[2] === $level - 1) {
                        $parentTag = gettype($parentData[0]) . ':' . $parentData[0];
                        $insertData = [];

                        foreach ($childrenByParent[$parentTag] ?? [] as $fullData) {
                            $insertData[] = [
                                DataHelper::checkNumeric($fullData[$id]),
                                DataHelper::checkNumeric($fullData[$fieldName]),
                                $level,
                                ($nodata ? null : $fullData),
                            ];
                            $noObjectsFoundOnLevel = false;
                        }

                        unset($childrenByParent[$parentTag]);

                        $objectsTree = array_merge(
                            array_slice($objectsTree, 0, (int) $parentKey + 1 + $insertedRows),
                            $insertData,
                            array_slice($objectsTree, (int) $parentKey + 1 + $insertedRows),
                        );

                        $insertedRows += count($insertData);
                    }
                }

                if ($noObjectsFoundOnLevel) {
                    break;
                } else {
                    $level++;
                }
            }
        }

        return $objectsTree;
    }

    /** Remove objects from the built tree so that only the selected ids and their parents up to the top level remain. For catalog entities their descendants are kept as well. */
    public function chopOffTreeOfItemsBranches(
        array $objectsTree,
        array $listOfIds,
        string $fieldWithParentId,
    ): array {
        $keepItemsIds = [];

        /** Index of the first occurrence of an id (type+value — keeps the strict ===) instead of a nested
         *  pass over the whole tree for every searched id. */
        $treeKeyById = [];

        foreach ($objectsTree as $key => $objectsTreeItem) {
            $treeKeyById[gettype($objectsTreeItem[0]) . ':' . $objectsTreeItem[0]] ??= $key;
        }

        foreach ($listOfIds as $idToFind) {
            $key = $treeKeyById[gettype($idToFind) . ':' . $idToFind] ?? null;

            if ($key === null) {
                continue;
            }

            $objectsTreeItem = $objectsTree[$key];
            $keepItemsIds[] = $idToFind;

            /** Find all descendants */
            $childKey = $key;
            $lookingForChilds = true;

            while ($lookingForChilds) {
                $childKey++;

                if (($objectsTree[$childKey] ?? false) && $objectsTree[$childKey][2] > $objectsTreeItem[2]) {
                    $keepItemsIds[] = $objectsTree[$childKey][0];
                } else {
                    $lookingForChilds = false;
                }
            }

            /** Find all parents */
            $parentId = $objectsTreeItem[3][$fieldWithParentId];
            $keepItemsIds[] = $parentId;
            $parentKey = $key;

            while ($parentId) {
                $parentKey--;

                if ($objectsTree[$parentKey] ?? false) {
                    $previousItemData = $objectsTree[$parentKey];

                    if ($parentId === $previousItemData[0]) {
                        $parentId = $previousItemData[3][$fieldWithParentId] ?? null;
                        $keepItemsIds[] = $parentId;
                    }
                } else {
                    break;
                }
            }
        }
        $keepItemsIds = array_unique($keepItemsIds);

        /** Delete all objects for which no relation was found */
        foreach ($objectsTree as $key => $objectsTreeItem) {
            if (!in_array($objectsTreeItem[0], $keepItemsIds)) {
                unset($objectsTree[$key]);
            }
        }

        return $objectsTree;
    }

    /** Prepare the query */
    private function prepare(string $query): PDOStatement|bool
    {
        $queryHash = DataHelper::hashData($query);

        if (isset($this->preparedQueriesCache[$queryHash])) {
            return $this->preparedQueriesCache[$queryHash];
        }

        try {
            $this->preparedQueriesCache[$queryHash] = $this->DB->prepare($query);

            return $this->preparedQueriesCache[$queryHash];
        } catch (PDOException $e) {
            throw new DatabaseQueryException(
                'PDO prepare error: ' . $query . ' | Error: ' . $e->getMessage(),
                code: (int) $e->getCode(),
                previous: $e,
            );
        }
    }

    /** Build the where part of the query
     * @return array{0: string, 1: array}
     */
    private function constructWhere(?array $criteria): array
    {
        $whereQuery = "";
        $whereParams = [];

        if ($criteria !== null) {
            $nameUsedInVariablesCount = [];

            foreach ($criteria as $key => $value) {
                if (!is_null($value) && (!is_int($key) || !is_null($value[1] ?? null))) {
                    $useNoValue = false;
                    $equalSign = $this->dialect->getNullSafeEqualOperator();
                    $dbColumn = (is_array($value) && is_int($key)) ? $value[0] : $key;

                    if (is_array($value) && is_int($key) && !is_null($value[2] ?? null)) {
                        if (!is_array($value[2])) {
                            $value[2] = [$value[2]];
                        }

                        if (in_array(OperandEnum::JSON_CONTAINS, $value[2]) || in_array(OperandEnum::JSON_NOT_CONTAINS, $value[2])) {
                            $negate = in_array(OperandEnum::JSON_NOT_CONTAINS, $value[2]);

                            if (($nameUsedInVariablesCount[$value[0]] ?? false) === false) {
                                $nameUsedInVariablesCount[$value[0]] = -1;
                            }
                            $nameUsedInVariablesCount[$value[0]]++;
                            $paramName = $value[0] . "_" . $nameUsedInVariablesCount[$value[0]];

                            $expr = $this->dialect->jsonContainsExpression($dbColumn, ":" . $paramName, $negate);
                            $whereQuery .= ($whereQuery !== "" ? " AND " : "") . $expr;
                            $whereParams[] = [$paramName, MultiselectSqlHelper::bindValue($value[1])];

                            continue;
                        }

                        if (in_array(OperandEnum::LIKE, $value[2])) {
                            $equalSign = " LIKE ";
                        } elseif (in_array(OperandEnum::NOT_LIKE, $value[2])) {
                            $equalSign = " NOT LIKE ";
                        } elseif (in_array(OperandEnum::LESS, $value[2])) {
                            $equalSign = "<";
                        } elseif (in_array(OperandEnum::MORE, $value[2])) {
                            $equalSign = ">";
                        } elseif (in_array(OperandEnum::LESS_OR_EQUAL, $value[2])) {
                            $equalSign = "<=";
                        } elseif (in_array(OperandEnum::MORE_OR_EQUAL, $value[2])) {
                            $equalSign = ">=";
                        } elseif (in_array(OperandEnum::NOT_EQUAL, $value[2])) {
                            $equalSign = "!=";
                        } elseif (in_array(OperandEnum::IS_NULL, $value[2])) {
                            $equalSign = " IS NULL";
                            $useNoValue = true;
                        } elseif (in_array(OperandEnum::NOT_NULL, $value[2])) {
                            $equalSign = " IS NOT NULL";
                            $useNoValue = true;
                        }

                        if (in_array(OperandEnum::LOWER, $value[2])) {
                            $dbColumn = "LOWER(" . $dbColumn . ")";
                        } elseif (in_array(OperandEnum::UPPER, $value[2])) {
                            $dbColumn = "UPPER(" . $dbColumn . ")";
                        }
                    } elseif (is_array($value) && ((($value[1] ?? false) && is_array($value[1])) || !is_int($key))) {
                        $equalSign = " IN (";
                    }

                    if (is_array($value) && is_int($key)) {
                        if (($nameUsedInVariablesCount[$value[0]] ?? false) === false) {
                            $nameUsedInVariablesCount[$value[0]] = -1;
                        }
                        $nameUsedInVariablesCount[$value[0]]++;
                        $value[0] .= "_" . $nameUsedInVariablesCount[$value[0]];

                        $whereQuery .= ($whereQuery !== "" ? " AND " : "") . $dbColumn . $equalSign . ($useNoValue ? "" : ":" . $value[0]);
                        $whereParams[] = $value;
                    } else {
                        if (($nameUsedInVariablesCount[$key] ?? false) === false) {
                            $nameUsedInVariablesCount[$key] = -1;
                        }
                        $nameUsedInVariablesCount[$key]++;
                        $key .= "_" . $nameUsedInVariablesCount[$key];

                        $whereQuery .= ($whereQuery !== "" ? " AND " : "") . $dbColumn . $equalSign . ($useNoValue ? "" : ":" . $key);
                        $whereParams[] = [$key, $value];
                    }
                    $whereQuery .= ($equalSign === " IN (" ? ")" : "");
                }
            }

            if ($whereQuery !== "") {
                $whereQuery = " WHERE " . $whereQuery;
            }
        }

        return [$whereQuery, $whereParams];
    }

    /** Execute the PDO query */
    private function execute(PDOStatement $statement, array $preparedData): void
    {
        try {
            foreach ($preparedData as $key => $value) {
                $paramKey = is_int($key) ? $key + 1 : $key;

                if (is_null($value)) {
                    $type = PDO::PARAM_NULL;
                } elseif (is_bool($value)) {
                    $type = PDO::PARAM_BOOL;
                } elseif (is_int($value)) {
                    $type = PDO::PARAM_INT;
                } else {
                    $type = PDO::PARAM_STR;
                }

                $statement->bindValue($paramKey, $value, $type);
            }

            $statement->execute();
        } catch (PDOException $e) {
            $this->lastQuery = [];

            throw new DatabaseQueryException(
                'PDO execute error: ' . $statement->queryString . ' | Error: ' . $e->getMessage(),
                parameters: $preparedData,
                code: (int) $e->getCode(),
                previous: $e,
            );
        }
    }
}
