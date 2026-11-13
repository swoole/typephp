<?php

namespace TypePhp\Parser;

use TypePhp\Analysis\CompilationStatistics;
use TypePhp\Type;

use TypePhp\CompilerBase;
use TypePhp\Transform\KeywordMethodNameNormalizer;
use TypePhp\Resolver\Reflection;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeAbstract;

trait UniversalMethodCall
{
    private ?array $methodsForRegistry = null;
    protected const array UNIVERSAL_METHODS = [
        Type::INT => [
            'add'      => ['handler' => 'calc_op', 'op' => '+', 'return_type' => Type::INT, 'min_args' => 1, 'max_args' => 1],
            'sub'      => ['handler' => 'calc_op', 'op' => '-', 'return_type' => Type::INT, 'min_args' => 1, 'max_args' => 1],
            'mul'      => ['handler' => 'calc_op', 'op' => '*', 'return_type' => Type::INT, 'min_args' => 1, 'max_args' => 1],
            'div'      => ['handler' => 'calc_op', 'op' => '/', 'return_type' => Type::INT, 'min_args' => 1, 'max_args' => 1],
            'mod'      => ['handler' => 'calc_op', 'op' => '%', 'return_type' => Type::INT, 'min_args' => 1, 'max_args' => 1],
            'inc'      => ['handler' => 'calc_inc', 'return_type' => Type::INT, 'min_args' => 0, 'max_args' => 0],
            'dec'      => ['handler' => 'calc_dec', 'return_type' => Type::INT, 'min_args' => 0, 'max_args' => 0],
            // math
            'abs'     => ['handler' => 'php_fn', 'fn' => 'abs', 'return_type' => Type::INT, 'min_args' => 0, 'max_args' => 0],
            'ceil'    => ['handler' => 'php_fn', 'fn' => 'ceil', 'return_type' => Type::FLOAT, 'min_args' => 0, 'max_args' => 0],
            'floor'   => ['handler' => 'php_fn', 'fn' => 'floor', 'return_type' => Type::FLOAT, 'min_args' => 0, 'max_args' => 0],
            'round'   => ['handler' => 'php_fn', 'fn' => 'round', 'return_type' => Type::FLOAT, 'min_args' => 0, 'max_args' => 2],
            'sqrt'    => ['handler' => 'php_fn', 'fn' => 'sqrt', 'return_type' => Type::FLOAT, 'min_args' => 0, 'max_args' => 0],
            'pow'     => ['handler' => 'php_fn', 'fn' => 'pow', 'return_type' => Type::VAR, 'min_args' => 1, 'max_args' => 1],
            'log'     => ['handler' => 'php_fn', 'fn' => 'log', 'return_type' => Type::FLOAT, 'min_args' => 0, 'max_args' => 1],
            'log10'   => ['handler' => 'php_fn', 'fn' => 'log10', 'return_type' => Type::FLOAT, 'min_args' => 0, 'max_args' => 0],
            'exp'     => ['handler' => 'php_fn', 'fn' => 'exp', 'return_type' => Type::FLOAT, 'min_args' => 0, 'max_args' => 0],
            'sin'     => ['handler' => 'php_fn', 'fn' => 'sin', 'return_type' => Type::FLOAT, 'min_args' => 0, 'max_args' => 0],
            'cos'     => ['handler' => 'php_fn', 'fn' => 'cos', 'return_type' => Type::FLOAT, 'min_args' => 0, 'max_args' => 0],
            'tan'     => ['handler' => 'php_fn', 'fn' => 'tan', 'return_type' => Type::FLOAT, 'min_args' => 0, 'max_args' => 0],
            'asin'    => ['handler' => 'php_fn', 'fn' => 'asin', 'return_type' => Type::FLOAT, 'min_args' => 0, 'max_args' => 0],
            'acos'    => ['handler' => 'php_fn', 'fn' => 'acos', 'return_type' => Type::FLOAT, 'min_args' => 0, 'max_args' => 0],
            'atan'    => ['handler' => 'php_fn', 'fn' => 'atan', 'return_type' => Type::FLOAT, 'min_args' => 0, 'max_args' => 0],
            'atan2'   => ['handler' => 'php_fn', 'fn' => 'atan2', 'return_type' => Type::FLOAT, 'min_args' => 1, 'max_args' => 1],
            'deg2rad' => ['handler' => 'php_fn', 'fn' => 'deg2rad', 'return_type' => Type::FLOAT, 'min_args' => 0, 'max_args' => 0],
            'rad2deg' => ['handler' => 'php_fn', 'fn' => 'rad2deg', 'return_type' => Type::FLOAT, 'min_args' => 0, 'max_args' => 0],
            'max'     => ['handler' => 'php_fn', 'fn' => 'max', 'return_type' => Type::INT, 'min_args' => 1, 'max_args' => 1],
            'min'     => ['handler' => 'php_fn', 'fn' => 'min', 'return_type' => Type::INT, 'min_args' => 1, 'max_args' => 1],
        ],
        Type::FLOAT => [
            'add'      => ['handler' => 'calc_op', 'op' => '+', 'return_type' => Type::FLOAT, 'min_args' => 1, 'max_args' => 1],
            'sub'      => ['handler' => 'calc_op', 'op' => '-', 'return_type' => Type::FLOAT, 'min_args' => 1, 'max_args' => 1],
            'mul'      => ['handler' => 'calc_op', 'op' => '*', 'return_type' => Type::FLOAT, 'min_args' => 1, 'max_args' => 1],
            'div'      => ['handler' => 'calc_op', 'op' => '/', 'return_type' => Type::FLOAT, 'min_args' => 1, 'max_args' => 1],
            'inc'      => ['handler' => 'calc_inc', 'return_type' => Type::FLOAT, 'min_args' => 0, 'max_args' => 0],
            'dec'      => ['handler' => 'calc_dec', 'return_type' => Type::FLOAT, 'min_args' => 0, 'max_args' => 0],
            // math
            'abs'     => ['handler' => 'php_fn', 'fn' => 'abs', 'return_type' => Type::FLOAT, 'min_args' => 0, 'max_args' => 0],
            'ceil'    => ['handler' => 'php_fn', 'fn' => 'ceil', 'return_type' => Type::FLOAT, 'min_args' => 0, 'max_args' => 0],
            'floor'   => ['handler' => 'php_fn', 'fn' => 'floor', 'return_type' => Type::FLOAT, 'min_args' => 0, 'max_args' => 0],
            'round'   => ['handler' => 'php_fn', 'fn' => 'round', 'return_type' => Type::FLOAT, 'min_args' => 0, 'max_args' => 2],
            'sqrt'    => ['handler' => 'php_fn', 'fn' => 'sqrt', 'return_type' => Type::FLOAT, 'min_args' => 0, 'max_args' => 0],
            'pow'     => ['handler' => 'php_fn', 'fn' => 'pow', 'return_type' => Type::FLOAT, 'min_args' => 1, 'max_args' => 1],
            'log'     => ['handler' => 'php_fn', 'fn' => 'log', 'return_type' => Type::FLOAT, 'min_args' => 0, 'max_args' => 1],
            'log10'   => ['handler' => 'php_fn', 'fn' => 'log10', 'return_type' => Type::FLOAT, 'min_args' => 0, 'max_args' => 0],
            'exp'     => ['handler' => 'php_fn', 'fn' => 'exp', 'return_type' => Type::FLOAT, 'min_args' => 0, 'max_args' => 0],
            'sin'     => ['handler' => 'php_fn', 'fn' => 'sin', 'return_type' => Type::FLOAT, 'min_args' => 0, 'max_args' => 0],
            'cos'     => ['handler' => 'php_fn', 'fn' => 'cos', 'return_type' => Type::FLOAT, 'min_args' => 0, 'max_args' => 0],
            'tan'     => ['handler' => 'php_fn', 'fn' => 'tan', 'return_type' => Type::FLOAT, 'min_args' => 0, 'max_args' => 0],
            'asin'    => ['handler' => 'php_fn', 'fn' => 'asin', 'return_type' => Type::FLOAT, 'min_args' => 0, 'max_args' => 0],
            'acos'    => ['handler' => 'php_fn', 'fn' => 'acos', 'return_type' => Type::FLOAT, 'min_args' => 0, 'max_args' => 0],
            'atan'    => ['handler' => 'php_fn', 'fn' => 'atan', 'return_type' => Type::FLOAT, 'min_args' => 0, 'max_args' => 0],
            'atan2'   => ['handler' => 'php_fn', 'fn' => 'atan2', 'return_type' => Type::FLOAT, 'min_args' => 1, 'max_args' => 1],
            'deg2rad' => ['handler' => 'php_fn', 'fn' => 'deg2rad', 'return_type' => Type::FLOAT, 'min_args' => 0, 'max_args' => 0],
            'rad2deg' => ['handler' => 'php_fn', 'fn' => 'rad2deg', 'return_type' => Type::FLOAT, 'min_args' => 0, 'max_args' => 0],
            'max'     => ['handler' => 'php_fn', 'fn' => 'max', 'return_type' => Type::FLOAT, 'min_args' => 1, 'max_args' => 1],
            'min'     => ['handler' => 'php_fn', 'fn' => 'min', 'return_type' => Type::FLOAT, 'min_args' => 1, 'max_args' => 1],
        ],
        Type::BOOL => [
        ],
        Type::STR => [
            // --- stdext string_methods (all use PHP standard functions) ---
            'length'                => ['handler' => 'php_fn', 'fn' => 'strlen', 'return_type' => Type::INT, 'min_args' => 0, 'max_args' => 0],
            'isEmpty'               => ['handler' => 'direct_method', 'method' => 'empty', 'return_type' => Type::BOOL, 'min_args' => 0, 'max_args' => 0],
            'lower'                 => ['handler' => 'php_fn', 'fn' => 'strtolower', 'return_type' => Type::STR, 'min_args' => 0, 'max_args' => 0],
            'upper'                 => ['handler' => 'php_fn', 'fn' => 'strtoupper', 'return_type' => Type::STR, 'min_args' => 0, 'max_args' => 0],
            'lowerFirst'            => ['handler' => 'php_fn', 'fn' => 'lcfirst', 'return_type' => Type::STR, 'min_args' => 0, 'max_args' => 0],
            'upperFirst'            => ['handler' => 'php_fn', 'fn' => 'ucfirst', 'return_type' => Type::STR, 'min_args' => 0, 'max_args' => 0],
            'upperWords'            => ['handler' => 'php_fn', 'fn' => 'ucwords', 'return_type' => Type::STR, 'min_args' => 0, 'max_args' => 1],
            'addCSlashes'           => ['handler' => 'php_fn', 'fn' => 'addcslashes', 'return_type' => Type::STR, 'min_args' => 1, 'max_args' => 1],
            'addSlashes'            => ['handler' => 'php_fn', 'fn' => 'addslashes', 'return_type' => Type::STR, 'min_args' => 0, 'max_args' => 0],
            'chunkSplit'            => ['handler' => 'php_fn', 'fn' => 'chunk_split', 'return_type' => Type::STR, 'min_args' => 0, 'max_args' => 2],
            'countChars'            => ['handler' => 'php_fn', 'fn' => 'count_chars', 'return_type' => Type::VAR, 'min_args' => 0, 'max_args' => 1],
            'htmlEntityDecode'      => ['handler' => 'php_fn', 'fn' => 'html_entity_decode', 'return_type' => Type::STR, 'min_args' => 0, 'max_args' => 2],
            'htmlEntityEncode'      => ['handler' => 'php_fn', 'fn' => 'htmlentities', 'return_type' => Type::STR, 'min_args' => 0, 'max_args' => 3],
            'htmlSpecialCharsEncode' => ['handler' => 'php_fn', 'fn' => 'htmlspecialchars', 'return_type' => Type::STR, 'min_args' => 0, 'max_args' => 3],
            'htmlSpecialCharsDecode' => ['handler' => 'php_fn', 'fn' => 'htmlspecialchars_decode', 'return_type' => Type::STR, 'min_args' => 0, 'max_args' => 1],
            'trim'                  => ['handler' => 'php_fn', 'fn' => 'trim', 'return_type' => Type::STR, 'min_args' => 0, 'max_args' => 2],
            'lTrim'                 => ['handler' => 'php_fn', 'fn' => 'ltrim', 'return_type' => Type::STR, 'min_args' => 0, 'max_args' => 1],
            'rTrim'                 => ['handler' => 'php_fn', 'fn' => 'rtrim', 'return_type' => Type::STR, 'min_args' => 0, 'max_args' => 1],
            'parseStr'              => ['handler' => 'cpp_fn', 'fn' => 'php::fn::parse_str', 'return_type' => Type::ARRAY, 'min_args' => 0, 'max_args' => 0],
            'parseUrl'              => ['handler' => 'php_fn', 'fn' => 'parse_url', 'return_type' => Type::VAR, 'min_args' => 0, 'max_args' => 1],
            'contains'              => ['handler' => 'php_fn', 'fn' => 'str_contains', 'return_type' => Type::BOOL, 'min_args' => 1, 'max_args' => 1],
            'incr'                  => ['handler' => 'php_fn', 'fn' => 'str_increment', 'return_type' => Type::STR, 'min_args' => 0, 'max_args' => 0],
            'decr'                  => ['handler' => 'php_fn', 'fn' => 'str_decrement', 'return_type' => Type::STR, 'min_args' => 0, 'max_args' => 0],
            'pad'                   => ['handler' => 'php_fn', 'fn' => 'str_pad', 'return_type' => Type::STR, 'min_args' => 1, 'max_args' => 3],
            'repeat'                => ['handler' => 'php_fn', 'fn' => 'str_repeat', 'return_type' => Type::STR, 'min_args' => 1, 'max_args' => 1],
            'replace'               => ['handler' => 'php_fn', 'fn' => 'str_replace', 'receiver_pos' => 3, 'return_type' => Type::STR, 'min_args' => 2, 'max_args' => 3],
            'iReplace'              => ['handler' => 'php_fn', 'fn' => 'str_ireplace', 'receiver_pos' => 3, 'return_type' => Type::STR, 'min_args' => 2, 'max_args' => 3],
            'shuffle'               => ['handler' => 'php_fn', 'fn' => 'str_shuffle', 'return_type' => Type::STR, 'min_args' => 0, 'max_args' => 0],
            'split'                 => ['handler' => 'php_fn', 'fn' => 'explode', 'receiver_pos' => 2, 'return_type' => Type::ARRAY, 'min_args' => 1, 'max_args' => 2],
            'startsWith'            => ['handler' => 'php_fn', 'fn' => 'str_starts_with', 'return_type' => Type::BOOL, 'min_args' => 1, 'max_args' => 1],
            'endsWith'              => ['handler' => 'php_fn', 'fn' => 'str_ends_with', 'return_type' => Type::BOOL, 'min_args' => 1, 'max_args' => 1],
            'wordCount'             => ['handler' => 'php_fn', 'fn' => 'str_word_count', 'return_type' => Type::VAR, 'min_args' => 0, 'max_args' => 2],
            'iCompare'              => ['handler' => 'php_fn', 'fn' => 'strcasecmp', 'return_type' => Type::INT, 'min_args' => 1, 'max_args' => 1],
            'compare'               => ['handler' => 'php_fn', 'fn' => 'strcmp', 'return_type' => Type::INT, 'min_args' => 1, 'max_args' => 1],
            'find'                  => ['handler' => 'php_fn', 'fn' => 'strstr', 'return_type' => Type::VAR, 'min_args' => 1, 'max_args' => 2],
            'iFind'                 => ['handler' => 'php_fn', 'fn' => 'stristr', 'return_type' => Type::VAR, 'min_args' => 1, 'max_args' => 2],
            'stripTags'             => ['handler' => 'php_fn', 'fn' => 'strip_tags', 'return_type' => Type::STR, 'min_args' => 0, 'max_args' => 2],
            'stripCSlashes'         => ['handler' => 'php_fn', 'fn' => 'stripcslashes', 'return_type' => Type::STR, 'min_args' => 0, 'max_args' => 0],
            'stripSlashes'          => ['handler' => 'php_fn', 'fn' => 'stripslashes', 'return_type' => Type::STR, 'min_args' => 0, 'max_args' => 0],
            'iIndexOf'              => ['handler' => 'php_fn', 'fn' => 'stripos', 'return_type' => Type::VAR, 'min_args' => 1, 'max_args' => 2],
            'indexOf'               => ['handler' => 'php_fn', 'fn' => 'strpos', 'return_type' => Type::VAR, 'min_args' => 1, 'max_args' => 2],
            'lastIndexOf'           => ['handler' => 'php_fn', 'fn' => 'strrpos', 'return_type' => Type::VAR, 'min_args' => 1, 'max_args' => 2],
            'iLastIndexOf'          => ['handler' => 'php_fn', 'fn' => 'strripos', 'return_type' => Type::VAR, 'min_args' => 1, 'max_args' => 2],
            'lastCharIndexOf'       => ['handler' => 'php_fn', 'fn' => 'strrchr', 'return_type' => Type::VAR, 'min_args' => 1, 'max_args' => 1],
            'substr'                => ['handler' => 'php_fn', 'fn' => 'substr', 'return_type' => Type::STR, 'min_args' => 1, 'max_args' => 2],
            'substrCompare'         => ['handler' => 'php_fn', 'fn' => 'substr_compare', 'return_type' => Type::INT, 'min_args' => 1, 'max_args' => 4],
            'substrCount'           => ['handler' => 'php_fn', 'fn' => 'substr_count', 'return_type' => Type::INT, 'min_args' => 1, 'max_args' => 3],
            'substrReplace'         => ['handler' => 'php_fn', 'fn' => 'substr_replace', 'return_type' => Type::STR, 'min_args' => 1, 'max_args' => 3],
            'reverse'               => ['handler' => 'php_fn', 'fn' => 'strrev', 'return_type' => Type::STR, 'min_args' => 0, 'max_args' => 0],
            'md5'                   => ['handler' => 'php_fn', 'fn' => 'md5', 'return_type' => Type::STR, 'min_args' => 0, 'max_args' => 1],
            'sha1'                  => ['handler' => 'php_fn', 'fn' => 'sha1', 'return_type' => Type::STR, 'min_args' => 0, 'max_args' => 1],
            'crc32'                 => ['handler' => 'php_fn', 'fn' => 'crc32', 'return_type' => Type::INT, 'min_args' => 0, 'max_args' => 0],
            'hash'                  => ['handler' => 'php_fn', 'fn' => 'hash', 'receiver_pos' => 2, 'return_type' => Type::STR, 'min_args' => 1, 'max_args' => 2],
            'hashCode'              => ['handler' => 'direct_method', 'method' => 'hashCode', 'return_type' => Type::INT, 'min_args' => 0, 'max_args' => 0],
            'base64Decode'          => ['handler' => 'php_fn', 'fn' => 'base64_decode', 'return_type' => Type::STR, 'min_args' => 0, 'max_args' => 0],
            'base64Encode'          => ['handler' => 'php_fn', 'fn' => 'base64_encode', 'return_type' => Type::STR, 'min_args' => 0, 'max_args' => 0],
            'urlDecode'             => ['handler' => 'php_fn', 'fn' => 'urldecode', 'return_type' => Type::STR, 'min_args' => 0, 'max_args' => 0],
            'urlEncode'             => ['handler' => 'php_fn', 'fn' => 'urlencode', 'return_type' => Type::STR, 'min_args' => 0, 'max_args' => 0],
            'rawUrlEncode'          => ['handler' => 'php_fn', 'fn' => 'rawurlencode', 'return_type' => Type::STR, 'min_args' => 0, 'max_args' => 0],
            'rawUrlDecode'          => ['handler' => 'php_fn', 'fn' => 'rawurldecode', 'return_type' => Type::STR, 'min_args' => 0, 'max_args' => 0],
            'match'                 => ['handler' => 'direct_method', 'method' => 'match', 'return_type' => Type::ARRAY, 'min_args' => 1, 'max_args' => 3, 'int_cast_args' => [1, 2]],
            'matchAll'              => ['handler' => 'direct_method', 'method' => 'matchAll', 'return_type' => Type::ARRAY, 'min_args' => 1, 'max_args' => 3, 'int_cast_args' => [1, 2]],
            'isNumeric'             => ['handler' => 'php_fn', 'fn' => 'is_numeric', 'return_type' => Type::BOOL, 'min_args' => 0, 'max_args' => 0],
            // mbstring
            'mbUpperFirst'          => ['handler' => 'php_fn', 'fn' => 'mb_ucfirst', 'return_type' => Type::STR, 'min_args' => 0, 'max_args' => 1],
            'mbLowerFirst'          => ['handler' => 'php_fn', 'fn' => 'mb_lcfirst', 'return_type' => Type::STR, 'min_args' => 0, 'max_args' => 1],
            'mbTrim'                => ['handler' => 'php_fn', 'fn' => 'mb_trim', 'return_type' => Type::STR, 'min_args' => 0, 'max_args' => 1],
            'mbSubstrCount'         => ['handler' => 'php_fn', 'fn' => 'mb_substr_count', 'return_type' => Type::INT, 'min_args' => 1, 'max_args' => 2],
            'mbSubstr'              => ['handler' => 'php_fn', 'fn' => 'mb_substr', 'return_type' => Type::STR, 'min_args' => 1, 'max_args' => 3],
            'mbUpper'               => ['handler' => 'php_fn', 'fn' => 'mb_strtoupper', 'return_type' => Type::STR, 'min_args' => 0, 'max_args' => 1],
            'mbLower'               => ['handler' => 'php_fn', 'fn' => 'mb_strtolower', 'return_type' => Type::STR, 'min_args' => 0, 'max_args' => 1],
            'mbFind'                => ['handler' => 'php_fn', 'fn' => 'mb_strstr', 'return_type' => Type::VAR, 'min_args' => 1, 'max_args' => 3],
            'mbIndexOf'             => ['handler' => 'php_fn', 'fn' => 'mb_strpos', 'return_type' => Type::VAR, 'min_args' => 1, 'max_args' => 3],
            'mbLastIndexOf'         => ['handler' => 'php_fn', 'fn' => 'mb_strrpos', 'return_type' => Type::VAR, 'min_args' => 1, 'max_args' => 3],
            'mbILastIndexOf'        => ['handler' => 'php_fn', 'fn' => 'mb_strripos', 'return_type' => Type::VAR, 'min_args' => 1, 'max_args' => 3],
            'mbLastCharIndexOf'     => ['handler' => 'php_fn', 'fn' => 'mb_strrchr', 'return_type' => Type::VAR, 'min_args' => 1, 'max_args' => 3],
            'mbILastCharIndex'      => ['handler' => 'php_fn', 'fn' => 'mb_strrichr', 'return_type' => Type::VAR, 'min_args' => 1, 'max_args' => 3],
            'mbLength'              => ['handler' => 'php_fn', 'fn' => 'mb_strlen', 'return_type' => Type::INT, 'min_args' => 0, 'max_args' => 1],
            'mbIFind'               => ['handler' => 'php_fn', 'fn' => 'mb_stristr', 'return_type' => Type::VAR, 'min_args' => 1, 'max_args' => 3],
            'mbIIndexOf'            => ['handler' => 'php_fn', 'fn' => 'mb_stripos', 'return_type' => Type::VAR, 'min_args' => 1, 'max_args' => 3],
            'mbCut'                 => ['handler' => 'php_fn', 'fn' => 'mb_strcut', 'return_type' => Type::STR, 'min_args' => 1, 'max_args' => 3],
            'mbRTrim'               => ['handler' => 'php_fn', 'fn' => 'mb_rtrim', 'return_type' => Type::STR, 'min_args' => 0, 'max_args' => 1],
            'mbLTrim'               => ['handler' => 'php_fn', 'fn' => 'mb_ltrim', 'return_type' => Type::STR, 'min_args' => 0, 'max_args' => 1],
            'mbDetectEncoding'      => ['handler' => 'php_fn', 'fn' => 'mb_detect_encoding', 'return_type' => Type::STR, 'min_args' => 0, 'max_args' => 2],
            'mbConvertEncoding'     => ['handler' => 'php_fn', 'fn' => 'mb_convert_encoding', 'return_type' => Type::STR, 'min_args' => 1, 'max_args' => 2],
            'mbConvertCase'         => ['handler' => 'php_fn', 'fn' => 'mb_convert_case', 'return_type' => Type::STR, 'min_args' => 0, 'max_args' => 2],
            // serialize
            'unserialize'           => ['handler' => 'php_fn', 'fn' => 'unserialize', 'return_type' => Type::VAR, 'min_args' => 0, 'max_args' => 0],
            'unmarshal'             => ['handler' => 'php_fn', 'fn' => 'unserialize', 'return_type' => Type::VAR, 'min_args' => 0, 'max_args' => 0],
            'jsonDecode'            => ['handler' => 'php_fn', 'fn' => 'json_decode', 'return_type' => Type::VAR, 'min_args' => 0, 'max_args' => 2, 'const_args' => [1 => 'true']],
            'jsonDecodeToObject'    => ['handler' => 'php_fn', 'fn' => 'json_decode', 'return_type' => Type::OBJECT, 'min_args' => 0, 'max_args' => 2, 'const_args' => [1 => 'false']],
            // phpx C++ methods (no PHP function equivalent)
            'equals'                => ['handler' => 'direct_method', 'method' => 'equals', 'return_type' => Type::BOOL, 'min_args' => 1, 'max_args' => 2],
        ],
        Type::ARRAY => [
            // --- stdext array_methods (all use PHP standard functions) ---
            'all'               => ['handler' => 'php_fn', 'fn' => 'array_all', 'return_type' => Type::BOOL, 'min_args' => 0, 'max_args' => 1],
            'any'               => ['handler' => 'php_fn', 'fn' => 'array_any', 'return_type' => Type::BOOL, 'min_args' => 0, 'max_args' => 1],
            'changeKeyCase'     => ['handler' => 'php_fn', 'fn' => 'array_change_key_case', 'return_type' => Type::ARRAY, 'min_args' => 0, 'max_args' => 1],
            'chunk'             => ['handler' => 'php_fn', 'fn' => 'array_chunk', 'return_type' => Type::ARRAY, 'min_args' => 1, 'max_args' => 2],
            'column'            => ['handler' => 'php_fn', 'fn' => 'array_column', 'return_type' => Type::ARRAY, 'min_args' => 1, 'max_args' => 2],
            'countValues'       => ['handler' => 'php_fn', 'fn' => 'array_count_values', 'return_type' => Type::ARRAY, 'min_args' => 0, 'max_args' => 0],
            'diff'              => ['handler' => 'php_fn', 'fn' => 'array_diff', 'return_type' => Type::ARRAY, 'min_args' => 1, 'max_args' => -1],
            'diffAssoc'         => ['handler' => 'php_fn', 'fn' => 'array_diff_assoc', 'return_type' => Type::ARRAY, 'min_args' => 1, 'max_args' => -1],
            'diffKey'           => ['handler' => 'php_fn', 'fn' => 'array_diff_key', 'return_type' => Type::ARRAY, 'min_args' => 1, 'max_args' => -1],
            'filter'            => ['handler' => 'php_fn', 'fn' => 'array_filter', 'return_type' => Type::ARRAY, 'min_args' => 0, 'max_args' => 2],
            'find'              => ['handler' => 'php_fn', 'fn' => 'array_find', 'return_type' => Type::VAR, 'min_args' => 1, 'max_args' => 1],
            'flip'              => ['handler' => 'php_fn', 'fn' => 'array_flip', 'return_type' => Type::ARRAY, 'min_args' => 0, 'max_args' => 0],
            'intersect'         => ['handler' => 'php_fn', 'fn' => 'array_intersect', 'return_type' => Type::ARRAY, 'min_args' => 1, 'max_args' => -1],
            'intersectAssoc'    => ['handler' => 'php_fn', 'fn' => 'array_intersect_assoc', 'return_type' => Type::ARRAY, 'min_args' => 1, 'max_args' => -1],
            'isList'            => ['handler' => 'php_fn', 'fn' => 'array_is_list', 'return_type' => Type::BOOL, 'min_args' => 0, 'max_args' => 0],
            'keyExists'         => ['handler' => 'php_fn', 'fn' => 'array_key_exists', 'receiver_pos' => 2, 'return_type' => Type::BOOL, 'min_args' => 1, 'max_args' => 1],
            'keyFirst'          => ['handler' => 'php_fn', 'fn' => 'array_key_first', 'return_type' => Type::VAR, 'min_args' => 0, 'max_args' => 0],
            'keyLast'           => ['handler' => 'php_fn', 'fn' => 'array_key_last', 'return_type' => Type::VAR, 'min_args' => 0, 'max_args' => 0],
            'keys'              => ['handler' => 'php_fn', 'fn' => 'array_keys', 'return_type' => Type::ARRAY, 'min_args' => 0, 'max_args' => 2],
            'map'               => ['handler' => 'php_fn', 'fn' => 'array_map', 'receiver_pos' => 2, 'return_type' => Type::ARRAY, 'min_args' => 1, 'max_args' => -1],
            'pad'               => ['handler' => 'php_fn', 'fn' => 'array_pad', 'return_type' => Type::ARRAY, 'min_args' => 2, 'max_args' => 2],
            'product'           => ['handler' => 'php_fn', 'fn' => 'array_product', 'return_type' => Type::VAR, 'min_args' => 0, 'max_args' => 0],
            'rand'              => ['handler' => 'php_fn', 'fn' => 'array_rand', 'return_type' => Type::VAR, 'min_args' => 0, 'max_args' => 1],
            'reduce'            => ['handler' => 'php_fn', 'fn' => 'array_reduce', 'return_type' => Type::VAR, 'min_args' => 1, 'max_args' => 2],
            'replace'           => ['handler' => 'php_fn', 'fn' => 'array_replace', 'return_type' => Type::ARRAY, 'min_args' => 1, 'max_args' => -1],
            'reverse'           => ['handler' => 'php_fn', 'fn' => 'array_reverse', 'return_type' => Type::ARRAY, 'min_args' => 0, 'max_args' => 1],
            'search'            => ['handler' => 'php_fn', 'fn' => 'array_search', 'receiver_pos' => 2, 'return_type' => Type::VAR, 'min_args' => 1, 'max_args' => 2],
            'slice'             => ['handler' => 'php_fn', 'fn' => 'array_slice', 'return_type' => Type::ARRAY, 'min_args' => 1, 'max_args' => 3],
            'sum'               => ['handler' => 'php_fn', 'fn' => 'array_sum', 'return_type' => Type::VAR, 'min_args' => 0, 'max_args' => 0],
            'unique'            => ['handler' => 'php_fn', 'fn' => 'array_unique', 'return_type' => Type::ARRAY, 'min_args' => 0, 'max_args' => 1],
            'values'            => ['handler' => 'php_fn', 'fn' => 'array_values', 'return_type' => Type::ARRAY, 'min_args' => 0, 'max_args' => 0],
            'count'             => ['handler' => 'php_fn', 'fn' => 'count', 'return_type' => Type::INT, 'min_args' => 0, 'max_args' => 0],
            'merge'             => ['handler' => 'php_fn', 'fn' => 'array_merge', 'return_type' => Type::ARRAY, 'min_args' => 1, 'max_args' => -1],
            'contains'          => ['handler' => 'php_fn', 'fn' => 'in_array', 'receiver_pos' => 2, 'return_type' => Type::BOOL, 'min_args' => 1, 'max_args' => 2],
            'join'              => ['handler' => 'php_fn', 'fn' => 'implode', 'receiver_pos' => 2, 'return_type' => Type::STR, 'min_args' => 1, 'max_args' => 1],
            'isEmpty'           => ['handler' => 'direct_method', 'method' => 'empty', 'return_type' => Type::BOOL, 'min_args' => 0, 'max_args' => 0],
            // mutating via PHP reference functions
            'sort'              => ['handler' => 'php_fn_ref', 'fn' => 'sort', 'return_type' => Type::BOOL, 'min_args' => 0, 'max_args' => 1],
            'pop'               => ['handler' => 'php_fn_ref', 'fn' => 'array_pop', 'return_type' => Type::VAR, 'min_args' => 0, 'max_args' => 0],
            'push'              => ['handler' => 'php_fn_ref', 'fn' => 'array_push', 'return_type' => Type::VAR, 'min_args' => 1, 'max_args' => -1],
            'shift'             => ['handler' => 'php_fn_ref', 'fn' => 'array_shift', 'return_type' => Type::VAR, 'min_args' => 0, 'max_args' => 0],
            'unshift'           => ['handler' => 'php_fn_ref', 'fn' => 'array_unshift', 'return_type' => Type::INT, 'min_args' => 1, 'max_args' => -1],
            'splice'            => ['handler' => 'php_fn_ref', 'fn' => 'array_splice', 'return_type' => Type::ARRAY, 'min_args' => 1, 'max_args' => 3],
            'walk'              => ['handler' => 'php_fn_ref', 'fn' => 'array_walk', 'return_type' => Type::BOOL, 'min_args' => 1, 'max_args' => 2],
            'sortDesc'          => ['handler' => 'php_fn_ref', 'fn' => 'rsort', 'return_type' => Type::BOOL, 'min_args' => 0, 'max_args' => 1],
            'keySort'           => ['handler' => 'php_fn_ref', 'fn' => 'ksort', 'return_type' => Type::BOOL, 'min_args' => 0, 'max_args' => 1],
            'valueSort'         => ['handler' => 'php_fn_ref', 'fn' => 'asort', 'return_type' => Type::BOOL, 'min_args' => 0, 'max_args' => 1],
            'combine'           => ['handler' => 'php_fn', 'fn' => 'array_combine', 'return_type' => Type::ARRAY, 'min_args' => 1, 'max_args' => 1],
            'fillKeys'          => ['handler' => 'php_fn', 'fn' => 'array_fill_keys', 'return_type' => Type::ARRAY, 'min_args' => 1, 'max_args' => 1],
            'replaceStr'        => ['handler' => 'php_fn', 'fn' => 'str_replace', 'receiver_pos' => 3, 'return_type' => Type::ARRAY, 'min_args' => 1, 'max_args' => 2],
            'iReplaceStr'       => ['handler' => 'php_fn', 'fn' => 'str_ireplace', 'receiver_pos' => 3, 'return_type' => Type::ARRAY, 'min_args' => 1, 'max_args' => 2],
            // serialize
            'serialize'         => ['handler' => 'php_fn', 'fn' => 'serialize', 'return_type' => Type::STR, 'min_args' => 0, 'max_args' => 0],
            'marshal'           => ['handler' => 'php_fn', 'fn' => 'serialize', 'return_type' => Type::STR, 'min_args' => 0, 'max_args' => 0],
            'jsonEncode'        => ['handler' => 'php_fn', 'fn' => 'json_encode', 'return_type' => Type::STR, 'min_args' => 0, 'max_args' => 2],
            // phpx C++ methods (no PHP function equivalent)
            'set'               => ['handler' => 'direct_method_mutate', 'method' => 'set', 'return_type' => Type::ARRAY, 'min_args' => 2, 'max_args' => 2],
            'get'               => ['handler' => 'direct_method', 'method' => 'get', 'return_type' => Type::VAR, 'min_args' => 1, 'max_args' => 1],
            'del'               => ['handler' => 'direct_method_mutate', 'method' => 'del', 'return_type' => Type::ARRAY, 'min_args' => 1, 'max_args' => 1],
            'clean'             => ['handler' => 'direct_method_mutate', 'method' => 'clean', 'return_type' => Type::ARRAY, 'min_args' => 0, 'max_args' => 0],
        ],
        Type::STREAM => [
            // --- stdext stream_methods ---
            'write'             => ['handler' => 'php_fn', 'fn' => 'fwrite', 'return_type' => Type::INT, 'min_args' => 1, 'max_args' => 2],
            'read'              => ['handler' => 'php_fn', 'fn' => 'fread', 'return_type' => Type::STR, 'min_args' => 1, 'max_args' => 1],
            'close'             => ['handler' => 'php_fn', 'fn' => 'fclose', 'return_type' => Type::BOOL, 'min_args' => 0, 'max_args' => 0],
            'dataSync'          => ['handler' => 'php_fn', 'fn' => 'fdatasync', 'return_type' => Type::BOOL, 'min_args' => 0, 'max_args' => 0],
            'sync'              => ['handler' => 'php_fn', 'fn' => 'fsync', 'return_type' => Type::BOOL, 'min_args' => 0, 'max_args' => 0],
            'truncate'          => ['handler' => 'php_fn', 'fn' => 'ftruncate', 'return_type' => Type::BOOL, 'min_args' => 0, 'max_args' => 1],
            'stat'              => ['handler' => 'php_fn', 'fn' => 'fstat', 'return_type' => Type::ARRAY, 'min_args' => 0, 'max_args' => 0],
            'seek'              => ['handler' => 'php_fn', 'fn' => 'fseek', 'return_type' => Type::INT, 'min_args' => 1, 'max_args' => 2],
            'tell'              => ['handler' => 'php_fn', 'fn' => 'ftell', 'return_type' => Type::INT, 'min_args' => 0, 'max_args' => 0],
            'lock'              => ['handler' => 'php_fn', 'fn' => 'flock', 'return_type' => Type::BOOL, 'min_args' => 1, 'max_args' => 2],
            'eof'               => ['handler' => 'php_fn', 'fn' => 'feof', 'return_type' => Type::BOOL, 'min_args' => 0, 'max_args' => 0],
            'getChar'           => ['handler' => 'php_fn', 'fn' => 'fgetc', 'return_type' => Type::STR, 'min_args' => 0, 'max_args' => 0],
            'getLine'           => ['handler' => 'php_fn', 'fn' => 'fgets', 'return_type' => Type::STR, 'min_args' => 0, 'max_args' => 1],
            // --- stream_* functions ---
            'getContents'       => ['handler' => 'php_fn', 'fn' => 'stream_get_contents', 'return_type' => Type::STR, 'min_args' => 0, 'max_args' => 2],
            'getMetaData'       => ['handler' => 'php_fn', 'fn' => 'stream_get_meta_data', 'return_type' => Type::ARRAY, 'min_args' => 0, 'max_args' => 0],
            'isLocal'           => ['handler' => 'php_fn', 'fn' => 'stream_is_local', 'return_type' => Type::BOOL, 'min_args' => 0, 'max_args' => 0],
            'isTTY'             => ['handler' => 'php_fn', 'fn' => 'stream_isatty', 'return_type' => Type::BOOL, 'min_args' => 0, 'max_args' => 0],
            'setBlocking'       => ['handler' => 'php_fn', 'fn' => 'stream_set_blocking', 'return_type' => Type::BOOL, 'min_args' => 1, 'max_args' => 1],
            'setChunkSize'      => ['handler' => 'php_fn', 'fn' => 'stream_set_chunk_size', 'return_type' => Type::INT, 'min_args' => 1, 'max_args' => 1],
            'setReadBuffer'     => ['handler' => 'php_fn', 'fn' => 'stream_set_read_buffer', 'return_type' => Type::INT, 'min_args' => 1, 'max_args' => 1],
            'setTimeout'        => ['handler' => 'php_fn', 'fn' => 'stream_set_timeout', 'return_type' => Type::BOOL, 'min_args' => 1, 'max_args' => 2],
            'setWriteBuffer'    => ['handler' => 'php_fn', 'fn' => 'stream_set_write_buffer', 'return_type' => Type::INT, 'min_args' => 1, 'max_args' => 1],
            'supportsLock'      => ['handler' => 'php_fn', 'fn' => 'stream_supports_lock', 'return_type' => Type::BOOL, 'min_args' => 0, 'max_args' => 0],
            'copy'              => ['handler' => 'php_fn', 'fn' => 'stream_copy_to_stream', 'return_type' => Type::INT, 'min_args' => 1, 'max_args' => 3],
            // --- stream_socket_* functions ---
            'accept'            => ['handler' => 'php_fn', 'fn' => 'stream_socket_accept', 'return_type' => Type::STREAM, 'min_args' => 0, 'max_args' => 1],
            'enableCrypto'      => ['handler' => 'php_fn', 'fn' => 'stream_socket_enable_crypto', 'return_type' => Type::INT, 'min_args' => 1, 'max_args' => 3],
            'getSocketName'     => ['handler' => 'php_fn', 'fn' => 'stream_socket_get_name', 'return_type' => Type::STR, 'min_args' => 1, 'max_args' => 1],
            'recvFrom'          => ['handler' => 'php_fn', 'fn' => 'stream_socket_recvfrom', 'return_type' => Type::STR, 'min_args' => 1, 'max_args' => 2],
            'sendTo'            => ['handler' => 'php_fn', 'fn' => 'stream_socket_sendto', 'return_type' => Type::INT, 'min_args' => 1, 'max_args' => 3],
            'shutdown'          => ['handler' => 'php_fn', 'fn' => 'stream_socket_shutdown', 'return_type' => Type::BOOL, 'min_args' => 1, 'max_args' => 1],
            'getRecord'         => ['handler' => 'php_fn', 'fn' => 'stream_get_line', 'return_type' => Type::STR, 'min_args' => 0, 'max_args' => 2],
            // --- stream filters ---
            'appendFilter'      => ['handler' => 'php_fn', 'fn' => 'stream_filter_append', 'return_type' => Type::VAR, 'min_args' => 1, 'max_args' => 3],
            'prependFilter'     => ['handler' => 'php_fn', 'fn' => 'stream_filter_prepend', 'return_type' => Type::VAR, 'min_args' => 1, 'max_args' => 3],
        ],
        Type::BIGINT => [
            'add'          => ['handler' => 'cpp_fn', 'fn' => 'php::BigInt::add', 'return_type' => Type::BIGINT, 'min_args' => 1, 'max_args' => 1],
            'sub'          => ['handler' => 'cpp_fn', 'fn' => 'php::BigInt::sub', 'return_type' => Type::BIGINT, 'min_args' => 1, 'max_args' => 1],
            'mul'          => ['handler' => 'cpp_fn', 'fn' => 'php::BigInt::mul', 'return_type' => Type::BIGINT, 'min_args' => 1, 'max_args' => 1],
            'div'          => ['handler' => 'cpp_fn', 'fn' => 'php::BigInt::div', 'return_type' => Type::BIGINT, 'min_args' => 1, 'max_args' => 1],
            'mod'          => ['handler' => 'cpp_fn', 'fn' => 'php::BigInt::mod', 'return_type' => Type::BIGINT, 'min_args' => 1, 'max_args' => 1],
            'pow'          => ['handler' => 'cpp_fn', 'fn' => 'php::BigInt::pow', 'return_type' => Type::BIGINT, 'min_args' => 1, 'max_args' => 1],
            'neg'          => ['handler' => 'cpp_fn', 'fn' => 'php::BigInt::neg', 'return_type' => Type::BIGINT, 'min_args' => 0, 'max_args' => 0],
            'cmp'          => ['handler' => 'cpp_fn', 'fn' => 'php::BigInt::cmp', 'return_type' => Type::INT, 'min_args' => 1, 'max_args' => 1],
            'abs'          => ['handler' => 'cpp_fn', 'fn' => 'php::BigInt::abs', 'return_type' => Type::BIGINT, 'min_args' => 0, 'max_args' => 0],
            'gcd'          => ['handler' => 'cpp_fn', 'fn' => 'php::BigInt::gcd', 'return_type' => Type::BIGINT, 'min_args' => 1, 'max_args' => 1],
            'divmod'       => ['handler' => 'cpp_fn', 'fn' => 'php::BigInt::divmod', 'return_type' => Type::ARRAY, 'min_args' => 1, 'max_args' => 1],
            'powmod'       => ['handler' => 'cpp_fn', 'fn' => 'php::BigInt::powmod', 'return_type' => Type::BIGINT, 'min_args' => 2, 'max_args' => 2],
            'sqrt'         => ['handler' => 'cpp_fn', 'fn' => 'php::BigInt::sqrt', 'return_type' => Type::BIGINT, 'min_args' => 0, 'max_args' => 0],
            'bitAnd'       => ['handler' => 'cpp_fn', 'fn' => 'php::BigInt::bitAnd', 'return_type' => Type::BIGINT, 'min_args' => 1, 'max_args' => 1],
            'bitOr'        => ['handler' => 'cpp_fn', 'fn' => 'php::BigInt::bitOr', 'return_type' => Type::BIGINT, 'min_args' => 1, 'max_args' => 1],
            'bitXor'       => ['handler' => 'cpp_fn', 'fn' => 'php::BigInt::bitXor', 'return_type' => Type::BIGINT, 'min_args' => 1, 'max_args' => 1],
            'bitNot'       => ['handler' => 'cpp_fn', 'fn' => 'php::BigInt::bitNot', 'return_type' => Type::BIGINT, 'min_args' => 0, 'max_args' => 0],
            'testBit'        => ['handler' => 'cpp_fn', 'fn' => 'php::BigInt::testBit', 'return_type' => Type::INT, 'min_args' => 1, 'max_args' => 1],
            'popCount'       => ['handler' => 'cpp_fn', 'fn' => 'php::BigInt::popCount', 'return_type' => Type::INT, 'min_args' => 0, 'max_args' => 0],
            'bitShiftLeft'   => ['handler' => 'cpp_fn', 'fn' => 'php::BigInt::bitShiftLeft', 'return_type' => Type::BIGINT, 'min_args' => 1, 'max_args' => 1],
            'bitShiftRight'  => ['handler' => 'cpp_fn', 'fn' => 'php::BigInt::bitShiftRight', 'return_type' => Type::BIGINT, 'min_args' => 1, 'max_args' => 1],
        ],
        Type::DECIMAL => [
            'add'      => ['handler' => 'cpp_fn', 'fn' => 'php::Decimal::add', 'return_type' => Type::DECIMAL, 'min_args' => 1, 'max_args' => 1],
            'sub'      => ['handler' => 'cpp_fn', 'fn' => 'php::Decimal::sub', 'return_type' => Type::DECIMAL, 'min_args' => 1, 'max_args' => 1],
            'mul'      => ['handler' => 'cpp_fn', 'fn' => 'php::Decimal::mul', 'return_type' => Type::DECIMAL, 'min_args' => 1, 'max_args' => 1],
            'div'      => ['handler' => 'cpp_fn', 'fn' => 'php::Decimal::div', 'return_type' => Type::DECIMAL, 'min_args' => 1, 'max_args' => 1],
            'mod'      => ['handler' => 'cpp_fn', 'fn' => 'php::Decimal::mod', 'return_type' => Type::DECIMAL, 'min_args' => 1, 'max_args' => 1],
            'pow'      => ['handler' => 'cpp_fn', 'fn' => 'php::Decimal::pow', 'return_type' => Type::DECIMAL, 'min_args' => 1, 'max_args' => 1],
            'neg'      => ['handler' => 'cpp_fn', 'fn' => 'php::Decimal::neg', 'return_type' => Type::DECIMAL, 'min_args' => 0, 'max_args' => 0],
            'cmp'      => ['handler' => 'cpp_fn', 'fn' => 'php::Decimal::cmp', 'return_type' => Type::INT, 'min_args' => 1, 'max_args' => 1],
            'abs'      => ['handler' => 'cpp_fn', 'fn' => 'php::Decimal::abs', 'return_type' => Type::DECIMAL, 'min_args' => 0, 'max_args' => 0],
            'divmod'   => ['handler' => 'cpp_fn', 'fn' => 'php::Decimal::divmod', 'return_type' => Type::ARRAY, 'min_args' => 1, 'max_args' => 1],
            'powmod'   => ['handler' => 'cpp_fn', 'fn' => 'php::Decimal::powmod', 'return_type' => Type::DECIMAL, 'min_args' => 2, 'max_args' => 2],
            'sqrt'     => ['handler' => 'cpp_fn', 'fn' => 'php::Decimal::sqrt', 'return_type' => Type::DECIMAL, 'min_args' => 0, 'max_args' => 0],
            'floor'    => ['handler' => 'cpp_fn', 'fn' => 'php::Decimal::floor', 'return_type' => Type::DECIMAL, 'min_args' => 0, 'max_args' => 0],
            'ceil'     => ['handler' => 'cpp_fn', 'fn' => 'php::Decimal::ceil', 'return_type' => Type::DECIMAL, 'min_args' => 0, 'max_args' => 0],
            'round'    => ['handler' => 'cpp_fn', 'fn' => 'php::Decimal::round', 'return_type' => Type::DECIMAL, 'min_args' => 0, 'max_args' => 1],
        ],
        Type::BIGFLOAT => [
            'add'      => ['handler' => 'cpp_fn', 'fn' => 'php::BigFloat::add', 'return_type' => Type::BIGFLOAT, 'min_args' => 1, 'max_args' => 1],
            'sub'      => ['handler' => 'cpp_fn', 'fn' => 'php::BigFloat::sub', 'return_type' => Type::BIGFLOAT, 'min_args' => 1, 'max_args' => 1],
            'mul'      => ['handler' => 'cpp_fn', 'fn' => 'php::BigFloat::mul', 'return_type' => Type::BIGFLOAT, 'min_args' => 1, 'max_args' => 1],
            'div'      => ['handler' => 'cpp_fn', 'fn' => 'php::BigFloat::div', 'return_type' => Type::BIGFLOAT, 'min_args' => 1, 'max_args' => 1],
            'neg'      => ['handler' => 'cpp_fn', 'fn' => 'php::BigFloat::neg', 'return_type' => Type::BIGFLOAT, 'min_args' => 0, 'max_args' => 0],
            'cmp'      => ['handler' => 'cpp_fn', 'fn' => 'php::BigFloat::cmp', 'return_type' => Type::INT, 'min_args' => 1, 'max_args' => 1],
            'abs'      => ['handler' => 'cpp_fn', 'fn' => 'php::BigFloat::abs', 'return_type' => Type::BIGFLOAT, 'min_args' => 0, 'max_args' => 0],
            'sqrt'     => ['handler' => 'cpp_fn', 'fn' => 'php::BigFloat::sqrt', 'return_type' => Type::BIGFLOAT, 'min_args' => 0, 'max_args' => 0],
        ],
    ];

