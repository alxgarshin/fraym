<?php

declare(strict_types=1);

namespace App\Helper;

use Fraym\Enum\OperandEnum;
use Fraym\Helper\DateHelper;
use Fraym\Interface\Helper;

abstract class UniversalHelper implements Helper
{
    /** Создание hash для капчи */
    public static function getCaptcha(): array
    {
        $clear = time() - (60 * 60);
        DB->delete(
            tableName: 'regstamp',
            criteria: [
                ['updated_at', $clear, [OperandEnum::LESS]],
            ],
        );

        $pass = '';
        $salt = 'abcdefghjkmnpqrstuvwxyz23456789';
        srand((int) ((float) microtime() * 1000000));
        $i = 0;

        while ($i <= 5) {
            $num = rand() % 31;
            $tmp = mb_substr($salt, $num, 1);
            $pass .= $tmp;
            ++$i;
        }
        $code = $pass;
        $hash = md5($code . $_ENV['PROJECT_HASH_WORD']);

        DB->insert(
            tableName: 'regstamp',
            data: [
                'code' => $code,
                'hash' => $hash,
                'created_at' => DateHelper::getNow(),
            ],
        );

        return ['response' => 'success', 'hash' => $hash];
    }
}
