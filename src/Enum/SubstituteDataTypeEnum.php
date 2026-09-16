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

namespace Fraym\Enum;

enum SubstituteDataTypeEnum: string
{
    /** Search in the table and sort by it, rather than by the main one */
    case TABLE = 'table';

    /** Choice from a hardcoded array */
    case ARRAY = 'array';
}
