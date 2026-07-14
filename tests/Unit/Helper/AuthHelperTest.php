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

use Fraym\Helper\{AuthHelper, DataHelper};
use PHPUnit\Framework\TestCase;

final class AuthHelperTest extends TestCase
{
    public function testGenerateAndValidateRoundTrip(): void
    {
        $payload = AuthHelper::validateAuthToken(AuthHelper::generateAuthToken());

        self::assertIsArray($payload);
        self::assertSame(5, $payload['id']);
        self::assertSame(7, $payload['sid']);
        self::assertArrayHasKey('exp', $payload);
    }

    public function testPayloadCarriesOnlyIdSidExp(): void
    {
        $payload = AuthHelper::validateAuthToken(AuthHelper::generateAuthToken());

        self::assertIsArray($payload);
        self::assertEqualsCanonicalizing(['exp', 'id', 'sid'], array_keys($payload));
        self::assertArrayNotHasKey('rights', $payload);
        self::assertArrayNotHasKey('bazecount', $payload);
    }

    public function testExpiredTokenRejected(): void
    {
        $token = $this->mint(['alg' => 'HS256', 'typ' => 'JWT'], ['exp' => time() - 10, 'id' => 5, 'sid' => 7]);

        self::assertNull(AuthHelper::validateAuthToken($token));
    }

    public function testWrongSignatureRejected(): void
    {
        $token = $this->mint(['alg' => 'HS256', 'typ' => 'JWT'], ['exp' => time() + 100, 'id' => 5, 'sid' => 7], 'attacker-secret');

        self::assertNull(AuthHelper::validateAuthToken($token));
    }

    public function testAlgSwapRejected(): void
    {
        $token = $this->mint(['alg' => 'none', 'typ' => 'JWT'], ['exp' => time() + 100, 'id' => 5, 'sid' => 7]);

        self::assertNull(AuthHelper::validateAuthToken($token));
    }

    public function testTamperedPayloadRejected(): void
    {
        [$header, , $signature] = explode('.', AuthHelper::generateAuthToken());
        $escalated = DataHelper::base64UrlEncode(DataHelper::jsonFixedEncode(['exp' => time() + 100, 'id' => 999, 'sid' => 7]));

        self::assertNull(AuthHelper::validateAuthToken($header . '.' . $escalated . '.' . $signature));
    }

    public function testMalformedTokenRejected(): void
    {
        self::assertNull(AuthHelper::validateAuthToken('only.two'));
        self::assertNull(AuthHelper::validateAuthToken('a.b.c.d'));
        self::assertNull(AuthHelper::validateAuthToken(''));
    }

    public function testCsrfValidatesForCurrentUser(): void
    {
        $token = AuthHelper::generateCsrfToken();

        self::assertTrue(AuthHelper::validateCsrfToken($token));
        self::assertFalse(AuthHelper::validateCsrfToken('deadbeef'));
    }

    public function testCsrfBoundToUser(): void
    {
        $token = AuthHelper::generateCsrfToken();
        CURRENT_USER->setId(999);

        self::assertFalse(AuthHelper::validateCsrfToken($token));
    }

    public function testPreAuthDoubleSubmitMatches(): void
    {
        $_COOKIE[AuthHelper::PRE_AUTH_CSRF_COOKIE] = 'abc123def';
        $_REQUEST[AuthHelper::PRE_AUTH_CSRF_COOKIE] = 'abc123def';

        self::assertTrue(AuthHelper::validatePreAuthCsrfToken());
    }

    public function testPreAuthDoubleSubmitRejectsMismatchOrMissing(): void
    {
        $_COOKIE[AuthHelper::PRE_AUTH_CSRF_COOKIE] = 'abc123def';
        $_REQUEST[AuthHelper::PRE_AUTH_CSRF_COOKIE] = 'tampered';
        self::assertFalse(AuthHelper::validatePreAuthCsrfToken());

        unset($_COOKIE[AuthHelper::PRE_AUTH_CSRF_COOKIE], $_REQUEST[AuthHelper::PRE_AUTH_CSRF_COOKIE]);
        self::assertFalse(AuthHelper::validatePreAuthCsrfToken());
    }
    protected function setUp(): void
    {
        CURRENT_USER->setId(5)->setSid(7)->setAllRights(['admin'])->setBazeCount(50);
    }

    private function mint(array $header, array $payload, ?string $secret = null): string
    {
        $secret ??= $_ENV['PROJECT_HASH_WORD'];
        $headerEncoded = DataHelper::base64UrlEncode(DataHelper::jsonFixedEncode($header));
        $payloadEncoded = DataHelper::base64UrlEncode(DataHelper::jsonFixedEncode($payload));
        $signature = DataHelper::base64UrlEncode(hash_hmac('SHA256', $headerEncoded . $payloadEncoded, $secret, true));

        return $headerEncoded . '.' . $payloadEncoded . '.' . $signature;
    }
}
