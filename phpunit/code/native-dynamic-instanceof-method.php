<?php

#[Native]
class DynamicNativeInstanceofMethod {}

function dynamicNativeInstanceof(DynamicNativeInstanceofMethod $object, string $class): bool
{
    return $object->instanceOf($class);
}
