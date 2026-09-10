# NeuroCheckout Connector — WooCommerce

Dépôt officiel : https://github.com/pisob/neurocheckout-connector-woocommerce

## Statut : sources de développement, pas une release stable

Ce premier dépôt public rend le code consultable. Il ne constitue pas une
certification de sécurité ni une nouvelle version installable en production.
Aucune release signée du connecteur n'est publiée à cette étape.
Ne pas utiliser automatiquement la branche main comme canal de mise à jour.

Les agents, décisions, workers, quotas et envois d'emails restent dans
NeuroCheckout Cloud, dont le code n'est pas inclus ici. Community est
l'interface auto-hébergée, pas un moteur Cloud autonome.

Le pilote de stockage local produits/paniers est **désactivé par défaut**.
Sa validation de bout en bout reste incomplète ; le parcours Cloud existant
reste utilisé. Publier ces sources n'active pas ce pilote.

## Installation et environnement de test

Les sources du plugin sont dans `neurocheckout-connector/`, à placer dans `wp-content/plugins/` sur une boutique de test sauvegardée.

Préférer les futurs paquets officiellement signés pour une installation utilisateur.
Ne jamais désinstaller sans sauvegarder la base et la configuration : les données
locales propres au connecteur et certains liens de récupération peuvent être perdus.
Aucune boutique n'est modifiée par la publication de ce dépôt.

Utiliser uniquement une clé API connecteur émise pour la boutique et
l'environnement sélectionnés ; le Client ID OAuth Community n'est pas cette clé.
Ne jamais committer de clés, données clients, fichiers .env ou exports de base.
Vérifier les consentements et les paramètres de données avant connexion au Cloud.

## Validation

```bash
python3 tools/validate.py
```

Ces contrôles exécutent le lint PHP et des tests isolés avec données synthétiques.
Ils ne remplacent pas les tests d'installation, migration, cron, achat, rotation
de clé et désinstallation sur les versions réelles de WooCommerce.
Les contrôles CI ne disposent d'aucun secret staging ou production.

## Contributions et releases

Les contributions externes ne sont pas encore ouvertes. Voir [CONTRIBUTING.md](CONTRIBUTING.md).
Les releases exigent une validation manuelle, des tests staging, un checksum et
une signature vérifiable ; aucun workflow de publication automatique n'est fourni.
Voir [RELEASING.md](RELEASING.md) et [SECURITY.md](SECURITY.md).

## Licence et marque

Code du connecteur : **GPL-2.0-or-later**, voir [LICENSE](LICENSE).
Les notices tierces sont conservées. Cette licence ne transfère pas les droits
sur la marque NeuroCheckout et ne donne pas accès au code privé du Cloud.
Une copie modifiée ne doit pas être présentée comme une version officielle.

