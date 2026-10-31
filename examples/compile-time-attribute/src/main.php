<?php

function main(): void
{
    $order = new \Shop\Order();
    $seen = 0;

    $order->total = 5000;
    $order->customer = '  ada  ';
    $order->net = 100;
    $order->add(5, $seen, 7, 8);
    $order->record('manual');

    echo 'kind=', \Shop\Order::KIND, \PHP_EOL;
    echo 'kind=', $order->kind(), \PHP_EOL;
    echo 'sku=', $order->sku(), \PHP_EOL;
    echo 'total=', $order->getTotal(), \PHP_EOL;
    echo 'customer=', $order->getCustomer(), \PHP_EOL;
    echo 'net=', $order->net, \PHP_EOL;
    echo 'doubleTotal=', $order->doubleTotal, \PHP_EOL;
    echo 'writes=', $order->writes(), \PHP_EOL;
    echo 'history=', implode(',', $order->history()), \PHP_EOL;
    echo 'price=', $order->price(), \PHP_EOL;
    echo 'seen=', $seen, \PHP_EOL;
    echo 'trace=', $order->trace_add(), \PHP_EOL;
    echo 'trace2=', $order->trace_internal(), \PHP_EOL;
    echo 'site=', $order->traced_at_add(), \PHP_EOL;
    echo 'describe=', \Shop\Order::describe(), \PHP_EOL;

    $receipt = new \Shop\Receipt();
    echo 'document=', \Shop\Document::describe(), \PHP_EOL;
    echo 'receipt=', \Shop\Receipt::describe(), \PHP_EOL;
    echo 'receipt-price=', $receipt->price(), \PHP_EOL;

    $money = new \Shop\Money(42);
    echo 'money=', \Shop\Money::describe(), \PHP_EOL;

    $bag = new \Shop\Bag();
    echo 'trait=', $bag->stamped(), \PHP_EOL;
    echo 'count=', $bag->count(), \PHP_EOL;

    echo 'enum=', \Shop\State::Open->label(), \PHP_EOL;
    echo 'enum2=', \Shop\Priority::Low->label(), \PHP_EOL;
}
