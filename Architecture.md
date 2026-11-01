# Architecture technique

> Décisions validées lors de la mission 0 (audit du 2026-11-01).
> Toute remise en cause d'une décision de ce document doit être discutée
> explicitement avant implémentation.

## 1. Vue d'ensemble

Monolithe **Laravel 12** servi par Apache + PHP-FPM 8.3. Une seule
application, un seul déploiement, aucun service supplémentaire.

```
Navigateur
   │ HTTPS (Apache, vhost ISPConfig — ne pas modifier)
   ▼
/web  (docroot : index.php + .htaccess + assets compilés)
   │ proxy_fcgi → socket unix /var/lib/php8.2-fpm/web18.sock (PHP 8.3 FPM)
   ▼
/private/veille  (racine Laravel : app, vendor, storage, .env)
   │
   ├── MariaDB 10.11  (données métier, tables jobs, cache applicatif)
   ├── Sendmail       (notifications)
   └── Cron ISPConfig → `php artisan schedule:run`  (collecte + alertes)
```

## 2. Choix structurants

| Décision | Justification |
|---|---|
| Monolithe Blade, pas de SPA | Le docroot est fixe (`/web`), pas de runtime Node fiable en prod (Node 18 EOL, Passenger non testé). Blade + Alpine couvre le besoin. |
| Sessions Laravel | Auth simple, CSRF natif, zéro friction. |
| Queue + cache `database` | Aucun service à maintenir ; pas d'extension php-redis installée. Redis existe sur localhost mais est partagé sans auth : non retenu. |
| Sessions `file` (défaut Laravel) | `storage/` est hors docroot et inscriptible ; inutile d'ajouter une table. |
| SimplePie | Éprouvé, tolérant aux flux malformés, gère RSS/Atom, `composer` natif. |
| Scheduler + cron unique | Pas de worker persistant possible sans droits root : un seul cron `schedule:run` orchestre collecte, queue et alertes. |
| Sendmail | Postfix local opérationnel, `sendmail_path` déjà configuré dans le pool FPM. |
| Repo = racine Laravel | `/private/veille` contient directement l'application (pas de sous-dossier `app/`). |

## 3. Arborescence cible

```
/var/www/clients/client2/web18/
├── private/veille/          ← CE DÉPÔT = racine Laravel
│   ├── app/                 (Actions, Http, Models, Jobs, Services…)
│   ├── bootstrap/ config/ database/ resources/ routes/
│   ├── storage/             (logs, cache fichier, sessions — hors docroot)
│   ├── vendor/              (hors docroot)
│   ├── public/              (sources des assets + index.php d'origine)
│   ├── .env                 (JAMAIS exposé, JAMAIS commité)
│   └── *.md                 (documentation du projet)
│
└── web/                     ← docroot ISPConfig (seule chose servie)
    ├── index.php            → bootstrap chargeant /private/veille
    ├── .htaccess            → rewrite Laravel + redirection HTTPS
    ├── build/               → symlink vers private/veille/public/build
    ├── storage              → symlink vers private/veille/public/storage
    ├── error/  stats/       (fichiers ISPConfig — ne pas supprimer)
    └── robots.txt  favicon.ico
```

`open_basedir` du pool FPM inclut déjà `/private` et `/web` : aucun
changement de configuration n'est requis pour cette topologie.

## 4. Organisation du code Laravel

```
app/
├── Actions/            # Cas d'usage unitaires (CreateVeille, FetchSource…)
├── Services/           # Services de domaine (FeedFetcher, KeywordMatcher)
├── Jobs/               # Jobs de queue (FetchFeedJob, SendAlertJob…)
├── Models/             # Eloquent
├── Http/
│   ├── Controllers/    # Minces : validation → Action → réponse
│   ├── Requests/       # FormRequests pour toute entrée utilisateur
│   └── Middleware/
├── Notifications/      # Alertes e-mail (Laravel Notifications)
└── Enums/              # AlertFrequency, SourceStatus…
```

Règles :

- Les contrôleurs ne contiennent **pas** de logique métier → Actions/Services.
- Toute entrée utilisateur passe par un **FormRequest**.
- Politiques d'accès (`Policy`) systématiques sur les objets utilisateur
  (veille, source perso, favori, note, catégorie).

## 5. Modèle de données (proposition MVP)

```
users                    id, name, email, password, …
categories               id, user_id→FK, name
sources                  id, name, feed_url (unique), site_url,
                         added_by→users (null = catalogue), is_active,
                         etag, last_modified, fetched_at, fail_count
items                    id, source_id→FK, guid, title, url, author,
                         published_at, summary, content_hash
                         UNIQUE(source_id, guid)
veilles                  id, user_id→FK, category_id→FK (null), name,
                         alert_frequency (enum : none|hourly|daily|weekly),
                         last_alert_at
veille_keywords          id, veille_id→FK, term
source_veille            source_id, veille_id         (pivot)
item_veille              item_id, veille_id           (matching)
item_user                item_id, user_id, is_read, is_favorite, note,
                         UNIQUE(item_id, user_id)
jobs / failed_jobs / job_batches / cache   (tables framework)
notifications            (table Laravel Notifications)
```

