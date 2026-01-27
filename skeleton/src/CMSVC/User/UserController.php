<?php

declare(strict_types=1);

namespace App\CMSVC\User;

use Fraym\BaseObject\{BaseController, CMSVC, IsAccessible};
use Fraym\Interface\Response;

/** @extends BaseController<UserService> */
#[CMSVC(
    model: UserModel::class,
    service: UserService::class,
    view: UserView::class,
)]
class UserController extends BaseController
{
    public function Response(): ?Response
    {
        return null;
    }

    #[IsAccessible]
    public function webpushSubscribe(): ?Response
    {
        return $this->asArray(
            $this->service->webpushSubscribe(
                $_REQUEST['deviceId'] ?? null,
                $_REQUEST['endpoint'] ?? null,
                $_REQUEST['p256dh'] ?? null,
                $_REQUEST['auth'] ?? null,
                $_REQUEST['contentEncoding'] ?? null,
            ),
        );
    }

    #[IsAccessible]
    public function webpushUnsubscribe(): ?Response
    {
        return $this->asArray(
            $this->service->webpushUnsubscribe(
                $_REQUEST['deviceId'] ?? null,
            ),
        );
    }

    public function getCaptcha(): ?Response
    {
        return $this->asArray(
            $this->service->getCaptcha(),
        );
    }

    #[IsAccessible]
    public function reverifyEm(): ?Response
    {
        return $this->asArray(
            $this->service->reverifyEm(),
        );
    }
}
