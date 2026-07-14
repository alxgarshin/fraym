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

/** S8/FR-135: номер строки/группы живёт на экземпляре Item (а не на общем Attribute — клоны строк не перетирают
 *  номер друг друга). Доступ — свойствами PHP 8.4, без геттеров/сеттеров. Дефолт lineNumber = null:
 *  вручную созданный и отрисованный (asHTML) элемент идёт без суффикса [n]. */
final class ElementLineNumberTest extends TestCase
{
    /** Регрессия FR-135: свежесозданный вручную элемент не должен получать суффикс [0]. */
    public function testFreshItemHasNoSuffixByDefault(): void
    {
        $item = $this->makeText();

        self::assertNull($item->lineNumber);
        self::assertSame('', $item->lineNumberWrapped);
    }

    public function testLineNumberWrappedWithoutGroup(): void
    {
        $item = $this->makeText();

        /** Многострочный рендер явно проставляет номер — нулевая строка даёт [0]. */
        $item->lineNumber = 0;
        self::assertSame('[0]', $item->lineNumberWrapped);

        $item->lineNumber = 3;
        self::assertSame('[3]', $item->lineNumberWrapped);

        $item->lineNumber = null;
        self::assertSame('', $item->lineNumberWrapped);
    }

    public function testGroupNumberGatedByGroup(): void
    {
        $grouped = $this->makeText(new Attribute\Text(group: 1));
        $grouped->lineNumber = 3;
        $grouped->groupNumber = 2;
        self::assertSame('[3][2]', $grouped->lineNumberWrapped);

        $ungrouped = $this->makeText();
        $ungrouped->lineNumber = 3;
        $ungrouped->groupNumber = 2;
        self::assertNull($ungrouped->groupNumber);
        self::assertSame('[3]', $ungrouped->lineNumberWrapped);
    }

    public function testCloneDoesNotShareLineNumber(): void
    {
        $original = $this->makeText();
        $original->lineNumber = 1;

        $clone = clone $original;
        $clone->lineNumber = 9;

        self::assertSame(1, $original->lineNumber);
        self::assertSame(9, $clone->lineNumber);
    }

    private function makeText(?Attribute\Text $attribute = null): Item\Text
    {
        $item = new Item\Text();
        $item->setAttribute($attribute ?? new Attribute\Text(), true);

        return $item;
    }
}
