# Production Configuration — Veille

> Configuration cible pour la mise en production.
> **Non appliquée** — préparée par l'audit M6.6 ; activation soumise
> à la décision de Gaël. `APP_KEY` existante : **préserver
> impérativement**, jamais régénérée.

## 1. Inventaire des variables réellement utilisées

Variables `env()` effectivement lues par `config/` dans ce projet
(uniquement celles du `.env` actuel + celles ayant un sens pour ce
serveur — les autres ont des défauts corrects) :

| Variable | `.env` actuel | Cible prod | Commentaire |
|---|---|---|---|
| `APP_NAME` | `Veille` | `Veille` | inchangé |
| `APP_ENV` | `local` | **`production`** | bloque tinker, debug |
| `APP_DEBUG` | `true` | **`false`** | **obligatoire** — stack traces |
| `APP_URL` | `https://veille.gaelmorvan.fr` | idem | déjà correct |
| `APP_KEY` | présente | **inchangée** | régénération = sessions invalidées |
| `APP_LOCALE` | `fr` | `fr` | inchangé |
| `APP_TIMEZONE` | `Europe/Paris` | inchangé | **inerte** — `config/app.php` fige `UTC` ; fuseau logique métier uniquement |
| `LOG_CHANNEL` | `stack` | `stack` | `storage/logs/laravel.log` |
| `LOG_LEVEL` | `debug` | **`info`** | évite les logs verbeux en prod |
| `DB_CONNECTION` | `mariadb` | `mariadb` | — |
| `DB_TIMEZONE` | absent | **`+00:00` explicite** | défaut `+00:00` déjà pris dans `config/database.php` ; expliciter = bonne pratique |
| `SESSION_DRIVER` | `file` | `file` | cohérent, aucune raison de changer |
| `SESSION_LIFETIME` | `120` | `120` | — |
| `SESSION_SECURE_COOKIE` | `true` | `true` | HTTPS obligatoire |
| `SESSION_DOMAIN` | `null` | `null` | domaine seul |
| `CACHE_STORE` | `database` | `database` | table `cache` (framework, Ran) |
| `QUEUE_CONNECTION` | `database` | `database` | table `jobs` — pas de Redis |
| `FILESYSTEM_DISK` | `local` | `local` | pas de stockage public actif |
| `MAIL_MAILER` | `log` | **`sendmail`** | `/usr/sbin/sendmail` Postfix confirmé |
| `MAIL_SENDMAIL_PATH` | absent | **`/usr/sbin/sendmail -bs`** explicite | défaut Laravel identique ; expliciter évite tout doute |
| `MAIL_FROM_ADDRESS` | `noreply@veille.gaelmorvan.fr` | idem | à vérifier en délivrabilité |
| `MAIL_FROM_NAME` | `${APP_NAME}` | idem | — |
| `BROADCAST_CONNECTION` | `log` | `log` | pas de temps réel |

**Non utilisées / à ne pas ajouter** : Redis, AWS, SMTP auth,
beanstalkd, SQS, Postmark, Slack — défauts corrects, pas de
config correspondante dans le code.

## 2. Cohérence config/*.php vérifiée

- `config/app.php` : `timezone => 'UTC'` hardcodé — **conforme** à la
  convention UTC structurelle (DataModel §3) ; `APP_TIMEZONE` inerte.
- `config/database.php` : `mariadb` + `'timezone' => env('DB_TIMEZONE',
  '+00:00')` — `set time_zone='+00:00'` à chaque connexion ✓.
- `config/mail.php` : `sendmail` driver présent, chemin via
  `MAIL_SENDMAIL_PATH` (défaut `/usr/sbin/sendmail -bs`).
- `config/session.php` : `file` + `secure` — compatible FPM web18.
- `config/cache.php` : `database` → table `cache` (framework, Ran).
- `config/queue.php` : `database` → `jobs` — drainée par `queue:work
  --stop-when-empty` via scheduler (cron ISPConfig existant).
- `config/logging.php` : `stack`/`single` → `storage/logs`.

**Divergence `.env.example`** : `SESSION_DRIVER=database` vs `.env`
réel `file` — les deux fonctionnent ; `file` est l'état actuel et
correct pour cette taille (à harmoniser dans `.env.example` lors d'un
commit doc ultérieur si souhaité — non bloquant).

## 3. Sécurité — vérifiée sans modification

| Point | Constaté | Statut |
|---|---|---|
| `.env` | mode `600`, `web18:client2` | ✅ |
| `.env` versionné ? | non — dans `.gitignore`, jamais commité | ✅ |
| Secrets en fichiers versionnés | `.env.example` (`APP_KEY=` vide), docs = placeholders | ✅ |
| `APP_DEBUG` | `true` actuel → `false` à la bascule | ⚠ |
| `.env`/`storage/`/`vendor/` exposés HTTP | N/A — `/web` non exposé encore | à vérifier au déploiement |
| `storage/`, `bootstrap/cache` | 755 `web18` — écrivables par FPM | ✅ |

## 4. Bascule vers la production (après accord Gaël)

```bash
cp -p .env .env.pre-prod                     # retour arrière
# éditer .env :
APP_ENV=production
APP_DEBUG=false
APP_URL=https://veille.gaelmorvan.fr
LOG_LEVEL=info
DB_TIMEZONE=+00:00
MAIL_MAILER=sendmail
MAIL_SENDMAIL_PATH="/usr/sbin/sendmail -bs"
php artisan config:cache route:cache view:cache
php artisan migrate --force                  # après autorisation
```

**Vérifications** : `php artisan config:show app.env` → `production` ;
`curl -Is https://veille.gaelmorvan.fr` → 200/302 ; `php artisan
schedule:list` → 5 tâches ; `tail storage/logs/laravel.log` sans
erreur ; premier `alerts:generate` manuel + digest de contrôle.

## 5. Retour arrière

```bash
cp -p .env.pre-prod .env && php artisan config:cache
```

Réversible intégralement (`.env` + caches) — `APP_KEY` jamais touchée.

## 6. Autorisations explicites requises de Gaël

1. Modification de `.env` (bascule des 6 valeurs).
2. `migrate --force` sur `veille_db`.
3. Activation réelle `sendmail` + premier envoi de test.
4. Choix `LOG_LEVEL` et éventuelle rotation des logs.
5. Vérification SPF/DKIM DNS (manuelle, hors applicatif).