    protected const array MUTATING_HANDLERS = ['direct_method_mutate', 'php_fn_ref'];

    /** @var array<string, array<string, true>> */
    private const array UNIVERSAL_MUTATING_METHODS = [
        Type::ARRAY => [
            'sort' => true,
            'pop' => true,
            'push' => true,
            'shift' => true,
            'unshift' => true,
            'splice' => true,
            'walk' => true,
            'sortDesc' => true,
            'keySort' => true,
            'valueSort' => true,
            'set' => true,
            'del' => true,
            'clean' => true,
        ],
    ];

    /** @var array<string, string>|null Lowercase method name => declared spelling. */
    private static ?array $universalMethodNames = null;

    /**
     * PHP method names are case-insensitive, so builtin universal methods are
     * looked up by their canonical spelling. No two builtin tables spell the
     * same name differently.
     */
    protected function canonicalUniversalMethodName(string $method): string
    {
        if (self::$universalMethodNames === null) {
            self::$universalMethodNames = [];
            foreach (self::UNIVERSAL_METHODS as $methods) {
                foreach (array_keys($methods) as $name) {
                    self::$universalMethodNames[strtolower($name)] = $name;
                }
            }
        }
        return self::$universalMethodNames[strtolower($method)] ?? $method;
    }

    protected function isUniversalMethodMutating(string $type, string $method): bool
    {
        return isset(self::UNIVERSAL_MUTATING_METHODS[$type][$this->canonicalUniversalMethodName($method)]);
    }

