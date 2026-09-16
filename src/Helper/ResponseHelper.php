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

namespace Fraym\Helper;

use Fraym\Enum\ResponseErrorCodeEnum;
use Fraym\Interface\Helper;
use Fraym\Response\ArrayResponse;

abstract class ResponseHelper implements Helper
{
    /** Envelope service keys: everything else in the response body is treated as data and moved to response_data */
    private const ENVELOPE_KEYS = [
        'response',
        'response_text',
        'response_data',
        'response_error_code',
        'ids',
        'fields',
        'messages',
        'redirect',
        'executionTime',
        'fraymVersion',
    ];

    public static function terminate(): never
    {
        exit;
    }

    /** 401 response: unauthorized */
    public static function response401(): never
    {
        self::responseWithErrorCode(ResponseErrorCodeEnum::unauthorized);
    }

    /** 403 response: access denied (CSRF) */
    public static function response403(): never
    {
        self::responseWithErrorCode(ResponseErrorCodeEnum::forbidden);
    }

    /** 404 response: object not found */
    public static function response404(): never
    {
        self::responseWithErrorCode(ResponseErrorCodeEnum::notFound);
    }

    /** Bring any response body to the unified envelope.
     *  Idempotent: applied both at output time and after the router fills in messages. */
    public static function buildEnvelope(array $payload, bool $setHttpStatus = true): array
    {
        $data = $payload['response_data'] ?? null;

        foreach ($payload as $key => $value) {
            if (!in_array($key, self::ENVELOPE_KEYS, true)) {
                $data = is_array($data) ? array_merge($data, [$key => $value]) : [$key => $value];
                unset($payload[$key]);
            }
        }

        $errorCode = $payload['response_error_code'] ?? null;
        $errorCode = $errorCode instanceof ResponseErrorCodeEnum ? $errorCode : ResponseErrorCodeEnum::tryFrom((string) $errorCode);

        $messages = $payload['messages'] ?? [];
        $hasErrorMessage = false;

        foreach ($messages as $message) {
            if (($message[0] ?? null) === 'error') {
                $hasErrorMessage = true;
                break;
            }
        }

        $response = $payload['response'] ?? (!is_null($errorCode) || $hasErrorMessage ? 'error' : 'success');

        $envelope = ['response' => $response];

        if (!is_null($errorCode)) {
            $envelope['response_error_code'] = $errorCode->value;

            if ($setHttpStatus && !headers_sent()) {
                header('HTTP/1.1 ' . $errorCode->getHttpStatusLine());
            }
        }

        $responseText = $payload['response_text'] ?? self::joinMessages($messages, $response);

        if ($responseText !== '') {
            $envelope['response_text'] = $responseText;
        }

        if (!is_null($data)) {
            $envelope['response_data'] = $data;
        }

        foreach (['ids', 'fields', 'messages', 'redirect', 'executionTime', 'fraymVersion'] as $key) {
            if (isset($payload[$key])) {
                $envelope[$key] = $payload[$key];
            }
        }

        return $envelope;
    }

