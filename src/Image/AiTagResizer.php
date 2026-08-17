<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Image;

use Contao\CoreBundle\Routing\ScopeMatcher;
use Contao\Image\DeferredImageInterface;
use Contao\Image\DeferredImageStorageInterface;
use Contao\Image\DeferredResizerInterface;
use Contao\Image\ImageInterface;
use Contao\Image\ResizeConfiguration;
use Contao\Image\ResizeOptions;
use Contao\Image\ResizerInterface;
use Imagine\Image\ImagineInterface;
use Netzhirsch\ContaoAiTagBundle\License\LicenseGate;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Legt die KI-Kennzeichnung auf jede erzeugte Bildgroesse.
 *
 * Warum ein Decorator und keine Subklasse von Contao\CoreBundle\Image\Resizer:
 * executeResize() ist als Hook nicht brauchbar, weil dort in DeferredResizer die
 * Deferral-Entscheidung faellt (ein Override wuerde Deferred-Rendering global
 * abschalten) und weil executeDeferredResize() per parent:: direkt die Methode
 * der Basisklasse aufruft und jede Subklasse ueberspringt.
 *
 * Ablauf:
 *  1. resize() - Sprache, Text und Ecke ermitteln und als Imagine-Option
 *                             einfrieren; die Option geht in den Cache-Hash ein, es
 *                             entsteht also je Sprache eine eigene Datei.
 *  2. resizeDeferredImage() - eingefrorene Option lesen und die Kennzeichnung auf das
 *                             fertige Bild brennen.
 */
final class AiTagResizer implements ResizerInterface, DeferredResizerInterface
{
    /**
     * Erzwingt die Kennzeichnung auch dort, wo sie sonst unterbleibt. Wird vor dem
     * Delegieren wieder entfernt, damit die Vorschau dieselbe Cache-Datei trifft wie
     * das Frontend und kein zusaetzliches Bild entsteht.
     */
    public const FORCE_KEY = 'netzhirsch_ai_tag_force';

    private const QUALITY_KEYS = ['quality', 'jpeg_quality', 'webp_quality', 'avif_quality', 'heic_quality', 'jxl_quality'];

    public function __construct(
        private readonly ResizerInterface&DeferredResizerInterface $inner,
        private readonly AiTagResolver $resolver,
        private readonly TagRenderer $renderer,
        private readonly LicenseGate $gate,
        private readonly DeferredImageStorageInterface $storage,
        private readonly ScopeMatcher $scopeMatcher,
        private readonly RequestStack $requestStack,
        private readonly string $cacheDir,
        private readonly string $uploadDir,
        /**
         * Backend-Bilder - etwa die Vorschau in der Dateiverwaltung - bleiben standardmaessig
         * unbearbeitet: dort soll die Datei zu sehen sein, nicht die Auslieferung. Die
         * Gegenueberstellung beider Fassungen leistet das eigene Vorschaufeld.
         */
        private readonly bool $tagBackendImages = false,
        /**
         * Qualitaet der ersten Kodierung. Bewusst hoch, weil die Nachbearbeitung ein zweites
         * Mal kodiert; gespeichert wird am Ende mit der Zielqualitaet der Bildgroesse.
         */
        private readonly int $intermediateQuality = 95,
        private readonly LoggerInterface|null $logger = null,
    ) {
    }

    public function resize(ImageInterface $image, ResizeConfiguration $config, ResizeOptions $options): ImageInterface
    {
        $tag = $this->resolveTag($image, $options);

        if (null === $tag) {
            return $this->inner->resize($image, $config, $options);
        }

        $options = $this->prepareOptions($options, $tag);
        $startedAt = time();
        $result = $this->inner->resize($image, $config, $options);

        // Eager erzeugt (targetPath oder bypassCache): sofort kennzeichnen.
        if (!$result instanceof DeferredImageInterface && $this->mayWrite($result->getPath(), $startedAt)) {
            $this->renderer->apply(
                $result->getPath(),
                $tag,
                $options->getImagineOptions(),
                $options->getPreserveCopyrightMetadata(),
            );
        }

        return $result;
    }

    public function getDeferredImage(string $targetPath, ImagineInterface $imagine): DeferredImageInterface|null
    {
        return $this->inner->getDeferredImage($targetPath, $imagine);
    }

    public function resizeDeferredImage(DeferredImageInterface $image, bool $blocking = true): ImageInterface|null
    {
        // Die eingefrorene Konfiguration muss VOR dem Ausfuehren gelesen werden, danach
        // hat Contao den Storage-Eintrag entfernt.
        $frozen = $this->readFrozenConfig($image->getPath());

        $startedAt = time();
        $result = $this->inner->resizeDeferredImage($image, $blocking);

        if (null === $result || null === $frozen || !$this->mayWrite($result->getPath(), $startedAt)) {
            return $result;
        }

        $this->renderer->apply($result->getPath(), $frozen['tag'], $frozen['imagine_options'], $frozen['preserve_copyright']);

        return $result;
    }

