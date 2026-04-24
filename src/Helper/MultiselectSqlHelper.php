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
 * Хелпер для работы с multiselect-колонками, хранящими JSON-массив значений
 * (например, '["group1","all2",1,2]'). Делегирует SQL-генерацию в DB->dialect,
 * чтобы выражение корректно работало и для MySQL, и для PostgreSQL.
 */
abstract class MultiselectSqlHelper implements Helper
{
    /**
     * SQL-выражение «колонка содержит значение» для multiselect JSON-колонок.
     *
     * $needle — либо bind-плейсхолдер (":bind_name"), либо SQL-литерал с JSON
     * внутри (см. jsonLiteral()).
     *
     * Пример:
     *   MultiselectSqlHelper::contains('project_group_ids', ':group_id')
     *     => MySQL:      JSON_CONTAINS(IFNULL(NULLIF(project_group_ids, ''), '[]'), :group_id)
     *     => PostgreSQL: COALESCE(NULLIF(project_group_ids, ''), '[]')::jsonb @> :group_id::jsonb
     */
    public static function contains(string $column, string $needle): string
    {
        return DB->dialect->jsonContainsExpression($column, $needle, negate: false);
    }

    /** SQL-выражение «колонка НЕ содержит значение» для multiselect JSON-колонок */
    public static function notContains(string $column, string $needle): string
    {
        return DB->dialect->jsonContainsExpression($column, $needle, negate: true);
    }

    /**
     * PHP-значение → SQL-литерал, внутри которого лежит валидный JSON.
     * Подходит для прямой подстановки вторым аргументом в contains()/notContains(),
     * когда bind-параметр не используется.
     *
     *   jsonLiteral(5)        => "'5'"
     *   jsonLiteral('group1') => "'\"group1\"'"
     *
     * Для пользовательского ввода предпочтительнее bind-параметр через contains(':name').
     */
    public static function jsonLiteral(int|string $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE);

        return "'" . str_replace("'", "''", $json) . "'";
    }

    /**
     * PHP-значение → строка для bind'а в prepared statement.
     *   bindValue(5)        => '5'
     *   bindValue('group1') => '"group1"'
     */
    public static function bindValue(int|string $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }
}
