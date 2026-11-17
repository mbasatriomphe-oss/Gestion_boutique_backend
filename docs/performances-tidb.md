# Réduction des lectures TiDB du point de vente

Les clients web et mobile utilisent les mêmes optimisations :

- `/produits?per_page=all&pos=1` ne renvoie que les champs du catalogue et les unités de vente ; les unités d'achat ne sont pas chargées.
- `/lots?per_page=all&latest_per_product=1` sélectionne le plus grand identifiant de lot par produit, comme le faisait le tri décroissant du client. Le serveur ne charge plus les produits, fournisseurs et approvisionnements de chaque lot pour cet usage. Les routes sans ces options gardent leurs réponses complètes.
- Les appels identiques simultanés partagent une seule requête HTTP. Les réponses réussies sont conservées en mémoire du navigateur : stock 10 secondes, produits et lots 15 secondes, devises et taux 60 secondes. Aucun cache de réponse n'est écrit dans TiDB.
- Les clés distinguent la session, l'URL et la méthode. Connexion, déconnexion et toute écriture vident ce cache. Une réponse ancienne encore en vol ne peut pas le remplir à nouveau. Les erreurs ne sont pas conservées ; un appel avec `cache: 'no-store'` ou `cache: 'reload'` force une lecture réseau.
- L'historique des ventes est demandé seulement à l'ouverture de l'onglet « Mes ventes ».

Une modification faite sur un autre appareil peut donc apparaître avec un délai de 10 secondes pour le stock, 15 secondes pour les prix et 60 secondes pour les taux après la dernière lecture. L'enregistrement d'une vente continue d'utiliser les contrôles de stock et de paiement du serveur, sans ce cache navigateur.

## Vérification

Les tests de cache vérifient la fusion des appels, l'expiration, l'isolation des sessions, les erreurs, les copies indépendantes et l'invalidation pendant une requête. Le test API du catalogue vérifie qu'un historique de plusieurs lots devient un seul lot par produit, avec moins de requêtes SQL et une réponse plus petite, et que les listes d'administration restent complètes.

Les tests locaux ne mesurent pas les RU TiDB. Pour mesurer le gain réel, comparer le même parcours avant/après dans TiDB Cloud, page Diagnosis → SQL Statements, triée par Total RU ou Mean RU. Comparer aussi le nombre d'exécutions et le volume des réponses ; garder un volume de données et une durée d'observation comparables.

Documentation : https://docs.pingcap.com/tidbcloud/serverless-faqs/
