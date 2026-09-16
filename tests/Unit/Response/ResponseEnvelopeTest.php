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

namespace Fraym\Tests\Unit\Response;

use Fraym\Enum\ResponseErrorCodeEnum;
use Fraym\Helper\ResponseHelper;
use PHPUnit\Framework\TestCase;

/** Unified response envelope: the agent tells data from metadata and gets a machine-readable error code */
final class ResponseEnvelopeTest extends TestCase
{
    public function testSuccessWithoutMessages(): void
    {
        $envelope = ResponseHelper::buildEnvelope([], false);

        self::assertSame(['response' => 'success'], $envelope);
    }

    public function testErrorMessageMakesErrorResponse(): void
    {
        $envelope = ResponseHelper::buildEnvelope([
            'messages' => [['error', 'Не заполнено обязательное поле «Название».']],
            'fields' => ['name[0]'],
        ], false);

        self::assertSame('error', $envelope['response']);
        self::assertSame('Не заполнено обязательное поле «Название».', $envelope['response_text']);
        self::assertSame(['name[0]'], $envelope['fields']);
    }

    public function testErrorCodeIsExposedAndForcesErrorResponse(): void
    {
        $envelope = ResponseHelper::buildEnvelope([
            'response_error_code' => ResponseErrorCodeEnum::validationFailed->value,
        ], false);

        self::assertSame('error', $envelope['response']);
        self::assertSame('validation_failed', $envelope['response_error_code']);
    }

    public function testCreatedIdsSurviveRedirect(): void
    {
        $envelope = ResponseHelper::buildEnvelope([
            'ids' => ['a1b2'],
            'redirect' => '/news/',
        ], false);

        self::assertSame('success', $envelope['response']);
        self::assertSame(['a1b2'], $envelope['ids']);
        self::assertSame('/news/', $envelope['redirect']);
    }

    public function testUnknownKeysMoveIntoResponseData(): void
    {
        $envelope = ResponseHelper::buildEnvelope([
            0 => ['id' => 1, 'name' => 'First'],
            1 => ['id' => 2, 'name' => 'Second'],
            'messages' => [],
        ], false);

        self::assertArrayNotHasKey(0, $envelope);
        self::assertSame(['id' => 1, 'name' => 'First'], $envelope['response_data'][0]);
        self::assertSame(['id' => 2, 'name' => 'Second'], $envelope['response_data'][1]);
    }

    public function testExistingResponseDataIsKept(): void
    {
        $envelope = ResponseHelper::buildEnvelope([
            'response_data' => ['a', 'b'],
        ], false);

        self::assertSame(['a', 'b'], $envelope['response_data']);
    }

    public function testEnvelopeIsIdempotent(): void
    {
        $once = ResponseHelper::buildEnvelope([
            'messages' => [['success', 'Новость добавлена.']],
            'ids' => ['a1b2'],
        ], false);

        self::assertSame($once, ResponseHelper::buildEnvelope($once, false));
    }

    public function testHttpStatusPerErrorCode(): void
    {
        self::assertSame(422, ResponseErrorCodeEnum::validationFailed->getHttpStatus());
        self::assertSame(401, ResponseErrorCodeEnum::unauthorized->getHttpStatus());
        self::assertSame(403, ResponseErrorCodeEnum::forbidden->getHttpStatus());
        self::assertSame(404, ResponseErrorCodeEnum::notFound->getHttpStatus());
        self::assertSame(400, ResponseErrorCodeEnum::wrongAction->getHttpStatus());
        self::assertSame(400, ResponseErrorCodeEnum::wrongDataFormat->getHttpStatus());
        self::assertSame(409, ResponseErrorCodeEnum::duplicate->getHttpStatus());
        self::assertSame(429, ResponseErrorCodeEnum::rateLimited->getHttpStatus());
        self::assertSame(500, ResponseErrorCodeEnum::internalError->getHttpStatus());
    }
}
