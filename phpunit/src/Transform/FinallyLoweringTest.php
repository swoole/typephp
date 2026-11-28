<?php
/**
 * This file is part of TypePHP(AOT).
 *
 * @link     https://www.swoole.com/aot/
 * @contact  service@swoole.com
 */

namespace TypePhp\Tests\Transform;

use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TypePhp\Transform\FinallyLowering;

/**
 * @internal
 * @coversNothing
 */
final class FinallyLoweringTest extends TestCase
{
    private function lower(string $source, bool $byRef = false): string
    {
        $body = (new ParserFactory())->createForNewestSupportedVersion()->parse('<?php ' . $source);
        // An empty enclosing finally lets the production pass visit a whole
        // function, including loops and labels around the cases under test.
        $root = new Stmt\TryCatch($body, [], new Stmt\Finally_([]));
        $next = 0;
        $lowering = new FinallyLowering(static function () use (&$next): string {
            return 'review_exit_' . $next++;
        }, $byRef);
        $printer = new Standard();
        $original = $printer->prettyPrint([$root]);
        $result = $printer->prettyPrint($lowering->lower($root));
        self::assertSame($original, $printer->prettyPrint([$root]), 'Lowering must not mutate the source AST');
        return $result;
    }

    private function execute(string $source): array
    {
        $function = eval('return function (array &$events) {' . $source . '};');
        $events = [];
        try {
            $result = ['return' => $function($events)];
        } catch (\Throwable $error) {
            $result = ['throw' => get_class($error) . ':' . $error->getMessage()];
        }
        return [...$result, 'events' => $events];
    }

    #[DataProvider('exitCases')]
    public function testPendingExitsMatchPhp(string $source): void
    {
        self::assertSame($this->execute($source), $this->execute($this->lower($source)));
    }

