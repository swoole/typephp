<?php
namespace LibraryLifecycle {
    class Worker implements Handler
    {
        public function act(): void { echo "hook\n"; }
    }
}

namespace {
    function exerciseLibrary(): void
    {
        $a = new \LibraryLifecycle\Derived();
        $a->run();
        $b = new \LibraryLifecycle\NonEmptyDerived();
        $b->run();
        echo 'overrides=', $a->getCount(), ',', $b->getCount(), "\n";
        $handler = new \LibraryLifecycle\Worker();
        \LibraryLifecycle\left($handler);
        \LibraryLifecycle\right($handler);
        \LibraryLifecycle\share();
        echo 'global=', $GLOBALS['libraryShared'], "\n";
        echo 'static=', \LibraryLifecycle\nextCount(), "\n";
    }

    function main(): void
    {
        exerciseLibrary();
    }
}
