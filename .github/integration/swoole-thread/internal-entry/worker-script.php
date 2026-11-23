<?php

[$queue, $round] = Swoole\Thread::getArguments();
return TypephpThreadEntry\run($queue, $round);