    protected function detectUniversalMethodReturnType(string $type, string $method): ?string
    {
        $builtin = self::UNIVERSAL_METHODS[$type][$this->canonicalUniversalMethodName($method)]['return_type'] ?? null;
        if ($builtin !== null) {
            return $builtin;
        }
        $ext = $this->findExtensionMethod($type, $method);
        return $ext ? $ext['return_type'] : null;
    }

    private function getMethodsForRegistry(): array
    {
        if ($this->methodsForRegistry !== null) {
            return $this->methodsForRegistry;
        }
        $registry = [];
        foreach ($this->symbols->classes() as $provider) {
            $target = $provider->methodsForTarget;
            if ($target === null) {
                continue;
            }
            if (!$this->isBuiltinMethodsForTarget($target) && $this->isInterface($target)) {
                $this->error("MethodsFor target {$target} must be a class; interfaces are not supported");
            }
            foreach ($provider->methods as $method) {
                if (str_starts_with($method->name, '__')) {
                    continue;
                }
                if (!($method->flags & \PhpParser\Modifiers::PUBLIC)) {
                    continue;
                }
                if (!($method->flags & \PhpParser\Modifiers::STATIC)) {
                    $this->error("MethodsFor method {$provider->getNamespacedName(false)}::{$method->name}() must be static");
                }
                $function = $method->functionDef;
                if ($function === null || empty($function->argInfoList)) {
                    $this->error("MethodsFor method {$provider->getNamespacedName(false)}::{$method->name}() must declare a receiver parameter");
                }
                $receiver = $function->argInfoList[0];
                if ($receiver->byRef || !$this->extensionReceiverMatchesTarget($receiver, $target)) {
                    $this->error("Invalid receiver parameter for MethodsFor method {$provider->getNamespacedName(false)}::{$method->name}()");
                }
                $targetKey = strtolower(ltrim($target, '\\'));
                $methodKey = strtolower($method->name);
                if ($target === '*' && KeywordMethodNameNormalizer::canonicalName($method->name) !== null) {
                    $this->error(
                        "Keyword extension method *::{$method->name}() conflicts with the builtin keyword method "
                        . KeywordMethodNameNormalizer::canonicalName($method->name) . '()'
                    );
                }
                if (isset($registry[$targetKey][$methodKey])) {
                    $this->error("Duplicate extension method {$target}::{$method->name}()");
                }
                if ($target === '*') {
                    foreach ($registry as $registeredTarget => $registeredMethods) {
                        if ($registeredTarget !== '*' && isset($registeredMethods[$methodKey])) {
                            $this->error(
                                "Keyword extension method *::{$method->name}() conflicts with extension method "
                                . "{$registeredTarget}::{$method->name}()"
                            );
                        }
                    }
                } elseif (isset($registry['*'][$methodKey])) {
                    $this->error(
                        "Extension method {$target}::{$method->name}() conflicts with keyword extension method "
                        . "*::{$method->name}()"
                    );
                }
                $registry[$targetKey][$methodKey] = [
                    'handler' => 'provider_extension',
                    'fn' => $this->getNativeName($method->name, $provider->namespace, $provider->name),
                    'class' => $provider->getNamespacedName(false),
                    'return_type' => $function->returnType,
                    'min_args' => max(0, $function->argCountRequired - 1),
                    'max_args' => $function->hasVariadicArg() ? -1 : count($function->argInfoList) - 1,
                    'receiver_immutable' => $receiver->immutable,
                ];
            }
        }
        return $this->methodsForRegistry = $registry;
    }

