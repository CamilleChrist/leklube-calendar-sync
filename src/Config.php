<?php

declare(strict_types=1);

namespace LeKlube;

use Dotenv\Dotenv;

/**
 * Identifiants et chemins de fichiers du projet, chargés depuis l'environnement.
 */
final readonly class Config
{
    public function __construct(
        public ?string $leklubeEmail,
        public ?string $leklubePassword,
        public string $googleCredentialsFile,
        public string $googleTokenFile,
        public string $calendarId,
        public string $colorId,
        public string $colorIdWaitlist,
    ) {}

    public static function fromEnv(string $projectRoot): self
    {
        if (file_exists($projectRoot . '/.env')) {
            Dotenv::createImmutable($projectRoot)->load();
        }

        return new self(
            leklubeEmail: $_ENV['LEKLUBE_EMAIL'] ?? null,
            leklubePassword: $_ENV['LEKLUBE_PASSWORD'] ?? null,
            googleCredentialsFile: $projectRoot . '/credentials.json',
            googleTokenFile: $projectRoot . '/token.json',
            calendarId: $_ENV['GOOGLE_CALENDAR_ID'] ?? 'primary',
            colorId: $_ENV['GOOGLE_CALENDAR_COLOR'] ?? '7',
            colorIdWaitlist: $_ENV['GOOGLE_CALENDAR_COLOR_WAITLIST'] ?? '9',
        );
    }

    /**
     * Vérifie que les credentials Google sont en place.
     */
    public function validateGoogle(): void
    {
        if (!file_exists($this->googleCredentialsFile)) {
            throw new SyncException(
                "Fichier de credentials Google introuvable : {$this->googleCredentialsFile}. "
                . "Télécharge-le depuis Google Cloud Console (voir README)."
            );
        }
    }
}
