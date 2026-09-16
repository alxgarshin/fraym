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

use Fraym\BaseObject\{ApiAction, ApiParam, BaseController};
use Fraym\Enum\ApiParamTypeEnum;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ParamTestController extends BaseController
{
    #[ApiAction(mutating: false, params: [
        new ApiParam('obj_limit', ApiParamTypeEnum::int, default: 0),
        new ApiParam('dynamic_load', ApiParamTypeEnum::bool, default: false),
    ])]
    public function loadConversation(): void
    {
    }

    public function plainAction(): void
    {
    }
}

/** param() reads only declared ApiParams, and reflection runs once per action */
final class ControllerParamTest extends TestCase
{
    public function testDeclaredParamsAreCastAndDefaulted(): void
    {
        $controller = new ParamTestController();

        self::assertSame(0, $controller->param('obj_limit', 'loadConversation'));

        $_REQUEST['obj_limit'] = '25';
        $_REQUEST['dynamic_load'] = 'true';

        self::assertSame(25, $controller->param('obj_limit', 'loadConversation'));
        self::assertTrue($controller->param('dynamic_load', 'loadConversation'));
    }

    public function testUndeclaredParamThrows(): void
    {
        $_REQUEST['search_string'] = 'anything';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("ApiParam not found for param('search_string')");

        (new ParamTestController())->param('search_string', 'loadConversation');
    }

    public function testParamOnActionWithoutAttributeThrows(): void
    {
        $this->expectException(RuntimeException::class);

        (new ParamTestController())->param('anything', 'plainAction');
    }

    public function testApiActionIsResolvedFromCacheOnRepeatedCalls(): void
    {
        $controller = new ParamTestController();

        $first = $controller->getApiAction('loadConversation');
        $second = $controller->getApiAction('loadConversation');

        self::assertSame($first, $second);
    }

    public function testMissingActionResolvesToNull(): void
    {
        self::assertNull((new ParamTestController())->getApiAction('noSuchAction'));
    }
    protected function setUp(): void
    {
        $_REQUEST = [];
    }
}
