<?php

declare(strict_types=1);

namespace App\CMSVC\Tag;

use Fraym\BaseObject\{BaseView, Controller};
use Fraym\Entity\{EntitySortingItem, MultiObjectsEntity, Rights};
use Fraym\Interface\Response;

#[MultiObjectsEntity(
    'Tag',
    'tag',
    [
        new EntitySortingItem(
            tableFieldName: 'name',
        ),
    ],
)]
#[Rights(
    viewRight: true,
    addRight: true,
    changeRight: true,
    deleteRight: true,
)]
#[Controller(TagController::class)]
class TagView extends BaseView
{
    public function Response(): ?Response
    {
        return null;
    }
}
