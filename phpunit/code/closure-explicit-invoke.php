<?php

final class ExplicitInvokeHolder
{
    public ?Closure $callback = null;
}

function explicitNullableInvoke(?Closure $callback, int $value): mixed
{
    return $callback?->__invoke($value);
}
function explicitOrdinaryInvoke(?Closure $callback, int $value): mixed
{
    return $callback->__INVOKE($value);
}
function explicitNamedInvoke(?Closure $callback, int $value): mixed
{
    return $callback?->__invoke(value: $value);
}
function explicitUnpackedInvoke(?Closure $callback, array $values): mixed
{
    return $callback?->__invoke(...$values);
}
function explicitReferenceInvoke(Closure $callback, mixed &$value): mixed
{
    return $callback->__invoke(std::ref($value));
}
function explicitZeroInvoke(?Closure $callback): mixed
{
    return $callback?->__invoke();
}
function explicitPropertyInvoke(ExplicitInvokeHolder $holder, int $value): mixed
{
    return $holder->callback?->__invoke($value);
}
