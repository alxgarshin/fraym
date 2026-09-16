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

use Attribute;

/** Function after FraymDelete. Added to the Service */
#[Attribute(Attribute::TARGET_CLASS)]
class PostDelete
{
    public function __construct(
        /** Function name */
        public string $callback = 'postDelete',
    ) {
    }
}
