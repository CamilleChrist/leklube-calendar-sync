<?php

declare(strict_types=1);

namespace LeKlube;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Client pour l'application Le Klube (Laravel + Inertia.js).
 *
 * L'authentification se fait par cookie de session et jeton CSRF. Une fois connecté, n'importe
 * quelle page renvoie ses données en JSON si on envoie les en-têtes X-Inertia.
 */
final class LeKlubeClient
{
    private const string BASE_URL = 'https://app.leklube.com';

    private readonly Client $http;
    private readonly CookieJar $cookies;

    private string $inertiaVersion = '';

    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {
        $this->cookies = new CookieJar();
        $this->http = new Client([
            'base_uri' => self::BASE_URL . '/',
            'cookies' => $this->cookies,
            'timeout' => 20,
            'http_errors' => false,
            'headers' => [
                // Application uniquement disponible sur mobile
                'User-Agent' => 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 '
                    . '(KHTML, like Gecko) Chrome/151.0.0.0 Mobile Safari/537.36',
                'Accept-Language' => 'fr-FR,fr;q=0.9',
            ],
        ]);
    }

    /**
     * Authentifie l'utilisateur et renvoie les props du tableau de bord.
     *
     * Cette page est de toute façon lue ici : c'est la seule preuve fiable que la session est
     * ouverte, et elle porte déjà les réservations. La relire ensuite serait une requête pour rien.
     *
     * @return array<string, mixed>
     */
    public function loginAndFetchDashboard(): array
    {
        $this->logger->info('Connexion à Le Klube...');

        $loginPage = $this->fetchHtml('/login');
        $this->inertiaVersion = $this->extractInertiaVersion($loginPage);

        $response = $this->request('POST', '/login', [
            'form_params' => [
                '_token' => $this->extractCsrfToken($loginPage),
                'email' => $this->config->leklubeEmail,
                'password' => $this->config->leklubePassword,
                'remember' => 'on',
            ],
            'headers' => ['X-XSRF-TOKEN' => $this->xsrfCookie()],
            'allow_redirects' => ['max' => 5, 'referer' => true],
        ]);

        if ($response->getStatusCode() === 429) {
            throw new SyncException(
                'Trop de tentatives de connexion (429). Le Klube limite les essais : attends quelques '
                . 'minutes avant de relancer.'
            );
        }

        $props = $this->fetchInertiaPage('/dashboard')['props'] ?? [];
        $user = $props['auth']['user'] ?? null;

        if ($user === null) {
            throw new SyncException(
                "Connexion à Le Klube échouée : identifiants refusés ou formulaire modifié. "
                . "Vérifie LEKLUBE_EMAIL / LEKLUBE_PASSWORD dans .env."
            );
        }

        $this->logger->info("Connexion réussie ({$user['email']})");

        return $props;
    }

    /**
     * Récupère une page au format Inertia (JSON) plutôt qu'en HTML.
     *
     * @return array<string, mixed>
     */
    private function fetchInertiaPage(string $path): array
    {
        $response = $this->inertiaRequest($path);

        // 409 = la version des assets a changé depuis notre lecture : on la relit et on réessaie.
        if ($response->getStatusCode() === 409) {
            $this->logger->debug('Version Inertia obsolète, rechargement...');
            $this->inertiaVersion = $this->extractInertiaVersion($this->fetchHtml('/login'));
            $response = $this->inertiaRequest($path);
        }

        $body = (string) $response->getBody();

        // Réponse HTML au lieu de JSON (redirection vers /login par exemple) : on lit le payload embarqué.
        $page = json_decode($body, true) ?? $this->extractInertiaPayload($body);

        if ($page === null) {
            throw new SyncException("Réponse Inertia inattendue pour $path (JSON illisible).");
        }

        return $page;
    }

    private function inertiaRequest(string $path): ResponseInterface
    {
        return $this->request('GET', $path, [
            'headers' => [
                'X-Inertia' => 'true',
                'X-Inertia-Version' => $this->inertiaVersion,
                'X-Requested-With' => 'XMLHttpRequest',
                'Accept' => 'text/html, application/xhtml+xml',
            ],
        ]);
    }

    private function fetchHtml(string $path): string
    {
        return (string) $this->request('GET', $path)->getBody();
    }

    /**
     * @param array<string, mixed> $options
     */
    private function request(string $method, string $uri, array $options = []): ResponseInterface
    {
        try {
            return $this->http->request($method, ltrim($uri, '/'), $options);
        } catch (GuzzleException $exc) {
            throw new SyncException(
                "Impossible de contacter Le Klube ($method $uri) : {$exc->getMessage()}",
                previous: $exc,
            );
        }
    }

    /**
     * Valeur du cookie XSRF-TOKEN, décodée comme le fait le client JavaScript.
     */
    private function xsrfCookie(): string
    {
        $cookie = $this->cookies->getCookieByName('XSRF-TOKEN');

        return urldecode($cookie?->getValue() ?? '');
    }

    private function extractCsrfToken(string $html): string
    {
        if (!preg_match('/<meta name="csrf-token" content="([^"]+)"/', $html, $m)) {
            throw new SyncException(
                "Jeton CSRF introuvable sur la page de connexion : la structure du site a probablement changé."
            );
        }

        return $m[1];
    }

    private function extractInertiaVersion(string $html): string
    {
        $version = $this->extractInertiaPayload($html)['version'] ?? '';

        if ($version === '') {
            throw new SyncException("Version Inertia introuvable dans la page : structure du site modifiée.");
        }

        return $version;
    }

    /**
     * Extrait le payload Inertia embarqué dans le HTML, dans une balise <script type="application/json">.
     *
     * @return array<string, mixed>|null
     */
    private function extractInertiaPayload(string $html): ?array
    {
        if (!preg_match('/<script[^>]*data-page[^>]*type="application\/json"[^>]*>(.*?)<\/script>/s', $html, $m)) {
            return null;
        }

        return json_decode($m[1], true);
    }
}
