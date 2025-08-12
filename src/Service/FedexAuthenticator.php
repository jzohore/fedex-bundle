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
        private readonly string $fedexClientId,
        private readonly string $fedexClientSecret,
        private readonly string $fedexOauthToken,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     * @throws ClientExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */
    public function getAccessToken(): string
    {
        $cacheKey = 'fedex_oauth_token';

        $item = $this->cache->getItem($cacheKey);
        if ($item->isHit()) {
            return $item->get();
        }

        $response = $this->httpClient->request('POST', $this->fedexOauthToken, [
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'grant_type' => 'client_credentials',
                'client_id' => $this->fedexClientId,
                'client_secret' => $this->fedexClientSecret,
            ],
        ]);

        if (200 !== $response->getStatusCode()) {
            throw new RuntimeException('FedEx API returned error: ' . $response->getStatusCode());
        }

        $data = $response->toArray();

        if (!isset($data['access_token'])) {
            throw new RuntimeException('No access_token in FedEx response');
        }

        $token = $data['access_token'];
        $expiresIn = $data['expires_in'] ?? 3600;

        $item->set($token);
        $item->expiresAfter($expiresIn - 60); // marge de sécurité
        $this->cache->save($item);

        return $token;
    }
}
