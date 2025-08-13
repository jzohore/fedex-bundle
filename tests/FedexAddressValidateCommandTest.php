<?php

namespace SonnyDev\FedexBundle\Tests;

use PHPUnit\Framework\TestCase;
use SonnyDev\FedexBundle\Command\FedexAddressValidateCommand;
use SonnyDev\FedexBundle\DTO\AddressValidationResult;
use SonnyDev\FedexBundle\Service\FedexAddressValidationService;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class FedexAddressValidateCommandTest extends TestCase
{
    private function makeDto(
        bool $resolved,
        ?bool $dpv,
        bool $interpolated,
        ?string $class,
        array $normalized,
        array $raw = []
    ): AddressValidationResult {
        return new AddressValidationResult(
            inputHash: 'hash123',
            normalizedAddress: $normalized,
            resolved: $resolved,
            dpvValid: $dpv,              // 👈 tri-état
            interpolated: $interpolated,
            classification: $class,
            annotations: [],
            raw: $raw
        );
    }

    public function test_table_output_fr_mapping_and_dpv_na(): void
    {
        // Mock service
        $service = $this->getMockBuilder(FedexAddressValidationService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['validate'])
            ->getMock();

        // Cas FR « à plat » : on veut voir Rue/Ville/CP/Pays remplis et DPV = N/A
        $dto = $this->makeDto(
            resolved: true,
            dpv: null, // pas fourni en FR
            interpolated: false,
            class: 'UNKNOWN',
            normalized: [
                'streetLines' => ['AGENCE ST PAUL', '10 RUE DE RIVOLI'],
                'city'        => 'PARIS',
                'state'       => 'PARIS',
                'postalCode'  => '75004',
                'countryCode' => 'FR',
            ],
            raw: [
                'streetLinesToken' => ['AGENCE ST PAUL', '10 RUE DE RIVOLI'],
                'city'             => 'PARIS',
                'stateOrProvinceCode' => 'PARIS',
                'postalCode'       => '75004',
                'countryCode'      => 'FR',
                'classification'   => 'UNKNOWN',
                'normalizedStatusNameDPV' => false,
                'attributes' => [
                    'Matched' => 'true',
                    'InterpolatedStreetAddress' => 'false',
                ],
            ]
        );

        $service->method('validate')->willReturn([$dto]);

        $app = new Application();
        $app->add(new FedexAddressValidateCommand($service));
        $tester = new CommandTester($app->find('fedex:address:validate'));

        $tester->execute([
            'street'  => '10 Rue de Rivoli',
            'country' => 'FR',
            '--city'  => 'Paris',
            '--postal'=> '75001',
        ]);

        $out = $tester->getDisplay();

        // En-têtes
        $this->assertStringContainsString('Résolue', $out);
        $this->assertStringContainsString('DPV', $out);
        $this->assertStringContainsString('Interpolée', $out);
        $this->assertStringContainsString('Classe', $out);
        $this->assertStringContainsString('Rue', $out);
        $this->assertStringContainsString('Ville', $out);
        $this->assertStringContainsString('CP', $out);
        $this->assertStringContainsString('Pays', $out);

        // Valeurs attendues
        $this->assertStringContainsString('oui', $out);         // Résolue
        $this->assertStringContainsString('N/A', $out);         // DPV tri-état
        $this->assertStringContainsString('non', $out);         // Interpolée
        $this->assertStringContainsString('UNKNOWN', $out);
        $this->assertStringContainsString('PARIS', $out);
        $this->assertStringContainsString('75004', $out);
        $this->assertStringContainsString('FR', $out);
    }

    public function test_json_output_contains_normalized_fields(): void
    {
        $service = $this->getMockBuilder(FedexAddressValidationService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['validate'])
            ->getMock();

        $dto = $this->makeDto(
            resolved: true,
            dpv: null,
            interpolated: false,
            class: 'business',
            normalized: [
                'streetLines' => ['350 5TH AVE'],
                'city'        => 'NEW YORK',
                'state'       => 'NY',
                'postalCode'  => '10118',
                'countryCode' => 'US',
            ]
        );

        $service->method('validate')->willReturn([$dto]);

        $app = new Application();
        $app->add(new FedexAddressValidateCommand($service));
        $tester = new CommandTester($app->find('fedex:address:validate'));

        $tester->execute([
            'street'  => '350 5th Ave',
            'country' => 'US',
            '--city'  => 'New York',
            '--state' => 'NY',
            '--postal'=> '10118',
            '--json'  => true,
        ]);

        $json = $tester->getDisplay();
        $this->assertStringContainsString('"normalized"', $json);
        $this->assertStringContainsString('"streetLines"', $json);
        $this->assertStringContainsString('"city": "NEW YORK"', $json);
        $this->assertStringContainsString('"postalCode": "10118"', $json);
        $this->assertStringContainsString('"countryCode": "US"', $json);
        $this->assertStringContainsString('"resolved": true', $json);
    }

    public function test_raw_option_outputs_raw_array(): void
    {
        $service = $this->getMockBuilder(FedexAddressValidationService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['validate'])
            ->getMock();

        $dto = $this->makeDto(
            resolved: false,
            dpv: null,
            interpolated: true,
            class: 'UNKNOWN',
            normalized: [
                'streetLines' => ['10 RUE RIV'],
                'city'        => 'PARIS',
                'state'       => 'PARIS',
                'postalCode'  => '07500',
                'countryCode' => 'FR',
            ],
            raw: ['some' => 'raw', 'attributes' => ['InterpolatedStreetAddress' => 'true']]
        );

        $service->method('validate')->willReturn([$dto]);

        $app = new Application();
        $app->add(new FedexAddressValidateCommand($service));
        $tester = new CommandTester($app->find('fedex:address:validate'));

        $tester->execute([
            'street'  => '10 Rue Riv',
            'country' => 'FR',
            '--city'  => 'Paris',
            '--postal'=> '7500',
            '--raw'   => true,
        ]);

        $out = $tester->getDisplay();
        $this->assertStringContainsString('"some": "raw"', $out);
        $this->assertStringContainsString('"InterpolatedStreetAddress": "true"', $out);
    }

    public function test_no_results_outputs_empty_table_headers(): void
    {
        $service = $this->getMockBuilder(FedexAddressValidationService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['validate'])
            ->getMock();

        $service->method('validate')->willReturn([]); // aucun résultat

        $app = new Application();
        $app->add(new FedexAddressValidateCommand($service));
        $tester = new CommandTester($app->find('fedex:address:validate'));

        $tester->execute([
            'street'  => '1 Nowhere',
            'country' => 'FR',
        ]);

        $out = $tester->getDisplay();
        // On vérifie que la commande ne crash pas et affiche au moins les headers
        $this->assertStringContainsString('Résolue', $out);
        $this->assertStringContainsString('DPV', $out);
        $this->assertStringContainsString('Classe', $out);
    }
}