<?php

declare(strict_types=1);

namespace App\CMSVC\User;

use Fraym\BaseObject\{ApiAction, ApiParam, BaseController, CMSVC, IsAccessible};
use Fraym\Enum\ApiParamTypeEnum;
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
    #[ApiAction(mutating: true, params: [
        new ApiParam('deviceId', ApiParamTypeEnum::string, obligatory: true),
        new ApiParam('endpoint', ApiParamTypeEnum::string, obligatory: true),
        new ApiParam('p256dh', ApiParamTypeEnum::string, obligatory: true),
        new ApiParam('auth', ApiParamTypeEnum::string, obligatory: true),
        new ApiParam('contentEncoding', ApiParamTypeEnum::string, default: 'aesgcm'),
    ])]
    public function webpushSubscribe(): ?Response
    {
        return $this->asArray(
            $this->service->webpushSubscribe(
                $this->param('deviceId'),
                $this->param('endpoint'),
                $this->param('p256dh'),
                $this->param('auth'),
                $this->param('contentEncoding'),
            ),
        );
    }

    #[IsAccessible]
    #[ApiAction(mutating: true, params: [
        new ApiParam('deviceId', ApiParamTypeEnum::string, obligatory: true),
    ])]
    public function webpushUnsubscribe(): ?Response
    {
        return $this->asArray(
            $this->service->webpushUnsubscribe(
                $this->param('deviceId'),
            ),
        );
    }

    #[ApiAction(mutating: true)]
    public function getCaptcha(): ?Response
    {
        return $this->asArray(
            $this->service->getCaptcha(),
        );
    }

    #[IsAccessible]
    #[ApiAction(mutating: true)]
    public function reverifyEm(): ?Response
    {
        return $this->asArray(
            $this->service->reverifyEm(),
        );
    }
}
