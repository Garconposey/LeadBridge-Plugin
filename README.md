# LeadBridge

Plugin WordPress qui orchestre l'envoi de leads issus de **Fluent Forms** vers plusieurs endpoints externes (Webylead Dashboard, Webylead App, Pardot, etc.) depuis une interface d'administration centralisée.

## Fonctionnalités

- **Multi-endpoints** : envoyez chaque soumission de formulaire vers un ou plusieurs destinataires simultanément
- **Mapping de champs** : renommez les slugs Fluent Forms en noms de champs attendus par chaque API
- **File d'attente de retry** : les envois échoués sont remis en file et relancés automatiquement toutes les 15 minutes via WP-Cron
- **Rate limiting** : protection contre les soumissions abusives par IP
- **Logs** : journal JSONL complet consultable depuis l'admin, avec possibilité de relancer ou ignorer chaque entrée manuellement
- **Test d'endpoint** : vérifiez la connectivité d'une URL directement depuis l'interface

## Prérequis

- WordPress 5.9+
- PHP 7.4+
- Plugin [Fluent Forms](https://wordpress.org/plugins/fluentform/) (gratuit ou pro)

## Installation

1. Copier le dossier `leadbridge` dans `/wp-content/plugins/`
2. Activer le plugin depuis **Extensions > Extensions installées**
3. Accéder au menu **LeadBridge** dans l'administration WordPress

## Structure

```
leadbridge/
├── leadbridge.php               # Point d'entrée, constantes, hooks d'activation
└── includes/
    ├── class-leadbridge-config.php  # Lecture/écriture de la configuration
    ├── class-leadbridge-core.php    # Interception Fluent Forms, dispatch, retry queue
    ├── class-leadbridge-sender.php  # Envoi HTTP vers les endpoints
    ├── class-leadbridge-admin.php   # Interface d'administration WordPress
    ├── class-leadbridge-logger.php  # Journalisation JSONL
    └── class-leadbridge-utils.php   # Utilitaires (IP, rate limit…)
```

## Licence

GPL v2 or later — [FOXT SEO](https://foxt-seo.com/)
