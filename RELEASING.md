# Publication contrôlée

Aucune release installable n'est créée par ce premier import de sources.
La création des tags est bloquée tant que le processus de signature n'est pas prêt.

Avant une première release :
1. Revue du commit exact, des licences, dépendances, fichiers inclus et données.
2. CI réussie et tests réels en staging : installation, upgrade, cron, restauration,
   commande convertie, rotation de clé et désinstallation sur les versions annoncées.
3. Archive reproductible issue d'une liste d'inclusion, sans tests, données ni secrets.
4. SHA-256 et signature par une clé mainteneur dédiée, privée et conservée hors Git.
5. Publication de la clé publique et de son empreinte par un canal officiel.
6. Vérification indépendante du paquet et documentation de retour à la version précédente.
7. Ouverture contrôlée de la création de tags au mainteneur autorisé, puis publication
   manuelle. Ne jamais publier un tag ou paquet depuis une contribution externe.

Le code Cloud, les accès de déploiement et les clés de signature ne doivent jamais
être placés dans ce dépôt ni exposés aux tests de contributions.

