# LeadBridge Plugin – Notes de projet

## Architecture
Plugin WordPress complet dans `C:\Users\leadbridge\`
- `leadbridge.php` – Point d'entrée, constantes, activation/désactivation, cron schedule
- `includes/class-leadbridge-config.php` – CRUD config en DB (LEADBRIDGE_OPTION), 6 templates sites
- `includes/class-leadbridge-logger.php` – JSONL dans `uploads/leadbridge-logs/leadbridge.log`
- `includes/class-leadbridge-utils.php` – IGN API, rate limiting, sanitization
- `includes/class-leadbridge-sender.php` – HTTP via wp_remote_post
- `includes/class-leadbridge-core.php` – Hook Fluent Forms, orchestration, retry WP-Cron
- `includes/class-leadbridge-admin.php` – UI admin complète (4 pages)
- `assets/css/admin.css` + `assets/js/admin.js`

## Données
- Config stockée : `leadbridge_config` (WP option)
- File retry : `leadbridge_queue` (WP option)
- Compteur erreurs : `leadbridge_failure_count` (WP option)
- Log : `wp-content/uploads/leadbridge-logs/leadbridge.log` (JSONL)

## 3 types d'endpoint
- `dashboard` : slug → label (mapping configurable) + champs fixes
- `bridge` : slugs bruts + champs fixes (formulaire, domaine)
- `culligan` : payload Pardot spécial (lastname = prenom+nom, city via IGN, question générée)

## User preferences
- Templates + config libre (6 presets disponibles)
- On failure : log + retry WP-Cron (3 max, 15min) + email + badge dashboard
- Langue française pour l'admin
