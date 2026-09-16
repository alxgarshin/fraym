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

use Fraym\Element\Item\LinkAt;

interface ElementAttribute
{
    /** Whether the element is obligatory */
    public ?bool $obligatory { get; set; }

    /** Class depending on whether the element is obligatory */
    public string $obligatoryStr { get; }

    /** Css class of the element hint */
    public ?string $helpClass { get; set; }

    /** Number of the element's sequential group */
    public ?int $group { get; set; }

    /** Control the element with non-standard handlers */
    public ?bool $noData { get; set; }

    /** Element virtuality (storing a JSON block in a single table cell) */
    public ?bool $virtual { get; set; }

    /** Wrapper for creating links around the element value */
    public LinkAt $linkAt { get; set; }

    /** Opening part of the link */
    public ?string $linkAtBegin { get; set; }

    /** Closing part of the link */
    public ?string $linkAtEnd { get; set; }

    /** Use the element in filters */
    public ?bool $useInFilters { get; set; }

    /** Element display context: when displaying fields, the element context is checked against the model's current context. Array in the format: [model:list|view|viewIfNotNull|create|update|embedded], e.g.: ['user:view', 'user:add'] */
    public string|array $context { get; set; }

    /** Main element validators */
    public array $basicElementValidators { get; set; }

    /** List of additional validators of a specific element of a specific model
     * @var array<int, string> $additionalValidators
     */
    public array $additionalValidators { get; set; }

    /** Save the field data with cleanup, keeping html in it */
    public ?bool $saveHtml { get; set; }

    /** Use data from this table column instead of the column named after the element */
    public ?string $alternativeDataColumnName { get; set; }

    /** Array of any additional data */
    public array $additionalData { get; set; }

    /** Use the corresponding service function instead of the standard asHTML */
    public ?string $customAsHTMLRenderer { get; set; }

    /** Check whether the context is in the list of contexts */
    public function checkContext(string $context): bool;

    /** Get the full list of validators, including additional ones */
    public function getValidators(array $additionalValidators): array;
}
