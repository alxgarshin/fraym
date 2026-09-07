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

/** Значения полей приходят с индексом объекта: name[0]. Скаляр (name=Test) прочитался бы
 *  строковым смещением PHP как первый байт и молча сохранился обрезанным, поэтому
 *  валидатор получает сырое значение $_REQUEST[$element->name] целиком, а не срез по строке. */
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
