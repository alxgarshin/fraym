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

abstract class RightsHelper implements Helper
{
    /** Get the BANNED_TYPES array */
    public static function getBannedTypes(): array
    {
        return $_ENV['BANNED_TYPES'];
    }

    /** Find an object id based on rights data */
    public static function findOneByRights(
        string|array|null $type,
        string $obj_type_to,
        int|string|null $obj_id_to = null,
        string $obj_type_from = '{user}',
        int|string|false|null $obj_id_from = null, //false is needed to search across all users, not just the current one, when $obj_type_from = '{user}'
        ?string $comment = null,
    ): int|string|null {
        $data = self::findByRights(
            $type,
            $obj_type_to,
            $obj_id_to,
            $obj_type_from,
            $obj_id_from,
            1,
            $comment,
        );

        return $data;
    }

    /** Find object ids based on rights data */
    public static function findByRights(
        string|array|null $type,
        string $obj_type_to,
        int|string|null $obj_id_to = null,
        string $obj_type_from = '{user}',
        int|string|false|null $obj_id_from = null, //false is needed to search across all users, not just the current one, when $obj_type_from = '{user}'
        int $limit = 0,
        ?string $comment = null,
    ): array|int|null {
        $obj_type_to = DataHelper::addBraces($obj_type_to);
        $obj_type_from = DataHelper::addBraces($obj_type_from);

        if (is_null($obj_id_from) && $obj_type_from === '{user}') {
            if (is_null(CURRENT_USER->id())) {
                return null;
            }
            $obj_id_from = CURRENT_USER->id();
        }

        if (is_null($obj_id_from) && is_null($obj_id_to)) {
            return null;
        }

        $order_by = '';
        $types = [];
        $postgre_injection = '';

        if (!is_null($type)) {
            if (is_array($type)) {
                foreach ($type as $key => $value) {
                    $type[$key] = DataHelper::addBraces($value);
                }
                $types = $type;

                $tieBreakField = !is_null($obj_id_from) ? 'obj_id_from' : 'obj_id_to';
                ['selectExtra' => $postgre_injection, 'orderBy' => $order_by] =
                    DB->dialect->orderByCustomValuesSql('type', $type, $tieBreakField);
            } else {
                $type = DataHelper::addBraces($type);
                $types[] = $type;
            }
        }

        $bannedTypes = self::getBannedTypes();

        $result = DB->query(
            'SELECT DISTINCT ' . $postgre_injection . (is_null($obj_id_from) || $obj_id_from === false ? 'obj_id_from' : 'obj_id_to') . ' AS data, type FROM relation WHERE obj_type_to=:obj_type_to AND obj_type_from=:obj_type_from' .
                (count($bannedTypes) === 0 ? '' : ' AND type NOT IN (:banned_types)') .
                (!is_null($type) ? ' AND type IN (:types)' : '') .
                ' AND ' .
                (is_null($obj_id_from) || $obj_id_from === false ? 'obj_id_to=:obj_id_to' : 'obj_id_from=:obj_id_from') .
                (!empty($comment) ? ' AND comment=:comment' : '') .
                $order_by .
                ($limit > 0 ? ' LIMIT :limit' : ''),
            [
                ['obj_type_to', $obj_type_to],
                ['obj_type_from', $obj_type_from],
                ['banned_types', $bannedTypes],
                ['types', $types],
                ['obj_id_to', $obj_id_to],
                ['obj_id_from', $obj_id_from],
                ['comment', $comment],
                ['limit', $limit],
            ],
        );

        if (count($result) > 0) {
            if ($limit === 1) {
                return $result[0]['data'];
            } else {
                $cleanResult = [];

                foreach ($result as $item) {
                    $cleanResult[] = $item['data'];
                }

                return array_unique($cleanResult);
            }
        }

        return null;
    }

    /** Check for any rights / relations other than BANNED_TYPES from one object to another */
    public static function checkAnyRights(
        string $obj_type_to,
        int|string $obj_id_to,
        string $obj_type_from = '{user}',
        int|string|null $obj_id_from = null,
    ): ?bool {
        $obj_type_to = DataHelper::addBraces($obj_type_to);
        $obj_type_from = DataHelper::addBraces($obj_type_from);

        if (is_null($obj_id_from) && $obj_type_from === '{user}') {
            if (is_null(CURRENT_USER->id())) {
                return null;
            }
            $obj_id_from = CURRENT_USER->id();
        }

        $result = DB->query(
            "SELECT * FROM relation WHERE obj_type_from=:obj_type_from" . ($obj_id_from ? " AND obj_id_from=:obj_id_from" : "") . " AND type NOT IN (:types) AND obj_type_to=:obj_type_to AND obj_id_to=:obj_id_to",
            [
                ['obj_type_from', $obj_type_from],
                ['obj_id_from', $obj_id_from],
                ['types', self::getBannedTypes()],
                ['obj_type_to', $obj_type_to],
                ['obj_id_to', $obj_id_to],
            ],
        );
        $rights_found = count($result) > 0;

        if (!$rights_found && $obj_type_from === '{user}' && $obj_id_from > 0) {
            $objTypeTo = DataHelper::clearBraces($obj_type_to);
            $check_creator = DB->select(
                (in_array($objTypeTo, ['task', 'event']) ? 'task_and_event' : $objTypeTo),
                [
                    'id' => $obj_id_to,
                ],
                true,
            );
            $rights_found = $obj_id_from === $check_creator['creator_id'];
        }

        return $rights_found;
    }

