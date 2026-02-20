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

use Fraym\Interface\Helper;

abstract class FileHelper implements Helper
{
    /** Получение upload_num'а на основе типа объекта */
    public static function getUploadNumByType(string $objType): ?int
    {
        $objType = DataHelper::clearBraces($objType);

        return match ($objType) {
            'user' => 1,
            default => null,
        };
    }

    /** Проверка доступности предпросмотра по расширению */
    public static function checkForPreview(string $filename): bool
    {
        $previewExtensions = [
            'doc',
            'docx',
            'xls',
            'xlsx',
            'ppt',
            'pptx',
            'pdf',
            'pages',
            'ai',
            'psd',
            'tiff',
            'dxf',
            'svg',
            'eps',
            'ps',
            'ttf',
            'xps',
        ];

        if (preg_match('/^.*\.(' . implode('|', $previewExtensions) . ')$/i', $filename)) {
            return true;
        }

        return false;
    }

    /** Проверяет, является ли URL внешней ссылкой на реальное изображение */
    public static function checkExternalImageExists(?string $url): bool
    {
        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $urlHost = parse_url($url, PHP_URL_HOST);
        $myHost = $_SERVER['HTTP_HOST'] ?? $_ENV['COOKIE_PATH'];

        $urlHost = preg_replace('/^www\./', '', strtolower($urlHost));
        $myHost  = preg_replace('/^www\./', '', strtolower($myHost));

        if (!$urlHost || $urlHost === $myHost) {
            return false;
        }

        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);

        curl_exec($ch);

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

        curl_close($ch);

        return ($httpCode === 200 && strpos((string) $contentType, 'image/') === 0);
    }

    /** Беглая проверка существования картинки по указанному пути (рекомендуется использовать ТОЛЬКО для внутренних ресурсов) */
    public static function checkImageExists(?string $filePath): bool
    {
        if (!$filePath) {
            return false;
        }

        return (bool) getimagesize($filePath);
    }

    /** Получение полного пути до хранимого файла */
    public static function getFileFullPath(
        string $filePath,
        int $uploadsType,
        bool $thumbnail = false,
        bool $returnWithInnerPath = false,
    ): string {
        return ($returnWithInnerPath ? INNER_PATH . 'public' : ABSOLUTE_PATH . ($thumbnail ? '/thumbnails' : '')) . $_ENV['UPLOADS_PATH'] . $_ENV['UPLOADS'][$uploadsType]['path'] . $filePath;
    }

    /** Получение пути файла из зашифрованного вида в БД */
    public static function getImagePath(
        ?string $attachmentsString,
        ?int $uploadsType = null,
        bool $thumbnail = false,
        bool $returnWithInnerPath = false,
    ): ?string {
        if (!is_null($attachmentsString)) {
            preg_match('#{([^:]+):([^}:]+)}#', $attachmentsString, $match);
            $result = $match[2] ?? null;

            if ($uploadsType && !is_null($result)) {
                if (file_exists(INNER_PATH . 'public' . $_ENV['UPLOADS_PATH'] . $_ENV['UPLOADS'][$uploadsType]['path'] . $result)) {
                    return self::getFileFullPath($result, $uploadsType, $thumbnail, $returnWithInnerPath);
                }
            }
        }

        return null;
    }

    /** Подсчет и вывод размера файла в человекочитаемом формате */
    public static function getFileSize(string $file): string
    {
        $LOCALE_FRAYM = LocaleHelper::getLocale(['fraym']);

        if (file_exists($file)) {
            $bytes = filesize($file);
            $decimals = 0;
            $sz = ['Б', 'К', 'М', 'Г'];
            $factor = (int) floor(($bytes - 1) / 3);
            $result = sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . ' ' . $sz[$factor] . ($factor > 0 ? 'б' : '');
        } else {
            $result = $LOCALE_FRAYM['file']['size_not_counted'];
        }

        return $result;
    }

    /** Получение отформатированного времени последнего изменения файла */
    public static function getModifiedTimeFormatted(string $filename, string $format = 'Ymd_Hi'): string
    {
        return date($format, self::getModifiedTime($filename) ?? time());
    }

    /** Получение времени последнего изменения файла */
    public static function getModifiedTime(string $filename): ?int
    {
        return !filemtime($filename) ? null : (int) filemtime($filename);
    }

    /** Получение типа файла на основе его расширения */
    public static function getFileTypeByExtension(string $filename): string
    {
        $filetype = 'file';

        if (preg_match('#\.(mp3|wav)$#i', $filename)) {
            $filetype = 'audio';
        } elseif (preg_match('#\.(mp4|mov|avi|mpeg4)$#i', $filename)) {
            $filetype = 'video';
        } elseif (preg_match('#\.(gif|jpe?g|png)$#i', $filename)) {
            $filetype = 'image';
        }

        return $filetype;
    }
}
