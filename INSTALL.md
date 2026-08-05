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

> **État actuel du dépôt.** Le **backend est complet** — providers, DTO, registre,
> service d'installation, contrôleur et routes. Il manque le **frontend**
> (`Components.yml` et le composant React), donc aucun onglet n'apparaît encore ;
> l'API client, elle, répond. Voir [« L'API client »](#lapi-client) pour la tester
> directement, et [« Ce qui manque encore »](#ce-qui-manque-encore-pour-un-onglet-fonctionnel).
>
> `blueprint -build` **échouera tel quel** : plusieurs chemins déclarés dans
> [`conf.yml`](conf.yml) n'existent pas encore. L'[étape B.3](#b3-régler-les-bindings-manquants-obligatoire)
> explique exactement quoi faire.

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

> Garde le gabarit « Working with components » sous la main : `CLAUDE.md` signale que
> le schéma de `Components.yml` de ce dépôt a été écrit d'après la documentation et
> **n'a jamais été validé** contre un panel qui tourne. Diffe le gabarit avec ton
> fichier avant de t'énerver sur un onglet qui n'apparaît pas.

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
└── app/
    └── Services/Modpacks/
        ├── ProviderInterface.php
        ├── ModrinthProvider.php
        └── CurseForgeProvider.php
```

### B.3 Régler les bindings manquants (obligatoire)

**C'est ici que ça casse si tu sautes l'étape.** La règle Blueprint est absolue :
tout chemin déclaré dans `conf.yml` doit exister, sinon le build s'interrompt sur

```
FATAL: Extension configuration points towards one or more files that do not exist
```

…sans jamais dire *lequel*. Voici l'état réel des bindings de ce dépôt :

| Binding dans `conf.yml` | Chemin | Présent ? |
|---|---|---|
| — (racine) | `conf.yml` | ✅ |
| `requests.app` | `app/` | ✅ |
| `requests.routers.client` | `routes/client.php` | ✅ |
| `info.icon` | `assets/icon.png` | ❌ |
| `admin.view` | `admin/view.blade.php` | ❌ |
| `admin.controller` | `admin/AdminController.php` | ❌ |
| `dashboard.components` | `Components.yml` | ❌ |
| `data.directory` | `data/` | ❌ |
| `data.public` | `public/` | ❌ |
| `database.migrations` | `database/migrations/` | ❌ |

Deux chemins possibles.

#### Option 1 — créer des stubs (recommandé : garde `conf.yml` intact)

```bash
DEV=/var/www/pterodactyl/.blueprint/dev
cd "$DEV"

mkdir -p assets admin data public database/migrations resources/scripts

# Icône : n'importe quel PNG fait l'affaire pour un build de test
curl -fsSL -o assets/icon.png https://dummyimage.com/64x64/2d2d2d/ffffff.png

# Page admin minimale
cat > admin/view.blade.php <<'BLADE'
<div class="row">
  <div class="col-xs-12">
    <div class="box">
      <div class="box-header with-border"><h3 class="box-title">Modpacks</h3></div>
      <div class="box-body">
        <p>Configuration à venir : clé API CurseForge et ID de l'egg installeur.</p>
      </div>
    </div>
  </div>
</div>
BLADE

cat > admin/AdminController.php <<'PHP'
<?php

namespace Pterodactyl\Http\Controllers\Admin\Extensions\modpacks;

use Pterodactyl\Http\Controllers\Controller;
use Illuminate\Contracts\View\Factory as ViewFactory;

class ModpacksExtensionController extends Controller
{
    public function __construct(private ViewFactory $view)
    {
    }

    public function index()
    {
        return $this->view->make('admin.extensions.modpacks.index');
    }
}
PHP

# Scripts d'extension (ils tournent à l'installation de l'extension,
# ils n'ont RIEN à voir avec l'installation d'un modpack)
for f in install update remove export; do
  printf '#!/bin/bash\n# %s.sh — extension lifecycle hook, no-op for now.\nexit 0\n' "$f" > "data/$f.sh"
done

touch public/.gitkeep database/migrations/.gitkeep

chown -R www-data:www-data "$DEV"
```

> Le nom de classe et le namespace du contrôleur admin ci-dessus dépendent de la
> version de Blueprint. Si le build s'en plaint, compare avec le gabarit produit par
> `blueprint -init`, qui fait autorité sur ta version.

#### Option 2 — retirer les bindings

Plus rapide pour un premier build, mais tu perds la page admin et l'onglet. Édite
`$DEV/conf.yml` et vide les valeurs concernées :

```yaml
info:
  icon: ''

admin:
  view: ''
  controller: ''

dashboard:
  components: ''

requests:
  app: 'app'
  routers:
    client: ''

data:
  directory: ''
  public: ''

database:
  migrations: ''
```

C'est aussi la technique de **bissection** quand le `FATAL` tombe : vide tous les
bindings, build, puis remets-les un par un jusqu'à ce que ça repète.

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

# L'extension est-elle enregistrée ?
blueprint -list
```

Le préfixe des routes compte : le composant React de ce projet code en dur
`/api/client/servers/{uuid}/modpacks`. `CLAUDE.md` le signale comme **non vérifié**.
Si `route:list` montre autre chose, c'est le composant qu'il faut corriger, pas la route.

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
3. Ouvrir l'egg importé et **noter son ID** (il est dans l'URL :
   `/admin/nests/egg/<ID>`). Il servira à l'étape suivante.

### D.2 Créer `config/modpacks.php`

Le panel a besoin de la clé CurseForge et de l'ID de l'egg. Tant que la page admin
n'existe pas, ça se fait à la main :

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

> La clé CurseForge est un **secret serveur**. C'est toute la raison pour laquelle les
> appels aux providers transitent par le panel : elle ne doit jamais atteindre le
> navigateur. Ne commite pas `config/modpacks.php` (il est déjà dans `.gitignore`).
>
> Une clé s'obtient sur <https://console.curseforge.com/>. **Modrinth n'en demande
> aucune** — commence par là pour valider la chaîne de bout en bout.

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
| `GET` | `…/servers/{server}/modpacks/providers` | accès au serveur | Liste des providers |
| `GET` | `…/modpacks/packs?provider=&query=&page=` | accès au serveur | Recherche de packs |
| `GET` | `…/modpacks/packs/{pack}/versions?provider=` | accès au serveur | Versions d'un pack |
| `POST` | `…/modpacks/install` | `startup.update` **+** `file.delete` | Lance l'installation |

Corps du `POST /install` :

```json
{ "provider": "modrinth", "pack": "1KVo5zza", "version": "yBz9Qvbp", "wipe": true }
```

Il répond `202 Accepted` : le panel a seulement demandé à Wings de réinstaller, la
progression réelle s'affiche dans la console du serveur.

Test rapide en ligne de commande (le préfixe exact vient de `route:list`) :

```bash
curl -H "Authorization: Bearer $PTERO_CLIENT_KEY" \
     -H "Accept: application/json" \
     "https://panel.example.com/api/client/servers/1a7ce997/modpacks/packs?provider=modrinth&query=create"
```

**La double permission sur `install` est intentionnelle** : installer un pack efface
le système de fichiers *et* réécrit la commande de démarrage. Un sous-utilisateur
disposant du seul accès console ne doit pas pouvoir détruire un serveur par ce biais.
Ne l'assouplis pas.

## Ce qui manque encore pour un onglet fonctionnel

Il reste le frontend. Le build passe et l'API répond, mais aucun onglet n'apparaît tant
que ces deux fichiers n'existent pas :

| Fichier | Rôle |
|---|---|
| `Components.yml` | Placement de l'onglet dans les pages serveur |
| `resources/scripts/ModpacksContainer.tsx` | L'onglet React |

Deux points à garder en tête en les écrivant :

- Le schéma de `Components.yml` de ce dépôt **n'a jamais été validé** contre un panel
  qui tourne (`CLAUDE.md`). Pars du gabarit « Working with components » de
  `blueprint -init` plutôt que de la documentation.
- Le composant ne doit contenir **aucun** branchement spécifique à un provider : il
  consomme `/providers` et traite toutes les entrées de la même façon. C'est la
  contrepartie de la normalisation faite par `Pack` et `Version` côté PHP.

Restent aussi ouverts, côté confort plutôt que fonctionnement : la page admin réelle
(la clé CurseForge se pose encore à la main), le masquage de l'onglet sur les eggs
non-Minecraft, et le sondage de `/state` pendant l'installation.

---

## Dépannage

| Symptôme | Cause probable et remède |
|---|---|
| `FATAL: Extension configuration points towards one or more files that do not exist` | Un chemin de `conf.yml` est absent. Le message ne dit pas lequel : bissecte en vidant les bindings (cf. [B.3](#b3-régler-les-bindings-manquants-obligatoire)). |
| `blueprint -build` : commande inconnue | Mode développement désactivé (cf. [A.5](#a5-activer-le-mode-développement)). |
| L'onglet n'apparaît pas | 1) Cache navigateur — Ctrl+Shift+R. 2) Schéma de `Components.yml` non validé : diffe avec le gabarit de `blueprint -init`. 3) L'onglet ne se cache pas encore sur les eggs non-Minecraft : c'est un manque connu, pas un bug de placement. |
| 404 sur les appels API de l'onglet | Le préfixe de route réel diffère de celui codé en dur. Vérifie avec `php artisan route:list \| grep modpacks`. |
| `CurseForge is not configured` | `config/modpacks.php` absent, ou config non rechargée : `php artisan config:clear`. |
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
