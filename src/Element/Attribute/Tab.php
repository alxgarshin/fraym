<?php

/*
 * This file is part of the Fraym package.
 *
 * (c) Alex Garshin <alxgarshin@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Fraym\Element\Attribute;

use Attribute;

/** Вкладка */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Tab extends BaseElement
{
    public function __construct(
        ?bool $obligatory = null,
        ?string $helpClass = null,
        ?int $group = null,
        ?bool $noData = true,
        ?bool $virtual = null,
        ?string $linkAtBegin = null,
        ?string $linkAtEnd = null,
        string|array $context = [],
        array $additionalValidators = [],
        array $additionalData = [],
        ?string $customAsHTMLRenderer = null,
    ) {
        parent::__construct(
            obligatory: $obligatory,
            helpClass: $helpClass,
            group: $group,
            noData: $noData,
            virtual: $virtual,
            linkAtBegin: $linkAtBegin,
            linkAtEnd: $linkAtEnd,
            context: $context,
            additionalValidators: $this->getValidators($additionalValidators),
            additionalData: $additionalData,
            customAsHTMLRenderer: $customAsHTMLRenderer,
        );
    }
}
