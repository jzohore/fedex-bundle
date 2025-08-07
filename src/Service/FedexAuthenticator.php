<?php

namespace SonnyDev\FedexBundle\Service;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class FedexAuthenticator
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheItemPoolInterface $cache,
        #[Autowire('%env(FEDEX_CLIENT_ID)%')] private string $clientId,
        #[Autowire('%env(FEDEX_CLIENT_SECRET)%')] private string $clientSecret,
        #[Autowire('%env(FEDEX_CLIENT_AUTH_LINK)%')] private string $authUrl,
    ) {}

    /**
     * @return string
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

        $response = $this->httpClient->request('POST', $this->authUrl, [
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ],
        ]);

        if (200 !== $response->getStatusCode()) {
            throw new \RuntimeException('FedEx API returned error: ' . $response->getStatusCode());
        }

        $data = $response->toArray();

        if (!isset($data['access_token'])) {
            throw new \RuntimeException('No access_token in FedEx response');
        }

        $token = $data['access_token'];
        $expiresIn = $data['expires_in'] ?? 3600;

        $item->set($token);
        $item->expiresAfter($expiresIn - 60); // marge de sécurité
        $this->cache->save($item);

        return $token;
    }
}
