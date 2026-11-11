<?php
class ImmutableClosureScalarValue
{
    #[Immutable]
    public function text(): string { return 'value'; }
}
function immutableClosureScalarResult(ImmutableClosureScalarValue $input): string
{
    $callback = function (#[Immutable] ImmutableClosureScalarValue $value): string {
        return $value->text();
    };
    return $callback($input);
}
