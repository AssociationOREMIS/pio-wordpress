# Extension WordPress OREMIS

L'extension qui fait fonctionner le site de l'association [OREMIS](https://oremis.fr), engagée contre le harcèlement scolaire et aux côtés des élèves en situation de handicap.

Elle relie le site aux outils de l'association : l'annuaire des bénévoles, le compte OREMIS et le chat d'écoute. Elle publie aussi les documents de gouvernance (procès-verbaux, séances à venir).

## Fonctionnalités

- **Équipes de l'association** : les membres d'une équipe, avec leur rôle et leur photo, toujours à jour depuis l'annuaire des bénévoles.
- **Gouvernance** : les procès-verbaux du conseil d'administration et de l'assemblée générale, et les prochaines séances avec leur ordre du jour, à télécharger.
- **Connexion des bénévoles** : avec leur compte OREMIS ou leur compte Google `@oremis.fr`.
- **Chat d'écoute OREMIS** : la bulle de discussion avec un bénévole, sur toutes les pages ou seulement certaines.
- **Mises à jour automatiques** : les nouvelles versions apparaissent dans le tableau de bord WordPress, comme pour n'importe quelle extension.

L'accessibilité compte : contrastes suffisants, navigation au clavier, liens qui annoncent l'ouverture d'un nouvel onglet, titres réglables pour respecter la structure des pages.

## Installation

1. Téléchargez `pio-oremis.zip` depuis la [dernière version publiée](https://github.com/AssociationOREMIS/pio-wordpress/releases/latest).
2. Dans WordPress : **Extensions > Ajouter > Téléverser une extension**, puis activez **PIO OREMIS API**.
3. Les réglages se trouvent dans le menu **OREMIS** (réservé aux administrateurs).

Les versions suivantes se proposent ensuite d'elles-mêmes dans **Tableau de bord > Mises à jour**.

Prérequis : WordPress 5.8 ou plus récent, PHP 7.4 ou plus récent.

## Utilisation

Les contenus s'ajoutent dans une page avec des codes courts (shortcodes).

### Membres d'une équipe

```
[pio_team code="CA-OFFICIER" columns="2" show_email="1"]
```

| Option | Rôle |
| --- | --- |
| `code` | Code de l'équipe dans l'annuaire. |
| `layout` | `cards` (cartes, par défaut) ou `list`. |
| `columns` | Nombre de colonnes, de 1 à 6. |
| `limit` | Nombre maximum de membres affichés. |
| `show_email` | `1` pour afficher l'adresse de contact. |
| `show_cib` | `1` pour afficher l'identifiant bénévole. |
| `class` | Classe CSS supplémentaire. |

La liste est mise en cache. Si l'annuaire est momentanément injoignable, les derniers membres connus restent affichés.

### Procès-verbaux et séances à venir

```
[pv_ca_list]
[pv_ag_list posts_per_page="20"]
[upcoming_ca_list]
[upcoming_ag_list show_pagination="0"]
```

| Option | Rôle |
| --- | --- |
| `posts_per_page` | Éléments par page, de 1 à 100 (10 par défaut). |
| `show_pagination` | `0` pour masquer la pagination. |
| `heading_level` | Niveau de titre de chaque élément, de 2 à 6 (3 par défaut). |

Les procès-verbaux et les séances se saisissent dans le menu **OREMIS** : date, titre et fichiers PDF. Une séance à venir reste affichée jusqu'à la fin du lendemain.

### Lien vers le chat

```
[oremis_chat_link text="Parler à un bénévole"]
```

Un lien vers la page complète du chat, affiché seulement quand le chat est activé.

## Réglages

| Menu | Contenu |
| --- | --- |
| **OREMIS > Config PIO** | Adresse et jeton de l'API de l'annuaire, durée du cache. |
| **OREMIS > Connexion** | Connexion avec le compte OREMIS et avec Google. |
| **OREMIS > Chat** | Affichage de la bulle : désactivée, administrateurs seulement (pour tester) ou tout le monde. |

Les secrets peuvent aussi être définis dans `wp-config.php`, hors de la base de données :

```php
define('PIO_OREMIS_API_TOKEN', '...');
define('PIO_OREMIS_SSO_CLIENT_SECRET', '...');
```

Une fois enregistrés, les secrets ne sont plus jamais réaffichés : laissez le champ vide pour garder la valeur actuelle.

## Sécurité et vie privée

- La connexion ne crée aucun compte et ne change aucun rôle. Les comptes sont gérés par l'association en dehors du site.
- Connexion avec le compte OREMIS : flux OAuth 2.0 avec PKCE, état à usage unique lié au navigateur, jetons révoqués aussitôt après la connexion.
- Connexion Google : seules les adresses `@oremis.fr` vérifiées sont acceptées.
- L'identifiant bénévole lié à un compte n'est jamais exposé publiquement, et seul un administrateur peut le modifier.
- Le chat ne fonctionne que sur le site de l'association : ailleurs, le navigateur refuse de l'afficher.

Vous avez trouvé une faille ? Écrivez-nous à [contact@oremis.fr](mailto:contact@oremis.fr) plutôt que d'ouvrir une issue publique.

## Contribuer

Les suggestions et les pull requests sont les bienvenues.

Pour publier une version, augmentez le numéro de version dans `pio-oremis.php` (l'en-tête `Version:` et la constante `PIO_OREMIS_VERSION`) dans la pull request. Une fois celle-ci fusionnée dans `main`, la publication est automatique : vérification de la syntaxe PHP, tag `v<version>`, version GitHub avec ses notes et son fichier `pio-oremis.zip`. Sans changement de numéro, rien n'est publié.
