<?php

declare(strict_types=1);

namespace App\CMSVC\Tag;

use Fraym\BaseObject\{BaseController, CMSVC, IsAdmin};

/** @extends BaseController<TagService> */
#[CMSVC(
    model: TagModel::class,
    service: TagService::class,
    view: TagView::class,
)]
#[IsAdmin('/start/')]
class TagController extends BaseController
{
}
