<?php
return static function (string $function): void {
    $requireAlias =& $function();
    $requireAlias = 'from require';
};
?>
