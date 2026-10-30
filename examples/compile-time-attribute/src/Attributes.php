<?php

namespace Shop;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class Entity
{
}

#[Attribute(Attribute::TARGET_CLASS)]
final class Audited
{
}

#[Attribute(Attribute::TARGET_CLASS)]
final class Described
{
}

#[Attribute(Attribute::TARGET_CLASS)]
final class Stamp
{
}

#[Attribute(Attribute::TARGET_CLASS)]
final class Labelled
{
    public function __construct(public readonly string $prefix = 'enum')
    {
    }
}

#[Attribute(Attribute::TARGET_PROPERTY)]
final class Clamped
{
    public function __construct(public readonly int $min, public readonly int $max)
    {
    }
}

#[Attribute(Attribute::TARGET_PROPERTY)]
final class Surcharge
{
    public function __construct(public readonly int $fee)
    {
    }
}

#[Attribute(Attribute::TARGET_PROPERTY)]
final class Derived
{
    public function __construct(public readonly string $expression)
    {
    }
}

#[Attribute(Attribute::TARGET_PROPERTY)]
final class Trimmed
{
}

#[Attribute(Attribute::TARGET_METHOD)]
final class Traced
{
}
