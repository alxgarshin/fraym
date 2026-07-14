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

enum PasswordHashVersion: string
{
    /** Legacy: Argon2 поверх md5(pepper+password) — перешифровывается при следующем входе */
    case WRAPPED_V1 = 'wrapped_v1';

    /** Текущий: Argon2ID поверх pepper+password */
    case FINAL_V2 = 'final_v2';
}
