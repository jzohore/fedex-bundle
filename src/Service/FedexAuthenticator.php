<?php

namespace SonnyDev\FedexBundle\Service;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class FedexAuthenticator
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheItemPoolInterface $cache,
        private readonly string $fedexClientId,          // default client_id
        private readonly string $fedexClientSecret,      // default client_secret
        private readonly string $fedexOauthToken,        // token endpoint URL
        // optionnels : identifiants spécifiques par scope
        private readonly ?string $fedexClientShipId = null,
        private readonly ?string $fedexClientShipSecret = null,
        private readonly ?string $fedexClientTrackId = null,
        private readonly ?string $fedexClientTrackSecret = null,
    ) {
    }

    /**
     * Obtenir un access token pour un scope donné.
     * Scopes supportés : 'default' (par défaut), 'ship', 'track'
     *
     * @throws InvalidArgumentException
     * @throws ClientExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */
    public function getAccessToken(string $scope = 'default'): string
    {
        $scope = strtolower($scope);
        if (!in_array($scope, ['default', 'ship', 'track'], true)) {
            throw new \Symfony\Component\Cache\Exception\InvalidArgumentException("Unknown FedEx auth scope: {$scope}");
        }

        $cacheKey = 'fedex_oauth_token_' . $scope;

        $item = $this->cache->getItem($cacheKey);
        if ($item->isHit()) {
            return (string)$item->get();
        }

        // Sélection du couple client_id/client_secret selon le scope
        [$clientId, $clientSecret] = match ($scope) {
            'ship' => [
                $this->fedexClientShipId ?? $this->fedexClientId,
                $this->fedexClientShipSecret ?? $this->fedexClientSecret,
            ],
            'track' => [
                $this->fedexClientTrackId ?? $this->fedexClientId,
                $this->fedexClientTrackSecret ?? $this->fedexClientSecret,
            ],
            default => [$this->fedexClientId, $this->fedexClientSecret],
        };

        $response = $this->httpClient->request('POST', $this->fedexOauthToken, [
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'grant_type' => 'client_credentials',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ],
        ]);

        if (200 !== $response->getStatusCode()) {
            // Affiche le corps pour diagnostiquer (scopes/permissions)
            $body = $response->getContent(false);
            throw new RuntimeException('FedEx Auth error: ' . $response->getStatusCode() . ' — ' . $body);
        }

        $data = $response->toArray(false);

        if (!isset($data['access_token'])) {
            throw new RuntimeException('No access_token in FedEx auth response');
        }

        $token = (string)$data['access_token'];
        $expiresIn = (int)($data['expires_in'] ?? 3600);

        $item->set($token);
        // marge de sécurité pour éviter l’expiration pile à l’appel
        $item->expiresAfter(max(60, $expiresIn - 60));
        $this->cache->save($item);

        return $token;
    }

    // Helpers optionnels si tu préfères des méthodes dédiées :
    public function getAccessTokenForShip(): string
    {
        return $this->getAccessToken('ship');
    }

    public function getAccessTokenForTrack(): string
    {
        return $this->getAccessToken('track');
    }
}
