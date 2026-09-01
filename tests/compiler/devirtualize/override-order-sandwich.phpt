--TEST--
Devirtualize: override flag survives sandwich declaration order (ancestor, leaf, intermediate)
--FILE--
<?php

class SandwichBase {
    protected function perform(): string {
        return "base";
    }

    public function delete(): string {
        return $this->perform();
    }
}

// Leaf is declared before its parent SandwichMid: the override flag of
// SandwichBase::perform() must still be registered, so the late-bound call
// in delete() stays dynamic.
class SandwichLeaf extends SandwichMid {
    protected function perform(): string {
        return "leaf";
    }
}

class SandwichMid extends SandwichBase {
}

function main() {
    var_dump((new SandwichLeaf())->delete());
}

?>
--EXPECT--
string(4) "leaf"
