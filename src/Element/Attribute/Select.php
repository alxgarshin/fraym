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
use Fraym\BaseObject\BaseHelper;
use Fraym\Element\Validator\ObligatoryValidator;
use Fraym\Interface\HasDefaultValue;
use Generator;
use InvalidArgumentException;

/** Dropdown list */
/** @implements HasDefaultValue<null|string|int> */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Select extends BaseElement implements HasDefaultValue
{
    public array $basicElementValidators = [
        ObligatoryValidator::class,
    ];

    /** Default value */
    public mixed $defaultValue {
        get => $this->_val;
        set {
            if (!is_null($value) && !is_string($value) && !is_int($value)) {
                throw new InvalidArgumentException('Wrong defaultValue type');
            }

            $this->_val = $value;
        }
    }

    private null|string|int $_val = null;

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
        public null|string|array $locked = null,

        /** Mechanism for dynamically searching the list of values based on user input */
        public ?BaseHelper $helper = null,

        /** Legacy search by the outdated dash format (`-v1-v2-`) in addition to JSON — for virtualField,
         *  where dash was used. False by default: filters generate only JSON conditions.
         *  Set to true only if old dash data exists (`database:scan-multiselect-formats`). */
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
