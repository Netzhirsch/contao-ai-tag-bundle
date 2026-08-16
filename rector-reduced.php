<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

/*
 * Gegenstueck zu phpstan-reduced.neon.dist: ueberspringt die Dateien, die eine
 * optionale Abhaengigkeit brauchen - das MCP-Bundle und Contao ab 5.5.
 */
return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withSkip([
        __DIR__.'/src/Mcp',
        __DIR__.'/tests/Mcp',
        __DIR__.'/src/EventListener/DetectAiSourceListener.php',
    ])
    ->withPhpSets(php81: true)
    ->withImportNames(importShortClasses: false)
;
