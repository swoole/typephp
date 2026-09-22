<?php

function main(): void
{
    $list = python\list([1, 2, 3]);
    $count = $list->count();
    $contains = $list->contains(2);
    $slice = $list->slice(0, 2)->toArray();
    $set = python\set([1, 2]);
    $member = $set->contains(1);
    $value = python\int(42);
    $value = $list;
    var_dump($count, $contains, $slice, $member, $value->toArray());
}
