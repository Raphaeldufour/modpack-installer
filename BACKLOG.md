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
| Onglet Versions — Vanilla, Paper, Purpur, Fabric, Folia, Velocity | ✅ grille par catégories façon mcjars.app — voir note ci-dessous |
| Onglet Modpacks — recherche, versions, providers Modrinth + CurseForge | ✅ |
| Modpacks — install d'un server pack CurseForge | ✅ testé (ATM10) |
| Modpacks — install d'un `.mrpack` Modrinth | ✅ écrit, non testé sur panel |
| Page admin — clé CurseForge | ✅ |
| Installation sans egg ni réinstallation | ✅ |
| Résolution des redirections CDN avant Wings | ✅ |
| `start.sh` généré, heap résolu au démarrage | ✅ |
| Onglet Mods — recherche, installation, suppression | ✅ testé sur panel |
| Onglet Mods — liste des mods installés | ✅ testé, corrigé (voir §1) — le correctif reste à revérifier sur panel |
| État installé — bannière « en cours » sur Versions et Modpacks | ✅ écrit, non testé sur panel |

**Architecture acquise**, à ne pas réinventer pour les onglets suivants :
le panel résout une URL, Wings télécharge et décompresse, le panel réécrit la
commande de démarrage. Le serveur garde son egg.

**Onglet Versions, refonte visuelle — fait.** Le sélecteur à trois `<select>`
plats a été remplacé par une grille de cartes groupées par catégorie
(Recommended / Established / Experimental), sur le modèle de mcjars.app :
`SoftwareRegistry::toArray()` renvoie désormais `category` et
`minecraftVersionCount` en plus de `key`/`label`, et `VersionsSection.tsx`
affiche les cartes avec une icône dessinée en SVG inline (pas de logo hotlinké
— un logo cassé ou bloqué n'a pas sa place dans une grille censée s'afficher
instantanément). La carte du logiciel actuellement installé est marquée
« Running » et la grille s'ouvre déjà pointée dessus. Un bandeau jaune signale
en plus quand le build installé n'est plus le dernier disponible, en
réutilisant l'appel `builds()` déjà exposé par l'API.