    public static function exitCases(): iterable
    {
        yield 'return from nested try' => ['try { try { return 22; } catch (Throwable $e) { return -22; } } finally { $events[] = "outer"; }'];
        yield 'return from nested catch' => ['try { try { throw new RuntimeException("inner"); } catch (RuntimeException $e) { return 33; } } finally { $events[] = "outer"; }'];
        yield 'three nested finally blocks' => ['try { try { try { return 1; } finally { $events[] = "inner"; } } finally { $events[] = "middle"; } } finally { $events[] = "outer"; }'];
        yield 'return expression precedes cleanup' => ['$value = 1; try { try { return ++$value; } catch (Throwable $e) {} } finally { $events[] = $value; $value = 9; }'];
        yield 'array return is a snapshot' => ['$value = [1]; try { try { return $value; } catch (Throwable $e) {} } finally { $value[] = 2; $events[] = $value; }'];
        yield 'void return' => ['try { try { return; } catch (Throwable $e) {} } finally { $events[] = "outer"; }'];
        yield 'normal fallthrough' => ['try { try { $events[] = "try"; } finally { $events[] = "inner"; } } finally { $events[] = "outer"; } return 7;'];
        yield 'finally return replaces return' => ['try { try { return 1; } finally { $events[] = "inner"; return 2; } } finally { $events[] = "outer"; }'];
        yield 'finally return replaces exception' => ['try { try { throw new RuntimeException("discard"); } finally { return 2; } } finally { $events[] = "outer"; }'];
        yield 'outer finally return wins' => ['try { try { return 1; } finally { return 2; } } finally { return 3; }'];
        yield 'finally throw replaces return' => ['try { return 1; } finally { $events[] = "cleanup"; throw new LogicException("cleanup"); }'];
        yield 'finally exception bypasses exited catch' => ['try { try { return 1; } catch (LogicException $e) { $events[] = "wrong-catch"; return 2; } } finally { $events[] = "cleanup"; throw new LogicException("cleanup"); }'];
        yield 'finally exception reaches enclosing catch' => ['try { try { return 1; } finally { throw new LogicException("cleanup"); } } catch (LogicException $e) { $events[] = $e->getMessage(); return 2; } finally { $events[] = "outer"; }'];
        yield 'catch exception skips sibling catches' => ['try { throw new RuntimeException("first"); } catch (RuntimeException $e) { throw new LogicException("second"); } catch (LogicException $e) { $events[] = "wrong-sibling"; } finally { $events[] = "outer"; }'];
        yield 'finally can inspect caught variable' => ['try { throw new RuntimeException("caught"); } catch (RuntimeException $e) { return 1; } finally { $events[] = $e->getMessage(); }'];
        yield 'nested break exits finally' => ['while (true) { try { try { break; } catch (Throwable $e) {} } finally { $events[] = "outer"; } } return 1;'];
        yield 'nested continue exits finally' => ['for ($i = 0; $i < 2; $i++) { try { try { continue; } catch (Throwable $e) {} } finally { $events[] = $i; } $events[] = "unreachable"; }'];
        yield 'local loop break stays in try' => ['try { while (true) { break; } $events[] = "after-loop"; } finally { $events[] = "outer"; }'];
        yield 'break two adjusts after leaving inner loop' => ['while (true) { try { while (true) { try { break 2; } finally { $events[] = "inner"; } } } finally { $events[] = "outer"; } }'];
        yield 'continue two adjusts after leaving inner loop' => ['for ($i = 0; $i < 2; $i++) { try { while (true) { try { continue 2; } finally { $events[] = "inner"; } } } finally { $events[] = $i; } }'];
        yield 'switch contributes a break level' => ['while (true) { try { switch (1) { case 1: try { break 2; } finally { $events[] = "inner"; } } } finally { $events[] = "outer"; } }'];
        yield 'foreach break' => ['foreach ([1, 2] as $item) { try { try { break; } catch (Throwable $e) {} } finally { $events[] = $item; } }'];
        yield 'do while continue' => ['$i = 0; do { try { try { continue; } catch (Throwable $e) {} } finally { $events[] = ++$i; } } while ($i < 2);'];
        yield 'exception state resets on repeated entry' => ['for ($i = 0; $i < 2; $i++) { try { try { if ($i === 0) { throw new RuntimeException("first"); } $events[] = "success"; } finally { $events[] = $i; } } catch (RuntimeException $e) { $events[] = $e->getMessage(); } }'];
        yield 'goto leaves nested protected regions' => ['try { try { goto done; } finally { $events[] = "inner"; } } finally { $events[] = "outer"; } done: return 1;'];
        yield 'goto stays in protected region' => ['try { goto inside; $events[] = "unreachable"; inside: $events[] = "inside"; } finally { $events[] = "outer"; }'];
        yield 'goto leaves only inner region' => ['try { try { goto inside; } finally { $events[] = "inner"; } inside: $events[] = "inside"; } finally { $events[] = "outer"; }'];
        yield 'goto from catch' => ['try { throw new RuntimeException("jump"); } catch (RuntimeException $e) { goto done; } finally { $events[] = "outer"; } done: return 1;'];
        yield 'nested closure return stays in closure' => ['try { $fn = function () { try { return 3; } finally {} }; $events[] = $fn(); return 4; } finally { $events[] = "outer"; }'];
        yield 'return expression throws before pending action is set' => ['try { try { return (function () { throw new RuntimeException("value"); })(); } catch (RuntimeException $e) { $events[] = "caught"; return 2; } } finally { $events[] = "outer"; }'];
    }

    public function testReferenceReturnKeepsItsBindingThroughCleanup(): void
    {
        $source = 'try { try { return $value; } catch (Throwable $e) {} } finally { $value = 2; $events[] = "outer"; }';
        foreach ([$source, $this->lower($source, true)] as $body) {
            $function = eval('return function &(array &$events, &$value) {' . $body . '};');
            $events = [];
            $value = 1;
            $result =& $function($events, $value);
            self::assertSame(2, $result);
            $result = 3;
            self::assertSame(3, $value);
            self::assertSame(['outer'], $events);
            unset($result);
        }
    }
}
