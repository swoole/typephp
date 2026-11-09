<?php

/**
 * Live documentation index over docs/<lang>/*.md.
 *
 * Chunks are split on ATX headings and carry heading_path + line numbers so
 * results always cite docs/<lang>/<file>.md#<heading> (L<line>). No prebuilt
 * database: the corpus is ~2 MB and is read on first use, which also removes
 * index staleness by construction.
 */
final class DocIndex
{
    private RepoRoot $root;

    /** @var array<int, array{lang: string, file: string, anchor: string, line: int, text: string}>|null */
    private ?array $chunks = null;

    public function __construct(RepoRoot $root)
    {
        $this->root = $root;
    }

    public function listTopics(): string
    {
        $lines = [];
        foreach ($this->languages() as $lang) {
            foreach ($this->docNames($lang) as $name) {
                $title = $this->docTitle($lang, $name);
                $lines[] = '[' . $lang . '] ' . $name . ($title !== '' ? ' - ' . $title : '');
            }
        }
        if (count($lines) === 0) {
            return 'No markdown docs found under ' . $this->root->docsPath('zh-cn');
        }
        return TextUtil::clip(implode("\n", $lines), 6000);
    }

    public function search(string $query, string $lang, int $limit): string
    {
        $terms = TextUtil::terms($query);
        if (count($terms) === 0) {
            return 'Empty query.';
        }
        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 10) {
            $limit = 10;
        }

        $scored = [];
        foreach ($this->loadChunks() as $index => $chunk) {
            if ($lang !== '' && $lang !== 'all' && $chunk['lang'] !== $lang) {
                continue;
            }
            $chunkScore = 0;
            foreach ($terms as $term) {
                $chunkScore += $this->scoreTerm($chunk, $term);
            }
            if ($chunkScore > 0) {
                $scored[$index] = $chunkScore;
            }
        }
        if (count($scored) === 0) {
            return 'No doc matches for: ' . $query;
        }

        $picked = [];
        for ($round = 0; $round < $limit; $round++) {
            $bestIndex = -1;
            $bestScore = 0;
            foreach ($scored as $index => $score) {
                $value = (int) $score;
                if ($value > $bestScore) {
                    $bestScore = $value;
                    $bestIndex = (int) $index;
                }
            }
            if ($bestIndex < 0) {
                break;
            }
            $picked[] = $bestIndex;
            unset($scored[$bestIndex]);
        }

