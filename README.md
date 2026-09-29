# 🏋️ Le Klube → Google Agenda

Synchronisation automatique de tes **séances de sport collectives Le Klube** vers **Google Calendar**.

Le script se connecte à [app.leklube.com](https://app.leklube.com/), récupère les séances que tu as réservées,
et les tient à jour dans ton agenda : création, mise à jour, et **suppression** des séances que tu as annulées.

Portage de l'approche du projet [myes-calendar-php](../myes-calendar-php), adapté à une application
Laravel + Inertia.js (session par cookie) plutôt qu'à une API JWT.

---

## 🚀 Fonctionnalités

- 🔐 Connexion par session Laravel + jeton CSRF
- 📡 Lecture des séances via le protocole Inertia (`/dashboard`), sans API publique ni scraping HTML
- 📅 Création et mise à jour des événements Google Calendar
- 🗑️ Suppression automatique des événements dont la séance n'est plus réservée
- ⏳ Cours en liste d'attente synchronisés, signalés par un titre et une couleur distincts
- 🚫 Anti-doublons exact via une propriété privée `leklubeId` posée sur chaque événement

> **Lecture seule côté Le Klube.** Ce script ne réserve ni n'annule aucun cours : il ne fait que lire.

---

## 🧠 Comment ça marche

Le Klube n'expose pas d'API publique, mais l'application est un Laravel + Inertia.js : **toute page
renvoie son JSON au lieu du HTML** si on envoie les en-têtes `X-Inertia: true` et
`X-Inertia-Version: <hash>`. Le hash change à chaque déploiement du site ; il est relu automatiquement
dans la page, et une réponse `409` déclenche une relecture puis un nouvel essai.

La synchro se réduit donc à **une seule requête** : le tableau de bord, demandé en JSON au moment de la
connexion — c'est déjà lui qui sert à vérifier que la session est ouverte. Tes réservations sont dans
`props.upcomingReservations`, et nulle part ailleurs. Si Le Klube change la forme de ce payload, la
synchro le dit au lieu de chercher ailleurs ; relance le script avec `--list` pour afficher la liste détectée.

### Ce que renvoie réellement Le Klube

Reconnaissance faite le 19/08/2026 :

| Source | Contenu |
|---|---|
| `dashboard` → `upcomingReservations` | **les séances réservées** — seule source utile |
| `fitness` → `courseCategories` | catalogue des cours (aucune date, aucun statut) |
| `/api/courses` | catalogue également, sans information de réservation |
| `/api/courses/sessions` | exige un paramètre `activity_id`, inutilisable sans cible |

Une réservation a cette forme :

```json
{
  "id": "/leklube/attendees/119807239",
  "classEventId": "/leklube/class_events/26495755",
  "activityId": "/leklube/activities/26219",
  "title": "AQUA WORK",
  "location": "STUDIO AQUA",
  "date": "2026-08-21",
  "startAt": "12:30",
  "durationMinutes": 45,
  "status": "booked",
  "type": "collective-course"
}
```

⚠️ **`startAt` est une heure nue**, à recombiner avec `date` : interprétée seule, elle
désignerait aujourd'hui et la séance passerait pour écoulée. Les identifiants sont des IRI,
raccourcis en `class_events:26495755` pour servir de clé d'agenda.

Seuls les statuts « réservé » (`booked`…) et « liste d'attente » (`on-waiting-list`…) sont retenus ;
une séance annulée, ou au statut inconnu, est ignorée.

Chaque séance retenue devient un événement portant la propriété privée `leklubeId`. À l'exécution suivante,
cette propriété permet de retrouver l'événement à coup sûr — et tout événement **à venir** dont la séance a
disparu ou a été annulée est supprimé de l'agenda. Les séances passées restent, pour garder l'historique.

---

## 📦 Installation

### 1. Prérequis

- PHP >= 8.5
- [Composer](https://getcomposer.org/)

### 2. Installer les dépendances

```bash
composer install
```

### 3. Configuration Le Klube

Copie `.env.example` vers `.env` et renseigne tes identifiants :

```env
LEKLUBE_EMAIL=ton_email
LEKLUBE_PASSWORD=ton_mot_de_passe
```

Le `.env` règle aussi l'agenda cible et les couleurs des événements (voir
[Agenda et couleurs](#4-agenda-et-couleurs)). Le reste est constant dans le code : le fuseau est fixé une fois
par `date_default_timezone_set()` en tête de `bin/leklube-sync.php`, la durée et le lieu de repli sont des
constantes de `src/ReservationExtractor.php`.

---

## 🔐 Configuration Google Calendar API

### 1. Activer l'API

- Va sur [Google Cloud Console](https://console.cloud.google.com/)
- Crée un projet dédié (par exemple `leklube-synch`)
- Active **Google Calendar API** dans la bibliothèque d'API

### 2. Créer des identifiants OAuth

- **API & Services → Credentials**
- **Create Credentials → OAuth Client ID**
- Type d'application : **Web application**
- Dans **Authorized redirect URIs**, ajoute une URI locale, par exemple :
  ```
  http://localhost:8080
  ```

Le script relit cette URI dans `credentials.json` et la réutilise **telle quelle** : avec ou sans
barre oblique finale, sur le port de ton choix, peu importe — c'est ta déclaration qui fait foi, et
le serveur local d'authentification écoute sur le port qu'elle indique.

> Google compare les URI de redirection caractère par caractère. Si le script en fabriquait une de
> son côté, le moindre écart (`…:8080` vs `…:8080/`) provoquerait un
> **« Accès bloqué : la demande de cette appli n'est pas valide »**.

Si Google demande de configurer l'écran de consentement, choisis **External**, renseigne le minimum, puis
ajoute ton adresse Gmail dans **Test users** — sans quoi l'autorisation sera refusée.

### 3. Télécharger les credentials

Renomme le fichier téléchargé en `credentials.json` et place-le à la racine du projet.

Au premier lancement, une URL d'autorisation s'ouvre dans le navigateur. Une fois l'accès accordé, le jeton
est enregistré dans `token.json` et réutilisé (puis rafraîchi automatiquement) ensuite.

### 4. Agenda et couleurs

Trois variables du `.env`, toutes facultatives :

| Variable | Défaut | Rôle |
|---|---|---|
| `GOOGLE_CALENDAR_ID` | `primary` | Agenda cible (`primary` = ton agenda principal) |
| `GOOGLE_CALENDAR_COLOR` | `7` (Paon) | Couleur des séances réservées |
| `GOOGLE_CALENDAR_COLOR_WAITLIST` | `9` (Myrtille) | Couleur des séances en liste d'attente |

Couleurs disponibles : 1 Lavande, 2 Sauge, 3 Raisin, 4 Flamant rose, 5 Banane, 6 Mandarine, 7 Paon,
8 Graphite, 9 Myrtille, 10 Basilic, 11 Tomate. Un changement de couleur est appliqué aux événements
existants à la synchro suivante.

**Agenda dédié (recommandé)** — pour isoler le sport de ton agenda principal : crée un agenda dans
Google Agenda, ouvre ses paramètres, copie son **ID** et reporte-le dans le `.env` :

```env
GOOGLE_CALENDAR_ID=xxxxxxxx@group.calendar.google.com
```

> Si tu changes d'agenda en cours de route, les événements déjà créés restent dans l'ancien : supprime-les
> à la main, le script ne regarde que l'agenda configuré.

---

## ▶️ Utilisation

Vérifier ce que le script détecte, sans toucher à Google :

```bash
php bin/leklube-sync.php --list
```

Simuler la synchronisation (aucune écriture dans l'agenda) :

```bash
php bin/leklube-sync.php --dry-run
```

Synchroniser pour de vrai :

```bash
php bin/leklube-sync.php
```

### Options

| Option | Effet |
|---|---|
| `--list` | Liste les séances détectées, sans contacter Google |
| `--dry-run` | Affiche les créations / mises à jour / suppressions sans les appliquer |
| `--verbose`, `-v` | Journalisation détaillée (messages de niveau DEBUG en plus) |
| `--help`, `-h` | Aide |

### Journaux

Les messages passent par [Monolog](https://github.com/Seldaek/monolog), une ligne par message :

```
2026-09-29 17:03:33 [INFO] leklube_sync: 3 séance(s) réservée(s) à venir
```

Les erreurs sortent sur **stderr**, tout le reste sur **stdout** : dans un cron, `>> sync.log 2>&1`
garde tout dans un même fichier.

### Vérifier que le code marche encore

```bash
php tests/smoke.php
```

Rejoue l'extraction et la conversion en événement sur un jeu de réservations figé, sans réseau.
Utile après avoir touché à `ReservationExtractor` ou `GoogleCalendarSync`.

---

## 🩺 Dépannage

**« Aucune séance réservée détectée »** alors que tu as bien des cours réservés — le format des réponses a
probablement changé. Pour afficher ce que le script a détecté, relance :

```bash
php bin/leklube-sync.php --list
```

**« Connexion à Le Klube échouée »** — vérifie `LEKLUBE_EMAIL` / `LEKLUBE_PASSWORD`. Si tu viens d'enchaîner
plusieurs essais, Laravel peut te limiter temporairement (HTTP 429) : attends quelques minutes.

**« Jeton CSRF introuvable » / « Version Inertia introuvable »** — la structure du site a changé ;
`src/LeKlubeClient.php` doit être mis à jour.

**« Accès bloqué : la demande de cette appli n'est pas valide »** — l'URI de redirection envoyée ne
correspond pas à celle enregistrée dans Google Cloud Console. Vérifie que `credentials.json` est bien
celui du client OAuth que tu utilises, et qu'il contient une URI `localhost`.

---
