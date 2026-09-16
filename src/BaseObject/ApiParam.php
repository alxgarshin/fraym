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

/** Description of a single action parameter. Passed as an array into ApiAction. */
final class ApiParam
{
    public function __construct(
        /** The name exactly as the parameter arrives on the wire: usually snake_case,
         *  but browser actions also use camelCase (deviceId, contentEncoding). */
        public readonly string $name,
        public readonly ApiParamTypeEnum $type = ApiParamTypeEnum::string,
        /** A flag for the manifest: param() itself doesn't check obligatoriness — that's the job of the action and validators */
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

    /** Kernel::init() constants are named in UPPER_SNAKE_CASE after the parameter name */
    private function getGlobalValue(): mixed
    {
        $constantName = mb_strtoupper($this->name);

        return defined($constantName) ? constant($constantName) : null;
    }
}
