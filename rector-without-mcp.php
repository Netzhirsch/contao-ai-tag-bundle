<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

/*
 * Gegenstueck zu phpstan-without-mcp.neon.dist: wird nur benutzt, wenn
 * netzhirsch/contao-mcp-bundle nicht installiert werden kann. Ohne dessen
 * Basisklasse ist src/Mcp nicht analysierbar.
 */
return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withSkip([
        __DIR__.'/src/Mcp',
        __DIR__.'/tests/Mcp',
    ])
    ->withPhpSets(php81: true)
    ->withImportNames(importShortClasses: false)
;
