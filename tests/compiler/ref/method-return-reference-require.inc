<?php
return static function ($box): void {
    $requireMethodAlias =& $box->valueRef();
    $requireMethodAlias = 'method require';
    $class = get_class($box);
    $requireStaticAlias =& $class::staticRef();
    $requireStaticAlias = 'static require';
};
?>
