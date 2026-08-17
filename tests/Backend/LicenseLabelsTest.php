<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Tests\Backend;

use Netzhirsch\ContaoAiTagBundle\Backend\LicenseLabels;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class LicenseLabelsTest extends TestCase
{
    private const TEMPLATE = __DIR__.'/../../contao/templates/backend/be_netzhirsch_ai_tag_license.html5';

    /**
     * Der Fehler, der diese Klasse ausgeloest hat: der Vorlage wurde eine Closure
     * uebergeben. Contaos Template::__get() fuehrt jedes aufrufbare Objekt beim Lesen
     * sofort aus, die Seite brach mit "Too few arguments" ab. Es darf deshalb nichts
     * anderes als Zeichenketten in die Vorlage gehen.
     */
    public function testEveryLabelIsAPlainString(): void
    {
        foreach (LicenseLabels::build($this->translator(), $this->state()) as $key => $value) {
            $this->assertIsString($value, \sprintf('Der Wert zu "%s" muss eine Zeichenkette sein.', $key));
        }
    }

    /**
     * Die Vorlage kann nichts ausgeben, was sie nicht bekommt - und sie schweigt
     * dabei. Deshalb wird hier abgeglichen, welche Schluessel sie tatsaechlich liest.
     */
    public function testTheTemplateFindsEveryLabelItReads(): void
    {
        $template = (string) file_get_contents(self::TEMPLATE);

        preg_match_all("/\\\$labels\\['([^']+)'\\]/", $template, $matches);

        $used = array_unique($matches[1]);
        $available = LicenseLabels::build($this->translator(), $this->state());

        $this->assertNotEmpty($used, 'Ohne gefundene Schluessel wuerde dieser Test nichts pruefen.');

        foreach ($used as $key) {
            $this->assertArrayHasKey($key, $available, \sprintf('Die Vorlage liest "%s", LicenseLabels liefert es nicht.', $key));
        }
    }

    public function testTheReasonIsResolvedForTheCurrentState(): void
    {
        $labels = LicenseLabels::build($this->translator(), ['reason' => 'wrong_domain', 'days_left' => 0]);

        $this->assertSame('netzhirsch_ai_tag.license.reason.wrong_domain', $labels['reason']);
    }

    public function testTheRemainingDaysAreAlreadySubstituted(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator
            ->method('trans')
            ->willReturnCallback(
                static fn (string $id, array $parameters = []): string => $id.('' !== implode('', $parameters) ? ':'.implode('', $parameters) : ''),
            )
        ;

        $labels = LicenseLabels::build($translator, ['reason' => 'ok', 'days_left' => 17]);

        $this->assertSame('netzhirsch_ai_tag.license.expiry.days_left:17', $labels['days_left']);
    }

    /**
     * @return array{reason: string, days_left: int}
     */
    private function state(): array
    {
        return ['reason' => 'ok', 'days_left' => 35];
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator
            ->method('trans')
            ->willReturnArgument(0)
        ;

        return $translator;
    }
}
