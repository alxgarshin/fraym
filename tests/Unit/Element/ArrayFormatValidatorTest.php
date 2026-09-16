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
use Fraym\Element\Validator\ArrayFormatValidator;
use PHPUnit\Framework\TestCase;

/** Field values arrive as name[0]; a scalar used to be read as the first byte of the string and silently corrupted data */
final class ArrayFormatValidatorTest extends TestCase
{
    public function testIndexedValuePasses(): void
    {
        self::assertTrue(ArrayFormatValidator::validate($this->makeElement(), ['TestPolygon'], []));
    }

    public function testMissingValuePasses(): void
    {
        self::assertTrue(ArrayFormatValidator::validate($this->makeElement(), null, []));
    }

    public function testScalarValueFails(): void
    {
        self::assertFalse(ArrayFormatValidator::validate($this->makeElement(), 'TestPolygon', []));
    }

    public function testFirstByteOfScalarIsWhatUsedToBeSaved(): void
    {
        $rawValue = 'TestPolygon';

        self::assertSame('T', $rawValue[0]);
        self::assertFalse(ArrayFormatValidator::validate($this->makeElement(), $rawValue, []));
    }

    private function makeElement(): Item\Text
    {
        $item = new Item\Text();
        $item->setAttribute(new Attribute\Text(), true);
        $item->name = 'name';

        return $item;
    }
}