    private function isBuiltinMethodsForTarget(string $target): bool
    {
        return $target === '*' || in_array($target, [
            Type::VAR, Type::INT, Type::FLOAT, Type::BOOL, Type::STR,
            Type::ARRAY, Type::OBJECT, Type::STREAM, Type::BIGINT,
            Type::BIGFLOAT, Type::DECIMAL, Type::BOX,
        ], true);
    }

    private function extensionReceiverMatchesTarget($receiver, string $target): bool
    {
        if ($target === '*') {
            return $receiver->type === Type::VAR;
        }
        if (!$this->isBuiltinMethodsForTarget($target)) {
            return $receiver->type === Type::OBJECT && $this->isSameClassName($receiver->declaredClass, $target);
        }
        return $receiver->type === $target;
    }

    private function findProviderExtension(string $target, string $method): ?array
    {
        return $this->getMethodsForRegistry()[strtolower(ltrim($target, '\\'))][strtolower($method)] ?? null;
    }

    protected const array TO_CONVERT_FN = [
        Type::BIGINT   => ['toInt' => 'php::BigInt::toInt', 'toFloat' => 'php::BigInt::toFloat', 'toString' => 'php::BigInt::toString', 'toBool' => 'php::BigInt::toBool'],
        Type::BIGFLOAT => ['toInt' => 'php::BigFloat::toInt', 'toFloat' => 'php::BigFloat::toFloat', 'toString' => 'php::BigFloat::toString', 'toBool' => 'php::BigFloat::toBool'],
        Type::DECIMAL  => ['toInt' => 'php::Decimal::toInt', 'toFloat' => 'php::Decimal::toFloat', 'toString' => 'php::Decimal::toString', 'toBool' => 'php::Decimal::toBool'],
    ];

