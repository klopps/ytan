<?php

declare(strict_types=1);

namespace Ytan\Service\LighthouseList;

use Smalot\PdfParser\Parser;

/**
 * Positioned text fragments of a PDF's pages ([x, y, text], y grows upwards),
 * the input of the lighthouse list parsers. Uses smalot/pdfparser, a pure PHP
 * parser - a dev dependency, since the lists are read by a CLI script
 * (bin/import-lighthouse-list.php) on a developer machine, never by the web app.
 */
final class PdfTextFragments
{
    /**
     * @return \Generator<int, list<array{0:float,1:float,2:string}>>
     */
    public static function pagesOf(string $pdfPath): \Generator
    {
        if (!class_exists(Parser::class)) {
            throw new \RuntimeException('smalot/pdfparser is not installed (run composer install with dev packages).');
        }

        $pdf = (new Parser())->parseFile($pdfPath);
        foreach ($pdf->getPages() as $page) {
            $fragments = [];
            foreach ($page->getDataTm() as [$matrix, $text]) {
                $fragments[] = [(float) $matrix[4], (float) $matrix[5], (string) $text];
            }
            yield $fragments;
        }
    }
}
