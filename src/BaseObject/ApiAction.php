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

use Attribute;

/** Объявление экшена контроллера во внешнем API. Метод без этого атрибута работает как раньше
 *  и в манифест не попадает. Описания живут в секции fraym_actions локали модуля. */
#[Attribute(Attribute::TARGET_METHOD)]
final class ApiAction
{
    /**
     * @param ApiParam[] $params
     */
    public function __construct(
        /** Меняет ли экшен данные: агент по этому признаку отличает чтение от записи */
        public readonly bool $mutating = false,
        public readonly array $params = [],
    ) {
    }

    public function getParam(string $name): ?ApiParam
    {
        foreach ($this->params as $param) {
            if ($param->name === $name) {
                return $param;
            }
        }

        return null;
    }
}
