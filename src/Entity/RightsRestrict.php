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

/** DTO ограничения прав доступа */
final class RightsRestrict
{
    public function __construct(
        /** Строка запроса, идущая в WHERE при выборке объекта */
        public string $query = '',

        /** Параметры запроса */
        public array $params = [],

        /** Признак проверки получения ограничения из одноименной функции в сервисе */
        public bool $serviceCheck = false,
    ) {
        if ($this->query && $this->params) {
            preg_match_all('/:[a-zA-Z0-9_]+/', $this->query, $matches);

            $expectedKeys = array_map(static fn ($match) => ltrim($match, ':'), $matches[0]);

            $this->params = $this->normalizeAndCleanParams($this->params, $expectedKeys);
        }
    }

    /** Очищает и нормализует массив параметров
     *
     * @param array $params Входящие параметры (ассоциативные или массивы массивов)
     * @param array $allowedKeys Список разрешенных имен параметров (без ':')
     *
     * @return array Массив строго в формате [['id', 'value', ?type]]
     */
    private function normalizeAndCleanParams(array $params, array $allowedKeys): array
    {
        $cleaned = [];

        $allowedMap = array_flip($allowedKeys);

        foreach ($params as $key => $value) {
            // Проверяем, что элемент — это массив и имеет индекс 0 (формат [['id', 'value']])
            if (is_array($value) && array_key_exists(0, $value)) {
                // Очищаем от двоеточия, если оно случайно затесалось (например, ':id')
                $paramName = ltrim((string) $value[0], ':');

                if (isset($allowedMap[$paramName])) {
                    // Сохраняем элемент целиком (чтобы не потерять PDO::PARAM_*, если он там есть)
                    $cleaned[] = $value;
                }
            }
            // Иначе обрабатываем как ассоциативный массив ['id' => 'value']
            else {
                $paramName = ltrim((string) $key, ':');

                if (isset($allowedMap[$paramName])) {
                    // Форматируем в нужный вид [['id', 'value']]
                    // Ключ сохраняем с двоеточием или без — как он пришел изначально
                    $cleaned[] = [$key, $value];
                }
            }
        }

        return $cleaned;
    }
}
