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

/** Built-in framework rights (other types are project business logic) */
enum BuiltInRights: string
{
    case ADMIN = 'admin';

    case BANNED = 'banned';
}
