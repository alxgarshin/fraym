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

namespace Fraym\Tests\Unit\BaseObject;

use Fraym\BaseObject\{ApiAction, ApiParam};
use Fraym\Enum\{ApiParamSourceEnum, ApiParamTypeEnum};
use PHPUnit\Framework\TestCase;

/** Атрибут действия и приведение параметров с провода к объявленным типам */
final class ApiActionTest extends TestCase
{
    public function testDefaultsAreRequestScopedString(): void
    {
        $param = new ApiParam('search_string');

        self::assertSame(ApiParamTypeEnum::string, $param->type);
        self::assertSame(ApiParamSourceEnum::request, $param->source);
    }

    public function testDefaultIsUsedWhenParamIsMissing(): void
    {
        self::assertSame(0, (new ApiParam('obj_limit', ApiParamTypeEnum::int, default: 0))->getValue());
        self::assertFalse((new ApiParam('dynamic_load', ApiParamTypeEnum::bool, default: false))->getValue());
    }

    public function testStringTrueBecomesBool(): void
    {
        $_REQUEST['dynamic_load'] = 'true';

        self::assertTrue((new ApiParam('dynamic_load', ApiParamTypeEnum::bool, default: false))->getValue());

        $_REQUEST['dynamic_load'] = 'false';

        self::assertFalse((new ApiParam('dynamic_load', ApiParamTypeEnum::bool, default: false))->getValue());
    }

    public function testNumericStringBecomesInt(): void
    {
        $_REQUEST['obj_limit'] = '42';

        self::assertSame(42, (new ApiParam('obj_limit', ApiParamTypeEnum::int))->getValue());
    }

    public function testSingleValueIsWrappedIntoArray(): void
    {
        $_REQUEST['tags'] = 'a';

        self::assertSame(['a'], (new ApiParam('tags', ApiParamTypeEnum::array))->getValue());

        $_REQUEST['tags'] = ['a', 'b'];

        self::assertSame(['a', 'b'], (new ApiParam('tags', ApiParamTypeEnum::array))->getValue());
    }

    public function testGlobalSourceReadsKernelConstant(): void
    {
        $_REQUEST['page'] = '7';

        /** Значение global-параметра берётся из константы, а не из $_REQUEST */
        self::assertSame(
            PAGE,
            (new ApiParam('page', ApiParamTypeEnum::int, source: ApiParamSourceEnum::global))->getValue(),
        );
    }

    public function testGetParamFindsDeclaredParamOnly(): void
    {
        $apiAction = new ApiAction(mutating: false, params: [
            new ApiParam('obj_limit', ApiParamTypeEnum::int, default: 0),
        ]);

        self::assertNotNull($apiAction->getParam('obj_limit'));
        self::assertNull($apiAction->getParam('obj_id'));
        self::assertFalse($apiAction->mutating);
    }
    protected function setUp(): void
    {
        $_REQUEST = [];
    }
}