    /**
     * Zwei Schutzmechanismen in einem:
     *
     * 1. Nie in das Dateiverwaltungs-Verzeichnis schreiben. Contao gibt bei
     *    unbestimmten Bildmaszen (etwa SVG) den Pfad des Originals zurueck - dort
     *    darf niemals gezeichnet werden.
     * 2. Nur kennzeichnen, wenn die Datei in diesem Aufruf entstanden ist. Bei einem
     *    Cache-Treffer liefert der Resizer die vorhandene, bereits gekennzeichnete
     *    Datei zurueck; ohne diese Pruefung wuerde sie bei jedem Seitenaufruf erneut
     *    bearbeitet und neu kodiert.
     */
    private function mayWrite(string $path, int $startedAt): bool
    {
        $path = Path::canonicalize($path);

        if (Path::isBasePath($this->uploadDir, $path)) {
            return false;
        }

        if (!is_file($path)) {
            return false;
        }

        clearstatcache(true, $path);

        return filemtime($path) >= $startedAt;
    }

    private function resolveTag(ImageInterface $image, ResizeOptions $options): AiTagOptions|null
    {
        // Der einzige Ort, an dem die Lizenz ueber die Kennzeichnung entscheidet:
        // Einbrennen ist die lizenzpflichtige Leistung. Markieren, Erkennung, Protokoll,
        // Nachweis-Export und die Textalternative im Markup bleiben ohne Lizenz nutzbar
        // - niemand soll den Zugriff auf seine eigenen Nachweise verlieren. Dass gerade
        // nichts eingebrannt wird, sagen die Dateiverwaltung und das Vorschaufeld
        // ausdruecklich; stillschweigend ausbleiben darf die Kennzeichnung nicht.
        if (!$this->gate->isActive()) {
            return null;
        }

        if (!$this->isForced($options) && !$this->tagBackendImages && $this->isBackendRequest()) {
            return null;
        }

        try {
            return $this->resolver->resolve($image, $this->targetQuality($options->getImagineOptions()));
        } catch (\Throwable $exception) {
            // Die Bildauslieferung darf an der Kennzeichnung nicht scheitern.
            $this->logger?->error('KI-Kennzeichnung konnte nicht aufgeloest werden: '.$exception->getMessage());

            return null;
        }
    }

    private function isForced(ResizeOptions $options): bool
    {
        return true === ($options->getImagineOptions()[self::FORCE_KEY] ?? false);
    }

    private function isBackendRequest(): bool
    {
        $request = $this->requestStack->getCurrentRequest();

        return null !== $request && $this->scopeMatcher->isBackendRequest($request);
    }

    private function prepareOptions(ResizeOptions $options, AiTagOptions $tag): ResizeOptions
    {
        $imagineOptions = $options->getImagineOptions();
        unset($imagineOptions[self::FORCE_KEY]);

        foreach (self::QUALITY_KEYS as $key) {
            if (isset($imagineOptions[$key])) {
                $imagineOptions[$key] = $this->intermediateQuality;
            }
        }

        $imagineOptions['quality'] = $this->intermediateQuality;
        $imagineOptions[AiTagOptions::OPTION_KEY] = $tag->toArray();

        $prepared = clone $options;
        $prepared->setImagineOptions($imagineOptions);

        // Ohne das gibt Contao bei uebereinstimmenden Maszen das ungekennzeichnete
        // Original zurueck, statt eine Kopie zu erzeugen.
        $prepared->setSkipIfDimensionsMatch(false);

        return $prepared;
    }

    /**
     * @return array{tag: AiTagOptions, imagine_options: array<string, mixed>, preserve_copyright: array<string, array<mixed>>}|null
     */
    private function readFrozenConfig(string $path): array|null
    {
        $relativePath = Path::isAbsolute($path) ? Path::makeRelative($path, $this->cacheDir) : $path;

        try {
            if (!$this->storage->has($relativePath)) {
                return null;
            }

            $config = $this->storage->get($relativePath);
        } catch (\Throwable $exception) {
            $this->logger?->debug('Deferred-Konfiguration fuer die KI-Kennzeichnung nicht lesbar: '.$exception->getMessage());

            return null;
        }

        $imagineOptions = $config['options']['imagine_options'] ?? [];
        $tag = AiTagOptions::fromArray($imagineOptions[AiTagOptions::OPTION_KEY] ?? null);

        if (null === $tag) {
            return null;
        }

        return [
            'tag' => $tag,
            'imagine_options' => $imagineOptions,
            'preserve_copyright' => $config['options']['preserve_copyright'] ?? [],
        ];
    }

    /**
     * @param array<string, mixed> $imagineOptions
     */
    private function targetQuality(array $imagineOptions): int
    {
        foreach (['jpeg_quality', 'quality', 'webp_quality'] as $key) {
            if (isset($imagineOptions[$key]) && is_numeric($imagineOptions[$key])) {
                return max(1, min(100, (int) $imagineOptions[$key]));
            }
        }

        return 80;
    }
}
