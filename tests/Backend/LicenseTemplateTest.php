<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Tests\Backend;

use Netzhirsch\ContaoAiTagBundle\Backend\LicenseLabels;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Fuehrt die Backend-Vorlage der Lizenz-Seite wirklich aus.
 *
 * Anlass: die Seite brach beim ersten Aufruf auf einer echten Installation ab,
 * weil ihr eine Closure uebergeben wurde (Contaos Template::__get() ruft
 * aufrufbare Objekte beim Lesen auf). Ein Test, der nur Klassen prueft, findet so
 * etwas nie - hier wird die Vorlage deshalb mit denselben Daten gerendert, die
 * das Modul setzt.
 *
 * Statt einer echten BackendTemplate wird ein einfaches Objekt gebunden: die
 * Vorlage liest ausschliesslich eigene Eigenschaften, und genau das ist der
 * Vertrag, der halten muss.
 */
class LicenseTemplateTest extends TestCase
{
    private const TEMPLATE = __DIR__.'/../../contao/templates/backend/be_netzhirsch_ai_tag_license.html5';

    /**
     * @param array<string, mixed> $license
     * @param list<string>         $expected
     */
    #[DataProvider('stateProvider')]
    public function testRendersEveryState(array $license, string $plan, array $expected): void
    {
        $html = $this->render($license, $plan);

        foreach ($expected as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, list<string>}>
     */
    public static function stateProvider(): iterable
    {
        yield 'nicht lizenzpflichtig' => [
            ['armed' => false, 'active' => true, 'type' => '', 'reason' => 'not_enforced', 'domain' => 'kunde.de', 'expires_at' => 0, 'days_left' => 0, 'in_grace' => false],
            '',
            ['notice.not_enforced', 'state.not_enforced'],
        ];

        yield 'keine Lizenz' => [
            ['armed' => true, 'active' => false, 'type' => '', 'reason' => 'no_token', 'domain' => 'kunde.de', 'expires_at' => 0, 'days_left' => 0, 'in_grace' => false],
            '',
            ['action=start_trial', 'action=subscribe', 'notice.inactive', 'reason.no_token'],
        ];

        yield 'Testphase laeuft' => [
            ['armed' => true, 'active' => true, 'type' => 'trial', 'reason' => 'ok', 'domain' => 'kunde.de', 'expires_at' => 1_800_000_000, 'days_left' => 12, 'in_grace' => false],
            '',
            ['action=subscribe', 'type.trial', 'expiry.days_left'],
        ];

        yield 'bezahlt' => [
            ['armed' => true, 'active' => true, 'type' => 'full', 'reason' => 'ok', 'domain' => 'kunde.de', 'expires_at' => 1_800_000_000, 'days_left' => 30, 'in_grace' => false],
            'monthly',
            ['action=manage_billing', 'type.full'],
        ];

        yield 'interne Lizenz' => [
            ['armed' => true, 'active' => true, 'type' => 'full', 'reason' => 'ok', 'domain' => 'kunde.de', 'expires_at' => 1_800_000_000, 'days_left' => 30, 'in_grace' => false],
            'internal',
            ['type.internal', 'expiry.unlimited'],
        ];

        yield 'Karenzzeit' => [
            ['armed' => true, 'active' => true, 'type' => 'full', 'reason' => 'expired', 'domain' => 'kunde.de', 'expires_at' => 1_700_000_000, 'days_left' => 0, 'in_grace' => true],
            'monthly',
            ['notice.grace', 'state.grace'],
        ];
    }

    /**
     * Die interne Lizenz hat keinen Stripe-Kunden - das Kundenportal darf dort nicht
     * angeboten werden, es koennte nur mit einem Fehler antworten.
     */
    public function testAnInternalLicenceOffersNoBillingButtons(): void
    {
        $html = $this->render(
            ['armed' => true, 'active' => true, 'type' => 'full', 'reason' => 'ok', 'domain' => 'kunde.de', 'expires_at' => 1_800_000_000, 'days_left' => 30, 'in_grace' => false],
            'internal',
        );

        $this->assertStringNotContainsString('action=manage_billing', $html);
        $this->assertStringNotContainsString('action=subscribe', $html);
    }

    /**
     * Ohne Durchsetzung gibt es nichts zu kaufen und nichts zu erneuern.
     */
    public function testWithoutEnforcementNoActionsAreOffered(): void
    {
        $html = $this->render(
            ['armed' => false, 'active' => true, 'type' => '', 'reason' => 'not_enforced', 'domain' => '', 'expires_at' => 0, 'days_left' => 0, 'in_grace' => false],
            '',
        );

        $this->assertStringNotContainsString('action=', $html);
    }

    /**
     * Der Hinweis auf eine neuere Fassung: sichtbar, verlinkt, und bei einem
     * sicherheitsrelevanten Release deutlicher dargestellt.
     */
    public function testShowsTheUpdateNotice(): void
    {
        $html = $this->render(
            ['armed' => true, 'active' => true, 'type' => 'full', 'reason' => 'ok', 'domain' => 'kunde.de', 'expires_at' => 1_800_000_000, 'days_left' => 30, 'in_grace' => false],
            'monthly',
            ['text' => 'Version 1.1.0 ist verfuegbar.', 'url' => 'https://example.com/notes', 'linkLabel' => 'Was sich geaendert hat', 'security' => false],
        );

        $this->assertStringContainsString('Version 1.1.0 ist verfuegbar.', $html);
        $this->assertStringContainsString('https://example.com/notes', $html);
        $this->assertStringContainsString('tl_info', $html);
    }

    public function testMarksASecurityReleaseMoreClearly(): void
    {
        $html = $this->render(
            ['armed' => true, 'active' => true, 'type' => 'full', 'reason' => 'ok', 'domain' => 'kunde.de', 'expires_at' => 1_800_000_000, 'days_left' => 30, 'in_grace' => false],
            'monthly',
            ['text' => 'Sicherheitsrelevantes Update.', 'url' => '', 'linkLabel' => 'Was sich geaendert hat', 'security' => true],
        );

        $this->assertStringContainsString('Sicherheitsrelevantes Update.', $html);
        // Ohne geprueften Verweis steht der Hinweis trotzdem, nur ohne Link.
        $this->assertStringNotContainsString('Was sich geaendert hat', $html);
        $this->assertStringContainsString('tl_error', $html);
    }

    /**
     * Der Normalfall: nichts angekuendigt, nichts zu sehen. Die Seite darf davon
     * nicht abhaengen - ein Bundle ohne diese Meldung laeuft unveraendert weiter.
     */
    public function testWithoutAnAnnouncementNothingIsShown(): void
    {
        $html = $this->render(
            ['armed' => true, 'active' => true, 'type' => 'full', 'reason' => 'ok', 'domain' => 'kunde.de', 'expires_at' => 1_800_000_000, 'days_left' => 30, 'in_grace' => false],
            'monthly',
        );

        $this->assertStringNotContainsString('update.', $html);
    }

    /**
     * @param array<string, mixed>                                                     $license
     * @param array{text: string, url: string, linkLabel: string, security: bool}|null $update
     */
    private function render(array $license, string $plan, array|null $update = null): string
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator
            ->method('trans')
            ->willReturnArgument(0)
        ;

        // Stellvertreter mit denselben Zugriffsregeln wie Contaos Template: __get()
        // fuehrt jedes aufrufbare Objekt beim Lesen aus. Genau daran ist die Seite auf
        // einer echten Installation gescheitert - hier wuerde derselbe Fehler auffallen.
        // Bewusst eine eigene Klasse: an eine interne wie stdClass laesst PHP keine
        // Closure binden (Warnung, Rueckgabe null).
        $data = new class() {
            /**
             * @var array<string, mixed>
             */
            private array $values = [];

            /**
             * Gesetzt wird ueber eine Methode statt ueber __set: die statische Analyse meldet
             * sonst jede Zuweisung als undefinierte Eigenschaft. Fuer die Vorlage zaehlt
             * allein das Lesen.
             */
            public function set(string $key, mixed $value): void
            {
                $this->values[$key] = $value;
            }

            public function __get(string $key): mixed
            {
                $value = $this->values[$key] ?? null;

                return \is_object($value) && \is_callable($value) ? $value() : $value;
            }
        };

        $data->set('labels', LicenseLabels::build($translator, ['reason' => (string) $license['reason'], 'days_left' => (int) $license['days_left']]));
        $data->set('license', $license);
        $data->set('licensePlan', $plan);
        $data->set('licenseFile', '/var/www/var/netzhirsch-ai-tag/license.json');
        $data->set('actionUrl', '/contao?do=netzhirsch_ai_tag_license&rt=token');
        $data->set('referer', '/contao');
        $data->set('backTitle', 'Zurueck');
        $data->set('backLabel', 'Zurueck');
        $data->set('messages', '');
        $data->set('update', $update);

        // Nicht static: eine statische Closure laesst sich nicht an ein Objekt binden,
        // und die Vorlage liest ihre Werte ueber $this. Der Pfad kommt ueber use(), weil
        // die Closure im Geltungsbereich des gebundenen Objekts laeuft und die Konstante
        // dieser Testklasse dort nicht erreichbar waere.
        $template = self::TEMPLATE;

        $render = function () use ($template): string {
            ob_start();

            try {
                include $template;

                return (string) ob_get_contents();
            } finally {
                ob_end_clean();
            }
        };

        // Die Praefixe der Uebersetzungsschluessel stehen in der Ausgabe, weil die
        // Attrappe die Kennung zurueckgibt - genau daran laesst sich pruefen, welcher
        // Zweig gerendert wurde.
        return str_replace('netzhirsch_ai_tag.license.', '', $render->call($data));
    }
}
