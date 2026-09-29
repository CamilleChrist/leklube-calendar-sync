<?php

declare(strict_types=1);

namespace LeKlube;

use Google\Client;
use Google\Service\Calendar;
use Psr\Log\LoggerInterface;

/**
 * Authentification OAuth pour l'API Google Calendar.
 */
final class GoogleAuth
{
    /** Port du serveur local d'authentification, si credentials.json n'en impose pas un autre. */
    private const int OAUTH_PORT = 8080;

    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getCalendarService(): Calendar
    {
        $client = new Client();
        $client->setAuthConfig($this->config->googleCredentialsFile);
        $client->setScopes([Calendar::CALENDAR]);
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        $token = $this->loadToken();
        if ($token !== null) {
            $client->setAccessToken($token);
        }

        if ($client->isAccessTokenExpired()) {
            if (!$this->refreshToken($client)) {
                $client->setAccessToken($this->authenticate($client));
            }

            $this->saveToken($client->getAccessToken());
        }

        return new Calendar($client);
    }

    /**
     * Renouvelle le token expiré sans passer par le navigateur. Renvoie false si c'est impossible.
     */
    private function refreshToken(Client $client): bool
    {
        $refreshToken = $client->getRefreshToken();
        if ($refreshToken === null) return false;

        $result = $client->fetchAccessTokenWithRefreshToken($refreshToken);
        if (isset($result['error'])) {
            $this->logger->warning('Token Google expiré et impossible à rafraîchir, ré-authentification...');
            return false;
        }

        return true;
    }

    /**
     * Lance le flux OAuth via un navigateur et un serveur local éphémère,
     * équivalent du run_local_server() de google-auth-oauthlib.
     *
     * @return array<string, mixed>
     */
    private function authenticate(Client $client): array
    {
        $this->logger->info('Authentification Google requise (ouverture du navigateur)...');

        $redirectUri = $this->resolveRedirectUri();
        $port = parse_url($redirectUri, PHP_URL_PORT) ?: self::OAUTH_PORT;

        $server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
        if ($server === false) {
            throw new SyncException(
                "Impossible de démarrer le serveur local sur le port $port pour l'authentification : $errstr. "
                . "Vérifie que ce port est libre, ou change l'URI de redirection dans Google Cloud Console."
            );
        }

        $this->logger->debug("URI de redirection utilisée : $redirectUri");
        $client->setRedirectUri($redirectUri);
        $authUrl = $client->createAuthUrl();

        echo "Ouvre cette URL dans ton navigateur pour autoriser l'accès :\n$authUrl\n";
        $this->openBrowser($authUrl);

        $code = $this->waitForAuthorizationCode($server);
        fclose($server);

        $accessToken = $client->fetchAccessTokenWithAuthCode($code);
        if (isset($accessToken['error'])) {
            throw new SyncException("Erreur lors de l'authentification Google : {$accessToken['error']}");
        }

        return $accessToken;
    }

    /**
     * Reprend telle quelle l'URI de redirection locale déclarée dans credentials.json.
     *
     * Google compare cette URI caractère par caractère avec celle enregistrée dans la console :
     * « http://localhost:8080 » et « http://localhost:8080/ » sont deux valeurs différentes, et
     * l'écart se solde par un « Accès bloqué : la demande de cette appli n'est pas valide ».
     * On évite le problème en n'inventant jamais l'URI, mais en relisant celle qui est déclarée.
     */
    private function resolveRedirectUri(): string
    {
        $fallback = 'http://localhost:' . self::OAUTH_PORT . '/';

        $credentials = json_decode(file_get_contents($this->config->googleCredentialsFile), true);
        $declared = ($credentials['web'] ?? $credentials['installed'] ?? [])['redirect_uris'] ?? [];

        if ($declared === []) return $fallback;

        foreach ($declared as $uri) {
            // Seule une URI locale peut recevoir la redirection : le serveur éphémère écoute ici.
            $host = parse_url($uri, PHP_URL_HOST);
            if ($host === 'localhost' || $host === '127.0.0.1') return $uri;
        }

        throw new SyncException(sprintf(
            "Aucune URI de redirection locale dans %s (déclarées : %s). Ajoute « %s » dans "
            . "Google Cloud Console → Credentials → ton client OAuth → Authorized redirect URIs.",
            basename($this->config->googleCredentialsFile),
            implode(', ', $declared),
            $fallback,
        ));
    }

    /**
     * Accepte la redirection OAuth sur le serveur local et en extrait le code d'autorisation.
     *
     * @param resource $server
     */
    private function waitForAuthorizationCode($server): string
    {
        $connection = stream_socket_accept($server, 300);
        if ($connection === false) {
            throw new SyncException('Aucune réponse reçue du navigateur (délai dépassé).');
        }

        $request = fread($connection, 8192);

        fwrite(
            $connection,
            "HTTP/1.1 200 OK\r\nContent-Type: text/html; charset=utf-8\r\n\r\n"
            . '<html><body>Authentification réussie, tu peux fermer cette fenêtre.</body></html>'
        );
        fclose($connection);

        if ($request === false || !preg_match('/[?&]code=([^&\s]+)/', $request, $m)) {
            throw new SyncException("Code d'autorisation introuvable dans la réponse de Google.");
        }

        return urldecode($m[1]);
    }

    private function openBrowser(string $url): void
    {
        $command = match (PHP_OS_FAMILY) {
            'Darwin' => 'open',
            'Windows' => 'start',
            default => 'xdg-open',
        };

        @exec(sprintf('%s %s > /dev/null 2>&1 &', $command, escapeshellarg($url)));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadToken(): ?array
    {
        if (!file_exists($this->config->googleTokenFile)) return null;

        return json_decode(file_get_contents($this->config->googleTokenFile), true);
    }

    /**
     * @param array<string, mixed> $token
     */
    private function saveToken(array $token): void
    {
        file_put_contents($this->config->googleTokenFile, json_encode($token, JSON_PRETTY_PRINT));
    }
}
