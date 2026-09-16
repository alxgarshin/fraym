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

use Fraym\BaseObject\{ApiAction, ApiParam, BaseHelper, BaseModel};
use Fraym\Enum\ApiParamTypeEnum;
use Fraym\Interface\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ParamTestHelper extends BaseHelper
{
    /** The helper doesn't need a locale here, and loading it requires a request environment */
    public function __construct()
    {
    }

    #[ApiAction(params: [
        new ApiParam('input', ApiParamTypeEnum::string, default: ''),
        new ApiParam('no_id', ApiParamTypeEnum::bool, default: false),
    ])]
    public function Response(): ?Response
    {
        return null;
    }

    public function printOut(int|string|null $id): string
    {
        return '';
    }

    public function printItem(?BaseModel $entityItem): string
    {
        return '';
    }
}

/** A helper request has no action: param() without an action name reads the attribute from Response() */
final class HelperParamTest extends TestCase
{
    public function testParamsAreReadFromResponseWithoutActionName(): void
    {
        $helper = new ParamTestHelper();

        self::assertSame('', $helper->param('input'));
        self::assertFalse($helper->param('no_id'));

        $_REQUEST['input'] = 'Ива';
        $_REQUEST['no_id'] = '1';

        self::assertSame('Ива', $helper->param('input'));
        self::assertTrue($helper->param('no_id'));
    }

    public function testUndeclaredParamThrows(): void
    {
        $_REQUEST['term'] = 'Ива';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("ApiParam not found for param('term')");

        (new ParamTestHelper())->param('term');
    }

    public function testApiActionOfResponseIsResolvedWithoutActionName(): void
    {
        self::assertInstanceOf(ApiAction::class, (new ParamTestHelper())->getApiAction());
    }

    protected function setUp(): void
    {
        $_REQUEST = [];
    }
}
