<?php

namespace SonnyDev\FedexBundle\Command;

use SonnyDev\FedexBundle\Service\FedexLocationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'fedex:location:search', description: 'Search FedEx locations near an address')]
final class FedexLocationSearchCommand extends Command
{
    public function __construct(private readonly FedexLocationService $locationService) // ->searchLocations(...)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('postal', InputArgument::REQUIRED, 'Code postal (ex: 75001)')
            ->addArgument('country', InputArgument::REQUIRED, 'Code pays ISO2 (ex: FR)')
            ->addOption('street', null, InputOption::VALUE_REQUIRED, 'Rue (ligne 1)')
            ->addOption('city', null, InputOption::VALUE_REQUIRED, 'Ville')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Sortie JSON (DTOs)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre maximum de résultats', '5');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $io = new SymfonyStyle($input, $stderr);

        try {
            $street  = (string) ($input->getOption('street') ?? '');
            $city    = (string) ($input->getOption('city') ?? '');
            $postal  = (string) $input->getArgument('postal');
            $country = strtoupper((string) $input->getArgument('country'));
            $limit   = (int) ($input->getOption('limit') ?? 5);
            if ($limit <= 0) { $limit = 5; }

            // ⚠️ searchLocationInFedexDto attend 4 paramètres
            $locations = $this->locationService->searchLocationInFedexDto(
                $street,
                $city,
                $postal,
                $country
            );

            // coupe à --limit
            if ($limit > 0) {
                $locations = array_slice($locations, 0, $limit);
            }

            // --json : on sort les DTOs sérialisés
            if ($input->getOption('json')) {
                $payload = array_map(static fn($loc) => [
                    'id'          => $loc->id,
                    'name'        => $loc->name,
                    'street'      => $loc->street,
                    'city'        => $loc->city,
                    'postalCode'  => $loc->postalCode,
                    'countryCode' => $loc->countryCode,
                ], $locations);

                $output->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                return Command::SUCCESS;
            }

            if (empty($locations)) {
                $io->warning('Aucune localisation trouvée.');
                return Command::SUCCESS;
            }

            // Table lisible
            $table = new Table($output);
            $table->setHeaders(['ID', 'Nom', 'Rue', 'Ville', 'CP', 'Pays']);

            foreach ($locations as $loc) {
                $table->addRow([
                    $loc->id,
                    $loc->name,
                    $loc->street,
                    $loc->city,
                    $loc->postalCode,
                    $loc->countryCode,
                ]);
            }

            $io->title('Résultats FedEx');
            $table->render();

            return Command::SUCCESS;

        } catch (Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }

    private function toNullableBool(mixed $val): ?bool
    {
        if (null === $val) {
            return null;
        }
        $v = strtolower((string)$val);

        return 'true' === $v ? true : ('false' === $v ? false : null);
    }
}
