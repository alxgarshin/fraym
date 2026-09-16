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
use Fraym\Element\Item\MultiselectCreator;
use Fraym\Element\Validator\ObligatoryValidator;
use Fraym\Interface\HasDefaultValue;
use Generator;
use InvalidArgumentException;
use RuntimeException;

/** Multiple choice */
/** @implements HasDefaultValue<null|string|array|Generator> */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Multiselect extends BaseElement implements HasDefaultValue
{
    public array $basicElementValidators = [
        ObligatoryValidator::class,
    ];

    /** Default value */
    public mixed $defaultValue {
        get => $this->_val;
        set {
            if (!is_null($value) && !is_string($value) && !is_array($value) && !$value instanceof Generator) {
                throw new InvalidArgumentException('Wrong defaultValue type');
            }

            $this->_val = $value;
        }
    }

    private null|string|array|Generator $_val = null;

    public function __construct(
        mixed $defaultValue = null,

        /** Possible values: an array or a callback function name string */
        public null|string|array $values = null {
            set(null|string|array|Generator $values) {
                if ($values instanceof Generator) {
                    $values = iterator_to_array($values);
                }

                $this->values = $values;
            }
        },

        /** Values locked from changes: an array or a callback function name string */
        public null|string|array $locked = null {
            set(null|string|array|Generator $locked) {
                if ($locked instanceof Generator) {
                    $locked = iterator_to_array($locked);
                }

                $this->locked = $locked;
            }
        },

        /** Single choice (radio) from the whole array */
        public bool $one = false,

        /** Data used to create images for the corresponding values: an array or a callback function name string */
        public null|string|array $images = null {
            set(null|string|array|Generator $images) {
                if ($images instanceof Generator) {
                    $images = iterator_to_array($images);
                }

                $this->images = $images;
            }
        },

        /** Path to the $images folder */
        public ?string $path = null,

        /** Add an internal choices filter line */
        public ?bool $search = null,

        /** Mechanism for extending the list by adding a new object to the existing related table */
        public ?MultiselectCreator $creator = null,

        /** Legacy search by the outdated dash format (`-v1-v2-`) in addition to JSON.
         *  False by default: filters generate only fast JSON conditions. Set to true
         *  only for fields that still have old dash data in the DB
         *  (check with the `database:scan-multiselect-formats` command). */
        public bool $legacySearch = false,
        ?bool $obligatory = null,
        ?string $helpClass = null,
        ?int $group = null,
        ?bool $noData = null,
        ?bool $virtual = null,
        ?string $linkAtBegin = null,
        ?string $linkAtEnd = null,
        ?bool $useInFilters = null,
        string|array $context = [],
        array $additionalValidators = [],
        ?string $alternativeDataColumnName = null,
        array $additionalData = [],
        ?string $customAsHTMLRenderer = null,
    ) {
        if ($this->one && !is_null($this->creator)) {
            throw new RuntimeException(
                "It is not allowed to use MultiselectCreator within a multiselect in a select-one mode. Please, change 'one' to false or remove 'creator'.",
            );
        }

        parent::__construct(
            obligatory: $obligatory,
            helpClass: $helpClass,
            group: $group,
            noData: $noData,
            virtual: $virtual,
            linkAtBegin: $linkAtBegin,
            linkAtEnd: $linkAtEnd,
            useInFilters: $useInFilters,
            context: $context,
            additionalValidators: $this->getValidators($additionalValidators),
            alternativeDataColumnName: $alternativeDataColumnName,
            additionalData: $additionalData,
            customAsHTMLRenderer: $customAsHTMLRenderer,
        );

        $this->defaultValue = $defaultValue;
    }
}
