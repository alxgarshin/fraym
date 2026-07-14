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

namespace Fraym\Tests\Unit\Helper;

use Fraym\Helper\DataHelper;
use PHPUnit\Framework\TestCase;

final class DataHelperTest extends TestCase
{
    public function testMultiselectToArrayReadsJson(): void
    {
        self::assertSame([4], DataHelper::multiselectToArray('[4]'));
        self::assertSame(['4'], DataHelper::multiselectToArray('["4"]'));
        self::assertSame(['4', '5'], DataHelper::multiselectToArray('["4","5"]'));
        self::assertSame([], DataHelper::multiselectToArray('[]'));
    }

    public function testMultiselectToArrayReadsLegacyDash(): void
    {
        self::assertSame(['4'], DataHelper::multiselectToArray('-4-'));
        self::assertSame(['4', '5'], DataHelper::multiselectToArray('-4-5-'));
        self::assertSame(['4', '5'], DataHelper::multiselectToArray('4-5'));
    }

    public function testMultiselectToArrayHandlesEmptyish(): void
    {
        self::assertSame([], DataHelper::multiselectToArray(null));
        self::assertSame([], DataHelper::multiselectToArray(''));
    }

    public function testMultiselectJsonRoundTrip(): void
    {
        $json = DataHelper::arrayToMultiselect(['4', '5']);

        self::assertSame(['4', '5'], DataHelper::multiselectToArray($json));
    }

    public function testBase64UrlRoundTrip(): void
    {
        $original = 'Hello, Мир! <>&"/+=';

        self::assertSame($original, DataHelper::base64UrlDecode(DataHelper::base64UrlEncode($original)));
    }

    public function testBase64UrlDecodeRejectsInvalid(): void
    {
        self::assertNull(DataHelper::base64UrlDecode('!!! not base64 !!!'));
    }
}
