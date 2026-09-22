--TEST--
Static Class Property Read/Write Test
--FILE--
<?php
class Select {
    public $errorHandler = null;
    public function setErrorHandler($errorHandler): void
    {
        $this->errorHandler = $errorHandler;
        ($this->errorHandler)();
    }
}

class Worker
{
    public static ?Select $globalEvent = null;
    public static string $eventLoopClass = 'Select';

    public static function init() {
        self::$globalEvent = new static::$eventLoopClass();
        self::$globalEvent->setErrorHandler(function () {
            var_dump(__FUNCTION__);
        });
    }
}

function main() {
    Worker::init();
}
?>
--EXPECT--
string(27) "{closure:Worker::init():18}"
