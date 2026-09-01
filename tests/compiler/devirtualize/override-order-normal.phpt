--TEST--
Devirtualize: override flag with normal declaration order (ancestor, intermediate, leaf) - control
--FILE--
<?php

class OrderedBase {
    protected function perform(): string {
        return "base";
    }

    public function delete(): string {
        return $this->perform();
    }
}

class OrderedMid extends OrderedBase {
}

class OrderedLeaf extends OrderedMid {
    protected function perform(): string {
        return "leaf";
    }
}

function main() {
    var_dump((new OrderedLeaf())->delete());
}

?>
--EXPECT--
string(4) "leaf"
