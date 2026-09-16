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

namespace Fraym\Interface;

use Fraym\BaseObject\BaseModel;
use Fraym\Element\{Attribute as Attribute, Item as Item};
use Fraym\Entity\BaseEntity;
use Fraym\Enum\ActEnum;

interface ElementItem
{
    /** Field name: matches the name in the DB */
    public ?string $name { get; set; }

    /** Field name visible to users */
    public ?string $shownName { get; set; }

    /** Field hint text */
    public ?string $helpText { get; set; }

    /** Parent entity */
    public ?BaseEntity $entity { get; set; }

    /** Parent model */
    public ?BaseModel $model { get; set; }

    /** Data to replace the element value on create */
    public ?Attribute\OnCreate $create { get; set; }

    /** Data to replace the element value on change */
    public ?Attribute\OnChange $change { get; set; }

    public ?int $lineNumber { get; set; }

    public string $lineNumberWrapped { get; }

    public ?int $groupNumber { get; set; }

    public function getAttribute(): ElementAttribute;

    public function setAttribute(ElementAttribute $attribute, bool $skipAttributeCheck = false): static;

    public function checkAttribute(ElementAttribute $attribute, string $elementClassName): void;

    public function getDefaultValue(): mixed;

    public function usualAsHTMLRenderer(bool $editableFormat, bool $removeHtmlFromValue = false): string;

    public function asHTML(bool $elementIsWritable, bool $removeHtmlFromValue = false): string;

    public function asArray(): array;

    public function asArrayBase(): array;

    public function checkDefaultValueInServiceFunctions(mixed $defaultValue): mixed;

    public function getObligatory(): bool;

    public function getObligatoryStr(): string;

    public function getGroup(): ?int;

    public function getHelpClass(): ?string;

    public function getLinkAt(): Item\LinkAt;

    public function getNoData(): ?bool;

    public function getVirtual(): ?bool;

    public function checkContext(array $context): bool;

    public function checkVisibility(): bool;

    public function checkDOMVisibility(): bool;

    public function checkWritable(?ActEnum $act = null, ?string $objectName = null): ?bool;

    public function asHTMLWrapped(?int $lineNumber, bool $elementIsWritable, int $elementTabindexNum): string;

    public function validate(mixed $value, array $options): array;

    public function get(): mixed;

    public function set(null $fieldValue): static;

    public function coerceForSave(mixed $value): mixed;
}
