<?php

namespace SonnyDev\FedexBundle\Command;

use SonnyDev\FedexBundle\DTO\AddressInput;
use SonnyDev\FedexBundle\Service\FedexAddressValidationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
#[AsCommand(name: 'fedex:address:validate', description: 'Valide et normalise des adresses via FedEx')]
final class FedexAddressValidateCommand extends Command
{
    public function __construct(private readonly FedexAddressValidationService $service)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('street', InputArgument::REQUIRED, 'Ligne de rue (ex: 10 Rue de Rivoli)')
            ->addArgument('country', InputArgument::REQUIRED, 'Code pays ISO2 (ex: FR)')
            ->addOption('city', null, InputOption::VALUE_REQUIRED, 'Ville')
            ->addOption('state', null, InputOption::VALUE_REQUIRED, 'État/Province')
            ->addOption('postal', null, InputOption::VALUE_REQUIRED, 'Code postal')
            ->addOption('residential', null, InputOption::VALUE_NONE, 'Adresse résidentielle')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Locale (fr_FR/en_US)', 'fr_FR')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Sortie JSON')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Limiter le nb d’adresses renvoyées (si plusieurs en sortie)', '5')
            ->addOption('raw', null, InputOption::VALUE_NONE, 'Afficher la réponse brute FedEx')

        ;

    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dto = new AddressInput(
            streetLines: [$input->getArgument('street')],
            city: $input->getOption('city'),
            state: $input->getOption('state'),
            postalCode: $input->getOption('postal'),
            countryCode: strtoupper((string)$input->getArgument('country')),
            residential: (bool)$input->getOption('residential'),
        );


        $locale = (string)$input->getOption('locale');
        $limit  = max(1, (int)$input->getOption('limit'));

        $results = $this->service->validate([$dto], $locale);
        if ($input->getOption('raw')) {
            // $resultsDto contient déjà les .raw par item — sinon renvoie directement $data de l’HTTP client
            $output->writeln(json_encode(array_map(fn($r) => $r->raw, $results), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return Command::SUCCESS;
        }
        $results = array_slice($results, 0, $limit);

        if ($input->getOption('json')) {
            $output->writeln(json_encode(array_map(fn($r) => [
                'inputHash'   => $r->inputHash,
                'normalized'  => $r->normalizedAddress,
                'resolved'    => $r->resolved,
                'dpvValid'    => $r->dpvValid,
                'interpolated'=> $r->interpolated,
                'class'       => $r->classification,
                'annotations' => $r->annotations,
            ], $results), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['Résolue', 'DPV', 'Interpolée', 'Classe', 'Rue', 'Ville', 'CP', 'Pays']);

        foreach ($results as $r) {
            $dpv = $r->dpvValid;
            $dpvText = $dpv === null ? 'N/A' : ($dpv ? 'oui' : 'non');

            $addr = $r->normalizedAddress;
            $street = '—';
            if (!empty($addr['streetLines'])) {
                // Heuristique : prendre la ligne qui contient un numéro, sinon la 1ère
                foreach ($addr['streetLines'] as $line) {
                    if (preg_match('/\\d/', $line)) { $street = $line; break; }
                }
                if ($street === '—') {
                    $street = $addr['streetLines'][0];
                }
            }

            $table->addRow([
                $r->resolved ? 'oui' : 'non',
                $dpvText,
                $r->interpolated ? 'oui' : 'non',
                $r->classification ?? '—',
                $street,
                $addr['city'] ?? '—',
                $addr['postalCode'] ?? '—',
                $addr['countryCode'] ?? '—',
            ]);
        }

        $table->render();
        return Command::SUCCESS;
    }
}