        $chunks = $this->loadChunks();
        $blocks = [];
        $budget = 4000;
        foreach ($picked as $index) {
            $chunk = $chunks[$index];
            $header = '[' . $chunk['lang'] . '] docs/' . $chunk['lang'] . '/' . $chunk['file']
                . ($chunk['anchor'] !== $chunk['file'] ? '#' . $chunk['anchor'] : '')
                . ' (L' . $chunk['line'] . ')';
            $excerpt = $this->excerptAround($chunk['text'], $terms);
            $block = $header . "\n" . $excerpt;
            if (strlen($block) > $budget) {
                $block = TextUtil::clip($block, $budget);
            }
            $blocks[] = $block;
            $budget -= (int) strlen($block);
            if ($budget < 400) {
                break;
            }
        }
        return implode("\n\n", $blocks);
    }

    public function changelog(string $version): string
    {
        $path = $this->root->path() . '/CHANGELOG.md';
        $content = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($content) || $content === '') {
            return 'CHANGELOG.md not found.';
        }
        $sections = preg_split('/^## /m', $content);
        if (!is_array($sections) || count($sections) < 2) {
            return TextUtil::clip($content, 4000);
        }
        $headings = [];
        for ($i = 1; $i < count($sections); $i++) {
            $lines = explode("\n", $sections[$i]);
            $headings[] = trim($lines[0]);
        }
        foreach ($sections as $section) {
            if ($section === $sections[0]) {
                continue;
            }
            $lines = explode("\n", $section);
            $heading = trim($lines[0]);
            if ($version === '' || str_starts_with($heading, $version)) {
                return TextUtil::clip('## ' . $section, 4000);
            }
        }
        if ($version === '') {
            return TextUtil::clip('## ' . $sections[1], 4000);
        }
        return 'No changelog section for version: ' . $version . "\nAvailable: " . implode(', ', $headings);
    }

    /**
     * @return array<int, string>
     */
    private function languages(): array
    {
        return ['zh-cn', 'en'];
    }

    /**
     * @return array<int, string>
     */
    private function docNames(string $lang): array
    {
        $dir = $this->root->docsPath($lang);
        if (!is_dir($dir)) {
            return [];
        }
        $entries = scandir($dir);
        if (!is_array($entries)) {
            return [];
        }
        $names = [];
        foreach ($entries as $entry) {
            if (str_ends_with($entry, '.md')) {
                $names[] = $entry;
            }
        }
        sort($names);
        return $names;
    }

    private function docTitle(string $lang, string $name): string
    {
        $content = file_get_contents($this->root->docsPath($lang) . '/' . $name);
        if (!is_string($content)) {
            return '';
        }
        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            if (str_starts_with($line, '# ')) {
                return trim(substr($line, 2));
            }
        }
        return '';
    }

    /**
     * @return array<int, array{lang: string, file: string, anchor: string, line: int, text: string}>
     */
    private function loadChunks(): array
    {
        if ($this->chunks !== null) {
            return $this->chunks;
        }
        $chunks = [];
        foreach ($this->languages() as $lang) {
            foreach ($this->docNames($lang) as $name) {
                $fileChunks = $this->chunkFile($lang, $name);
                foreach ($fileChunks as $chunk) {
                    $chunks[] = $chunk;
                }
            }
        }
        $this->chunks = $chunks;
        return $chunks;
    }

    /**
     * @return array<int, array{lang: string, file: string, anchor: string, line: int, text: string}>
     */
    private function chunkFile(string $lang, string $name): array
    {
        $content = file_get_contents($this->root->docsPath($lang) . '/' . $name);
        if (!is_string($content) || $content === '') {
            return [];
        }
        $lines = explode("\n", $content);
        $chunks = [];
        $anchor = $name;
        $buffer = [];
        $startLine = 1;
        foreach ($lines as $offset => $line) {
            $lineNo = (int) ($offset + 1);
            if (preg_match('/^(#{1,6})\s+(.+?)\s*$/', $line, $matches) === 1) {
                if (count($buffer) > 0) {
                    $chunks[] = [
                        'lang' => $lang,
                        'file' => $name,
                        'anchor' => $anchor,
                        'line' => $startLine,
                        'text' => implode("\n", $buffer),
                    ];
                }
                $anchor = (string) $matches[2];
                $buffer = [$line];
                $startLine = $lineNo;
            } else {
                $buffer[] = $line;
            }
        }
        if (count($buffer) > 0) {
            $chunks[] = [
                'lang' => $lang,
                'file' => $name,
                'anchor' => $anchor,
                'line' => $startLine,
                'text' => implode("\n", $buffer),
            ];
        }
        return $chunks;
    }

    /**
     * @param array{lang: string, file: string, anchor: string, line: int, text: string} $chunk
     */
    private function scoreTerm(array $chunk, string $term): int
    {
        $text = strtolower($chunk['text']);
        $count = (int) substr_count($text, $term);
        if ($count === 0) {
            return 0;
        }
        $score = $count;
        if (substr_count(strtolower($chunk['anchor']), $term) > 0) {
            $score += 8;
        }
        if (substr_count(strtolower($chunk['file']), $term) > 0) {
            $score += 4;
        }
        return $score;
    }

    /**
     * @param array{lang: string, file: string, anchor: string, line: int, text: string} $chunk
     * @param array<int, string> $terms
     */
    private function excerptAround(string $text, array $terms): string
    {
        $position = -1;
        foreach ($terms as $term) {
            $found = stripos($text, $term);
            if ($found !== false && ($position < 0 || $found < $position)) {
                $position = (int) $found;
            }
        }
        if ($position < 0) {
            $position = 0;
        }
        $start = $position - 120;
        if ($start < 0) {
            $start = 0;
        }
        return TextUtil::utf8SafeSlice($text, $start, 480);
    }
}
