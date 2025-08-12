<?php

namespace SonnyDev\FedexBundle\Command;

use SonnyDev\FedexBundle\Dto\FedexTrackingEvent;
use SonnyDev\FedexBundle\Service\FedexTrackingService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'fedex:track',
    description: 'Affiche les événements de suivi FedEx pour un numéro de tracking.'
)]
class FedexTrackCommand extends Command
{
    public function __construct(
        private readonly FedexTrackingService $trackingService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('trackingNumber', InputArgument::REQUIRED, 'Numéro de suivi FedEx (tracking).')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Locale d’affichage', 'fr_FR')
            ->addOption('timezone', null, InputOption::VALUE_REQUIRED, 'Fuseau horaire (ex: Europe/Paris)', 'Europe/Paris')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Limiter le nombre de lignes affichées', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $trackingNumber = (string) $input->getArgument('trackingNumber');
        $locale  = (string) $input->getOption('locale');
        $tzName  = (string) $input->getOption('timezone');
        $limit   = (int) $input->getOption('limit');

        try {
            $events = $this->trackingService->trackShipment($trackingNumber, includeDetailedScans: true, locale: $locale);
        } catch (\Throwable $e) {
            $output->writeln('<error>Erreur lors de l’appel FedEx : ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if (empty($events)) {
            $output->writeln('<comment>Aucun événement trouvé pour ce numéro.</comment>');
            return Command::SUCCESS;
        }

        // Optionnel : limiter le nombre d’événements affichés
        if ($limit > 0) {
            $events = \array_slice($events, 0, $limit);
        }

        $table = new Table($output);
        $table->setHeaders(['Date', 'Ville / Pays', 'Description']);

        $timezone = new \DateTimeZone($tzName);

        /** @var FedexTrackingEvent $event */
        foreach ($events as $event) {
            $date = $event->date->setTimezone($timezone);

            // Format date FR simple : 15/08/2025 13:37
            $dateStr = $date->format('d/m/Y H:i');

            $location = trim(implode(' / ', array_filter([
                $event->city ?: null,
                $event->country ?: null,
            ])));

            $table->addRow([
                $dateStr,
                $location !== '' ? $location : '-',
                $event->description,
            ]);
        }

        $output->writeln(sprintf('<info>Numéro :</info> %s', $trackingNumber));
        $table->render();

        return Command::SUCCESS;
    }
}