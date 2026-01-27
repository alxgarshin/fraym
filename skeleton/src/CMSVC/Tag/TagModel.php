<?php

declare(strict_types=1);

namespace App\CMSVC\Tag;

use Fraym\BaseObject\{BaseModel, Controller};
use Fraym\BaseObject\Trait\{CreatedUpdatedAtTrait, CreatorIdTrait, IdTrait};
use Fraym\Element\{Attribute, Item};

#[Controller(TagController::class)]
class TagModel extends BaseModel
{
    use IdTrait;
    use CreatorIdTrait;
    use CreatedUpdatedAtTrait;

    #[Attribute\Text(
        obligatory: true,
    )]
    public Item\Text $name;
}
