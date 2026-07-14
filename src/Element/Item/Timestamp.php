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

/** Отметка времени */
class Timestamp extends BaseElement
{
    use CloneTrait;

    /** Значение */
    private ?DateTimeImmutable $fieldValue;

    private Attribute\Timestamp $attribute;

    public function usualAsHTMLRenderer(bool $editableFormat, bool $removeHtmlFromValue = false): string
    {
        $value = $this->get()->getTimestamp();
        $name = $this->name . $this->lineNumberWrapped;
        $html = '';

        if ($this->getShowInObjects()) {
            $html .= $this->getAsUsualDateTime();
        }

        $html .= '<input type="hidden" name="' . $name . '" value="' . $value . '" class="timestamp" />';

        return $html;
    }

    public function asArray(): array
    {
        return array_merge(
            [
                'fieldValue' => $this->get(),
                'showInObjects' => $this->getShowInObjects(),
            ],
            $this->asArrayBase(),
        );
    }

    public function getAttribute(): Attribute\Timestamp
    {
        return $this->attribute;
    }

    public function setAttribute(ElementAttribute $attribute, bool $skipAttributeCheck = false): static
    {
        if (!$skipAttributeCheck) {
            $this->checkAttribute($attribute, Attribute\Timestamp::class);
        }
        /** @var Attribute\Timestamp $attribute */
        $this->attribute = $attribute;

        return $this;
    }

    public function getDefaultValue(DateTimeZone|string|null $dateTimeZone = null): DateTimeImmutable
    {
        $now = new DateTimeImmutable();

        if ($dateTimeZone) {
            $now = $now->setTimezone($dateTimeZone instanceof DateTimeZone ? $dateTimeZone : new DateTimeZone($dateTimeZone));
        }

        return $now;
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

    public function set(null|DateTimeImmutable|int $fieldValue, DateTimeZone|string|null $dateTimeZone = null): static
    {
        $this->fieldValue = DateHelper::convertToDateTime($fieldValue, $dateTimeZone);

        return $this;
    }

    public function getShowInObjects(): ?bool
    {
        return $this->getAttribute()->showInObjects;
    }

    public function coerceForSave(mixed $value): mixed
    {
        return DateHelper::getNow();
    }

    protected function isDOMVisible(): bool
    {
        return (bool) $this->getShowInObjects();
    }
}
