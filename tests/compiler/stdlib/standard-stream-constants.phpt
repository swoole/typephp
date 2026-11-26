--TEST--
Standard stream constants retain their runtime resource values
--FILE--
<?php

namespace RuntimeStreams {
    use const STDERR as ErrorOutput;

    function errorStream()
    {
        return ErrorOutput;
    }
}

namespace {
    function main(): void
    {
        $input = STDIN;
        $output = \STDOUT;
        $error = \RuntimeStreams\errorStream();
        var_dump(is_resource($input), is_resource($output), is_resource($error));
        var_dump($error === constant('STDERR'));
        var_dump(fwrite($output, ''), fwrite($error, ''));
    }
}
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
int(0)
int(0)
