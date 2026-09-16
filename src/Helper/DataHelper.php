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

use DateTime;
use DateTimeImmutable;
use Exception;
use Fraym\Element\{Attribute as Attribute, Item as Item};
use Fraym\Entity\BaseEntity;
use Fraym\Enum\{ActEnum, EscapeModeEnum};
use Fraym\Interface\{ElementItem, HasDefaultValue, Helper};
use Fraym\Vendor\StripTags\StripTags;
use Generator;
use JsonException;
use PHPUnit\Util\Json;

abstract class DataHelper implements Helper
{
    /** Determine the default ActEnum if none is provided */
    public static function getActDefault(?BaseEntity $entity = null): ActEnum
    {
        $act = ACT;

        if (is_null($act)) {
            if (DataHelper::getId() > 0) {
                $act = !is_null($entity) ? $entity->defaultItemActType : ActEnum::edit;
            } else {
                $act = !is_null($entity) ? $entity->defaultListActType : ActEnum::list;
            }
        }

        return $act;
    }

    /** Check that all variables are present in the array */
    public static function inArrayAll(array $needles, array $haystack): bool
    {
        return empty(array_diff($needles, $haystack));
    }

    /** Check that any of the variables is present in the array */
    public static function inArrayAny(array $needles, array $haystack): bool
    {
        return !empty(array_intersect($needles, $haystack));
    }

    /** Move an array element from one index to another */
    public static function changeValueIndexInArray(array $array, int|string $oldIndex, int|string $newIndex): array
    {
        $out = array_splice($array, $oldIndex, 1);
        array_splice($array, $newIndex, 0, $out);

        return $array;
    }

    /** Get the id regardless of whether this is a save request or just a page output */
    public static function getId(): null|int|string
    {
        return ID[key(ID ?? []) ?? 0] ?? null;
    }

    /** Find in $_REQUEST the key of the data array that contains the given id */
    public static function findDataKeyInRequestById(int|string $id): int
    {
        if (ID === null) {
            return 0;
        }

        foreach (ID as $key => $value) {
            if ((string) $value === (string) $id) {
                return $key;
            }
        }

        return 0;
    }

    /** Output messages to the admin only */
    public static function adminEcho(string $str): void
    {
        if (CURRENT_USER->isAdmin(true) && CookieHelper::getCookie('testMode') === '1') {
            ResponseHelper::error($str);
        }
    }

    /** Get a json file and decode it */
    public static function getJsonFile(string $filePath): ?array
    {
        $data = null;

        if (file_exists($filePath)) {
            $fileData = file_get_contents($filePath);
            $data = DataHelper::jsonFixedDecode($fileData, true);
            unset($fileData);
        }

        return $data;
    }

    /** Safe JSON parsing that reports an error in case of a problem */
    public static function jsonFixedDecode(?string $string, bool $strict = false): mixed
    {
        try {
            if ($string === null) {
                return [];
            }

            $data = $strict ? json_decode($string, true, 512, JSON_THROW_ON_ERROR) : json_decode($string, true);

            if (!$strict && !is_array($data)) {
                $data = [
                    DataHelper::jsonLastErrorText(),
                ];
            }
        } catch (JsonException $jsonException) {
            throw new Exception('JSON parsing error: ' . $jsonException->getMessage() . '. Details: ' . DataHelper::jsonLastErrorText());
        }

        return $data;
    }

    /** JSON encoding without converting Cyrillic into \u characters */
    public static function jsonFixedEncode(array $array): string
    {
        /* in case the array data is not UTF-8 for some reason */
        $array = self::fixUTFEncoding($array);

        return json_encode($array, JSON_UNESCAPED_UNICODE);
    }

    /** Fix array data into UTF-8 data */
    public static function fixUTFEncoding(array $array): array
    {
        foreach ($array as $key => $value) {
            if (is_string($value)) {
                $array[$key] = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            } elseif (is_array($value)) {
                $array[$key] = self::fixUTFEncoding($value);
            }
        }

        return $array;
    }

