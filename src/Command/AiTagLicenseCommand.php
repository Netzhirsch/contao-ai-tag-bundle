<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Command;

use Netzhirsch\ContaoAiTagBundle\License\LicenseGate;
use Netzhirsch\ContaoAiTagBundle\License\LicenseStore;
use Netzhirsch\ContaoAiTagBundle\License\RenewalClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lizenzzustand anzeigen und Token erneuern - fuer Betrieb und Fehlersuche
 * ohne Backend-Zugang.
 *
 * Weder Token noch instance_secret werden ausgegeben. Wer sie sehen will, kann
 * die Datei lesen; die Ausgabe eines Support-Befehls landet dagegen schnell in
 * einem Ticket oder einem Chat.
 *
 * In der CLI gibt es keinen Request-Host. Die Domain kommt dann aus
 * `license_backend_url`, sonst aus dem gespeicherten Token - siehe LicenseGate.
 */
#[AsCommand(
    name: 'netzhirsch:ai-tag:license',
    description: 'Zeigt den Lizenzzustand der KI-Kennzeichnung oder erneuert das Token.',
)]
final class AiTagLicenseCommand extends Command
{
    public function __construct(
        private readonly LicenseGate $gate,
        private readonly LicenseStore $store,
        private readonly RenewalClient $renewalClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'status oder renew', 'status')
            ->setHelp('status zeigt den Zustand, renew erzwingt einen Erneuerungsversuch (holt auch eine interne Lizenz).')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = (string) $input->getArgument('action');

        if (!\in_array($action, ['status', 'renew'], true)) {
            $io->error(\sprintf('Unbekannte Aktion "%s". Erlaubt sind status und renew.', $action));

            return Command::INVALID;
        }

        if ('renew' === $action) {
            // Auch ein Fehlschlag ist ein Ergebnis: der Zustand danach sagt mehr als ein
            // abgebrochener Befehl, deshalb wird er in jedem Fall ausgegeben.
            $this->renew($io);
        }

        $state = $this->gate->state();

        $io->definitionList(
            ['Durchsetzung' => $state['armed'] ? 'aktiv' : 'nicht lizenzpflichtig (kein Schluessel einkompiliert)'],
            ['Kennzeichnung' => $state['active'] ? 'wird eingebrannt' : 'wird NICHT eingebrannt'],
            ['Grund' => $state['reason']],
            ['Art' => '' !== $state['type'] ? $state['type'] : '-'],
            ['Plan' => '' !== $state['plan'] ? $state['plan'] : '-'],
            ['Domain' => '' !== $state['domain'] ? $state['domain'] : '-'],
            ['Laeuft ab' => $state['expires_at'] > 0 ? date('Y-m-d H:i', $state['expires_at']).' ('.$state['days_left'].' Tage)' : '-'],
            ['Karenz' => $state['in_grace'] ? 'ja' : 'nein'],
            ['Datei' => $this->store->filePath()],
        );

        return $state['active'] ? Command::SUCCESS : Command::FAILURE;
    }

    private function renew(SymfonyStyle $io): void
    {
        $result = $this->renewalClient->renew(true);

        if ($result['ok'] ?? false) {
            $io->success('Token erneuert.');

            return;
        }

        $io->warning(\sprintf(
            'Erneuerung nicht moeglich: %s (%s)',
            (string) ($result['message'] ?? ''),
            (string) ($result['error'] ?? ''),
        ));
    }
}