    /**
     * Generate C++ code for a to* keyword conversion call.
     */
    protected function genToConvertCall(string $receiver, string $method, string $receiverType = ''): string
    {
        if ($receiverType !== '' && isset(self::TO_CONVERT_FN[$receiverType][$method])) {
            return self::TO_CONVERT_FN[$receiverType][$method] . '(' . $receiver . ')';
        }
        return match ($method) {
            'toInt'       => 'php::toInt(' . $receiver . ')',
            'toFloat'     => 'php::toFloat(' . $receiver . ')',
            'toString'    => 'php::toString(' . $receiver . ')',
            'toBool'      => 'php::toBool(' . $receiver . ')',
            'toArray'     => 'php::toArray(' . $receiver . ')',
            'toStream'    => 'php::toStream(' . $receiver . ')',
            'toBigInt'    => 'php::BigInt::newInstance(' . $receiver . ')',
            'toBigFloat'  => 'php::BigFloat::newInstance(' . $receiver . ')',
            'toDecimal'   => 'php::Decimal::newInstance(' . $receiver . ')',
            'toAny'       => $receiver,
            default       => $receiver,
        };
    }

    protected function findUniversalMethodAnyType(string $type, string $method): ?array
    {
        $builtin = self::UNIVERSAL_METHODS[$type][$this->canonicalUniversalMethodName($method)] ?? null;
        if ($builtin !== null) {
            return $builtin;
        }
        return $this->findExtensionMethod($type, $method);
    }

