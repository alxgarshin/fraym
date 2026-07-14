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

namespace Fraym\Element\Item;

use DateTimeImmutable;
use DateTimeZone;
use Fraym\Element\Attribute as Attribute;
use Fraym\Element\Item\Trait\CloneTrait;
use Fraym\Helper\{DateHelper};
use Fraym\Interface\ElementAttribute;

/** Календарь в формате "дата" или "дата+время" */
class Calendar extends BaseElement
{
    use CloneTrait;

    /** Значение */
    private ?DateTimeImmutable $fieldValue;

    private Attribute\Calendar $attribute;

    public function usualAsHTMLRenderer(bool $editableFormat, bool $removeHtmlFromValue = false): string
    {
        $html = '';

        if ($editableFormat) {
            $value = $this->get();
            $name = $this->name . $this->getLineNumberWrapped();

            $html = '<input type="date' . ($this->getShowDatetime() ? 'time-local' : '') . '" name="' . $name . '" id="' . $name . '" class="dpkr' . ($this->getShowDatetime() ? '_time' : '') . $this->getObligatoryStr() . '" value="' . $value?->format('Y-m-d' . ($this->getShowDatetime() ? ' H:i' : '')) . '" />';
        } else {
            $linkAtBegin = $this->getLinkAt()->getLinkAtBegin();
            $linkAtEnd = $this->getLinkAt()->getLinkAtEnd();
            $value = $this->get();

            if (!is_null($value)) {
                $html = $linkAtBegin . $value->format($this->getShowDatetime() ? 'd.m.Y H:i' : 'd.m.Y') . $linkAtEnd;
            }
        }

        return $html;
    }

    public function asArray(): array
    {
        return array_merge(
            [
                'fieldValue' => $this->get(),
                'defaultValue' => $this->getDefaultValue(),
                'showDatetime' => $this->getShowDatetime(),
            ],
            $this->asArrayBase(),
        );
    }

    public function getAttribute(): Attribute\Calendar
    {
        return $this->attribute;
    }

    public function setAttribute(ElementAttribute $attribute, bool $skipAttributeCheck = false): static
    {
        if (!$skipAttributeCheck) {
            $this->checkAttribute($attribute, Attribute\Calendar::class);
        }
        /** @var Attribute\Calendar $attribute */
        $this->attribute = $attribute;

        return $this;
    }

    public function getDefaultValue(DateTimeZone|string|null $dateTimeZone = null): ?DateTimeImmutable
    {
        $defaultValue = $this->checkDefaultValueInServiceFunctions($this->attribute->defaultValue);

        if (!is_a($defaultValue, 'DateTimeImmutable') && !is_null($defaultValue)) {
            $defaultValue = new DateTimeImmutable($defaultValue);

            if ($dateTimeZone) {
                $defaultValue = $defaultValue->setTimezone($dateTimeZone instanceof DateTimeZone ? $dateTimeZone : new DateTimeZone($dateTimeZone));
            }
        }

        return $defaultValue;
    }

    public function get(DateTimeZone|string|null $dateTimeZone = null): ?DateTimeImmutable
    {
        if (!isset($this->fieldValue)) {
            $pureValue = $this->model?->getModelDataFieldValue($this->name);
            $this->fieldValue = DateHelper::convertToDateTime($pureValue, $dateTimeZone);
        }

        return $this->fieldValue ?? $this->getDefaultValue($dateTimeZone);
    }

    public function getAsTimeStamp(DateTimeZone|string|null $dateTimeZone = null): ?int
    {
        return DateHelper::timestamp($this->get($dateTimeZone));
    }

    public function getAsAtom(DateTimeZone|string|null $dateTimeZone = null): ?string
    {
        return DateHelper::atom($this->get($dateTimeZone));
    }

    public function getAsUsualDate(DateTimeZone|string|null $dateTimeZone = null): ?string
    {
        return DateHelper::date($this->get($dateTimeZone));
    }

    public function getAsUsualDateTime(DateTimeZone|string|null $dateTimeZone = null): ?string
    {
        return DateHelper::dateTime($this->get($dateTimeZone));
    }

    public function set(null|DateTimeImmutable|string|int $fieldValue, DateTimeZone|string|null $dateTimeZone = null): static
    {
        $this->fieldValue = DateHelper::convertToDateTime($fieldValue, $dateTimeZone);

        return $this;
    }

    public function getShowDatetime(): ?bool
    {
        return $this->getAttribute()->showDatetime;
    }

    public function coerceForSave(mixed $value): mixed
    {
        if (is_null($value)) {
            return null;
        }

        $preppedValue = is_numeric($value) ? $value : strtotime($value);

        return $this->getAttribute()->saveAsTimestamp ? $preppedValue : date('Y-m-d H:i:s', $preppedValue);
    }
}
