<?php

namespace SonnyDev\FedexBundle\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Helper\Table;
use DateTimeImmutable;

#[AsCommand(name: 'fedex:quote', description: 'Get FedEx shipping quotes (price + delivery estimate)')]
final class FedexQuoteCommand extends Command
{
    public function __construct(private readonly object $rateService) // any service exposing getQuotes(...)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            // Required minimal shipment data
            ->addArgument('from-postal', InputArgument::REQUIRED, 'Origin postal code (e.g. 75001)')
            ->addArgument('from-country', InputArgument::REQUIRED, 'Origin country code (ISO2, e.g. FR)')
            ->addArgument('to-postal', InputArgument::REQUIRED, 'Destination postal code (e.g. 10001)')
            ->addArgument('to-country', InputArgument::REQUIRED, 'Destination country code (ISO2, e.g. US)')

            // Options for addresses
            ->addOption('from-city', null, InputOption::VALUE_REQUIRED, 'Origin city')
            ->addOption('from-state', null, InputOption::VALUE_REQUIRED, 'Origin state/province code')
            ->addOption('to-city', null, InputOption::VALUE_REQUIRED, 'Destination city')
            ->addOption('to-state', null, InputOption::VALUE_REQUIRED, 'Destination state/province code')

            // Package(s)
            ->addOption(
                'pkg',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Add a package with semicolon pairs, e.g.: "weight=2;weightUnit=KG;length=30;width=20;height=10;dimensionUnit=CM". '
                . 'Repeat --pkg for multiple packages. If omitted, you must provide --weight (single package).'
            )
            ->addOption('weight', null, InputOption::VALUE_REQUIRED, 'Shortcut: single package weight (numeric)')
            ->addOption('weight-unit', null, InputOption::VALUE_REQUIRED, 'LB or KG (single package)', 'KG')
            ->addOption('length', null, InputOption::VALUE_REQUIRED, 'Single package length')
            ->addOption('width', null, InputOption::VALUE_REQUIRED, 'Single package width')
            ->addOption('height', null, InputOption::VALUE_REQUIRED, 'Single package height')
            ->addOption('dim-unit', null, InputOption::VALUE_REQUIRED, 'CM or IN (single package)', 'CM')

            // Shipment meta
            ->addOption('ship-date', null, InputOption::VALUE_REQUIRED, 'Ship date (YYYY-MM-DD). Default = today')
            ->addOption('currency', null, InputOption::VALUE_REQUIRED, 'Preferred currency (e.g. EUR, USD)')
            ->addOption('service', null, InputOption::VALUE_REQUIRED, 'Optional FedEx service code to force (e.g. FEDEX_INTERNATIONAL_PRIORITY)')

            // Output
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output raw JSON payload of quotes')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output);

        // --- Build address arrays matching your service signature
        $from = [
            'postalCode' => (string)$input->getArgument('from-postal'),
            'countryCode' => strtoupper((string)$input->getArgument('from-country')),
            'city' => $input->getOption('from-city'),
            'stateOrProvinceCode' => $input->getOption('from-state'),
        ];
        $to = [
            'postalCode' => (string)$input->getArgument('to-postal'),
            'countryCode' => strtoupper((string)$input->getArgument('to-country')),
            'city' => $input->getOption('to-city'),
            'stateOrProvinceCode' => $input->getOption('to-state'),
        ];

        // --- Build packages array
        $packages = $this->buildPackages($input, $io);
        if (empty($packages)) {
            $io->error('You must provide at least one package via --pkg or --weight.');
            return Command::INVALID;
        }

        $shipDate = $input->getOption('ship-date') ? new DateTimeImmutable((string)$input->getOption('ship-date')) : new DateTimeImmutable('today');
        $currency = $input->getOption('currency') ? strtoupper((string)$input->getOption('currency')) : null;
        $serviceCode = $input->getOption('service') ? strtoupper((string)$input->getOption('service')) : null;

