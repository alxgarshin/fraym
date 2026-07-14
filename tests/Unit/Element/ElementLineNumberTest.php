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

/** S8: номер строки/группы живёт на экземпляре Item, а не на общем Attribute
 *  (клоны строк списка не должны перетирать номер друг друга). */
final class ElementLineNumberTest extends TestCase
{
    public function testLineNumberWrappedWithoutGroup(): void
    {
        $item = $this->makeText();

        self::assertSame('[0]', $item->getLineNumberWrapped());

        $item->setLineNumber(3);
        self::assertSame('[3]', $item->getLineNumberWrapped());

        $item->setLineNumber(null);
        self::assertSame('', $item->getLineNumberWrapped());
    }

    public function testGroupNumberGatedByGroup(): void
    {
        $grouped = $this->makeText(new Attribute\Text(group: 1));
        $grouped->setLineNumber(3)->setGroupNumber(2);
        self::assertSame('[3][2]', $grouped->getLineNumberWrapped());

        $ungrouped = $this->makeText();
        $ungrouped->setLineNumber(3)->setGroupNumber(2);
        self::assertSame('[3]', $ungrouped->getLineNumberWrapped());
    }

    public function testCloneDoesNotShareLineNumber(): void
    {
        $original = $this->makeText();
        $original->setLineNumber(1);

        $clone = clone $original;
        $clone->setLineNumber(9);

        self::assertSame(1, $original->getLineNumber());
        self::assertSame(9, $clone->getLineNumber());
    }

    private function makeText(?Attribute\Text $attribute = null): Item\Text
    {
        $item = new Item\Text();
        $item->setAttribute($attribute ?? new Attribute\Text(), true);

        return $item;
    }
}
