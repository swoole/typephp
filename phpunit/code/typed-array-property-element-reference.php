<?php

class TypedArrayPropertyReferenceBox
{
    #[StdList(Type::Int)]
    public array $numbers = [1];
}

function typedArrayPropertyElementReference(TypedArrayPropertyReferenceBox $box): void
{
    $reference =& $box->numbers[0];
}
