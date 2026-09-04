<?php

function test_post_inc_basic(): void {
    $sum = 0;
    for ($i = 0; $i < 10; $i++) {
        $sum += $i;
    }
}

function test_post_dec_basic(): void {
    $sum = 0;
    for ($i = 10; $i > 0; $i--) {
        $sum += $i;
    }
}

function test_multi_post(): void {
    for ($i = 0, $j = 10; $i < 5; $i++, $j--) {
    }
}

function test_mixed_post(): void {
    $j = 0;
    for ($i = 0; $i < 10; $i++, $j += 2) {
    }
}

function test_nested_for(): void {
    for ($i = 0; $i < 5; $i++) {
        for ($j = 0; $j < 5; $j++) {
        }
    }
}

function test_while_not_affected(): void {
    $i = 0;
    while ($i < 10) {
        $i++;
    }
}

function test_do_while_not_affected(): void {
    $i = 0;
    do {
        $i++;
    } while ($i < 10);
}

// --- Cases that must NOT be rewritten (property/static-property/array-element) ---

class Counter {
    public int $value = 0;
}

class StaticCounter {
    public static int $count = 0;
}

function test_property_post_inc_not_rewritten(): void {
    $obj = new Counter();
    for ($i = 0; $i < 10; $i++) {
        $obj->value++;
    }
}

function test_property_post_dec_not_rewritten(): void {
    $obj = new Counter();
    for ($i = 0; $i < 10; $i++) {
        $obj->value--;
    }
}

function test_static_property_post_inc_not_rewritten(): void {
    for ($i = 0; $i < 10; $i++) {
        StaticCounter::$count++;
    }
}

function test_static_property_post_dec_not_rewritten(): void {
    for ($i = 0; $i < 10; $i++) {
        StaticCounter::$count--;
    }
}

function test_array_element_post_inc_not_rewritten(): void {
    $arr = [0, 0, 0];
    for ($i = 0; $i < 10; $i++) {
        $arr[$i % 3]++;
    }
}

function test_array_element_post_dec_not_rewritten(): void {
    $arr = [10, 10, 10];
    for ($i = 0; $i < 10; $i++) {
        $arr[$i % 3]--;
    }
}
