<?php

declare(strict_types=1);

namespace Jot\Article;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Jot\Exception\HttpException;
use Jot\Web\Copy;

final class ArticleExtractor
{
    public function wordCount(string $html): int
    {
        if (trim($html) === '') {
            throw new HttpException(422, Copy::EXTRACTION_FAILED);
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false) {
            throw new HttpException(422, Copy::EXTRACTION_FAILED);
        }

        $xpath = new DOMXPath($document);
        $unreadable = $xpath->query('//script|//style|//nav|//header|//footer|//aside|//form|//template|//svg|//noscript|//iframe|//canvas');

        if ($unreadable !== false) {
            foreach (iterator_to_array($unreadable) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        foreach (['//article', '//main', '//body'] as $selector) {
            $nodes = $xpath->query($selector);
            $largestCount = 0;

            if ($nodes === false) {
                continue;
            }

            foreach ($nodes as $node) {
                if (!$node instanceof DOMElement) {
                    continue;
                }

                $largestCount = max($largestCount, $this->countWords($node->textContent));
            }

            if ($largestCount > 0) {
                return $largestCount;
            }
        }

        throw new HttpException(422, Copy::EXTRACTION_FAILED);
    }

    private function countWords(string $text): int
    {
        $matched = preg_match_all("/[\p{L}\p{N}]+(?:[’'\x{2010}\x{2011}-][\p{L}\p{N}]+)*/u", $text, $matches);
        return $matched === false ? 0 : $matched;
    }
}