    /** Output the last JSON processing error data */
    public static function jsonLastErrorText(): string
    {
        $constants = get_defined_constants(true);
        $json_errors = [];

        foreach ($constants['json'] as $name => $value) {
            if (!strncmp($name, 'JSON_ERROR_', 11)) {
                $json_errors[$value] = $name;
            }
        }

        return $json_errors[json_last_error()];
    }

    /** Add a signature to the API request array */
    public static function apiSignature(array $request, ?string $hash = null): array
    {
        if (!is_null($hash)) {
            $request['hash'] = $hash;
        }
        $requestSignature = self::hashData($request);
        unset($request['hash']);
        $request['signature'] = $requestSignature;

        return $request;
    }

    /** User activity logging.
     * $_REQUEST is mutated synchronously (the controller of the same request reads it);
     * DB writes are actually offloaded: fastcgi_finish_request() sends the response to the client, the log is written afterwards. */
    public static function activityLog(bool $fullLog = false): void
    {
        if (CURRENT_USER->id() > 0 && 'profile' === KIND) {
            $_REQUEST['updated_at'][0] = time() + 20;
        }

        register_shutdown_function(static function () use ($fullLog): void {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }

            if ($fullLog && 'get_new_events' !== ACTION && 'load_tasks' !== ACTION && CURRENT_USER->id() > 0) {
                DB->insert('activity_log', [
                    ['user_id', CURRENT_USER->isLogged() ? CURRENT_USER->id() : null],
                    ['real_ip', self::getRealIp()],
                    ['url', self::selfURL()],
                    ['kind', KIND],
                    ['id', self::getId()],
                    ['action', ACTION],
                    ['obj_type', OBJ_TYPE],
                    ['obj_id', OBJ_ID],
                    ['created_at', DateHelper::getNow()],
                    ['updated_at', DateHelper::getNow()],
                ]);
            }

            if (CURRENT_USER->id() > 0) {
                DB->update('user', [['updated_at', DateHelper::getNow()]], ['id' => CURRENT_USER->id()]);
            }
        });
    }

    /** Fix an http address to a correct one */
    public static function fixURL(string $url): string
    {
        if (!str_starts_with($url, 'http')) {
            $url = 'https://' . $url;
        }

        return $url;
    }

    /** Determine the page address */
    public static function selfURL(): string
    {
        $s = empty($_SERVER['HTTPS']) ? '' : ($_SERVER['HTTPS'] === 'on' ? 's' : '');
        $protocol = mb_substr(mb_strtolower($_SERVER['SERVER_PROTOCOL']), 0, mb_strpos(mb_strtolower($_SERVER['SERVER_PROTOCOL']), '/')) . $s;
        $port = ($_SERVER['SERVER_PORT'] === '80') ? '' : (':' . $_SERVER['SERVER_PORT']);

        return $protocol . '://' . $_SERVER['SERVER_NAME'] . $port . $_SERVER['REQUEST_URI'];
    }

    /** Get the "real" user IP.
     * X-Forwarded-For is accepted ONLY if the request came through a trusted proxy
     * (REMOTE_ADDR is in TRUSTED_PROXIES); otherwise REMOTE_ADDR. Protection against IP spoofing. */
    public static function getRealIp(): string
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';

        if (self::isTrustedProxy($remoteAddr) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            /** X-Forwarded-For: client, proxy1, ... — the leftmost address is the originating client */
            $clientIp = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);

            if (filter_var($clientIp, FILTER_VALIDATE_IP) !== false) {
                return $clientIp;
            }
        }

        return $remoteAddr;
    }

    /** preg_quote for preg_replace replacement content */
    public static function pregQuoteReplaced(?string $text): string
    {
        return preg_replace('#(?<!\\\\)(\\$|\\\\)#', '\\\\$1', $text);
    }

    /** Get the object name in curly braces */
    public static function addBraces(string $name): string
    {
        $clearedName = self::clearBraces($name);

        return $clearedName !== '' ? '{' . $clearedName . '}' : '';
    }

    /** Get the object name without curly braces */
    public static function clearBraces(?string $name): string
    {
        return str_replace(['{', '}'], '', (string) $name);
    }

    /** Clean HTML data with StripTags */
    public static function escapeHTMLData(?string $string): string
    {
        $_StripTags = CACHE->getFromCache('stripTags', 0);

        if (!isset($_StripTags) || !($_StripTags instanceof StripTags)) {
            $_StripTags = new StripTags();
            CACHE->setToCache('stripTags', 0, $_StripTags);
        }

        return $_StripTags->filter((string) $string);
    }

    /** Hash a string / array */
    public static function hashData(string|array $data): string
    {
        if (is_array($data)) {
            $data = serialize($data);
        }

        return hash('xxh3', $data);
    }

    /** Get a random byte string */
    public static function getRandomStringBin2hex(int $length = 200): string
    {
        $bytes = random_bytes($length / 2);

        return bin2hex($bytes);
    }

    /** Base64Url string encoding */
    public static function base64UrlEncode(string $str): string
    {
        return rtrim(strtr(base64_encode($str), '+/', '-_'), '=');
    }

    /** Base64Url string decoding (the inverse of base64UrlEncode); null on invalid input */
    public static function base64UrlDecode(string $str): ?string
    {
        $base64 = strtr($str, '-_', '+/');
        $padded = str_pad($base64, intdiv(strlen($base64) + 3, 4) * 4, '=', STR_PAD_RIGHT);
        $decoded = base64_decode($padded, true);

        return $decoded === false ? null : $decoded;
    }

    /** Search by key in an array of the form [name, value] */
    public static function getFlatArrayElement(int|string|array $needle, array $haystack): ?array
    {
        if (is_array($needle)) {
            $needle = $needle[0];
        }

        $needle = (string) $needle;

        foreach ($haystack as $value) {
            if ($needle === $value[0] || self::addBraces($needle) === $value[0] || (is_numeric($needle) && (int) $needle === $value[0])) {
                return $value;
            }
        }

        return null;
    }

    /** Convert a multiselect value to an array */
    public static function multiselectToArray(?string $string): array
    {
        $returnArray = [];

        if (!is_null($string)) {
            $trimmed = trim($string);

            if (str_starts_with($trimmed, '[')) {
                $decoded = json_decode($trimmed, true);
                $returnArray = is_array($decoded) ? $decoded : [];
            } elseif ($trimmed !== '') {
                if (str_starts_with($trimmed, '-') && str_ends_with($trimmed, '-')) {
                    $trimmed = mb_substr($trimmed, 1, mb_strlen($trimmed) - 2);
                }

                $returnArray = explode('-', $trimmed);
            }

            foreach ($returnArray as $key => $value) {
                if (is_string($value) && trim($value) === '') {
                    unset($returnArray[$key]);
                }
            }
        }

        return $returnArray;
    }

    /** Convert an array to multiselect values */
    public static function arrayToMultiselect(array $array): string
    {
        $valueArray = [];

        foreach ($array as $key => $value) {
            if ($value === 'on') {
                $valueArray[] = $key;
            } elseif ($value) {
                $valueArray[] = $value;
            }
        }

        return self::jsonFixedEncode($valueArray);
    }

    /** Check whether it's a number or a string and return it in the corresponding type */
    public static function checkNumeric(mixed $data): mixed
    {
        return is_numeric($data) ? (int) $data : $data;
    }

    /** Parse the virtual data format */
    public static function unmakeVirtual(string $data): array
    {
        $result = [];

        if ($data !== '') {
            preg_match_all('#\[([^]]+)]\[(.*?)]\r\n#msu', $data, $matches);

            foreach ($matches[0] as $key => $value) {
                if (str_starts_with($matches[1][$key], 'virtual')) {
                    $result[$matches[1][$key]] = $matches[2][$key];
                }
            }
        }

        return $result;
    }

    /** Build the object structure from virtually structured data
     * @return Generator<int|string, ElementItem>
     */
    public static function virtualStructure(
        string $query,
        array $params = [],
        string $prefix = '',
        array $additionalDataFields = [],
    ): Generator {
        $uploads = $_ENV['UPLOADS'];

        $fieldsData = DB->query($query, $params);

        foreach ($fieldsData as $fieldData) {
            $values = [];

            $obligatory = ($fieldData[$prefix . 'mustbe'] ?? false) === '1';

            $read = match ($fieldData[$prefix . 'rights'] ?? false) {
                1 => 100,
                2, 3 => 10,
                4 => 1,
                default => 100000,
            };

            $write = match ($fieldData[$prefix . 'rights'] ?? false) {
                1, 2 => 100,
                3, 4 => 10,
                default => 100000,
            };

            if ($fieldData[$prefix . 'values'] ?? false) {
                $field_values = DataHelper::escapeOutput($fieldData[$prefix . 'values'] ?? null);
                preg_match_all('#\[(\d+)]\[([^]]+)]#', $field_values, $matches);

                foreach ($matches[1] as $key => $value) {
                    $values[] = [$value, $matches[2][$key]];
                }
            }

            if (($fieldData[$prefix . 'group'] ?? false) === 0) {
                $fieldData[$prefix . 'group'] = null;
            }

            $itemClassName = match ($fieldData[$prefix . 'type'] ?? false) {
                'calendar' => Item\Calendar::class,
                'checkbox' => Item\Checkbox::class,
                'file' => Item\File::class,
                'h1' => Item\H1::class,
                'multiselect' => Item\Multiselect::class,
                'number' => Item\Number::class,
                'select' => Item\Select::class,
                'text' => Item\Text::class,
                'textarea' => Item\Textarea::class,
                'wysiwyg' => Item\Wysiwyg::class,
                default => throw new Exception('Unknown virtual field type: ' . ($fieldData[$prefix . 'type'] ?? false)),
            };

            $attributeClassName = match ($fieldData[$prefix . 'type'] ?? false) {
                'calendar' => Attribute\Calendar::class,
                'checkbox' => Attribute\Checkbox::class,
                'file' => Attribute\File::class,
                'h1' => Attribute\H1::class,
                'multiselect' => Attribute\Multiselect::class,
                'number' => Attribute\Number::class,
                'select' => Attribute\Select::class,
                'text' => Attribute\Text::class,
                'textarea' => Attribute\Textarea::class,
                'wysiwyg' => Attribute\Wysiwyg::class,
                default => throw  new Exception('Unknown virtual field type: ' . ($fieldData[$prefix . 'type'] ?? false)),
            };

            $field = new $itemClassName();

            $fieldAttribute = new $attributeClassName();

            $field->setAttribute($fieldAttribute);
            $field->name = 'virtual' . ($fieldData['id'] ?? '');
            $field->shownName = $fieldData[$prefix . 'name'] ?? null;
            $field->helpText = $fieldData[$prefix . 'help'] ?? null;

            $fieldAttribute->context = [$read, $write];

            $fieldAttribute->virtual = true;
            $fieldAttribute->obligatory = $obligatory;
            $fieldAttribute->group = $fieldData[$prefix . 'group'] ?? null;
            $fieldAttribute->useInFilters = (bool) ($fieldData['show_in_filters'] ?? false);

            if ($fieldAttribute instanceof HasDefaultValue) {
                $fieldAttribute->defaultValue = DataHelper::escapeOutput($fieldData[$prefix . 'default'] ?? null);
            }

            if (property_exists($fieldAttribute, 'values')) {
                /** @var Attribute\Multiselect|Attribute\Select $fieldAttribute */
                $fieldAttribute->values = $values;
            }

            $additionalData = [];

            foreach ($additionalDataFields as $additionalDataField) {
                $additionalData[$additionalDataField] = $fieldData[$additionalDataField] ?? null;
            }
            $fieldAttribute->additionalData = $additionalData;

            if ('file' === ($fieldData[$prefix . 'type'] ?? false)) {
                /** @var Attribute\File $fieldAttribute */
                if (isset($uploads[0]['virtual'])) {
                    $new_upload = [
                        'path' => $uploads[0]['virtual']['path'],
                        'columnname' => 'virtual' . $fieldData['id'],
                        'extensions' => $uploads[0]['virtual']['extensions'],
                    ];
                    $_ENV['UPLOADS'][] = $new_upload;
                    end($_ENV['UPLOADS']);
                    $fieldAttribute->uploadNum = key($_ENV['UPLOADS']);
                } else {
                    ResponseHelper::error('Virtual upload data not set.');
                    $field = null;
                }
            }

            if (!is_null($field)) {
                yield $fieldData['id'] => $field;
            }
        }
        unset($fieldsData);
    }

    /** Escape data before output to the frontend */
    public static function escapeOutput(mixed $value, EscapeModeEnum $escapeModeEnum = EscapeModeEnum::forHTML): mixed
    {
        if (is_null($value)) {
            return null;
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::escapeOutput($item, $escapeModeEnum);
            }
        } else {
            $value = (string) $value;

            if ($escapeModeEnum === EscapeModeEnum::forHTML) {
                $value = htmlspecialchars($value);
            } elseif ($escapeModeEnum === EscapeModeEnum::forHTMLforceNewLines) {
                $value = str_replace(["\r\n", "\n"], '<br>', htmlspecialchars($value));
            } elseif ($escapeModeEnum === EscapeModeEnum::forAttributes) {
                $value = htmlspecialchars($value, ENT_COMPAT);
            } elseif ($escapeModeEnum === EscapeModeEnum::plainHTML) {
            } elseif ($escapeModeEnum === EscapeModeEnum::plainHTMLforceNewLines) {
                $value = str_replace(["\r\n", "\n"], '<br>', $value);
            }
        }

        return $value;
    }

    /** Filter data before storing it in the DB */
    public static function filterInput(bool|int|string|null|DateTime|DateTimeImmutable|array|float $value, array $fieldParams, bool $topLevel = true): mixed
    {
        if (is_null($value)) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = DataHelper::filterInput($item, [], false);
            }

            if ($topLevel) {
                $value = DataHelper::jsonFixedEncode($value);
            }
        } elseif (in_array('url', $fieldParams)) {
            $value = filter_var($value, FILTER_SANITIZE_URL);
        } elseif (in_array('email', $fieldParams)) {
            $value = filter_var($value, FILTER_SANITIZE_EMAIL);
        } elseif (in_array('html', $fieldParams)) {
            $value = DataHelper::escapeHTMLData($value);
        } elseif ($value instanceof DateTime || $value instanceof DateTimeImmutable) {
            $value = $value->format('Y-m-d H:i:s');
        }

        return $value;
    }

    /** Whether the IP is in the trusted proxies list (TRUSTED_PROXIES — CSV of CIDRs or single IPs) */
    private static function isTrustedProxy(string $ip): bool
    {
        $trusted = $_ENV['TRUSTED_PROXIES'] ?? '';

        if (!is_string($trusted) || $trusted === '' || $ip === '') {
            return false;
        }

        foreach (explode(',', $trusted) as $cidr) {
            $cidr = trim($cidr);

            if ($cidr !== '' && self::ipInCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    /** Check whether the IP belongs to a CIDR subnet (IPv4 and IPv6 — via inet_pton, bitwise comparison) */
    private static function ipInCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        [$subnet, $maskBitsRaw] = explode('/', $cidr, 2);

        if (filter_var($ip, FILTER_VALIDATE_IP) === false || filter_var($subnet, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $ipBin = inet_pton($ip);
        $subnetBin = inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            /** different address families (IPv4 vs IPv6) */
            return false;
        }

        $maskBits = (int) $maskBitsRaw;

        if ($maskBits < 0 || $maskBits > strlen($ipBin) * 8) {
            return false;
        }

        $fullBytes = intdiv($maskBits, 8);
        $remainingBits = $maskBits % 8;

        if ($fullBytes > 0 && strncmp($ipBin, $subnetBin, $fullBytes) !== 0) {
            return false;
        }

        if ($remainingBits > 0) {
            $mask = ~(0xFF >> $remainingBits) & 0xFF;

            if ((ord($ipBin[$fullBytes]) & $mask) !== (ord($subnetBin[$fullBytes]) & $mask)) {
                return false;
            }
        }

        return true;
    }
}
