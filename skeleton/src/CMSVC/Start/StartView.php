<?php

declare(strict_types=1);

namespace App\CMSVC\Start;

use Fraym\BaseObject\{BaseView, Controller};
use Fraym\Interface\Response;

#[Controller(StartController::class)]
class StartView extends BaseView
{
    public function Response(): ?Response
    {
        $LOCALE = $this->LOCALE;

        $PAGETITLE = null;

        $RESPONSE_DATA = $LOCALE['welcome'];

        return $this->asHtml($RESPONSE_DATA, $PAGETITLE);
    }
}
