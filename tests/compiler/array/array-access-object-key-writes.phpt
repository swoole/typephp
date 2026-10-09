--TEST--
SplObjectStorage object-key assignments write through local, instance, nested and static containers
--FILE--
<?php

class ObjectStorageHolder
{
    public SplObjectStorage $storage;
    public static SplObjectStorage $shared;

    public function __construct()
    {
        $this->storage = new SplObjectStorage();
    }
}

class ObjectStorageContext
{
    public ObjectStorageHolder $holder;

    public function __construct()
    {
        $this->holder = new ObjectStorageHolder();
    }
}

trait PrivateObjectStorageWrites
{
    private ArrayAccess $entries;

    public function setEntries(ArrayAccess $entries): void
    {
        $this->entries = $entries;
    }

    public function writeEntry(object $key): mixed
    {
        return $this->entries[$key] = 12;
    }

    public function updateEntry(object $key): mixed
    {
        return $this->entries[$key] /= 5;
    }
}

class PrivateObjectStorageHolder
{
    use PrivateObjectStorageWrites;
}

function main(): void
{
    $key = new stdClass();
    $local = new SplObjectStorage();
    $holder = new ObjectStorageHolder();
    $context = new ObjectStorageContext();
    ObjectStorageHolder::$shared = new SplObjectStorage();

    var_dump($local[$key] = 4);
    var_dump($holder->storage[$key] = 6);
    var_dump($context->holder->storage[$key] = 8);
    var_dump(ObjectStorageHolder::$shared[$key] = 10);

    $holder->storage[$key] = 12;
    $context->holder->storage[$key] = 14;
    ObjectStorageHolder::$shared[$key] = 16;
    var_dump($holder->storage[$key], $context->holder->storage[$key], ObjectStorageHolder::$shared[$key]);

    var_dump($local[$key] += 2);
    var_dump($holder->storage[$key] *= 2);
    var_dump($context->holder->storage[$key] /= 4);
    var_dump(ObjectStorageHolder::$shared[$key] -= 3);
    var_dump($local[$key], $holder->storage[$key], $context->holder->storage[$key], ObjectStorageHolder::$shared[$key]);

    $private = new PrivateObjectStorageHolder();
    $private->setEntries($local);
    var_dump($private->writeEntry($key), $private->updateEntry($key), $local[$key]);
}
?>
--EXPECT--
int(4)
int(6)
int(8)
int(10)
int(12)
int(14)
int(16)
int(6)
int(24)
float(3.5)
int(13)
int(6)
int(24)
float(3.5)
int(13)
int(12)
float(2.4)
float(2.4)
