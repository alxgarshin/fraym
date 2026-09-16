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

namespace Fraym\Helper;

use Fraym\Interface\Helper;

/**
 * Helper for multiselect columns that store a JSON array of values
 * (e.g. '["group1","all2",1,2]'). Delegates SQL generation to DB->dialect,
 * so that the expression works correctly for both MySQL and PostgreSQL.
 */
abstract class MultiselectSqlHelper implements Helper
{
    /**
     * SQL expression "column contains the value" for multiselect JSON columns.
     *
     * $needle — either a bind placeholder (":bind_name") or an SQL literal with JSON
     * inside (see jsonLiteral()).
     *
     * Example:
     *   MultiselectSqlHelper::contains('project_group_ids', ':group_id')
     *     => MySQL:      JSON_CONTAINS(IFNULL(NULLIF(project_group_ids, ''), '[]'), :group_id)
     *     => PostgreSQL: COALESCE(NULLIF(project_group_ids, ''), '[]')::jsonb @> :group_id::jsonb
     */
    public static function contains(string $column, string $needle): string
    {
        return DB->dialect->jsonContainsExpression($column, $needle, negate: false);
    }

    /** SQL expression "column does NOT contain the value" for multiselect JSON columns */
    public static function notContains(string $column, string $needle): string
    {
        return DB->dialect->jsonContainsExpression($column, $needle, negate: true);
    }

    /**
     * PHP value → SQL literal containing valid JSON.
     * Suitable for direct substitution as the second argument of contains()/notContains(),
     * when no bind parameter is used.
     *
     *   jsonLiteral(5)        => "'5'"
     *   jsonLiteral('group1') => "'\"group1\"'"
     *
     * For user input, a bind parameter via contains(':name') is preferred.
     */
    public static function jsonLiteral(int|string $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE);

        return "'" . str_replace("'", "''", $json) . "'";
    }

    /**
     * PHP value → string for binding in a prepared statement.
     *   bindValue(5)        => '5'
     *   bindValue('group1') => '"group1"'
     */
    public static function bindValue(int|string $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }
}
