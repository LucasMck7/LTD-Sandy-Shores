# 🤖 Bot Discord — LTD Sandy Shores

## Fonctionnalités
| Commande | Description |
|---|---|
| `/stock` | Niveaux des stations en temps réel |
| `/service` | Vendeurs actuellement en service |
| `/redistrib` | Voir les correspondances factures → stations |

**Automatique :**
- Rôle Discord assigné → crée l'employé sur le site (profil vide)
- Rôle Discord retiré → désactive l'employé
- Message "Redistribution N°X 2500L" dans le salon → soustrait les litres de la station
- Activité du bot mise à jour avec le stock global toutes les 5 min

---

## ⚠️ Avant tout : Régénère le token Discord
Le token a été exposé dans une conversation — **il est compromis**.
1. Discord Developer Portal → ton application → Bot
2. **Reset Token** → copie le nouveau token
3. Mets-le dans ton `.env`

---

## Déploiement sur Hostinger SSH

### Étape 1 — Connexion SSH
```bash
ssh -p 65002 u524909271@145.79.20.68
```
*(Mot de passe = ton mot de passe Hostinger hPanel)*

### Étape 2 — Vérifier Node.js
```bash
node --version
```
Si absent ou < 18 :
```bash
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.7/install.sh | bash
source ~/.bashrc
nvm install 18 && nvm use 18 && nvm alias default 18
```

### Étape 3 — Uploader le bot
**Via Gestionnaire de fichiers Hostinger :**
1. Zippe le dossier `ltd-bot`
2. hPanel → Fichiers → Gestionnaire de fichiers
3. Navigue vers `/home/u524909271/`
4. Upload le ZIP → clic droit → Extraire

**Via SFTP (FileZilla) :**
- Hôte : `145.79.20.68` | Port : `65002`
- Login : `u524909271` | Mot de passe : ton mot de passe SSH

### Étape 4 — Configurer le bot
```bash
cd ~/ltd-bot
cp .env.example .env
nano .env
```
Remplis tous les IDs de rôles Discord.

**Obtenir un ID de rôle :**
Discord → ton serveur → Paramètres → Rôles → clic droit sur le rôle → Copier l'identifiant

### Étape 5 — Installer les dépendances
```bash
npm install
```

### Étape 6 — Installer PM2 et démarrer
```bash
npm install -g pm2
pm2 start index.js --name ltd-bot
pm2 save
pm2 startup   # copie-colle la commande affichée
```

### Étape 7 — Vérifier
```bash
pm2 status        # doit afficher "online"
pm2 logs ltd-bot  # vérifier pas d'erreur
```

---

## Permissions Discord requises pour le bot

Dans Discord Developer Portal → OAuth2 → URL Generator :
- Scopes : `bot`, `applications.commands`
- Bot Permissions : `Manage Roles`, `Read Messages`, `Send Messages`, `Read Message History`, `Add Reactions`

**Important :** le rôle du bot doit être **au-dessus** des rôles qu'il gère dans la hiérarchie Discord.

---

## Configuration des redistributions

Admin du site → onglet **🤖 Bot** :
- Associer chaque N° de redistribution à une station
- Le bot lit le salon `1441586751310270495` en temps réel

Formats reconnus :
- `Redistribution N°14 - 2500L`
- `Redistribution n°3 2000 litres`
- `Redistribution N°7 1500 L`

---

## Commandes PM2
```bash
pm2 restart ltd-bot   # Redémarrer après mise à jour
pm2 stop ltd-bot      # Arrêter
pm2 logs ltd-bot      # Logs en direct
pm2 monit             # Monitoring
```
