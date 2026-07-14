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

/** S2: приведение значения к формату сохранения — полиморфный coerceForSave вместо instanceof-цепочки */
final class CoerceForSaveTest extends TestCase
{
    public function testNumberCoercion(): void
    {
        $item = new Item\Number();
        $item->setAttribute(new Attribute\Number(), true);

        self::assertSame(5, $item->coerceForSave('5'));
        self::assertSame(0, $item->coerceForSave('not-a-number'));
    }

    public function testEmailWrapsForValidator(): void
    {
        $item = new Item\Email();
        $item->setAttribute(new Attribute\Email(), true);
        $item->name = 'em';

        self::assertSame(['em', 'user@example.com', ['email']], $item->coerceForSave('user@example.com'));
    }

    public function testFileJoinsArray(): void
    {
        $item = new Item\File();
        $item->setAttribute(new Attribute\File(), true);

        self::assertSame('ab', $item->coerceForSave(['a', 'b']));
        self::assertSame('x', $item->coerceForSave('x'));
    }

    public function testPasswordHashesOrNull(): void
    {
        $item = new Item\Password();
        $item->setAttribute(new Attribute\Password(), true);

        self::assertNull($item->coerceForSave(null));

        $hash = $item->coerceForSave('secret');
        self::assertIsString($hash);
        self::assertNotSame('secret', $hash);
        self::assertStringStartsWith('$argon2id$', $hash);
    }

    public function testCalendarFormatsDate(): void
    {
        $item = new Item\Calendar();
        $item->setAttribute(new Attribute\Calendar(saveAsTimestamp: false), true);

        self::assertSame(
            date('Y-m-d H:i:s', strtotime('2024-01-15 10:00:00')),
            $item->coerceForSave('2024-01-15 10:00:00'),
        );
        self::assertNull($item->coerceForSave(null));
    }

    public function testDefaultLeavesValueUnchanged(): void
    {
        $item = new Item\Text();
        $item->setAttribute(new Attribute\Text(), true);

        self::assertSame('unchanged', $item->coerceForSave('unchanged'));
    }
}
