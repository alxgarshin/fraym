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

/** S8/FR-135: the line/group number lives on the Item instance (not on the shared Attribute — row clones don't overwrite
 *  each other's number). Accessed via PHP 8.4 properties, without getters/setters. lineNumber defaults to null:
 *  a manually created and rendered (asHTML) element has no [n] suffix. */
final class ElementLineNumberTest extends TestCase
{
    /** FR-135 regression: a freshly created manual element must not get the [0] suffix. */
    public function testFreshItemHasNoSuffixByDefault(): void
    {
        $item = $this->makeText();

        self::assertNull($item->lineNumber);
        self::assertSame('', $item->lineNumberWrapped);
    }

    public function testLineNumberWrappedWithoutGroup(): void
    {
        $item = $this->makeText();

        /** Multi-line rendering sets the number explicitly — row zero gives [0]. */
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
