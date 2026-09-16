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

namespace Fraym\Interface;

interface Validator
{
    /** Returns true if validation passed */
    public static function validate(ElementItem $element, mixed $value, array $options): bool;

    /** Returns the validator class */
    public static function getName(): string;

    /** Builds the error message */
    public static function getMessage(array $messageData): string;
}
