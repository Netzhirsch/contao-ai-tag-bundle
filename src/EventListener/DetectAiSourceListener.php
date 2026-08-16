<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\EventListener;

use Contao\CoreBundle\Filesystem\Dbafs\DbafsChangeEvent;
use Contao\CoreBundle\Filesystem\FilesystemItem;
use Contao\CoreBundle\Filesystem\VirtualFilesystemInterface;
use Netzhirsch\ContaoAiTagBundle\Detection\AiSourceInspector;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Prueft jede neu in tl_files aufgenommene Datei auf ihre Herkunftsangaben.
 *
 * DbafsChangeEvent statt des postUpload-Hooks: der feuert nur im
 * Backend-Dateimanager, waehrend das Ereignis jeden Weg abdeckt - Upload, Drag
 * and Drop, contao:filesystem:sync, MCP, per FTP nachgeschobene Dateien. Contao
 * selbst indiziert darueber die Backend-Suche neu.
 *
 * Es gibt das Ereignis erst ab Contao 5.5; auf 5.3 registriert das Bundle
 * stattdessen den Hook (siehe NetzhirschContaoAiTagBundle::loadExtension).
 */
#[AsEventListener]
class DetectAiSourceListener
{
    public function __construct(
        private readonly VirtualFilesystemInterface $filesStorage,
        private readonly AiSourceInspector $inspector,
        private readonly string $uploadPath,
    ) {
    }

    public function __invoke(DbafsChangeEvent $event): void
    {
        if (!$this->inspector->isEnabled()) {
            return;
        }

        /** @var FilesystemItem $item */
        foreach ($event->getCreatedFilesystemItems($this->filesStorage) as $item) {
            if ($item->isFile()) {
                $this->inspector->inspect($this->uploadPath.'/'.ltrim($item->getPath(), '/'));
            }
        }
    }
}