        try {
            // Call your existing service
            $quotes = $this->rateService->getQuotes($from, $to, $packages, $shipDate, $currency, $serviceCode);

            if ($input->getOption('json')) {
                $output->writeln(json_encode(array_map(fn($q) => $this->quoteToArray($q), $quotes), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                return Command::SUCCESS;
            }

            if (empty($quotes)) {
                $io->warning('No quotes returned for the given shipment.');
                return Command::SUCCESS;
            }

            $io->title('FedEx Quotes');
            $table = new Table($output);
            $table->setHeaders(['Service code', 'Service name', 'Amount', 'Currency', 'Transit (days)', 'ETA']);
            foreach ($quotes as $q) {
                // Expecting your FedexRateQuote DTO with properties shown in your sample
                $table->addRow([
                    $q->serviceCode,
                    $q->serviceName,
                    number_format($q->amount, 2, '.', ' '),
                    $q->currency,
                    $q->transitDays ?? '—',
                    $q->estimatedDeliveryDate?->format('Y-m-d') ?? '—',
                ]);
            }
            $table->render();

            return Command::SUCCESS;
        } catch (\SonnyDev\FedexBundle\Exception\FedexApiException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        } catch (\Throwable $e) {
            $io->error('Unexpected error: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Build packages array from either repeated --pkg options or single-package shortcuts.
     * Each package structure must match what your service expects:
     *   [ 'weight' => float, 'weightUnit' => 'KG|LB', 'length' => float, 'width' => float, 'height' => float, 'dimensionUnit' => 'CM|IN' ]
     */
    private function buildPackages(InputInterface $input, SymfonyStyle $io): array
    {
        $packages = [];
        $pkgOptions = (array)$input->getOption('pkg');

        // Parse repeated --pkg options
        foreach ($pkgOptions as $raw) {
            $parsed = $this->parseSemicolonPairs((string)$raw);
            if (!isset($parsed['weight'])) {
                $io->warning("Ignoring package without 'weight': $raw");
                continue;
            }
            $packages[] = [
                'weight' => (float)$parsed['weight'],
                'weightUnit' => strtoupper($parsed['weightUnit'] ?? 'KG'),
                'length' => isset($parsed['length']) ? (float)$parsed['length'] : null,
                'width' => isset($parsed['width']) ? (float)$parsed['width'] : null,
                'height' => isset($parsed['height']) ? (float)$parsed['height'] : null,
                'dimensionUnit' => strtoupper($parsed['dimensionUnit'] ?? 'CM'),
            ];
        }

        // Fallback: single package via shortcuts
        if (empty($packages) && $input->getOption('weight') !== null) {
            $packages[] = [
                'weight' => (float)$input->getOption('weight'),
                'weightUnit' => strtoupper((string)$input->getOption('weight-unit')),
                'length' => $input->getOption('length') !== null ? (float)$input->getOption('length') : null,
                'width' => $input->getOption('width') !== null ? (float)$input->getOption('width') : null,
                'height' => $input->getOption('height') !== null ? (float)$input->getOption('height') : null,
                'dimensionUnit' => strtoupper((string)$input->getOption('dim-unit')),
            ];
        }

        return $packages;
    }

    /**
     * Parse a string like "weight=2;weightUnit=KG;length=30;width=20;height=10;dimensionUnit=CM" to an assoc array.
     */
    private function parseSemicolonPairs(string $raw): array
    {
        $out = [];
        foreach (array_filter(array_map('trim', explode(';', $raw))) as $pair) {
            if (!str_contains($pair, '=')) { continue; }
            [$k, $v] = array_map('trim', explode('=', $pair, 2));
            if ($k !== '') { $out[$k] = $v; }
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function quoteToArray(object $q): array
    {
        return [
            'serviceCode' => $q->serviceCode ?? null,
            'serviceName' => $q->serviceName ?? null,
            'amount' => $q->amount ?? null,
            'currency' => $q->currency ?? null,
            'transitDays' => $q->transitDays ?? null,
            'estimatedDeliveryDate' => $q->estimatedDeliveryDate?->format(DATE_ATOM) ?? null,
        ];
    }
}