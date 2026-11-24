<?php

namespace OrderTest;

class Mid extends Base
{
    public function deleteInherited(): string
    {
        return $this->perform();
    }
}
