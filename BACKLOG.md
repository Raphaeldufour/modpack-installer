# Backlog

Ce qui reste à construire, pourquoi, et ce que chaque morceau va coûter.

Les pièges déjà rencontrés et documentés (redirections refusées par Wings,
placeholders Blueprint dans le JSX, commentaires dans `conf.yml`, namespace de
`app/`) sont dans [`CLAUDE.md`](CLAUDE.md) — ils s'appliquent à tout ce qui suit
et ne sont pas répétés ici.

---

## État actuel

Vérifié sur un panel réel (Blueprint `beta-2026-06`).

| Fonction | État |
|---|---|
| Onglet Versions — Vanilla, Paper, Purpur, Fabric, Folia, Velocity | ✅ |
| Onglet Modpacks — recherche, versions, providers Modrinth + CurseForge | ✅ |
| Modpacks — install d'un server pack CurseForge | ✅ testé (ATM10) |
| Modpacks — install d'un `.mrpack` Modrinth | ✅ écrit, non testé sur panel |
| Page admin — clé CurseForge | ✅ |
| Installation sans egg ni réinstallation | ✅ |
| Résolution des redirections CDN avant Wings | ✅ |
| `start.sh` généré, heap résolu au démarrage | ✅ |

**Architecture acquise**, à ne pas réinventer pour les onglets suivants :
le panel résout une URL, Wings télécharge et décompresse, le panel réécrit la
commande de démarrage. Le serveur garde son egg.

---

## 1. Onglet Mods

Le plus rentable des onglets restants : un mod = un fichier, donc aucun problème
d'échelle, et toute l'infrastructure existe déjà.

### 1.1 Recherche et installation

- `ModProviderInterface` sur le modèle de `ProviderInterface` : `search()`,
  `versions()`, `resolve()`.
- Modrinth : `/v2/search` avec `facets=[["project_type:mod"]]`, plus
  `["categories:fabric"]` et `["versions:1.21.1"]` pour les filtres.
- CurseForge : `/v1/mods/search` avec `classId=6`, `gameVersion` et
  `modLoaderType` (1=Forge, 4=Fabric, 5=Quilt, 6=NeoForge).
- Installation : un seul `pull` vers `mods/`. Passer par `RemoteFile::resolve()`
  — CurseForge redirige.
- Filtres loader + version Minecraft dans l'UI, comme sur les captures.

### 1.2 Lister les mods déjà installés

C'est la partie non triviale. `getDirectory('/mods')` donne des noms de
fichiers, pas des projets.

- Modrinth expose `/v2/version_file/{sha1}?algorithm=sha1` : le hash d'un jar
  redonne le projet et la version. C'est la seule façon fiable de reconnaître un
  mod posé à la main.
- Mais **le panel n'a pas le fichier** — il faudrait le télécharger pour le
  hasher, ce que l'architecture interdit. Deux issues :
  - garder un manifeste de ce que l'extension a installé (`.modpacks-state.json`
    sur le volume, écrit via `putContent`), et n'afficher que ça ;
  - ou accepter un affichage dégradé : nom de fichier + taille, sans métadonnées.
- **Décision à prendre avant de coder.** Le manifeste local est plus simple et
  plus honnête ; il ne reconnaîtra pas les mods installés hors extension, ce
  qu'il faut dire dans l'UI.

### 1.3 Suppression

- `deleteFiles('/mods', [...])`, plus retrait de l'entrée du manifeste.
- Exiger `file.delete`.

### 1.4 Compatibilité version / mods — *à la fin*

