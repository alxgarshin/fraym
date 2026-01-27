<?php

declare(strict_types=1);

namespace App\CMSVC\NewsEdit;

use DateTimeImmutable;
use Fraym\BaseObject\{BaseService, Controller};
use Fraym\Entity\{PreChange, PreCreate};
use Fraym\Helper\ResponseHelper;
use Generator;

/** @extends BaseService<NewsEditModel> */
#[PreCreate]
#[PreChange]
#[Controller(NewsEditController::class)]
class NewsEditService extends BaseService
{
    public function preCreate(): void
    {
        $this->preChangeCheck();
    }

    public function preChange(): void
    {
        $this->preChangeCheck();
    }

    public function preChangeCheck(): void
    {
        $LOCALE = $this->LOCALE;

        if ($_REQUEST['quote'] ?? false) {
            if ($_REQUEST['quote'][0] !== '' && $_REQUEST['attachments'][0][0] === '') {
                ResponseHelper::responseOneBlock('error', $LOCALE['no_quote_if_no_img'], ['attachments[0]', 'quote[0]']);
            }
        }
    }

    public function getShowDateDefault(): DateTimeImmutable
    {
        return new DateTimeImmutable('today 00:00');
    }

    public function getTagsValues(): Generator
    {
        return DB->getArrayOfItems("tag ORDER BY name", 'id');
    }

    public function getTagsLocked(): array
    {
        return [];
    }

    public function getTagsMultiselectCreatorCreatorId(): int|string
    {
        return CURRENT_USER->id();
    }

    public function getTagsMultiselectCreatorCreatedUpdatedAt(): int
    {
        return time();
    }

    public function checkRights(): bool
    {
        return CURRENT_USER->isAdmin();
    }
}
