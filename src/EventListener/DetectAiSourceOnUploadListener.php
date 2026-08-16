<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\EventListener;

use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Netzhirsch\ContaoAiTagBundle\Detection\AiSourceInspector;

/**
 * Erkennung fuer Contao 5.3, wo es DbafsChangeEvent noch nicht gibt.
 *
 * Der Hook deckt nur den Upload im Backend-Dateimanager ab. Dateien, die per
 * Konsole, MCP oder FTP dazukommen, werden auf 5.3 erst geprueft, wenn jemand sie
 * im Backend oeffnet (AiSourceInspector::inspectIfUnchecked).
 */
#[AsHook('postUpload')]
class DetectAiSourceOnUploadListener
{
    public function __construct(private readonly AiSourceInspector $inspector)
    {
    }

    /**
     * @param array<string> $files Pfade relativ zum Projektverzeichnis
     */
    public function __invoke(array $files): void
    {
        if (!$this->inspector->isEnabled()) {
            return;
        }

        foreach ($files as $file) {
            $this->inspector->inspect((string) $file);
        }
    }
}