    /**
     * Look up an object extension using the receiver's static class hierarchy.
     * Real methods always win. Extensions are searched on the exact static
     * class, then its parents from nearest to farthest, and finally Type::Object
     * when the receiver is statically known to be an object. __call() remains
     * the last fallback in the caller.
     */
    protected function findObjectExtensionMethod(
        string $class,
        string $method,
        bool $receiverIsDefinitelyObject,
    ): ?array {
        $class = ltrim($class, '\\');
        if ($class !== '' && $this->objectTypeDeclaresMethod($class, $method)) {
            return null;
        }

        $visited = [];
        $current = $class;
        while ($current !== '') {
            $key = strtolower(ltrim($current, '\\'));
            if (isset($visited[$key])) {
                break;
            }
            $visited[$key] = true;

            $extension = $this->findProviderExtension($current, $method);
            if ($extension !== null) {
                return $extension;
            }

            $classDef = $this->getClassDef($current);
            if ($classDef !== null) {
                $current = $classDef->extends;
                continue;
            }
            $reflection = Reflection::getClass($current);
            $parent = $reflection?->getParentClass();
            $current = $parent === false || $parent === null ? '' : $parent->getName();
        }

        return $receiverIsDefinitelyObject
            ? $this->findProviderExtension(Type::OBJECT, $method)
            : null;
    }

    private function objectTypeDeclaresMethod(string $class, string $method): bool
    {
        return $this->classOrInterfaceDeclaresMethod($class, $method, []);
    }

    /** @param array<string, true> $visited */
    private function classOrInterfaceDeclaresMethod(string $type, string $method, array $visited): bool
    {
        $type = ltrim($type, '\\');
        $key = strtolower($type);
        if ($type === '' || isset($visited[$key])) {
            return false;
        }
        $visited[$key] = true;

        $classDef = $this->getClassDef($type);
        if ($classDef !== null) {
            if ($classDef->hasMethod($method) || $classDef->hasAbstractMethod($method)) {
                return true;
            }
            foreach ($classDef->implements as $interface) {
                if ($this->classOrInterfaceDeclaresMethod($interface, $method, $visited)) {
                    return true;
                }
            }
            return $classDef->extends !== ''
                && $this->classOrInterfaceDeclaresMethod($classDef->extends, $method, $visited);
        }

        if ($this->hasInterface($type)) {
            $interfaceDef = $this->getInterface($type);
            if ($interfaceDef->hasMethod($method)) {
                return true;
            }
            foreach ($interfaceDef->extendsList ?: ($interfaceDef->extends ? [$interfaceDef->extends] : []) as $parent) {
                if ($this->classOrInterfaceDeclaresMethod($parent, $method, $visited)) {
                    return true;
                }
            }
            return false;
        }

        $reflection = Reflection::getClass($type);
        return $reflection?->hasMethod($method) ?? false;
    }

    /**
     * Look up an extension function for the given type+method.
     * Extension functions use the exact {typePrefix}_{method} spelling.
     * Lookup remains case-insensitive according to PHP function semantics.
     *
     * Checks compiled user-defined functions first, then falls back to PHP internal
     * functions using reflection to resolve parameter counts and return types.
     */
    protected function findExtensionMethod(string $type, string $method): ?array
    {
        return $this->findProviderExtension($type, $method);
    }

    /**
     * Look up a keyword extension method using the exact __{method} spelling
     * in the root namespace. Lookup remains case-insensitive.
     */
    protected function findKeywordExtensionMethod(string $method): ?array
    {
        return $this->findProviderExtension('*', $method);
    }

