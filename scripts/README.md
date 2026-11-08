# Sauvegardes Veille (M12.8)

`backup-veille.sh` — dump quotidien + rotation 7 j dans
`private/backups/veille-auto/` (répertoire dédié ; les sauvegardes
manuelles `backups/veille/*` ne sont JAMAIS supprimées).

> ⚠ M12.8.1 — les ~10 sauvegardes manuelles de `backups/veille/` sont
> une exception temporaire validée : aucune suppression automatique,
> durée indéfinie en attente de réexamen — voir `Backup_Restore.md`.

## Planification ISPConfig (à saisir manuellement)

Cron : `0 4 * * *` (quotidien 04:00)
Commande : `/bin/bash /var/www/clients/client2/web18/private/veille/scripts/backup-veille.sh`

Vérifier après activation : nouvelle entrée dans
`backups/backup.log` chaque jour + purge après 7 j.

## Restauration

```bash
# 1. Identifier la sauvegarde
ls private/backups/veille-auto/
# 2. Vérifier l'intégrité
gunzip -t private/backups/veille-auto/<STAMP>/veille_db.sql.gz
# 3. Maintenance
cd private/veille && php artisan down --retry=30
# 4. Restaurer (destination vide ou nouvelle base — jamais d'écrasement
#    aveugle ; dump = INSERT complets avec DROP TABLE par défaut)
zcat private/backups/veille-auto/<STAMP>/veille_db.sql.gz | \
  mysql -h<host> -u<user> -p veille_db
# 5. Cache routes si routes-v7.php.bak diffère
php artisan route:cache && php artisan up
```
