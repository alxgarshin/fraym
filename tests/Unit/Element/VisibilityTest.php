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

namespace Fraym\Tests\Unit\Element;

use Fraym\Element\{Attribute, Item};
use PHPUnit\Framework\TestCase;

/** S2: DOM-видимость и «скрыт когда пусто» — полиморфные методы вместо instanceof-цепочки */
final class VisibilityTest extends TestCase
{
    public function testStructuralTypesAreNotDomVisible(): void
    {
        self::assertFalse($this->item(Item\Hidden::class, new Attribute\Hidden())->checkDOMVisibility());
        self::assertFalse($this->item(Item\H1::class, new Attribute\H1())->checkDOMVisibility());
        self::assertFalse($this->item(Item\Tab::class, new Attribute\Tab())->checkDOMVisibility());
    }

    public function testPlainFieldIsVisible(): void
    {
        $text = $this->item(Item\Text::class, new Attribute\Text());

        self::assertTrue($text->checkDOMVisibility());
        self::assertTrue($text->checkVisibility());
    }

    public function testTabIsNeverVisible(): void
    {
        self::assertFalse($this->item(Item\Tab::class, new Attribute\Tab())->checkVisibility());
    }

    public function testTimestampDomVisibilityFollowsShowInObjects(): void
    {
        self::assertTrue($this->item(Item\Timestamp::class, new Attribute\Timestamp(showInObjects: true))->checkDOMVisibility());
        self::assertFalse($this->item(Item\Timestamp::class, new Attribute\Timestamp(showInObjects: false))->checkDOMVisibility());
    }

    public function testSelectHiddenOnlyWhenEmptyAndOptional(): void
    {
        self::assertFalse($this->item(Item\Select::class, new Attribute\Select(values: []))->checkVisibility());
        self::assertTrue($this->item(Item\Select::class, new Attribute\Select(values: [['a', 'A']]))->checkVisibility());
        self::assertTrue($this->item(Item\Select::class, new Attribute\Select(values: [], obligatory: true))->checkVisibility());
    }

    public function testMultiselectHiddenWhenEmptyAndOptional(): void
    {
        self::assertFalse($this->item(Item\Multiselect::class, new Attribute\Multiselect(values: []))->checkVisibility());
        self::assertTrue($this->item(Item\Multiselect::class, new Attribute\Multiselect(values: [['a', 'A']]))->checkVisibility());
    }

    private function item(string $itemClass, Attribute\BaseElement $attribute): Item\BaseElement
    {
        $item = new $itemClass();
        $item->setAttribute($attribute, true);

        return $item;
    }
}
