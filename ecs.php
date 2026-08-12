<?php

declare(strict_types=1);

use Contao\EasyCodingStandard\Set\SetList;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return ECSConfig::configure()
    ->withSets([SetList::CONTAO])
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withRootFiles()
    ->withParallel()
    ->withSpacing(indentation: '    ', lineEnding: "\n")
    ->withCache(sys_get_temp_dir().'/ecs_netzhirsch_contao_ai_tag')
;
