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

enum ApiParamSourceEnum: string
{
    case request = 'request';

    /** The value has already been parsed by Kernel::init() into the constant of the same name (OBJ_ID, ID, PAGE, SORTING, OBJ_TYPE) */
    case global = 'global';
}
