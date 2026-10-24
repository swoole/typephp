<?php
function readGlobals(): array
{
    return [$_GET, $_POST, $_COOKIE, $_SERVER, $_FILES, $_SESSION, $_REQUEST, $_ENV, $GLOBALS];
}
