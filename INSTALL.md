# Tutoriel — installer l'extension Blueprint « Modpacks »

Ce document couvre les deux sens de « installer le blueprint » :

- **[Partie A](#partie-a--installer-le-framework-blueprint)** — installer le framework Blueprint sur le panel.
- **[Partie B](#partie-b--installer-cette-extension-en-mode-développement)** — installer *cette* extension en mode développement (le cas de ce dépôt).
- **[Partie C](#partie-c--empaqueter-et-installer-un-fichier-blueprint)** — empaqueter en `.blueprint` et l'installer sur un autre panel.
- **[Partie D](#partie-d--importer-legg-installeur)** — importer l'egg installeur et le configurer.

> **Avertissement, à lire avant de commencer.**
> `blueprint -build` écrit **directement** dans l'installation live du panel. Un build
> raté laisse le panel cassé. Fais tout ceci sur une VM jetable ou l'image Docker
> Blueprint — **jamais** sur un panel de production. C'est aussi la règle posée dans
> [`CLAUDE.md`](CLAUDE.md).

> **État actuel du dépôt.** L'extension est complète et a été buildée avec succès sur
> un panel réel (Blueprint `beta-2026-06`) : `blueprint -build` aboutit, les assets
> compilent, l'onglet s'affiche, et les routes client et admin sont montées.
>
> Le préfixe des routes client est désormais **confirmé** :
> `/api/client/extensions/modpacks/servers/{server}`. Les pièges rencontrés en chemin
> (commentaires dans `conf.yml`, namespace de `app/`, placeholders dans le JSX) sont
> documentés au [dépannage](#dépannage) — ils sont tous invisibles à la lecture du code.

---

## Prérequis

| Élément | Détail |
|---|---|
| Panel Pterodactyl | Installation **de test**, version proche de `1.11.11` (la cible déclarée dans `conf.yml`) |
| Accès | `root` ou `sudo` sur la machine du panel |
| Chemin du panel | `/var/www/pterodactyl` dans tout ce document |
| Outils | `curl`, `unzip`, `git`, `zip`, `python3` |
| Wings | Un nœud fonctionnel — l'installation d'un modpack passe par un conteneur d'installation |
| Optionnel | Une clé API CurseForge (Modrinth n'en demande aucune) |

Vérifie la version du panel avant de commencer :

```bash
cd /var/www/pterodactyl
php artisan --version
grep "'version'" config/app.php
```

---

## Partie A — installer le framework Blueprint

À sauter si Blueprint est déjà installé (`blueprint -v` répond).

### A.1 Sauvegarder le panel

Non négociable : l'installeur Blueprint modifie les fichiers du panel.

```bash
cd /var/www
tar -czf ~/pterodactyl-backup-$(date +%F).tar.gz pterodactyl
mysqldump -u root -p panel > ~/panel-backup-$(date +%F).sql
```

### A.2 Installer les dépendances

Blueprint recompile les assets du panel, il lui faut donc Node et Yarn :

```bash
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt-get install -y nodejs zip unzip git
sudo npm i -g yarn
```

### A.3 Poser Blueprint dans le panel

Récupère la dernière release depuis
<https://github.com/BlueprintFramework/framework/releases>, puis :

```bash
cd /var/www/pterodactyl
wget "$(curl -sSL https://api.github.com/repos/BlueprintFramework/framework/releases/latest \
  | grep -o 'https://.*release\.zip')" -O release.zip
unzip -o release.zip
rm release.zip
chown -R www-data:www-data /var/www/pterodactyl
```

### A.4 Lancer l'installeur

```bash
cd /var/www/pterodactyl
chmod +x blueprint.sh
bash blueprint.sh
```

Le script pose des questions sur le propriétaire des fichiers et le webserver, puis
recompile les assets. C'est long (plusieurs minutes). En cas de doute sur une
réponse, suis la doc officielle : <https://blueprint.zip/docs/>.

Vérification :

```bash
blueprint -v
```

### A.5 Activer le mode développement

Sans ça, `blueprint -build` n'existe pas.

Deux façons, selon la version de Blueprint :

- **Depuis le panel** : `/admin/extensions` → Blueprint → passer `developer` à `true`.
- **Depuis le fichier** : éditer `/var/www/pterodactyl/.blueprintrc` et mettre
  `developer="true"`.

Puis :

```bash
cd /var/www/pterodactyl
blueprint -init
```

`-init` propose des gabarits de départ ; il crée surtout `/var/www/pterodactyl/.blueprint/dev/`,
qui est le répertoire dans lequel on va travailler.

> Garde le gabarit « Working with components » (numéro `3`) sous la main. Le
> `components/` de ce dépôt a été calé dessus, mais cette API a déjà bougé d'une
> version de Blueprint à l'autre : en cas d'onglet qui refuse d'apparaître, diffe
> les deux avant de chercher ailleurs.

---

## Partie B — installer cette extension en mode développement

### B.1 Récupérer le dépôt

```bash
git clone https://github.com/raphaeldufour/modpack-installer.git ~/modpack-installer
```

### B.2 Copier les fichiers dans le dossier dev

La racine de ce dépôt **est** la racine de l'extension : `conf.yml` doit se retrouver
directement dans `.blueprint/dev/`. Le dossier `egg/` fait exception — il n'est pas
livré avec l'extension, il s'importe séparément (voir [Partie D](#partie-d--importer-legg-installeur)).

```bash
DEV=/var/www/pterodactyl/.blueprint/dev

rsync -a --delete \
  --exclude '.git' \
  --exclude 'egg' \
  --exclude '*.md' \
  ~/modpack-installer/ "$DEV/"

chown -R www-data:www-data "$DEV"
```

Contenu attendu à ce stade :

```
.blueprint/dev/
├── conf.yml
├── admin/          assets/         components/
├── data/           database/       public/
├── routes/client.php
└── app/
    ├── Http/Controllers/ModpackController.php
    └── Services/          (Pack, Version, providers, registry, settings, install service)
```

> **Le namespace de `app/` n'est pas libre.** Blueprint symlinke `requests.app` vers
> `app/BlueprintFramework/Extensions/modpacks` dans le panel, donc tout ce qui est ici
> est namespacé `Pterodactyl\BlueprintFramework\Extensions\modpacks\…`. Avec n'importe
> quelle autre racine, l'autoloading échoue et `route:list` signale la classe du
> contrôleur comme introuvable. Blueprint expose la même chaîne via le placeholder
> `{appcontext}`.

### B.3 Vérifier les bindings

Rien à créer : tous les chemins déclarés dans `conf.yml` existent dans le dépôt.
Cette étape est une vérification, pas une correction.

La règle Blueprint est absolue : tout chemin déclaré dans `conf.yml` doit exister,
sinon le build s'interrompt sur

```
FATAL: Extension configuration points towards one or more files that do not exist
```

…sans jamais dire *lequel*. D'où l'intérêt de contrôler avant de builder :

| Binding dans `conf.yml` | Chemin | Rôle |
|---|---|---|
| — (racine) | `conf.yml` | Manifeste |
| `info.icon` | `assets/icon.png` | Icône de l'extension |
| `admin.view` | `admin/view.blade.php` | Page admin |
| `admin.controller` | `admin/AdminController.php` | Contrôleur admin |
| `dashboard.components` | `components/` | Dossier des composants React |
| `requests.app` | `app/` | Fusionné dans l'arbre `app/` du panel |
| `requests.routers.client` | `routes/client.php` | Endpoints de l'API client |
| `data.directory` | `data/` | Hooks de cycle de vie de l'extension |
| `data.public` | `public/` | Servi sur `/extensions/modpacks/…` (vide) |
| `database.migrations` | `database/migrations/` | Vide : la config vit dans la table `settings` |

Contrôle rapide depuis le dossier dev, avant de builder :

```bash
cd /var/www/pterodactyl/.blueprint/dev

for p in assets/icon.png admin/view.blade.php admin/AdminController.php \
         components components/Components.yml app routes/client.php \
         data public database/migrations; do
  [ -e "$p" ] && echo "ok   $p" || echo "MISSING $p"
done
```

**Le piège qui rend ce `FATAL` déroutant** : il tombe alors que tous les chemins que
tu as écrits existent. `conf.yml` n'est pas lu par un parseur YAML mais par
`scripts/libraries/parse_yaml.sh`, un helper sed/awk. Son retrait des commentaires ne
s'applique **que si le commentaire ne contient aucune apostrophe ni guillemet**. Une
seule apostrophe suffit :

```yaml
  app: 'app'      # copied into the panel's app/ tree
```

devient la valeur `app'      # copied into the panel's app/ tree`. Le chemin ne résout
plus, et le build meurt sans nommer le moindre fichier.

**Donc : aucun commentaire en fin de ligne dans `conf.yml`.** Les commentaires sur
leur propre ligne sont retirés sans risque, quel que soit leur contenu.

Détection immédiate :

```bash
grep -nE "^[^#]*: *['\"].*['\"] *#" conf.yml && echo "^^ commentaires en fin de ligne : à déplacer"
```

Deux autres faits tirés de `scripts/commands/extensions/install.sh` :

- `admin.view` est le **seul** binding obligatoire ; tous les autres sont ignorés
  lorsqu'ils sont vides.
- `dashboard.components`, `data.directory`, `data.public`, `requests.views`,
  `requests.app` et `database.migrations` sont testés avec `-d` : ce doivent être des
  **dossiers**. Les autres sont testés avec `-f`.

> **Si le `FATAL` tombe malgré tout**, va lire la validation de *ta* version — elle fait
> autorité, et le fichier peut bouger d'une release à l'autre :
>
> ```bash
> F=$(grep -rl "points towards one or more files" /var/www/pterodactyl --include='*.sh' | head -1)
> grep -n -B 40 "points towards one or more files" "$F"
> ```

> Les scripts de `data/` tournent à l'installation de **l'extension** (ils affichent
> les étapes de configuration) et n'ont rien à voir avec `egg/install.sh`, qui installe
> un modpack dans un serveur. Ils doivent tous sortir en `exit 0` : un code non nul
> fait échouer l'installation de l'extension.

### B.4 Construire

```bash
cd /var/www/pterodactyl
blueprint -build
```

Puis **vide le cache du navigateur** (Ctrl+Shift+R) : le panel sert du JS compilé et
mis en cache, un onglet neuf n'apparaît pas sans rechargement forcé.

### B.5 Vérifier

```bash
# Les routes client ont-elles bien été montées, et sous quel préfixe ?
php artisan route:list | grep modpacks
```

Attendu :

```
GET|HEAD  api/client/extensions/modpacks/servers/{server}/providers
GET|HEAD  api/client/extensions/modpacks/servers/{server}/packs
GET|HEAD  api/client/extensions/modpacks/servers/{server}/packs/{pack}/versions
POST      api/client/extensions/modpacks/servers/{server}/install
GET|HEAD  admin/extensions/modpacks
```

**Blueprint monte les routes d'extension sous `/api/client/extensions/<identifier>`**,
et non à côté des routes `/api/client/servers` du panel. C'est pourquoi le groupe de
`routes/client.php` ne répète pas `modpacks` dans son propre préfixe, et pourquoi
`ModpacksSection.tsx` construit ses URL sur cette base. Si `route:list` montre autre
chose après une montée de version de Blueprint, c'est le composant qu'il faut aligner.

(`blueprint -list` n'existe pas ; l'extension apparaît dans `/admin/extensions`.)

---

## Partie C — empaqueter et installer un fichier `.blueprint`

Une fois l'extension satisfaisante en dev, empaquette-la pour la distribuer :

```bash
cd /var/www/pterodactyl
blueprint -export
```

Le fichier `modpacks.blueprint` atterrit dans `/var/www/pterodactyl/.blueprint/extensions/`
(l'emplacement exact est affiché par la commande).

Sur le panel cible :

```bash
# Sauvegarde d'abord (cf. A.1), puis :
cd /var/www/pterodactyl
blueprint -install modpacks.blueprint
```

Désinstallation :

```bash
blueprint -remove modpacks
```

> Un panel qui reçoit un `.blueprint` n'a **pas** besoin du mode développement — il
> n'est requis que pour `-build` / `-export`.

---

## Partie D — importer l'egg installeur

L'extension ne télécharge aucun fichier elle-même : elle bascule le serveur sur un egg
dédié, met à jour ses variables, puis déclenche une réinstallation. C'est le script
d'installation de cet egg qui fait le vrai travail, dans le conteneur d'installation —
d'où un log d'installation en direct dans la console du serveur.

### D.1 Importer l'egg

1. Panel → **Admin** → **Nests** → choisir ou créer un nest (ex. `Minecraft`).
2. **Import Egg** → envoyer [`egg/modpack-installer.json`](egg/modpack-installer.json).
3. C'est tout : la page admin de l'étape suivante liste les eggs et te laisse
   sélectionner celui-ci. (Son ID reste visible dans l'URL `/admin/nests/egg/<ID>` si
   tu configures par fichier plutôt que par l'interface.)

### D.2 Configurer l'extension

Rends-toi sur **Admin → Extensions → Modpacks**. Deux réglages :

- **CurseForge API key** — nécessaire uniquement pour les packs CurseForge. Une clé
  s'obtient sur <https://console.curseforge.com/>.
- **Installer egg** — la liste déroulante des eggs du panel, groupée par nest.
  Sélectionne celui importé en [D.1](#d1-importer-legg). Plus besoin de relever son ID
  à la main.

Le bouton **Apply Changes** n'apparaît qu'une fois un champ modifié.

> **Modrinth ne demande aucune clé.** Tu peux laisser le champ CurseForge vide et
> valider toute la chaîne avec Modrinth — c'est le chemin le plus rapide.
>
> Choisir le mauvais egg est destructeur : les serveurs sont basculés dessus puis
> réinstallés. Vérifie que tu sélectionnes bien « Modpack Installer ».
>
> La clé est un **secret serveur**. Elle ne quitte jamais le panel vers un navigateur
> (c'est toute la raison du proxy sur les providers), mais elle est écrite dans
> l'environnement du serveur installé, car `install.sh` tourne dans un conteneur qui
> n'a pas accès aux réglages du panel. La variable d'egg est en `user_viewable: false`.

<details>
<summary>Alternative : <code>config/modpacks.php</code> (panels installés avant la page admin)</summary>

Les réglages de la page admin ont la priorité. Si un champ est laissé vide, l'extension
retombe sur ce fichier, ce qui évite de casser un panel déjà configuré ainsi :

```bash
cat > /var/www/pterodactyl/config/modpacks.php <<'PHP'
<?php

return [
    'curseforge_api_key' => env('CURSEFORGE_API_KEY'),
    'installer_egg_id' => (int) env('MODPACK_INSTALLER_EGG_ID'),
];
PHP
```

Puis dans `/var/www/pterodactyl/.env` :

```dotenv
CURSEFORGE_API_KEY=ta-cle-curseforge
MODPACK_INSTALLER_EGG_ID=42
```

Et recharge la config :

```bash
cd /var/www/pterodactyl
php artisan config:clear
php artisan config:cache
```

Ne commite pas `config/modpacks.php` (il est déjà dans `.gitignore`). La page admin
signale quand une valeur provient encore de ce fichier.

</details>

### D.3 Modifier le script d'installation de l'egg

`egg/install.sh` est la **source de vérité**. Le JSON de l'egg n'est qu'un produit
dérivé (le script y est encapsulé dans une chaîne échappée, illisible en revue).

```bash
cd ~/modpack-installer
$EDITOR egg/install.sh
bash -n egg/install.sh        # contrôle de syntaxe — toujours avant de régénérer
python3 egg/build_egg.py      # régénère egg/modpack-installer.json
```

Puis réimporte le JSON dans le panel (Admin → l'egg → **Import**, en écrasant).

**Ne modifie jamais `egg/modpack-installer.json` à la main** : `build_egg.py` l'écrase
sans prévenir. Les métadonnées de l'egg (variables, images Docker, commande de
démarrage) se modifient dans `egg/egg.template.json`.

---

## L'API client

Le backend est complet. Une fois l'extension buildée, ces endpoints existent (aux
réserves de préfixe évoquées en [B.5](#b5-vérifier)) :

| Méthode | Chemin | Permission | Rôle |
|---|---|---|---|
| `GET` | `…/servers/{server}/providers` | accès au serveur | Liste des providers |
| `GET` | `…/servers/{server}/packs?provider=&query=&page=` | accès au serveur | Recherche de packs |
| `GET` | `…/servers/{server}/packs/{pack}/versions?provider=` | accès au serveur | Versions d'un pack |
| `POST` | `…/servers/{server}/install` | `startup.update` **+** `file.delete` | Lance l'installation |

Base : `/api/client/extensions/modpacks`.

Corps du `POST /install` :

```json
{ "provider": "modrinth", "pack": "1KVo5zza", "version": "yBz9Qvbp", "wipe": true }
```

Il répond `202 Accepted` : le panel a seulement demandé à Wings de réinstaller, la
progression réelle s'affiche dans la console du serveur.

Test rapide en ligne de commande :

```bash
curl -H "Authorization: Bearer $PTERO_CLIENT_KEY" \
     -H "Accept: application/json" \
     "https://panel.example.com/api/client/extensions/modpacks/servers/1a7ce997/packs?provider=modrinth&query=create"
```

**La double permission sur `install` est intentionnelle** : installer un pack efface
le système de fichiers *et* réécrit la commande de démarrage. Un sous-utilisateur
disposant du seul accès console ne doit pas pouvoir détruire un serveur par ce biais.
Ne l'assouplis pas.

## L'onglet React

`components/Components.yml` déclare une route serveur qui monte
`components/sections/ModpacksSection.tsx` sur `/modpacks` — soit, dans le panel,
`/server/<id>/modpacks`.

```yaml
Navigation:
  Routes:
    - { Name: "Modpacks", Path: "/modpacks", Type: "server", Component: "sections/ModpacksSection", AdminOnly: "false" }
```

Trois pièges, tous vérifiés contre le gabarit officiel « Working with components »
([`BlueprintFramework/templates`](https://github.com/BlueprintFramework/templates), dossier `3`) :

1. `dashboard.components` pointe vers le **dossier** `components/`, pas vers
   `Components.yml`. L'inverse casse le build.
2. Les valeurs de `Component:` sont relatives à ce dossier et **sans extension**.
3. Toutes les valeurs sont des chaînes, `AdminOnly: "false"` compris.

> **Piège majeur du JSX sous Blueprint.** Avant de compiler, Blueprint remplace ses
> placeholders dans **tous** les fichiers de l'extension : `{identifier}`, `{name}`,
> `{author}`, `{version}`, `{random}`, `{timestamp}`, `{mode}`, `{target}`, `{root}`,
> `{webroot}`, `{viewcontext}`, `{appcontext}`, `{engine}`, `{fs}`, plus les formes
> `{root/…}`, `{webroot/…}`, `{fs/private}` et `{is_target}`.
>
> Or JSX est fait d'accolades. Une variable React nommée `version` produit
> `value={version}`, que Blueprint réécrit en `value=0.1.0` — et babel s'étrangle sur
> `JSX value should be either an expression or a quoted JSX text`. C'est pourquoi
> l'état s'appelle ici `versionId` et non `version`.
>
> Deux parades : renommer la variable, ou échapper avec `!` — `!{version}` produit le
> texte littéral `{version}`. Détection avant build :
>
> ```bash
> grep -rnE '(^|[^!])\{(identifier|name|author|version|random|timestamp|mode|target|root|webroot|viewcontext|appcontext|engine|fs|is_target)\}' components/ app/ admin/ routes/
> ```

L'onglet ne contient **aucun** branchement spécifique à un provider : il lit
`/providers` et traite toutes les entrées de la même façon. C'est la contrepartie de
la normalisation faite par `Pack` et `Version` côté PHP — ajouter un provider reste
une modification purement backend.

Le bouton d'installation est désactivé si l'utilisateur n'a pas les deux permissions,
et l'installation passe par une confirmation explicite qui nomme le pack, la version
et le sort des fichiers existants : l'action est destructive et irréversible depuis
l'onglet.

`components/tsconfig.json` ne sert qu'à l'outillage d'éditeur. Ses alias `@/` pointent
vers `.dist/types`, généré par Blueprint **dans le panel** — donc absent de ce dépôt.
Des erreurs de types dans un éditeur ouvert sur le dépôt seul sont normales.

## Ce qui manque encore

Rien de bloquant. Restent des points de confort :

- L'onglet ne se masque pas sur les eggs non-Minecraft. La logique de filtrage vit dans
  `resources/scripts/blueprint/extends/routers/ServerRouter.tsx` du dépôt du framework.
- Pas de sondage de `/state` pendant l'installation : la progression s'observe dans la
  console du serveur.
- Les mods bloqués par leur auteur (`allowModDistribution: false`) ne sont pas encore
  remontés dans l'interface — seulement en `BLOCKED:` dans le log d'installation.
- FTB, Technic et ATLauncher n'ont pas de provider.

---

## Dépannage

| Symptôme | Cause probable et remède |
|---|---|
| `FATAL: Extension configuration points towards one or more files that do not exist` | Un chemin de `conf.yml` est absent — typiquement une copie incomplète vers `.blueprint/dev/`. Le message ne dit pas lequel : lance le contrôle de [B.3](#b3-vérifier-les-bindings), puis bissecte en vidant les bindings. |
| `blueprint -build` : commande inconnue | Mode développement désactivé (cf. [A.5](#a5-activer-le-mode-développement)). |
| L'onglet n'apparaît pas | 1) Cache navigateur — Ctrl+Shift+R (le panel sert du JS compilé). 2) `dashboard.components` doit valoir `components` (le dossier), pas `components/Components.yml`. 3) Le schéma a déjà changé entre versions de Blueprint : diffe avec le gabarit `3` de `blueprint -init`. |
| L'onglet apparaît sur un serveur non-Minecraft | Manque connu, pas un bug de placement : le filtrage par egg n'est pas implémenté. |
| `The route api/client/... could not be found` dans l'onglet | Le préfixe codé en dur ne correspond pas à celui monté. La base est `/api/client/extensions/modpacks`, pas `/api/client`. Vérifie avec `php artisan route:list \| grep modpacks`. |
| `CurseForge is not configured` | Aucune clé enregistrée : **Admin → Extensions → Modpacks**. Si tu configures par fichier, `config/modpacks.php` est absent ou la config n'a pas été rechargée (`php artisan config:clear`). |
| `The modpack installer egg has not been configured` | Aucun egg sélectionné sur la page admin, ou l'egg choisi a été supprimé depuis. |
| `JSX value should be either an expression or a quoted JSX text` pendant `Rebuilding panel assets` | Un placeholder Blueprint a été substitué dans le JSX (`value={version}` → `value=0.1.0`). Renomme la variable ou échappe-la en `!{version}` (cf. [L'onglet React](#longlet-react)). Le build Blueprint affiche quand même `SUCCESS` : c'est la compilation des assets qui a échoué, pas l'installation. |
| `Class "Pterodactyl\…\ModpackController" not found` | Le namespace de `app/` doit être `Pterodactyl\BlueprintFramework\Extensions\modpacks\…` — c'est là que Blueprint symlinke `requests.app`. |
| La page admin renvoie une 500 | Le nom de classe `modpacksExtensionController` est dérivé de `info.identifier` par Blueprint. Compare avec le gabarit `2` (« Admin configuration ») de `blueprint -init` pour ta version. |
| Le pack s'installe mais le serveur ne démarre pas | Regarde le log d'installation dans la console du serveur. Les lignes `BLOCKED:` signalent des mods dont l'auteur interdit la redistribution — il n'existe aucun contournement légal, il faut les ajouter à la main. Les lignes `WARN:` signalent un téléchargement échoué. |
| Mods client-only qui crashent le serveur | Un `.mrpack` Modrinth est un manifeste, pas un pack serveur. Le filtre `env.server != "unsupported"` doit être appliqué, et `server-overrides/` copié **après** `overrides/`. |
| HTTP 429 des providers | Modrinth limite le débit et exige un User-Agent descriptif : mets une vraie adresse de contact dans `ModrinthProvider::client()`. Le cache de 300 s est un plancher, pas un réglage à baisser. |
| Le panel est cassé après un build | C'est exactement pourquoi la Partie A commence par une sauvegarde. Restaure l'archive et le dump SQL. |

---

## Aide-mémoire

```bash
cd /var/www/pterodactyl

blueprint -build                          # compiler le dossier dev dans le panel live
blueprint -export                         # produire un .blueprint distribuable
blueprint -install modpacks.blueprint     # installer un paquet
blueprint -remove modpacks                # désinstaller
php artisan route:list | grep modpacks    # vérifier le préfixe des routes client

cd ~/modpack-installer
bash -n egg/install.sh                    # vérifier la syntaxe avant de régénérer
python3 egg/build_egg.py                  # régénérer egg/modpack-installer.json
```
