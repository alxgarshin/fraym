<?php

declare(strict_types=1);

namespace App\CMSVC\Tag;

use Fraym\BaseObject\{BaseService, Controller};

/** @extends BaseService<TagModel> */
#[Controller(TagController::class)]
class TagService extends BaseService
{
}