    /** Set CORS headers based on ALLOWED_ORIGINS from .env */
    public static function setCorsHeaders(): void
    {
        $allowedOrigins = array_filter(
            array_map('trim', explode(',', $_ENV['ALLOWED_ORIGINS'] ?? '')),
        );

        if (empty($allowedOrigins)) {
            return;
        }

        if (in_array('*', $allowedOrigins, true)) {
            header('Access-Control-Allow-Origin: *');

            return;
        }

        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        }
    }

    /** Build the browser response after a dynamic request */
    public static function response(
        array $messages,
        ?string $redirectPath = null,
        array $fields = [],
        array $ids = [],
        ?ResponseErrorCodeEnum $errorCode = null,
    ): ArrayResponse {
        $response = [];

        if (!is_null($errorCode)) {
            $response['response_error_code'] = $errorCode->value;
        }

        if ($ids !== []) {
            $response['ids'] = array_values($ids);
        }

        if (!is_null($redirectPath)) {
            foreach ($messages as $message) {
                if ($message[0] === 'success') {
                    self::success($message[1]);
                } elseif ($message[0] === 'error') {
                    self::error($message[1]);
                } elseif ($message[0] === 'information') {
                    self::info($message[1]);
                }
            }
            $response['redirect'] = $redirectPath;
            $response['executionTime'] = GLOBALTIMER->getTimerDiff();
            self::setCorsHeaders();
            print DataHelper::jsonFixedEncode(self::buildEnvelope($response));
            self::terminate();
        } else {
            foreach ($messages as $message) {
                $response['messages'][] = [$message[0], $message[1]];
            }

            foreach ($fields as $field) {
                $response['fields'][] = $field; //array of field names
            }
        }

        return new ArrayResponse($response);
    }

    /** Shorthand for building a single browser response after a dynamic request */
    public static function responseOneBlock(
        string $messageType,
        string $message,
        array $fields = [],
        ?ResponseErrorCodeEnum $errorCode = null,
    ): void {
        $errorCode = $errorCode ?? ($messageType === 'error' ? ResponseErrorCodeEnum::validationFailed : null);
        $response = self::response([[$messageType, $message]], null, $fields, [], $errorCode);
        $responseData = $response->getData();
        $responseData['executionTime'] = GLOBALTIMER->getTimerDiff();
        self::setCorsHeaders();
        print DataHelper::jsonFixedEncode(self::buildEnvelope($responseData));
        self::terminate();
    }

    /** Build the path to redirect the user's browser to based on the operation results */
    public static function redirectConstruct(bool $checkOnlyReferer = false, bool $doNotIncludeId = false): ?string
    {
        $redirectPath = null;

        $refererPath = $_REQUEST['go_back_after_save_referer'][0] ?? null;
        $goBackAfterSave = ($_REQUEST['go_back_after_save'][0] ?? null) === 'on';

        if (!is_null($refererPath) && $refererPath !== '' && $goBackAfterSave) {
            /* if $refererPath has no www but ABSOLUTE_PATH does, replace it */
            if (preg_match('#www\.#', ABSOLUTE_PATH) && !preg_match('#www\.#', $refererPath)) {
                $refererPath = preg_replace(
                    '#' . preg_replace('#www\.#', '', ABSOLUTE_PATH) . '#',
                    ABSOLUTE_PATH,
                    $refererPath,
                );
            }

            /* if we came here by a direct link from an external site, just go to the root section */
            if (!preg_match('#' . ABSOLUTE_PATH . '/#', $refererPath)) {
                $refererPath = ABSOLUTE_PATH . '/' . KIND . '/';
            }

            $redirectPath = $refererPath;
        } elseif (!$checkOnlyReferer) {
            $path = '/';
            $path .= KIND . '/';

            if (!$doNotIncludeId && DataHelper::getId() > 0) {
                $path .= DataHelper::getId() . '/';
            }

            $path2 = '';

            if (PAGE > 0) {
                $path2 .= 'page=' . PAGE;
            }

            if (SORTING > 0) {
                $path2 .= $path2 !== '' ? '&' : '';
                $path2 .= 'sorting=' . SORTING;
            }

            $redirectPath = $path . $path2;
        }

        return $redirectPath;
    }

    /** Redirect the user's browser */
    public static function redirect(string $link, ?array $cookieParams = null): void
    {
        if (!is_null($cookieParams)) {
            CookieHelper::batchSetCookie($cookieParams);
        }

        if (REQUEST_TYPE->isDynamicRequest() && (str_starts_with($link, '/') || preg_match('#^' . preg_quote(ABSOLUTE_PATH) . '#', $link))) {
            $response = self::response([], $link);
            print_r(DataHelper::jsonFixedEncode($response->getData()));
        } else {
            header('Location: ' . $link);
        }

        self::terminate();
    }

    /** Build the redirect path from the cookie data */
    public static function createRedirect(): ?string
    {
        $redirectPath = null;

        if (CookieHelper::getCookie('redirectToKind')) {
            $redirectPath = ABSOLUTE_PATH . '/';
            $redirectPath .= CookieHelper::getCookie('redirectToKind') . '/';

            if (CookieHelper::getCookie('redirectToObject')) {
                $redirectPath .= CookieHelper::getCookie('redirectToObject') . '/';
            }

            $redirectToId = CookieHelper::getCookie('redirectToId');

            if ($redirectToId) {
                $redirectToId = DataHelper::jsonFixedDecode($redirectToId);

                if ($redirectToId) {
                    $redirectPath .= (is_array($redirectToId) ? $redirectToId[0] : $redirectToId) . '/';
                }
            }

            if (CookieHelper::getCookie('redirectParams')) {
                $redirectPath .= CookieHelper::getCookie('redirectParams');
            }

            CookieHelper::batchDeleteCookie(['redirectToKind', 'redirectToId', 'redirectParams']);
        }

        return $redirectPath;
    }

    /** Add a success message */
    public static function success(string $str): void
    {
        self::addMessage('success', $str);
    }

    /** Add a failure / error message */
    public static function error(string $str): void
    {
        self::addMessage('error', $str);
    }

    /** Add an info message */
    public static function info(string $str): void
    {
        self::addMessage('information', $str);
    }

    /** Terminate the request with a machine-readable error code: a dynamic client gets the envelope, a regular page load only the status */
    private static function responseWithErrorCode(ResponseErrorCodeEnum $errorCode): never
    {
        if (!headers_sent()) {
            header('HTTP/1.1 ' . $errorCode->getHttpStatusLine());
        }

        if (REQUEST_TYPE->isDynamicRequest()) {
            self::setCorsHeaders();

            print DataHelper::jsonFixedEncode(self::buildEnvelope([
                'response_error_code' => $errorCode->value,
                'executionTime' => GLOBALTIMER->getTimerDiff(),
            ], false));
        }

        self::terminate();
    }

    /** Join the message texts matching the final response type */
    private static function joinMessages(array $messages, string $response): string
    {
        $suitableTypes = $response === 'error' ? ['error'] : ['success', 'information'];
        $texts = [];

        foreach ($messages as $message) {
            if (in_array($message[0] ?? null, $suitableTypes, true) && ($message[1] ?? '') !== '') {
                $texts[] = (string) $message[1];
            }
        }

        return implode(' ', $texts);
    }

    /** Add a message to the cookie array */
    private static function addMessage(string $type, string $str): void
    {
        $cookieMessages = CookieHelper::getCookie('messages', true);

        $cookieMessages[] = [$type, $str];

        CookieHelper::batchSetCookie(['messages' => $cookieMessages]);
    }
}
