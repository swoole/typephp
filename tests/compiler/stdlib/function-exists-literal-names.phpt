--TEST--
function_exists literal names follow runtime lookup rather than source call resolution
--FILE--
<?php
namespace ExistsLibrary {
    function target(): void {}
}

namespace ExistsScope {
    use function ExistsLibrary\target as imported_target;
    use ExistsLibrary as LibraryAlias;

    function local_target(): void {}

    function check(): void
    {
        echo json_encode([
            function_exists('local_target'),
            function_exists('imported_target'),
            function_exists('LibraryAlias\\target'),
            function_exists('ExistsLibrary\\target'),
            function_exists('\\ExistsLibrary\\target'),
            function_exists('ExistsScope\\local_target'),
        ]), "\n";
    }
}

namespace {
    function literal_target(): void {}

    function main(): void
    {
        echo json_encode([
            function_exists('literal_target'),
            function_exists('LITERAL_TARGET'),
            function_exists('\\literal_target'),
            function_exists('\\\\literal_target'),
            function_exists('literal_target\\'),
            function_exists('\\literal_target\\'),
        ]), "\n";
        echo json_encode([
            function_exists('strlen'),
            function_exists('STRLEN'),
            function_exists('\\strlen'),
            function_exists('\\\\strlen'),
            function_exists('strlen\\'),
            function_exists('\\strlen\\'),
        ]), "\n";
        echo json_encode([
            function_exists('ExistsLibrary\\target'),
            function_exists('EXISTSLIBRARY\\TARGET'),
            function_exists('\\ExistsLibrary\\target'),
            function_exists('\\\\ExistsLibrary\\target'),
            function_exists('ExistsLibrary\\target\\'),
            function_exists('ExistsLibrary_target'),
            function_exists('ExistsLibrary__target'),
            function_exists(''),
            function_exists('\\'),
        ]), "\n";
        ExistsScope\check();
    }
}
?>
--EXPECT--
[true,true,true,false,false,false]
[true,true,true,false,false,false]
[true,true,true,false,false,false,false,false,false]
[false,false,false,true,true,true]