**Ce qui n'a délibérément pas été copié : le total de builds par carte.**
Chez mcjars.app, ce chiffre vient d'une base qui indexe depuis longtemps
chaque build jamais publié par chaque logiciel. Cette extension n'a pas cet
historique et ne fait que relayer les API amont en direct — calculer un total
en direct pour Paper, par exemple, coûterait un appel amont par version
Minecraft déjà publiée (44 aujourd'hui) rien que pour afficher la grille. Seul
`minecraftVersionCount` est affiché, qui ne coûte qu'un seul appel déjà mis en
cache 300s par chaque implémentation.

---

## 1. Onglet Mods — fait

Construit : `ModProviderInterface`, providers Modrinth et CurseForge,
`ModProviderRegistry`, `ModInstallService`, `InstalledModsService`,
`ModController`, `ModsSection.tsx`.

**La question ouverte de ce backlog a été tranchée.** Identifier un jar déjà
présent demandait soit un manifeste local de ce que l'extension avait installé,
soit une lecture du fichier. C'est la lecture qui a été retenue :
`getContent` récupère le jar, puis `sha1` interroge Modrinth
(`/v2/version_files`) et l'empreinte murmur2 interroge CurseForge
(`/v1/fingerprints`). Avantage décisif : **les mods posés à la main sont
reconnus**, ce qu'un manifeste local n'aurait jamais fait.

Le prix, à connaître : cela fait transiter chaque jar par le panel, ce que
l'architecture évite partout ailleurs. C'est borné (plafond de 64 Mo par
fichier, mods de quelques Mo) et sans alternative pour de l'identification, mais
c'est la seule exception à la règle — ne pas s'en servir de précédent pour
justifier de faire passer un pack par le panel.

**Testé sur panel, cassé, corrigé.** Un scan sur un pack de la taille d'ATM10
(~250 mods, tous non identifiés juste après l'installation) dépassait le
`max_execution_time` de 30 s de PHP — `Maximum execution time of 30 seconds
exceeded` en plein milieu de `curseForgeFingerprint()`, la liste restant
bloquée sur « Scanning… » indéfiniment. `list()` traite désormais les mods en
attente par lot borné à 15 s réels (`SCAN_TIME_BUDGET_SECONDS`), pas par
nombre fixe : ce qui n'entre pas dans le budget revient marqué
`reason: 'pending_scan'` (un état que `isReusable()` anticipait déjà sans que
rien ne le produise) et n'est **pas** mis en cache, donc l'appel suivant le
reprend sans relire ce qui est déjà identifié. Le contrôleur renvoie
maintenant `{ mods, scanning }` au lieu d'un tableau brut, et l'onglet sonde
toutes les 1,5 s tant que `scanning` est vrai, en affichant les résultats
partiels au fur et à mesure plutôt que de bloquer derrière un spinner unique.

**Amorçage de l'état à l'installation — fait.** Un scan par lots de 15 s reste
long à l'échelle d'un pack de plusieurs centaines de mods : même optimisé, il
faut plusieurs allers-retours pour tout identifier. Plutôt que de laisser le
scan redécouvrir ce que l'extension vient elle-même d'installer,
`InstalledModsService::seedIdentified()` et `::seedFromKnownHashes()` écrivent
l'entrée du cache au moment de l'install, avec l'identité déjà connue :

- **Installation d'un mod seul** (`ModsSection.tsx` → `ModController::install()`
  → `ModInstallService::handle()`) : le pull est en foreground, donc le fichier
  est déjà sur le disque quand l'appel revient. `seedIdentified()` écrit
  directement l'entrée avec les champs d'affichage (nom, icône, version) que
  l'onglet avait déjà à l'écran — aucun aller-retour supplémentaire vers le
  provider.
- **Installation d'un modpack Modrinth** (`ModpackInstallService::applyManifest()`) :
  le format `.mrpack` embarque un `sha1` par fichier dans
  `modrinth.index.json`. `ProviderInterface::manifestFiles()` le remonte
  désormais, et `seedFromKnownHashes()` résout le lot en un seul appel groupé à
  `/v2/version_files` — sans jamais relire un fichier pour le hasher, puisque
  le hash était déjà dans le manifeste. Appelé en toute fin de
  `ModpackInstallService::handle()`, après le jar serveur et l'écriture du
  startup, pour laisser le plus de temps possible aux téléchargements de fond
  (`foreground: false`, plafonnés à 3 en parallèle par Wings) d'atterrir avant
  la vérification. Ce qui n'est pas encore sur le disque à ce moment-là est
  simplement laissé de côté — l'écriture ne vérifie l'entrée réelle du dossier
  qu'au moment d'écrire, jamais en la devinant — et repris par le scan normal.

**Limite explicite : les packs CurseForge autonomes (zip serveur déjà
assemblé, sans manifeste par fichier) ne sont pas couverts.** Il n'existe pas
de hash par fichier à récupérer avant le téléchargement dans ce cas — voir
[5.1](#51-packs-curseforge-à-manifeste). Ces packs continuent de dépendre
entièrement du scan borné à 15 s déjà en place.

Restent ouverts sur cet onglet :

- **Compatibilité version / mods** — comparer la version Minecraft du serveur
  avec celles déclarées par chaque mod. Dépend de
  [4.2](#42-connaître-létat-installé--fait).
- Rien à faire côté cache : `.modpacks-mods-state.json` sur le volume garde
  l'identification par signature d'entrée, et seuls les jars inconnus ou modifiés
  sont relus.

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

**Réutilisation** : Mods étant fait, Plugins est le même service avec un dossier
et des facettes différents. Mais `InstalledModsService` **code `mods/` en dur**,
tout comme le nom de son fichier d'état. Le premier travail de cet onglet est
donc d'extraire le dossier en paramètre plutôt que de dupliquer 500 lignes.

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

### 4.2 Connaître l'état installé — fait

`InstalledStateService` écrit `.modpacks-state.json` à la fin de chaque
installation réussie (`VersionInstallService::handle()` et
`ModpackInstallService::handle()`), et le lit via `GET /state`
(`StateController`). Les onglets Versions et Modpacks affichent tous deux la
bannière « Currently running… » / « Installed… » via un hook partagé
(`components/sections/shared/InstalledState.tsx`), rafraîchie après chaque
install réussie plutôt qu'au rechargement de la page.

Le nom du pack et de la version affichés viennent du frontend (`packName`,
`versionName` dans le corps de `POST /install`) plutôt que d'un second appel
provider : ce sont des libellés cosmétiques, jamais utilisés pour résoudre ou
autoriser quoi que ce soit, donc les faire venir du client — qui les a déjà
depuis sa recherche — évite un aller-retour. Le build réellement résolu (pas
seulement celui demandé) est capturé via `Download::$resolvedBuild`, alimenté
par les quatre implémentations de `SoftwareInterface`, pour qu'« installer la
dernière version » enregistre *laquelle* plutôt que le mot « dernière ».

Reste ouvert :

- **Non testé sur panel.**
- Le signal de « build plus récent disponible » (comparer le build enregistré
  aux derniers de `SoftwareInterface::builds()`) n'est pas encore affiché — la
  donnée existe, l'UI de comparaison reste à écrire.
- Prérequis de la compatibilité mods
  ([1](#1-onglet-mods--fait), point resté ouvert) et du filtrage par version
  dans Plugins.

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
- **L'image Docker n'est changée que si l'egg en déclare une adaptée.** Sur un
  egg Paper qui n'expose que Java 21, installer une 1.12.2 laissera l'image en
  place et le serveur ne démarrera pas — l'onglet le signale, mais ne le corrige
  pas.
- **`INSTALL.md` § Dépannage a un entête de tableau dupliqué et des lignes de
  l'ère egg** (`MODPACK_ID`/`MODPACK_VERSION`, `bash: start.sh`, lignes
  `BLOCKED:`/`WARN:` d'un log de script qui n'existe plus). Repéré en y
  ajoutant l'entrée sur la limite de téléchargements concurrents de Wings,
  pas corrigé — une passe de nettoyage dédiée au fichier serait plus sûre
  qu'un patch au fil de l'eau.

---

## Ordre suggéré

1. **Revérifier sur panel** le correctif de scan par lots de l'onglet Mods
   ([§1](#1-onglet-mods--fait)) et l'[état installé](#42-connaître-létat-installé--fait)
   — ce dernier n'a jamais tourné sur un panel réel.
2. **Onglet Plugins** — quasi gratuit après Mods : même service, dossier
   `plugins/` et facettes différentes.
3. **[4.1](#41-rapport-de-progression) progression** — nécessaire avant tout ce
   qui télécharge en masse.
4. **[5.1](#51-packs-curseforge-à-manifeste) packs CurseForge à manifeste** —
   dépend du point précédent.
5. **Onglet Worlds** — plus risqué (écrasement de monde), à faire après
   [4.3](#43-sauvegarde-avant-écrasement).
6. **[5.3](#53-forge-et-neoforge-dans-longlet-versions) Forge/NeoForge** — le
   script existe déjà, c'est surtout de l'extraction.
