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
        $name = $this->name . $this->getLineNumberWrapped();
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

    public function getDefaultValue(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }

    public function get(): ?DateTimeImmutable
    {
        if (!isset($this->fieldValue)) {
            $pureValue = $this->model?->getModelDataFieldValue($this->name);
            $this->fieldValue = DateHelper::convertToDateTime($pureValue);
        }

        return $this->fieldValue ?? $this->getDefaultValue();
    }

    public function getAsTimeStamp(): ?int
    {
        return DateHelper::timestamp($this->get());
    }

    public function getAsAtom(): ?string
    {
        return DateHelper::atom($this->get());
    }

    public function getAsUsualDate(): ?string
    {
        return DateHelper::date($this->get());
    }

    public function getAsUsualDateTime(): ?string
    {
        return DateHelper::dateTime($this->get());
    }

    public function set(null|DateTimeImmutable|int $fieldValue): static
    {
        $this->fieldValue = DateHelper::convertToDateTime($fieldValue);

        return $this;
    }

    public function getShowInObjects(): ?bool
    {
        return $this->getAttribute()->showInObjects;
    }
}
