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

namespace Fraym\Entity;

use Fraym\Entity\Filters\SqlCondition;

/** Access rights restriction DTO */
final class RightsRestrict
{
    public function __construct(
        /** Query string that goes into WHERE when selecting the object */
        public string $query = '',

        /** Query parameters */
        public array $params = [],

        /** Flag to get the restriction from the service function of the same name */
        public bool $serviceCheck = false,

        /** Restriction in the constructWhere format (['field' => value] / [['field', value]]).
         *  Preferred over the string query: the table alias is attached declaratively, without a regex
         *  that breaks on JOINs/subqueries. When criteria is set, the string query is ignored. */
        public array $criteria = [],
    ) {
        if ($this->query && $this->params) {
            preg_match_all('/:[a-zA-Z0-9_]+/', $this->query, $matches);

            $expectedKeys = array_map(static fn ($match) => ltrim($match, ':'), $matches[0]);

            $this->params = $this->normalizeAndCleanParams($this->params, $expectedKeys);
        }
    }

    /** WHERE fragment and parameters with the table prefix applied (e.g. 't1.').
     * @return array{0: string, 1: array} */
    public function getWhere(string $prefix = ''): array
    {
        if ($this->criteria !== []) {
            return $this->buildSqlFromCriteria($prefix);
        }

        $query = $this->query;

        if ($prefix !== '' && $query !== '') {
            /** @deprecated regex-based alias attachment — breaks on JOINs/subqueries; use criteria */
            $query = preg_replace('# (and|or) (\(?)#i', ' $1 $2' . $prefix, $query);
            $query = preg_replace('#^(\(?)#', '$1' . $prefix, $query);
        }

        return [$query ?? '', $this->params];
    }

    /** Cleans up and normalizes the parameters array
     *
     * @param array $params Incoming parameters (associative or arrays of arrays)
     * @param array $allowedKeys List of allowed parameter names (without ':')
     *
     * @return array Array strictly in the format [['id', 'value', ?type]]
     */
    private function normalizeAndCleanParams(array $params, array $allowedKeys): array
    {
        $cleaned = [];

        $allowedMap = array_flip($allowedKeys);

        foreach ($params as $key => $value) {
            // Check that the element is an array with index 0 (format [['id', 'value']])
            if (is_array($value) && array_key_exists(0, $value)) {
                // Strip the colon if it slipped in by accident (e.g. ':id')
                $paramName = ltrim((string) $value[0], ':');

                if (isset($allowedMap[$paramName])) {
                    // Keep the whole element (so as not to lose PDO::PARAM_* if it is there)
                    $cleaned[] = $value;
                }
            }
            // Otherwise process as an associative array ['id' => 'value']
            else {
                $paramName = ltrim((string) $key, ':');

                if (isset($allowedMap[$paramName])) {
                    // Format into the required form [['id', 'value']]
                    // Keep the key with or without the colon — as it originally came
                    $cleaned[] = [$key, $value];
                }
            }
        }

        return $cleaned;
    }

    /** @return array{0: string, 1: array} */
    private function buildSqlFromCriteria(string $prefix): array
    {
        $cond = new SqlCondition('r');
        $parts = [];

        foreach ($this->criteria as $key => $value) {
            if (is_string($key)) {
                $field = $prefix . $key;
                $parts[] = is_null($value) ? $field . ' IS NULL' : $cond->eq($field, $value);
            } elseif (is_array($value)) {
                $field = $prefix . $value[0];
                $fieldValue = $value[1] ?? null;
                $parts[] = is_null($fieldValue) ? $field . ' IS NULL' : $cond->eq($field, $fieldValue);
            }
        }

        return [implode(' AND ', $parts), $cond->getParams()];
    }
}