Comparer la version Minecraft du serveur (déduite d'où ?) avec celles déclarées
par chaque mod. Suppose de connaître la version installée de façon fiable →
dépend de [4.2](#42-connaître-létat-installé).

---

## 2. Onglet Plugins

Structurellement identique à Mods, dossier `plugins/` au lieu de `mods/`.

- Modrinth : `project_type:plugin`.
- CurseForge : `classId=5` (Bukkit Plugins).
- Spiget (`api.spiget.org/v2`) couvre SpigotMC, que ni Modrinth ni CurseForge
  n'indexent — mais **beaucoup de ressources Spigot sont "external"** et
  n'exposent pas de fichier téléchargeable. Il faut le détecter et le dire,
  sinon on installe des fichiers vides.
- Les plugins ne concernent que Paper/Purpur/Spigot ; masquer l'onglet ou
  avertir si le serveur tourne sur Forge/Fabric.

**Réutilisation** : si Mods est bien découpé, Plugins est essentiellement le même
service avec un dossier et des facettes différents. Écrire Mods en gardant ça en
tête.

---

## 3. Onglet Worlds

- CurseForge `classId=17` (Worlds). Modrinth n'a pas de catégorie monde.
- Installation : `pull` du zip → `decompressFile` → le contenu atterrit dans un
  dossier dont **on ne connaît pas le nom à l'avance**.
- Il faut ensuite soit renommer ce dossier en `world`, soit écrire
  `level-name=<dossier>` dans `server.properties`. La seconde option est moins
  destructrice.
- `getDirectory('/')` avant et après la décompression permet de repérer le
  dossier apparu.
- **Écraser un monde est irréversible.** Confirmation explicite obligatoire, et
  c'est le cas d'usage qui justifie le plus [4.3](#43-sauvegarde-avant-écrasement).
- Lister et supprimer les mondes présents : `getDirectory('/')` filtré sur les
  dossiers contenant `level.dat`.

---

## 4. Chantiers transverses

### 4.1 Rapport de progression

Le manque le plus visible aujourd'hui. L'installation est synchrone, et les mods
d'un pack à manifeste sont mis en file chez Wings avec `foreground: false` —
**un mod qui échoue le fait silencieusement**.

Deux approches :

- **Fichier d'état sur le volume** : le service écrit `.modpacks-install.json`
  (étape courante, n/total, erreurs) via `putContent`, un endpoint le relit via
  `getContent`, l'onglet sonde. Simple, sans dépendance, mais l'écriture doit
  rester dans la requête.
- **Job en file Laravel** : `pteroq` tourne déjà sur un panel standard. Permet de
  télécharger les mods en `foreground: true` un par un, donc de savoir lesquels
  ont échoué, sans bloquer la requête. Plus juste, plus de surface.

La seconde débloque aussi [5.1](#51-packs-curseforge-à-manifeste).

### 4.2 Connaître l'état installé

Les captures montrent « Currently running Paper 1.21.4, build #173 ». Rien ne le
sait aujourd'hui.

- Écrire ce qui vient d'être installé dans `.modpacks-state.json` sur le volume.
- L'onglet Versions l'affiche et signale les builds plus récents.
- Prérequis de la compatibilité mods ([1.4](#14-compatibilité-version--mods--à-la-fin))
  et du filtrage par version dans Mods/Plugins.

### 4.3 Sauvegarde avant écrasement

Installer un pack efface le serveur. Proposer une sauvegarde Pterodactyl
(`BackupRepository`) avant, ou au minimum un avertissement plus ferme quand des
mondes existent.

### 4.4 Masquer les onglets sur les eggs non-Minecraft

Un serveur Rust affiche aujourd'hui l'onglet Modpacks. La logique de filtrage
par egg vit dans `resources/scripts/blueprint/extends/routers/ServerRouter.tsx`
du framework ; Blueprint ne l'applique pas seul au placement des composants.

### 4.5 Cache partagé et limites de débit

Le cache de 300 s est par panel. Modrinth throttle sur User-Agent — et le
User-Agent du dépôt est encore `your-contact@example.com`, **à changer avant
toute mise en production**.

### 4.6 Tests

Il n'y a aucun test automatisé. Les vérifications faites jusqu'ici étaient
manuelles et jetables. Les cibles les plus rentables, toutes du code pur :
`JavaVersion`, `RemoteFile::absolute`, `ModrinthProvider::manifestFiles`, le
scan des `gameVersions` CurseForge.

---

## 5. Providers et logiciels manquants

### 5.1 Packs CurseForge à manifeste

Refusés aujourd'hui avec un message explicite. Un manifeste liste des
`projectID`/`fileID`, pas des URL : chaque mod demande un appel `download-url`
**et** une redirection à suivre, soit ~600 allers-retours pour 300 mods.

- Utiliser `POST /v1/mods/files` (endpoint bulk) : les objets fichiers renvoyés
  contiennent `downloadUrl`, ce qui supprime les N premiers appels.
- Reste N redirections à résoudre. Soit les paralléliser, soit accepter que
  `edge.forgecdn.net` → `mediafilez.forgecdn.net` soit une transformation stable
  (à vérifier, pas à supposer).
- Dépend de [4.1](#41-rapport-de-progression) pour ne pas bloquer la requête.

### 5.2 Mods interdits de redistribution

`allowModDistribution: false` fait renvoyer une URL nulle par CurseForge. Aucun
contournement légal. Aujourd'hui le mod manque, sans que personne ne le sache :
il faut les collecter et les afficher, avec un lien vers la page du projet.

### 5.3 Forge et NeoForge dans l'onglet Versions

Ils publient un installeur à exécuter — le panel ne peut pas. Mais
`ModpackInstallService::writeBootstrap()` fait déjà exactement ça au premier
démarrage : **extraire ce script dans un service partagé** et l'onglet Versions
peut les proposer.

- Forge : `maven.minecraftforge.net`, `promotions_slim.json` pour les versions
  recommandées.
- NeoForge : `maven.neoforged.net/api/maven/versions/releases/net/neoforged/neoforge`.
- La version Minecraft se déduit du numéro NeoForge (`21.1.x` → `1.21.1`).

### 5.4 Autres logiciels serveur

Par ordre de facilité :

| Logiciel | API | Note |
|---|---|---|
| Quilt | `meta.quiltmc.org/v3` | Calqué sur Fabric, le plus rapide |
| Pufferfish / Mohist / Magma | mavens propres | Jar unique |
| Sponge | `dl-api.spongepowered.org/v2` | Jar unique |
| Waterfall / BungeeCord | Paper API / Jenkins | Proxies : pas de monde, pas de mods |
| Spigot | — | Exige BuildTools, donc un processus. À écarter |

### 5.5 Autres providers de modpacks

| Provider | API | Note |
|---|---|---|
| Feed The Beast | `api.modpacks.ch` | Livre un installeur serveur — le plus simple |
| Technic | `api.technicpack.net` | Solder ; zips souvent prêts pour serveur |
| ATLauncher | `api.atlauncher.com` | Index JSON |
| Voids Wrath | aucune publique | L'addon commercial semble scraper — vérifier leurs conditions avant |

---

## 6. Dette et risques connus

- **`info.target` vaut `1.11.11`** alors que le panel testé est `beta-2026-06`.
  Chaque build affiche un avertissement. À corriger une fois la cible réelle
  arrêtée.
- **Les hooks `data/*.sh` sont des no-op** qui n'affichent que du texte. Ils
  conviennent, mais si un réglage devait un jour être initialisé, c'est là.
- **Aucune pagination réelle** dans la recherche : `page` est accepté par l'API
  mais l'onglet ne l'expose pas.
- **Le `.mrpack` Modrinth n'a jamais été testé sur un panel.** Le parsing du
  manifeste est vérifié, le chemin complet non.
- **L'image Docker n'est changée que si l'egg en déclare une adaptée.** Sur un
  egg Paper qui n'expose que Java 21, installer une 1.12.2 laissera l'image en
  place et le serveur ne démarrera pas — l'onglet le signale, mais ne le corrige
  pas.

---

## Ordre suggéré

1. **Onglet Mods** — valeur immédiate, aucune inconnue technique, et il fixe les
   conventions que Plugins réutilisera.
2. **[4.2](#42-connaître-létat-installé) état installé** — petit, et débloque
   l'affichage « version en cours » et les filtres de compatibilité.
3. **Onglet Plugins** — quasi gratuit après Mods.
4. **[4.1](#41-rapport-de-progression) progression** — nécessaire avant tout ce
   qui télécharge en masse.
5. **[5.1](#51-packs-curseforge-à-manifeste) packs CurseForge à manifeste** —
   dépend du point précédent.
6. **Onglet Worlds** — plus risqué (écrasement de monde), à faire après
   [4.3](#43-sauvegarde-avant-écrasement).
7. **[5.3](#53-forge-et-neoforge-dans-longlet-versions) Forge/NeoForge** — le
   script existe déjà, c'est surtout de l'extraction.