Principes :

- `sources` et `items` sont **mutualisées** : un flux n'est téléchargé qu'une
  fois, quel que soit le nombre d'abonnés.
- Une source personnelle (`added_by` non null) n'est visible que de son
  propriétaire ; une source du catalogue (`added_by` null) est proposée à tous.
- Le matching mot-clé produit `item_veille` ; l'état de lecture/favori/note
  est porté par `item_user` → individualisation totale.
- Rétention articles : politique de purge à définir en M4 (ex. 6 mois ou
  N derniers par source) pour borner la table `items`.

## 6. Flux de collecte

```
cron * * * * *  →  php artisan schedule:run
                     ├── everyMinute: queue:work --stop-when-empty --max-time=50
                     ├── everyFiveMinutes: feeds:fetch
                     │       └─ dispatch FetchFeedJob(source) pour chaque flux dû
                     └── alertes selon alert_frequency des veilles
```

`FetchFeedJob` (queue `database`) :

1. Requête conditionnelle (`ETag` / `Last-Modified`) → 304 = rien à faire.
2. Parse SimplePie → upsert `items` (dédoublonnage `source_id+guid`,
   fallback `content_hash` si guid absent).
3. Pour chaque nouvel item : `KeywordMatcher` → lignes `item_veille`.
4. Backoff sur échec (`fail_count`, désactivation au-delà d'un seuil).

`KeywordMatcher` (MVP) : correspondance insensible à la casse sur
`titre + résumé`, OU logique entre mots-clés d'une même veille.
Opérateurs ET/exclusion : post-MVP.

## 7. Alertes e-mail

- Champ `veilles.alert_frequency` : `none | hourly | daily | weekly`.
- Le scheduler sélectionne les veilles dues → `SendAlertJob` →
  Notification Laravel (driver `sendmail`), e-mail listant les nouveaux
  articles depuis `last_alert_at`.
- Expéditeur : `noreply@veille.gaelmorvan.fr` (délivrabilité SPF/DKIM
  **à vérifier** lors de M5).

## 8. Sécurité

| Domaine | Règle |
|---|---|
| Exposition | Seul `/web` est servi. `.env`, `vendor/`, `storage/` sont hors docroot (open_basedir les autorise quand même en lecture PHP). |
| Secrets | Jamais dans Git. `APP_KEY` généré côté serveur dans `.env`. |
| Transport | HTTPS forcé par `.htaccess` (301). HSTS envisageable plus tard. |
| Cookies | `SESSION_SECURE_COOKIE=true`, HttpOnly + SameSite=Lax (défauts Laravel). |
| Entrées | FormRequests partout ; échappement Blade `{{ }}` par défaut ; HTML des articles assaini (`mews/purifier` ou équivalent) avant affichage. |
| SSRF | Les URLs de flux saisies par les utilisateurs sont un vecteur SSRF : n'accepter que `http(s)`, résoudre le DNS et rejeter les IP privées/réservées (à implémenter en M2). |
| Rate limiting | Throttling natif Breeze sur login ; limitation de la fréquence d'ajout de sources/veilles. |
| Droits fichiers | Propriétaire `web18` partout ; `storage/` et `bootstrap/cache/` inscriptibles ; pas de chmod 777. |
| Dépendances | Versions épinglées (`composer.lock`, `package-lock.json` commités), audits `composer audit` / `npm audit` dans le workflow. |

## 9. Performances (cibles MVP)

- Pool FPM `ondemand`, `max_children=10` : suffisant ; à surveiller.
- Index DB : `items(source_id, published_at)`, `item_veille(veille_id, item_id)`,
  `item_user(user_id, is_read)`, `veilles(user_id)`.
- OPcache actif côté FPM ; `config:cache`, `route:cache`, `view:cache` au
  déploiement.
- Pagination systématique des listes d'articles ; eager loading sur les
  relations utilisées en boucle.

## 10. Points vérifiés vs à vérifier

**Vérifié (audit)** : PHP 8.3 FPM + modules Laravel complets ; `AllowOverride
All` + `SymlinksIfOwnerMatch` ; certificat Let's Encrypt actif ; MariaDB en
service ; Postfix/sendmail opérationnels ; accès sortant HTTPS ; Redis/
Memcached présents mais non retenus ; pool FPM `web18` en `ondemand`.

**À vérifier** (droits insuffisants ou pas encore fait) :

- Création de la base + utilisateur MariaDB (panneau ISPConfig).
- Création du cron `schedule:run` (panneau ISPConfig).
- Délivrabilité e-mail : enregistrements SPF/DKIM/DMARC du domaine.
- Politique de sauvegarde réelle (répertoire `backup/` géré par ISPConfig).
- Version affichée dans le panneau ISPConfig pour le site.
