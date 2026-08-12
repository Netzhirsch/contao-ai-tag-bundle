<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

/*
 * Der Boden ist PHP 8.1, weil Contao 5.3 (LTS) ab 8.1 laeuft. Ein hoeheres Set
 * wuerde typisierte Klassenkonstanten (8.3) und "readonly class" (8.2) einbauen
 * und das Bundle auf 5.3-Installationen unbrauchbar machen.
 */
return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withPhpSets(php81: true)
    ->withImportNames(importShortClasses: false)
;
