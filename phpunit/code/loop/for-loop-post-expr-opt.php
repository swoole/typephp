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
