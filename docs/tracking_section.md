# 🔍 Tracking

## Exemple d’utilisation (PHP)

```php
use SonnyDev\FedexBundle\Service\FedexTrackingService;

/** @var FedexTrackingService $tracking */
$events = $tracking->trackShipment('123456789012', includeDetailedScans: true, locale: 'fr_FR');

foreach ($events as $event) {
    // $event est une instance de FedexTrackingEvent
    echo sprintf(
        "%s | %s%s%s\n",
        $event->date->format('d/m/Y H:i'),
        $event->city ?? '-',
        ($event->city && $event->country) ? ' / ' : '',
        $event->country ?? '-'
    );
    echo "→ {$event->description}\n";
}
```

## Type de retour : `FedexTrackingEvent`

```php
FedexTrackingEvent {
    string $type;            // Ex: PU, AR, DL, etc.
    string $description;     // Description lisible de l'événement
    \DateTimeImmutable $date; // Date/heure de l'événement (UTC)
    ?string $city;           // Ville si disponible
    ?string $country;        // Pays si disponible
}
```

## Commande CLI (optionnel)

```bash
php bin/console fedex:track 123456789012 --timezone=Europe/Paris --locale=fr_FR
```

Rendu : un tableau avec **Date**, **Ville / Pays**, **Description** (le plus récent en premier).
