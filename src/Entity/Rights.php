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

namespace Fraym\Entity;

use Attribute;
use Fraym\BaseObject\BaseService;
use Fraym\Enum\ActEnum;
use Fraym\Helper\DataHelper;

/** Rights */
#[Attribute(Attribute::TARGET_CLASS)]
class Rights
{
    /** Parent entity */
    public ?BaseEntity $entity = null;

    public ?BaseService $service {
        get => $this->entity->view->CMSVC->service;
    }

    /** SQL restriction on viewing data */
    public ?RightsRestrict $viewRestrict = null {
        get {
            $defaultValue = $this->viewRestrict;

            if ($defaultValue instanceof RightsRestrict) {
                $service = $this->service;

                if (!$defaultValue->serviceCheck && $defaultValue->query && !is_null($service) && method_exists($service, $defaultValue->query)) {
                    $serviceQuery = $service->{$defaultValue->query}();

                    if (is_string($serviceQuery)) {
                        $defaultValue->query = $serviceQuery;
                    } else {
                        $this->viewRestrict = $defaultValue = $serviceQuery;
                    }
                }

                if (!is_null($defaultValue)) {
                    $defaultValue->serviceCheck = true;

                    if ($defaultValue->query === '') {
                        $this->viewRestrict = $defaultValue = null;
                    }
                }
            }

            return $defaultValue;
        }
        set(string|RightsRestrict|null $value) {
            if (is_string($value)) {
                $value = new RightsRestrict($value);
            }

            $this->viewRestrict = $value;
        }
    }

    /** SQL restriction on changing data */
    public ?RightsRestrict $changeRestrict = null {
        get {
            $defaultValue = $this->changeRestrict;

            if ($defaultValue instanceof RightsRestrict) {
                $service = $this->service;

                if (!$defaultValue->serviceCheck && $defaultValue->query && !is_null($service) && method_exists($service, $defaultValue->query)) {
                    $serviceQuery = $service->{$defaultValue->query}();

                    if (is_string($serviceQuery)) {
                        $defaultValue->query = $serviceQuery;
                    } else {
                        $this->changeRestrict = $defaultValue = $serviceQuery;
                    }
                }

                if (!is_null($defaultValue)) {
                    $defaultValue->serviceCheck = true;

                    if ($defaultValue->query === '') {
                        $this->changeRestrict = $defaultValue = null;
                    }
                }
            }

            return $defaultValue;
        }
        set(string|RightsRestrict|null $value) {
            if (is_string($value)) {
                $value = new RightsRestrict($value);
            }

            $this->changeRestrict = $value;
        }
    }

    /** SQL restriction on deleting data */
    public ?RightsRestrict $deleteRestrict = null {
        get {
            $defaultValue = $this->deleteRestrict;

            if ($defaultValue instanceof RightsRestrict) {
                $service = $this->service;

                if (!$defaultValue->serviceCheck && $defaultValue->query && !is_null($service) && method_exists($service, $defaultValue->query)) {
                    $serviceQuery = $service->{$defaultValue->query}();

                    if (is_string($serviceQuery)) {
                        $defaultValue->query = $serviceQuery;
                    } else {
                        $this->deleteRestrict = $defaultValue = $serviceQuery;
                    }
                }

                if (!is_null($defaultValue)) {
                    $defaultValue->serviceCheck = true;

                    if ($defaultValue->query === '') {
                        $this->deleteRestrict = $defaultValue = null;
                    }
                }
            }

            return $defaultValue;
        }
        set(string|RightsRestrict|null $value) {
            if (is_string($value)) {
                $value = new RightsRestrict($value);
            }

            $this->deleteRestrict = $value;
        }
    }

    public function __construct(
        /** Right to view data: bool or the name of a service function to check it */
        public bool|string $viewRight {
            get {
                $defaultValue = $this->viewRight;
                $service = $this->service;

                if (is_string($defaultValue) && !is_null($service) && method_exists($service, $defaultValue)) {
                    $defaultValue = $service->{$defaultValue}();
                }

                if (!is_bool($defaultValue) || $defaultValue === false) {
                    if (DataHelper::getActDefault($this->entity) === ActEnum::add) {
                        $defaultValue = $this->addRight;
                    } elseif (!is_null(DataHelper::getId())) {
                        $defaultValue = $this->changeRight || $this->deleteRight;
                    }
                }

                return $defaultValue;
            }
            set => $this->viewRight = $value;
        },

        /** Right to add data: bool or the name of a service function to check it */
        public bool|string $addRight {
            get {
                $defaultValue = $this->addRight;
                $service = $this->service;

                if (is_string($defaultValue) && !is_null($service) && method_exists($service, $defaultValue)) {
                    $defaultValue = $service->{$defaultValue}();
                }

                return $defaultValue;
            }
            set => $this->addRight = $value;
        },

        /** Right to change data: bool or the name of a service function to check it */
        public bool|string $changeRight {
            get {
                $defaultValue = $this->changeRight;
                $service = $this->service;

                if (is_string($defaultValue) && !is_null($service) && method_exists($service, $defaultValue)) {
                    $defaultValue = $service->{$defaultValue}();
                }

                return $defaultValue;
            }
            set => $this->changeRight = $value;
        },

        /** Right to delete data: bool or the name of a service function to check it */
        public bool|string $deleteRight {
            get {
                $defaultValue = $this->deleteRight;
                $service = $this->service;

                if (is_string($defaultValue) && !is_null($service) && method_exists($service, $defaultValue)) {
                    $defaultValue = $service->{$defaultValue}();
                }

                return $defaultValue;
            }
            set => $this->deleteRight = $value;
        },
        string|RightsRestrict|null $viewRestrict = null,
        string|RightsRestrict|null $changeRestrict = null,
        string|RightsRestrict|null $deleteRestrict = null,
    ) {
        $this->viewRestrict = $viewRestrict;
        $this->changeRestrict = $changeRestrict;
        $this->deleteRestrict = $deleteRestrict;
    }
}
