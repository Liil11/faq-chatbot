<?php
declare(strict_types=1);

namespace FAQ;

/**
 * MdService — loads and provides access to FAQ data from knowledge/info.md.
 *
 * Drop-in replacement for FAQService (same public API), but parses Markdown
 * Q/A blocks instead of JSON. Recognized patterns:
 *
 *   ## Frequently Asked Questions   → starts the FAQ section
 *   ### Category Name               → category header (H3)
 *   **Q: question text**           → question line
 *   A: answer text ...             → answer (may span multiple lines)
 *   ---                            → horizontal rule (ends current Q/A)
 *
 * Sections outside "Frequently Asked Questions" (e.g. ## Organization,
 * ## Policies, ## Notes) are ignored.
 */
final class MdService
{
    /** @var array<int, array{question: string, answer: string, category: string}> */
    private array $faqs = [];

    private string $dataPath;

    public function __construct(?string $dataPath = null)
    {
        $this->dataPath = $dataPath ?? __DIR__ . '/../../knowledge/info.md';
        $this->load();
    }

    // ---------------------------------------------------------------------
    // Loading & parsing
    // ---------------------------------------------------------------------

    /**
     * Load & parse FAQ data from the markdown file.
     */
    private function load(): void
    {
        if (!is_file($this->dataPath)) {
            throw new \RuntimeException("FAQ markdown file not found: {$this->dataPath}");
        }

        $contents = file_get_contents($this->dataPath);
        if ($contents === false) {
            throw new \RuntimeException("Failed to read FAQ markdown file: {$this->dataPath}");
        }

        $this->faqs = $this->parse($contents);
    }

    /**
     * Parse the markdown source into an array of {question, answer, category}.
     *
     * @return array<int, array{question: string, answer: string, category: string}>
     */
    private function parse(string $md): array
    {
        $lines = preg_split("/\r\n|\n|\r/", $md);
        $faqs = [];

        $currentCategory = 'general';
        $currentQuestion = null;
        $currentAnswer = [];
        $inFaqSection = false;

        $flush = function () use (&$faqs, &$currentQuestion, &$currentAnswer, &$currentCategory) {
            if ($currentQuestion === null) {
                return;
            }
            $answer = trim(implode("\n", $currentAnswer));
            // Strip leading "A:" prefix on the first line, if present.
            $answer = (string) preg_replace('/^\s*A:\s*/', '', $answer);
            $faqs[] = [
                'question' => trim($currentQuestion),
                'answer'   => trim($answer),
                'category' => $currentCategory !== '' ? $currentCategory : 'general',
            ];
            $currentQuestion = null;
            $currentAnswer = [];
        };

        foreach ($lines as $rawLine) {
            $line = trim($rawLine);

            // ---- Section scoping -----------------------------------------
            if (preg_match('/^##\s+(?!#)/', $line)) {
                if (preg_match('/^##\s+Frequently Asked Questions\s*$/i', $line)) {
                    $inFaqSection = true;
                } elseif ($inFaqSection) {
                    // Leaving the FAQ section ends the current Q/A and the section.
                    $flush();
                    $inFaqSection = false;
                }
                continue;
            }

            if (!$inFaqSection) {
                continue;
            }

            // ---- Category (H3) -------------------------------------------
            if (preg_match('/^###\s+(.+?)\s*$/', $line, $m)) {
                $flush();
                $currentCategory = $this->normalizeCategory($m[1]);
                continue;
            }

            // ---- Horizontal rule ----------------------------------------
            if (preg_match('/^-{3,}\s*$/', $line)) {
                $flush();
                continue;
            }

            // ---- Question start ------------------------------------------
            if (preg_match('/^\*\*Q:\s*(.+?)\*\*\s*$/', $line, $m)) {
                $flush();
                $currentQuestion = $m[1];
                continue;
            }

            // ---- Answer continuation -------------------------------------
            if ($currentQuestion !== null && $line !== '') {
                $currentAnswer[] = $line;
            }
        }

        // Flush any trailing Q/A at EOF.
        $flush();

        return $faqs;
    }

