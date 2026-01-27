<?php

declare(strict_types=1);

use Captcha\Captcha;
use Identicon\Identicon;

$pathToLoader = __DIR__ . '/../../public/fraym.php';

$path = $_REQUEST['path'];

if ($path === 'captcha') {
    require_once $pathToLoader;

    $imageData = DB->select(
        tableName: 'regstamp',
        criteria: [
            'hash' => $_GET['hash'],
        ],
        oneResult: true,
    );
    $string = $imageData['code'];

    if ($string !== '') {
        define('EWIKI_FONT_DIR', dirname(__FILE__));
        define('CAPTCHA_INVERSE', 0);
        define('CAPTCHA_STRING', $string);
        putenv('GDFONTPATH=' . realpath('.'));

        header('Content-type: image/jpeg');
        $img = Captcha::image(CAPTCHA_STRING);
        imagejpeg($img);
        imagedestroy($img);
    }
} elseif ($path === 'identicon') {
    require_once $pathToLoader;

    $identicon = new Identicon();

    $identicon->identicon('', [
        'size' => 35,
        'backr' => [255, 255],
        'backg' => [255, 255],
        'backb' => [255, 255],
        'forer' => [1, 255],
        'foreg' => [1, 255],
        'foreb' => [1, 255],
        'squares' => 4,
        'autoadd' => 1,
        'gravatar' => 0,
        'grey' => 0,
    ]);

    $out = $identicon->identicon_build($_GET['hash'], $_GET['size']);

    header('Cache-Control: private, max-age=10800, pre-check=10800');
    header('Pragma: private');
    header('Expires: ' . date(DATE_RFC822, strtotime(' 2 day')));
    header('Content-type: image/png');
    imagepng($out);
    imagedestroy($out);
}
