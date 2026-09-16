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

namespace Fraym\Element\Attribute;

use Attribute;

/** Data used during OnCreate as a replacement for the element data */
#[Attribute(Attribute::TARGET_PROPERTY)]
class OnCreate
{
    public function __construct(
        /** Exact element data */
        public mixed $data = null,

        /** Name of the function providing the element data */
        public ?string $callback = null,
    ) {
    }
}