    /**
     * Unified keyword method lookup (to* builtins + __ extension methods).
     * Returns the return type string, or null if the method is not a keyword method.
     */
    protected function findKeywordMethod(string $method): ?string
    {
        if (strcasecmp($method, 'instanceOf') === 0) {
            return Type::BOOL;
        }
        if (isset(CompilerBase::KEYWORD_METHOD_MAP[$method])) {
            return CompilerBase::KEYWORD_METHOD_MAP[$method];
        }
        $kwExt = $this->findKeywordExtensionMethod($method);
        return $kwExt ? $kwExt['return_type'] : null;
    }

    /**
     * @param string $receiver C++ expression for the receiver
     * @param bool   $isVar    Whether the receiver is a variable (allows mutating methods)
     */
    protected function parseUniversalMethodCall(Node\Expr\MethodCall $expr, string $receiver, string $method, array $def, bool $isVar = true): ?string
    {
        if ($def['handler'] !== 'provider_extension') {
            $method = $this->canonicalUniversalMethodName($method);
        }
        if ($this->getTypedArrayDefinition($expr->var) !== null
            && in_array($def['handler'], ['php_fn_ref', 'direct_method_mutate', 'provider_extension'], true)) {
            $this->fatalError($expr, 'Typed arrays cannot use untyped mutating or extension methods');
        }
        if ($def['handler'] === 'php_fn') {
            $function = strtolower($def['fn']);
            $this->compilationStatistics->record(CompilationStatistics::FUNCTIONS, $function);
            $this->compilationStatistics->record(CompilationStatistics::DIRECT_FUNCTIONS, $function);
        } elseif ($def['handler'] === 'cpp_fn'
            && str_starts_with($def['fn'], 'php::fn::')
        ) {
            $function = strtolower(substr($def['fn'], strlen('php::fn::')));
            $this->compilationStatistics->record(CompilationStatistics::FUNCTIONS, $function);
            $this->compilationStatistics->record(CompilationStatistics::DIRECT_FUNCTIONS, $function);
        }
        if ($this->isWasiTarget()) {
            if ($def['handler'] === 'php_fn') {
                $this->assertWasiFunctionSupported($expr, $def['fn']);
            } elseif ($def['handler'] === 'cpp_fn'
                && str_starts_with($def['fn'], 'php::fn::')
            ) {
                $this->assertWasiFunctionSupported(
                    $expr,
                    substr($def['fn'], strlen('php::fn::')),
                );
            }
        }
        if ($this->isNanoPolicyMode()) {
            if ($def['handler'] === 'php_fn') {
                $this->assertNanoFunctionSupported($expr, $def['fn']);
            } elseif ($def['handler'] === 'cpp_fn'
                && str_starts_with($def['fn'], 'php::fn::')
            ) {
                $this->assertNanoFunctionSupported(
                    $expr,
                    substr($def['fn'], strlen('php::fn::')),
                );
            }
        }
        $this->validateUniversalMethodArgs($expr, $method, $def, $isVar);
        $args = $this->normalizeUniversalNamedArgs($expr, $method, $def);

        if ($this->getTypedArrayDefinition($expr->var) !== null && in_array($method, ['keyExists', 'get'], true)) {
            $argument = $expr->args[0];
            if ($argument->unpack || $argument->name !== null || $argument->byRef) {
                $this->fatalError($argument, 'Typed array lookup methods require a positional key');
            }
            $key = $this->guardTypedArrayValue($this->getTypedArrayDefinition($expr->var), $argument->value,
                $this->parseExprAsValue($argument->value), true);
            return $receiver . ($method === 'keyExists' ? '.exists(' : '.get(') . $key . ')';
        }
        if (in_array($def['handler'], ['php_fn', 'php_fn_ref'], true)) {
            $position = $def['receiver_pos'] ?? 0;
            foreach ($args as $index => $argument) {
                $parameterIndex = $position === 0 || $index >= $position - 1 ? $index + 1 : $index;
                $this->validateTypedArrayDynamicArgument($argument, $def['fn'], '', $parameterIndex);
            }
        }

        return match ($def['handler']) {
            'calc_op'              => $this->genUniversalCalcOp($receiver, $def['op'], $args),
            'calc_inc'             => '(' . $receiver . ' + 1)',
            'calc_dec'             => '(' . $receiver . ' - 1)',
            'direct_method'        => $this->genUniversalDirectMethod($receiver, $def['method'], $args, $def['int_cast_args'] ?? []),
            'direct_method_mutate' => $this->genUniversalMutatingMethod($receiver, $def['method'], $args),
            'convert_fn'           => $this->genUniversalConvertFn($receiver, $def['fn']),
            'php_fn'               => $this->genUniversalPhpFn($receiver, $def['fn'], $args, $def['receiver_pos'] ?? 0, $def['const_args'] ?? []),
            'php_fn_ref'           => $this->genUniversalPhpFnRef($receiver, $def['fn'], $args, $def['return_type']),
            'cpp_fn'               => $this->genUniversalCppFn($receiver, $def['fn'], $args, $def['receiver_pos'] ?? 0),
            'provider_extension'   => $this->genProviderExtensionCall($receiver, $def, $args),
            default                => null,
        };
    }

    protected function genProviderExtensionCall(string $receiver, array $definition, array $args): string
    {
        $nativeFunc = $definition['fn'];
        $tail = $this->parseNativeCallArgs($args, $nativeFunc, 1);
        return self::PREFIX . $nativeFunc . '(' . $this->getCeWrapper($definition['class']) . ', '
            . $receiver . ($tail === '' ? '' : ', ' . $tail) . ')';
    }

    /**
     * Generate a stream method call with null guard.
     * Stream resources are nullable — throws Error if the receiver is null.
     */
    protected function genStreamNullGuard(Node\Expr\MethodCall $expr, string $receiver, string $method, array $def): string
    {
        if ($def['handler'] !== 'provider_extension') {
            $method = $this->canonicalUniversalMethodName($method);
        }
        $this->validateUniversalMethodArgs($expr, $method, $def, false);
        $args = $this->normalizeUniversalNamedArgs($expr, $method, $def);

        // Evaluate receiver into temp var to avoid double evaluation
        $streamVar = $this->addTmpVar(Type::VAR);
        $this->addBeforeStmtLine($streamVar . ' = ' . $receiver . ';');

        $methodCall = match ($def['handler']) {
            'php_fn'        => $this->genUniversalPhpFn('php::toStream(' . $streamVar . ')', $def['fn'], $args, $def['receiver_pos'] ?? 0, $def['const_args'] ?? []),
            'direct_method' => $this->genUniversalDirectMethod('php::toStream(' . $streamVar . ')', $def['method'], $args, $def['int_cast_args'] ?? []),
            'cpp_fn'        => $this->genUniversalCppFn('php::toStream(' . $streamVar . ')', $def['fn'], $args, $def['receiver_pos'] ?? 0),
            'provider_extension' => $this->genProviderExtensionCall('php::toStream(' . $streamVar . ')', $def, $args),
            default         => null,
        };

        // A void provider call is already a complete expression statement and
        // cannot be materialized in the generic php::Var result temporary.
        if (($def['return_type'] ?? Type::VAR) === Type::VOID) {
            return $methodCall;
        }

        $tmpVar = $this->addTmpVar(Type::VAR);
        $this->addBeforeStmtLine("{$tmpVar} = {$methodCall};");
        return $tmpVar;
    }

    /**
     * Wrap a non-variable receiver expression in the appropriate type conversion
     * so that direct C++ method calls (e.g. .get(), .set()) work on the correct type.
     */
    protected function wrapUniversalReceiver(string $type, string $expr): string
    {
        $convFns = [
            Type::ARRAY  => 'toArray',
            Type::STR    => 'toString',
            Type::INT    => 'toInt',
            Type::FLOAT  => 'toFloat',
            Type::BOOL   => 'toBool',
        ];
        if (isset($convFns[$type])) {
            return 'php::' . $convFns[$type] . '(' . $expr . ')';
        }
        return $expr;
    }

    protected function validateUniversalMethodArgs(Node\Expr\MethodCall $expr, string $method, array $def, bool $isVar): void
    {
        $argCount = count($expr->args);
        $maxArgs = $def['max_args'];
        if ($maxArgs === -1) {
            $maxArgs = PHP_INT_MAX;
        }
        if ($argCount < $def['min_args'] || $argCount > $maxArgs) {
            $expected = $def['min_args'] === $def['max_args'] ? "exactly {$def['min_args']}" : "{$def['min_args']} to {$def['max_args']}";
            $this->fatalError($expr, "Method `{$method}()` expects {$expected} argument(s), {$argCount} given");
        }

        if (!$isVar && in_array($def['handler'], self::MUTATING_HANDLERS, true)) {
            $this->fatalError($expr, 'Cannot call mutating method `' . $method . '()` on a non-variable expression');
        }

        $this->assertUniversalArgumentForms($expr, $method, $def);
    }

    /**
     * Builtin universal methods map onto fixed native or PHP functions, so only
     * plain positional arguments are meaningful. Named arguments are accepted
     * solely for handlers backed by a real PHP function, whose parameter names
     * are stable.
     */
    protected function assertUniversalArgumentForms(Node\Expr\MethodCall $expr, string $method, array $def): void
    {
        if ($def['handler'] === 'provider_extension') {
            return;
        }
        $allowsNamed = in_array($def['handler'], ['php_fn', 'php_fn_ref'], true);
        foreach ($expr->args as $argument) {
            if (!$argument instanceof Node\Arg) {
                continue;
            }
            if ($argument->unpack) {
                $this->fatalError($argument, "Method `{$method}()` does not support argument unpacking");
            }
            if ($argument->byRef) {
                $this->fatalError($argument, "Method `{$method}()` does not support by-reference arguments");
            }
            if ($argument->name !== null && !$allowsNamed) {
                $this->fatalError($argument, "Method `{$method}()` does not support named arguments");
            }
        }
    }

