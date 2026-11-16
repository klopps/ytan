<?php

declare(strict_types=1);

namespace Ytan\Tests;

/**
 * Deletes a temporary test directory with everything below it. Used to be a
 * private copy of the same function in ten test classes.
 */
trait RemovesDirectories
{
    private function rmrf(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->rmrf($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
