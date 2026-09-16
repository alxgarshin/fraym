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
    /** Legacy: Argon2 over md5(pepper+password) — rehashed on the next login */
    case WRAPPED_V1 = 'wrapped_v1';

    /** Current: Argon2ID over pepper+password */
    case FINAL_V2 = 'final_v2';
}