    /**
     * Rewrites named arguments of a php_fn/php_fn_ref universal method into the
     * positional user arguments expected by the code generators. The receiver
     * and constant arguments occupy fixed slots of the underlying PHP function.
     * Source order is kept in the `universal_source_order` attribute so operands
     * are still lowered in the order they were written.
     *
     * @param array<int, Node\Arg|Node\VariadicPlaceholder> $args
     * @return array<int, Node\Arg|Node\VariadicPlaceholder>
     */
    protected function normalizeUniversalNamedArgs(Node\Expr\MethodCall $expr, string $method, array $def): array
    {
        $args = $expr->args;
        $hasNamed = false;
        foreach ($args as $argument) {
            if ($argument instanceof Node\Arg && $argument->name !== null) {
                $hasNamed = true;
                break;
            }
        }
        if (!$hasNamed || !in_array($def['handler'], ['php_fn', 'php_fn_ref'], true)) {
            return $args;
        }

        $receiverPos = $def['handler'] === 'php_fn' ? ($def['receiver_pos'] ?? 0) : 0;
        $constArgs = $def['handler'] === 'php_fn' ? ($def['const_args'] ?? []) : [];
        if ($receiverPos > 0 && $constArgs) {
            $this->fatalError($expr, "Method `{$method}()` does not support named arguments");
        }
        $reflection = Reflection::getFunction($def['fn']);
        if ($reflection === null) {
            $this->fatalError($expr, "Method `{$method}()` does not support named arguments");
        }
        $parameters = $reflection->getParameters();
        $nameIndex = [];
        foreach ($parameters as $index => $parameter) {
            if (!$parameter->isVariadic()) {
                $nameIndex[$parameter->getName()] = $index;
            }
        }

        $receiverIndex = max($receiverPos - 1, 0);
        $reserved = [$receiverIndex => true];
        foreach ($constArgs as $position => $_) {
            $reserved[$position] = true;
        }

        $slots = [];
        $nextSlot = 0;
        $seenNamed = false;
        foreach (array_values($args) as $order => $argument) {
            if (!$argument instanceof Node\Arg) {
                continue;
            }
            $argument = clone $argument;
            $argument->setAttribute('universal_source_order', $order);
            if ($argument->name === null) {
                if ($seenNamed) {
                    $this->fatalError($argument, 'Cannot use positional argument after named argument');
                }
                while (isset($reserved[$nextSlot])) {
                    $nextSlot++;
                }
                $slots[$nextSlot++] = $argument;
                continue;
            }
            $seenNamed = true;
            $name = $argument->name->name;
            if (!isset($nameIndex[$name])) {
                $this->fatalError($argument, "Unknown named argument `{$name}` for method `{$method}()`");
            }
            $slot = $nameIndex[$name];
            if (isset($reserved[$slot])) {
                $this->fatalError($argument, "Named argument `{$name}` targets the receiver or a fixed argument of `{$method}()`");
            }
            if (isset($slots[$slot])) {
                $this->fatalError($argument, "Named argument `{$name}` overwrites an earlier argument");
            }
            $argument->name = null;
            $slots[$slot] = $argument;
        }

        $last = max(array_keys($slots) ?: [0]);
        $normalized = [];
        for ($slot = 0; $slot <= max($last, $receiverIndex - 1); $slot++) {
            if (isset($reserved[$slot])) {
                continue;
            }
            if (isset($slots[$slot])) {
                $normalized[] = $slots[$slot];
                continue;
            }
            $parameter = $parameters[$slot] ?? null;
            if ($parameter === null || !$parameter->isDefaultValueAvailable()) {
                $this->fatalError($expr, "Method `{$method}()` is missing required argument `" . ($parameter?->getName() ?? (string) $slot) . '`');
            }
            $normalized[] = new Node\Arg(
                \PhpParser\BuilderHelpers::normalizeValue($parameter->getDefaultValue()),
                false,
                false,
                $expr->getAttributes(),
            );
        }

        return $normalized;
    }

    /** @return array<int, int> Argument indexes in the order they must be lowered. */
    private function universalArgLoweringOrder(array $args): array
    {
        $order = array_keys($args);
        usort($order, static fn(int $a, int $b): int =>
            ($args[$a]->getAttribute('universal_source_order', PHP_INT_MAX)
                <=> $args[$b]->getAttribute('universal_source_order', PHP_INT_MAX)) ?: $a <=> $b);
        return $order;
    }

    protected function genUniversalCalcOp(string $object, string $op, array $args): string
    {
        $argExpr = $this->parseExpr($args[0]->value);
        return '(' . $object . ' ' . $op . ' ' . $argExpr . ')';
    }

    protected function genUniversalDirectMethod(string $object, string $cppMethod, array $args, array $intCastArgs = []): string
    {
        if (empty($args)) {
            return $object . '.' . $cppMethod . '()';
        }
        $argExprs = [];
        foreach ($args as $i => $arg) {
            $expr = $this->parseOrderedOperand($arg->value, false);
            if (in_array($i, $intCastArgs, true)) {
                $expr = 'php::toInt(' . $expr . ')';
            }
            $argExprs[] = $expr;
        }
        return $object . '.' . $cppMethod . '(' . implode(', ', $argExprs) . ')';
    }

    protected function genUniversalMutatingMethod(string $object, string $cppMethod, array $args): string
    {
        $call = $this->genUniversalDirectMethod($object, $cppMethod, $args);
        return '(' . $call . ', ' . $object . ')';
    }

    protected function genUniversalConvertFn(string $receiver, string $fn): string
    {
        return 'php::' . $fn . '(' . $receiver . ')';
    }

    private function mergeConstArgs(array $argExprs, array $constArgs): array
    {
        if (!$constArgs) {
            return $argExprs;
        }
        $maxPos = max(array_keys($constArgs));
        $totalSlots = max(count($argExprs) + count($constArgs), $maxPos + 1);
        $merged = [];
        $regIdx = 0;
        for ($i = 0; $i < $totalSlots; $i++) {
            if (isset($constArgs[$i])) {
                $merged[] = $constArgs[$i];
            } else {
                $merged[] = $argExprs[$regIdx++];
            }
        }
        return $merged;
    }

    /**
     * Try to generate a direct C++ call for a php_fn method using the FuncCallOptimizer's
     * type-aware argument resolution. Returns null if the function is not optimizable.
     */
    private function tryOptimizePhpFn(string $receiver, string $phpFunc, array $args, int $receiverPos, array $constArgs): ?string
    {
        $config = $this->getFuncCallConfig()[$phpFunc] ?? null;
        if ($config === null) {
            return null;
        }

        $targetName = $phpFunc;
        if (is_string($config)) {
            $targetName = $config;
            $config = $this->getFuncCallConfig()[$targetName] ?? [];
        }

        // Skip entries that need FuncCall AST nodes
        if (isset($config['handler']) || isset($config['bigDispatch']) || isset($config['conversion'])) {
            return null;
        }

        $refInfo = $this->getArgReflectionInfo($targetName);
        if (!empty($config['variadic']) || ($refInfo['variadic'] ?? false)) {
            return null;
        }

        $target = $config['target'] ?? null;
        if ($target === null) {
            $target = 'php::fn::' . $targetName;
        } elseif (!str_starts_with($target, 'php::')) {
            $target = 'php::fn::' . $target;
        }

        $argTypeStr = $config['args'] ?? ($refInfo['args'] ?? '');
        $defaults = $config['defaults'] ?? [];

        $rawArgs = $this->mergeConstArgs(
            $this->buildReceiverArgs($receiver, $args, $receiverPos),
            $constArgs
        );

        if ($argTypeStr !== '') {
            $types = explode('_', $argTypeStr);
            foreach ($types as $i => $type) {
                if (!isset($rawArgs[$i])) {
                    if (($type[0] ?? '') === self::ARG_OPTIONAL && isset($defaults[$i])) {
                        continue;
                    }
                    return null;
                }
                $rawArgs[$i] = $this->applyArgConversion($rawArgs[$i], $type);
            }
        }

        return $target . '(' . implode(', ', $rawArgs) . ')';
    }

    /**
     * @param int $receiverPos Position of the receiver in the final argument list.
     *  0 (default) = receiver first. Non-zero values are 1-indexed (1 = before 1st user arg,
     *  2 = before 2nd, etc.). e.g. hash(algo, data, binary) needs receiver as data → receiverPos=2.
     */
    protected function genUniversalPhpFn(string $receiver, string $phpFunc, array $args, int $receiverPos = 0, array $constArgs = []): string
    {
        // Try direct C++ call with type conversions first
        $optimized = $this->tryOptimizePhpFn($receiver, $phpFunc, $args, $receiverPos, $constArgs);
        if ($optimized !== null) {
            return $optimized;
        }

        // Fall back to dynamic php::call()
        $argExprs = $this->mergeConstArgs(
            $this->buildReceiverArgs($receiver, $args, $receiverPos),
            $constArgs
        );

        return 'php::call(' . $this->getFuncPtr($phpFunc) . ', php::ArgList{' . implode(', ', $argExprs) . '})';
    }

    // Same receiverPos semantics as genUniversalPhpFn.
    protected function genUniversalCppFn(string $receiver, string $cppFunc, array $args, int $receiverPos = 0): string
    {
        $argExprs = $this->buildReceiverArgs($receiver, $args, $receiverPos);
        return $cppFunc . '(' . implode(', ', $argExprs) . ')';
    }

    protected function buildReceiverArgs(string $receiver, array $args, int $receiverPos): array
    {
        $userArgs = [];
        foreach ($this->universalArgLoweringOrder($args) as $index) {
            $userArgs[$index] = $this->parseOrderedOperand($args[$index]->value, false);
        }
        ksort($userArgs);

        if ($receiverPos === 0) {
            $argExprs = [$receiver];
            foreach ($userArgs as $ua) {
                $argExprs[] = $ua;
            }
        } else {
            $argExprs = [];
            foreach ($userArgs as $i => $ua) {
                if ($i === $receiverPos - 1) {
                    $argExprs[] = $receiver;
                }
                $argExprs[] = $ua;
            }
            if ($receiverPos > count($userArgs)) {
                $argExprs[] = $receiver;
            }
        }

        return $argExprs;
    }

    protected function genUniversalPhpFnRef(string $receiver, string $phpFunc, array $args, string $returnType): string
    {
        $tmpRef = $this->addTmpVar(Type::REF);
        $tmpVar = $this->addTmpVar(Type::VAR);
        $argExprs = ['&' . $tmpRef];
        $lowered = [];
        foreach ($this->universalArgLoweringOrder($args) as $index) {
            $lowered[$index] = $this->parseExpr($args[$index]->value);
        }
        ksort($lowered);
        array_push($argExprs, ...$lowered);
        $this->addBeforeStmtLine($tmpRef . ' = ' . $receiver . '.toReference();');
        $this->addBeforeStmtLine($tmpVar . ' = php::call(' . $this->getFuncPtr($phpFunc) . ', php::ArgList{' . implode(', ', $argExprs) . '});');
        return $tmpVar;
    }
}
