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

/** Declares a controller action in the external API. A method without this attribute works as before
 *  and is not included in the manifest. Descriptions live in the fraym_actions section of the module locale. */
#[Attribute(Attribute::TARGET_METHOD)]
final class ApiAction
{
    /**
     * @param ApiParam[] $params
     */
    public function __construct(
        /** Whether the action modifies data: the agent uses this flag to tell reads from writes */
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
