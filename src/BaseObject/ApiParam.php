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

namespace Fraym\BaseObject;

use Fraym\Enum\{ApiParamSourceEnum, ApiParamTypeEnum};

/** Описание одного параметра action'а. Передаётся массивом внутрь ApiAction. */
final class ApiParam
{
    public function __construct(
        /** Имя ровно в том виде, в каком параметр приезжает на проводе: обычно snake_case,
         *  но у браузерных экшенов встречается и camelCase (deviceId, contentEncoding). */
        public readonly string $name,
        public readonly ApiParamTypeEnum $type = ApiParamTypeEnum::string,
        /** Признак для манифеста: сам param() обязательность не проверяет — это дело экшена и валидаторов */
        public readonly bool $obligatory = false,
        public readonly mixed $default = null,
        public readonly ApiParamSourceEnum $source = ApiParamSourceEnum::request,
    ) {
    }

    public function getValue(): mixed
    {
        $rawValue = $this->source === ApiParamSourceEnum::global
            ? $this->getGlobalValue()
            : ($_REQUEST[$this->name] ?? null);

        if (is_null($rawValue) || $rawValue === '') {
            return $this->default;
        }

        return $this->type->cast($rawValue);
    }

    public function asArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type->value,
            'obligatory' => $this->obligatory,
            'default' => $this->default,
        ];
    }

    /** Константы Kernel::init() именуются в UPPER_SNAKE_CASE от имени параметра */
    private function getGlobalValue(): mixed
    {
        $constantName = mb_strtoupper($this->name);

        return defined($constantName) ? constant($constantName) : null;
    }
}