    /**
     * Map a heading text to a stable lowercase category slug.
     */
    private function normalizeCategory(string $name): string
    {
        $name = trim($name);
        $map = [
            'General'                                 => 'general',
            'KTP (Kartu Tanda Penduduk)'              => 'ktp',
            'KK (Kartu Keluarga)'                     => 'kk',
            'KIA (Kartu Identitas Anak)'              => 'kia',
            'Perpindahan Penduduk WNI'                => 'movement',
            'Birth Certificate (Akta Kelahiran)'      => 'birth',
            'Death Certificate (Akta Kematian)'       => 'death',
            'Marriage (Akta Nikah / Perceraian)'      => 'marriage',
            'Akta Pengakuan & Pengesahan Anak'        => 'child_acknowledgment',
            'Akta Pengangkatan Anak (Adopsi)'         => 'adoption',
            'Perubahan Nama (Name Change)'            => 'name_change',
            'Data Correction & Services'             => 'services',
        ];
        if (isset($map[$name])) {
            return $map[$name];
        }
        // Fallback: lowercase + collapse non-alphanumerics.
        $slug = strtolower($name);
        $slug = (string) preg_replace('/[^a-z0-9]+/', '_', $slug);
        return trim($slug, '_');
    }

    // ---------------------------------------------------------------------
    // Public API — mirrors FAQService
    // ---------------------------------------------------------------------

    /**
     * Get all FAQs.
     *
     * @return array<int, array{question: string, answer: string, category: string}>
     */
    public function getAll(): array
    {
        return $this->faqs;
    }

    /**
     * Get FAQs by category.
     *
     * @return array<int, array{question: string, answer: string, category: string}>
     */
    public function getByCategory(string $category): array
    {
        return array_values(array_filter(
            $this->faqs,
            fn($faq) => $faq['category'] === $category
        ));
    }

    /**
     * Get unique categories.
     *
     * @return array<int, string>
     */
    public function getCategories(): array
    {
        return array_values(array_unique(array_column($this->faqs, 'category')));
    }

    /**
     * Search FAQs by keyword in question or answer with fuzzy matching fallback.
     *
     * @return array<int, array{question: string, answer: string, category: string}>
     */
    public function search(string $keyword): array
    {
        $keyword = mb_strtolower($keyword);

        // First pass: exact substring match
        $exactMatches = array_filter($this->faqs, function ($faq) use ($keyword) {
            return mb_strpos(mb_strtolower($faq['question']), $keyword) !== false
                || mb_strpos(mb_strtolower($faq['answer']), $keyword) !== false;
        });

        if (count($exactMatches) >= 3) {
            return array_values($exactMatches);
        }

        // Second pass: fuzzy match using similar_text() on questions + answers
        $scored = [];
        foreach ($this->faqs as $faq) {
            $qLower = mb_strtolower($faq['question']);
            $aLower = mb_strtolower($faq['answer']);

            similar_text($keyword, $qLower, $qScore);
            similar_text($keyword, $aLower, $aScore);

            $score = ($qScore * 0.7) + ($aScore * 0.3);

            $tokens = preg_split('/\s+/', $keyword, -1, PREG_SPLIT_NO_EMPTY);
            $boost = 0;
            foreach ($tokens as $tok) {
                if (mb_strlen($tok) > 2 && mb_strpos($qLower, $tok) !== false) {
                    $boost += 10;
                }
            }

            $finalScore = $score + $boost;
            if ($finalScore > 25) {
                $scored[] = ['faq' => $faq, 'score' => $finalScore];
            }
        }

        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        return array_values(array_map(fn($item) => $item['faq'], array_slice($scored, 0, 5)));
    }

    /**
     * Get random FAQ (useful for suggestions).
     */
    public function getRandom(): ?array
    {
        return $this->faqs !== [] ? $this->faqs[array_rand($this->faqs)] : null;
    }

    /**
     * Get count of FAQs.
     */
    public function count(): int
    {
        return count($this->faqs);
    }

    /**
     * Reload data from file (useful if file was updated).
     */
    public function reload(): void
    {
        $this->load();
    }
}