    /** Check whether one object has certain rights / relations to another */
    public static function checkRights(
        string|array $type,
        string $obj_type_to,
        int|string $obj_id_to,
        string $obj_type_from = '{user}',
        int|string|null $obj_id_from = null,
    ): ?bool {
        $obj_type_to = DataHelper::addBraces($obj_type_to);
        $obj_type_from = DataHelper::addBraces($obj_type_from);

        if (!$obj_id_to) {
            return null;
        }

        if (is_null($obj_id_from) && $obj_type_from === '{user}') {
            if (is_null(CURRENT_USER->id())) {
                return null;
            }
            $obj_id_from = CURRENT_USER->id();
        }
        $types = [];

        if ($type) {
            if (is_array($type)) {
                foreach ($type as $value) {
                    $types[] = DataHelper::addBraces($value);
                }
            } else {
                $types[] = DataHelper::addBraces($type);
            }
        }

        $bannedTypes = self::getBannedTypes();

        $result = DB->query(
            "SELECT COUNT(id) FROM relation WHERE obj_type_from=:obj_type_from" . ($obj_id_from ? " AND obj_id_from=:obj_id_from" : "") . " AND obj_type_to=:obj_type_to" .
                (count($bannedTypes) === 0 ? "" : " AND type NOT IN (:banned_types)") .
                ($type ? " AND type IN (:types)" : "") .
                " AND obj_id_to=:obj_id_to",
            [
                ['obj_type_from', $obj_type_from],
                ['obj_id_from', $obj_id_from],
                ['obj_type_to', $obj_type_to],
                ['banned_types', $bannedTypes],
                ['types', $types],
                ['obj_id_to', $obj_id_to],
            ],
            true,
        );

        return is_array($result) && ($result[0] ?? 0) > 0;
    }

    /** Set a right / relation */
    public static function addRights(
        string $type,
        string $obj_type_to,
        int|string $obj_id_to,
        string $obj_type_from = '{user}',
        int|string|null $obj_id_from = null,
        ?string $comment = null,
    ): bool {
        $type = DataHelper::addBraces($type);
        $obj_type_to = DataHelper::addBraces($obj_type_to);
        $obj_type_from = DataHelper::addBraces($obj_type_from);

        if (is_null($obj_id_from)) {
            $obj_id_from = CURRENT_USER->id();
        }

        if (!self::checkRights($type, $obj_type_to, $obj_id_to, $obj_type_from, $obj_id_from)) {
            $result = DB->insert(
                'relation',
                [
                    ['creator_id', CURRENT_USER->id()],
                    ['obj_type_from', $obj_type_from],
                    ['obj_id_from', $obj_id_from],
                    ['type', $type],
                    ['obj_type_to', $obj_type_to],
                    ['obj_id_to', $obj_id_to],
                    ['comment', $comment],
                    ['created_at', DateHelper::getNow()],
                    ['updated_at', DateHelper::getNow()],
                ],
            );

            return $result !== false;
        }

        return true;
    }

    /** Delete a right / relation */
    public static function deleteRights(
        ?string $type = null,
        ?string $obj_type_to = null,
        int|string|null $obj_id_to = null,
        string $obj_type_from = '{user}',
        int|string|null $obj_id_from = null,
        string $additional_condition = '',
    ): bool {
        if (!empty($type)) {
            $type = DataHelper::addBraces($type);
        }

        if (!is_null($obj_type_to)) {
            $obj_type_to = DataHelper::addBraces($obj_type_to);
        }
        $obj_type_from = DataHelper::addBraces($obj_type_from);

        //if obj_type_from and obj_id_from aren't specified, the right/relation is deleted for the current user. If specified, for the corresponding object.
        //if type isn't specified, all rights/relations of this pair are deleted
        //if obj_type_to isn't specified, all relations of this type with all possible object combinations are deleted
        //if obj_id_to isn't specified, relations with all objects of the given type are deleted
        //if obj_id_from isn't specified, relations from all objects of the given type are deleted
        if (is_null($obj_id_from) && $obj_type_from === '{user}') {
            $obj_id_from = CURRENT_USER->id();
        }

        $result = DB->query(
            "DELETE FROM relation WHERE obj_type_from=:obj_type_from" . ($obj_id_from > 0 ? " AND obj_id_from=:obj_id_from" : "") . ($type ? " AND type=:type" : " AND type!=''") . ($obj_type_to ? " AND obj_type_to=:obj_type_to" : "") . ($obj_id_to > 0 ? " AND obj_id_to=:obj_id_to" : "") . $additional_condition,
            [
                ['obj_type_from', $obj_type_from],
                ['obj_id_from', $obj_id_from],
                ['type', $type],
                ['obj_type_to', $obj_type_to],
                ['obj_id_to', $obj_id_to],
            ],
        );

        return $result !== false;
    }
}
