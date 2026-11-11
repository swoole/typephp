<?php
class ImmutableClosureObjectValue
{
    #[Immutable]
    public function copy(): self { return new self(); }
}
function immutableClosureObjectResult(): Closure
{
    return function (#[Immutable] ImmutableClosureObjectValue $value): ImmutableClosureObjectValue {
        return $value->copy();
    };
}
