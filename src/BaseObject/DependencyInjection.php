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

namespace Fraym\BaseObject;

use Attribute;

/** Attribute for Dependency Injection of any class (only BaseService descendants are recommended) into a PUBLIC property (with ReflectionNamedType) of any CMSVC object */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class DependencyInjection
{
}
