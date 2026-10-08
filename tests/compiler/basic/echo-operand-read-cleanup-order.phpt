--TEST--
Echo operands preserve variable reads, reference writeback, postfix updates and temporary destruction
--FILE--
<?php
class EchoOperandOrder {
    public int $n = 0;
    public function __toString(): string { echo '[string]'; return 'object'; }
    public function __destruct() { echo '[destroy]'; }
}
function echo_order_object(): EchoOperandOrder { return new EchoOperandOrder(); }
function echo_order_change(string &$value): string { $value = 'changed'; return $value; }
function echo_order_trace(string $value): string { echo '[trace]'; return $value; }
function main(): void
{
    $value = 'old';
    echo $value, '|', $value = 'new', '|', $value, "\n";
    $value = 'old';
    echo $value, '|', echo_order_change($value), '|', $value, "\n";
    $o = new EchoOperandOrder();
    echo $o->n++, '|', $o->n, '|', $o->n++, '|', $o->n, "\n";
    echo 'object:', echo_order_object(), '|next', "\n";
    echo 'conditional:', false ? echo_order_trace('skip') : strtoupper(echo_order_trace('ok')), '|end', "\n";
    unset($o);
    echo "\n";
}
?>
--EXPECT--
old|new|new
old|changed|changed
0|1|1|2
object:[string]object[destroy]|next
conditional:[trace]OK|end
[destroy]
