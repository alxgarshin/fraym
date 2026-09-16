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

namespace Fraym\Element\Validator;

use Fraym\Interface\ElementItem;

/** Field values arrive with an object index: name[0]. A scalar (name=Test) would be read
 *  as the first byte via PHP string offset and silently saved truncated, so
 *  the validator receives the raw value of $_REQUEST[$element->name] as a whole, not a per-line slice. */
final class ArrayFormatValidator extends BaseValidator
{
    public static function validate(ElementItem $element, mixed $value, array $options): bool
    {
        return is_null($value) || is_array($value);
    }

    public static function getMessage(array $messageData): string
    {
        $LOC = self::getLocale();
        [$message, $multiple] = self::getSequenceMessagePart($messageData);

        return $LOC[($multiple ? 'wrong_array_format_in_fields' : 'wrong_array_format_in_field')] . ' ' . $message;
    }
}
