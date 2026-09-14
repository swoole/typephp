--TEST--
namespaced builtin fallbacks retain direct optimizer paths while compiled shadows win
--FILE--
<?php
namespace NamespacedBuiltinFallback {
    class Probe {
        private array $blocks;
        private int $size = 0;

        public function __construct(array $blocks) {
            $this->blocks = $blocks;
        }

        public function countBlocks(): int {
            $this->size = count($this->blocks);
            return $this->size;
        }

        public function matches(): bool {
            return in_array('needle', $this->blocks, true)
                && str_contains('needle in haystack', 'needle');
        }
    }

    function strlen(string $value): int {
        return 7;
    }

    function shadowedLength(): int {
        return strlen('ignored');
    }
}
namespace {
    function main(): void {
        $probe = new \NamespacedBuiltinFallback\Probe(['needle']);
        echo $probe->countBlocks(), "\n";
        var_dump($probe->matches());
        echo \NamespacedBuiltinFallback\shadowedLength(), "\n";
    }
}
?>
--EXPECT--
1
bool(true)
7
