<?php

namespace App\Services;

use App\Models\PdfDocument;
use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Smalot\PdfParser\Parser;

/**
 * @class PdfClassifier
 *
 * @package App\Services
 *
 * Tags each collected PDF with the report type from config/report_types.php
 * whose column headers appear in its text. The text is read in code, so no
 * AI tokens are spent.
 */
class PdfClassifier
{
    /**
     * The share of a report type's columns a PDF must contain to get that type.
     */
    public const MIN_SCORE = 0.7;

    /**
     * Column headers sit at the top of a report, so only the first pages are read.
     */
    private const PAGES = 2;

    /**
     * classifyPending
     *
     * Classify the documents that haven't been checked yet.
     *
     * @param Collection<int, PdfDocument> $documents
     * @return int number of documents classified
     */
    public function classifyPending(Collection $documents): int
    {
        $pending = $documents->whereNull('classified_at');

        $pending->each(fn (PdfDocument $document) => $this->classify($document));

        return $pending->count();
    }

    /**
     * classify
     *
     * Read the document's text, match it against the configured report types
     * and save the result. A file that is missing or can't be read is marked
     * checked with no type.
     *
     * @param PdfDocument $document
     * @return void
     */
    public function classify(PdfDocument $document): void
    {
        $contents = $document->contents();
        $text = $contents ? $this->text($contents) : '';

        $document->update([
            'report_type' => $this->reportTypeForText($text, config('report_types')),
            'classified_at' => now(),
        ]);
    }

    /**
     * reportTypeForText
     *
     * The report type with the largest share of its columns found in the
     * text, when that share reaches MIN_SCORE. Ties go to the type with more
     * matched columns.
     *
     * @param string $text
     * @param array<string, array<int|string, mixed>> $types
     * @return string|null
     */
    public function reportTypeForText(string $text, array $types): ?string
    {
        $text = $this->normalize($text);

        if (trim($text) === '') {
            return null;
        }

        $best = null;
        $bestScore = 0.0;
        $bestMatched = 0;

        foreach ($types as $type => $columns) {
            $columns = Arr::flatten($columns);

            if (! $columns) {
                continue;
            }

            $matched = count(array_filter($columns, fn ($column) => $this->hasColumn($text, (string) $column)));
            $score = $matched / count($columns);

            if ($score > $bestScore || ($score === $bestScore && $matched > $bestMatched)) {
                [$best, $bestScore, $bestMatched] = [$type, $score, $matched];
            }
        }

        return $bestScore >= self::MIN_SCORE ? $best : null;
    }

    /**
     * text
     *
     * The text of the PDF's first pages, or an empty string when the file
     * can't be parsed.
     *
     * @param string $contents raw PDF bytes
     * @return string
     */
    protected function text(string $contents): string
    {
        try {
            $pages = (new Parser())->parseContent($contents)->getPages();
        } catch (Exception) {
            return '';
        }

        return collect(array_slice($pages, 0, self::PAGES))
            ->map(fn ($page) => $page->getText())
            ->implode("\n");
    }

    /**
     * hasColumn
     *
     * Whether the column header appears in the normalized text as whole words.
     * Spacing may differ ("Invoice #" matches "Invoice#", headers may wrap
     * onto a new line), and a header with a slash, like "Client / Debtor",
     * matches when each part appears on its own.
     *
     * @param string $text normalized text
     * @param string $column
     * @return bool
     */
    private function hasColumn(string $text, string $column): bool
    {
        $parts = array_filter(array_map(fn ($part) => trim($this->normalize($part)), explode('/', $column)));

        if (! $parts) {
            return false;
        }

        foreach ($parts as $part) {
            preg_match_all('/[a-z0-9]+|[#+]/', $part, $tokens);
            $pattern = implode('\s*', array_map(fn ($token) => preg_quote($token, '/'), $tokens[0]));

            if (! preg_match('/(?<![a-z0-9])'.$pattern.'(?![a-z0-9])/', $text)) {
                return false;
            }
        }

        return true;
    }

    /**
     * normalize
     *
     * Lowercase the text and turn everything except letters, digits, "#" and
     * "+" into spaces.
     *
     * @param string $text
     * @return string
     */
    private function normalize(string $text): string
    {
        return preg_replace('/[^a-z0-9#+]+/', ' ', mb_strtolower($text));
    }
}
