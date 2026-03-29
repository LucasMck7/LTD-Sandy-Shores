<?php
// ============================================================
//  api.php — LTD Sandy Shores — API unifiée
//  Corrigé : bugs critiques, actions manquantes, sécurité
// ============================================================
require_once 'config.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Secret');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

$action = $_GET['action'] ?? '';
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

switch ($action) {

// ══════════════════════════════════════
//  PARTICULIER — COMMANDES
// ══════════════════════════════════════

// [FIX] Alias save_order_particulier + save_order (les deux fonctionnent)
case 'save_order':
case 'save_order_particulier':
    try {
        $pdo = getDB();
        $b = $body;
        // Validation champs obligatoires
        $prenom = trim($b['prenom'] ?? '');
        $nom    = trim($b['nom'] ?? '');
        $tel    = trim($b['tel'] ?? '');
        $items  = $b['products'] ?? $b['items'] ?? [];
        $total  = (float)($b['total'] ?? 0);
        if (empty($prenom) || empty($nom) || empty($tel)) {
            jsonResponse(['success'=>false,'error'=>'Champs obligatoires manquants (prenom, nom, tel)'],400);
            break;
        }
        if (empty($items)) {
            jsonResponse(['success'=>false,'error'=>'La commande ne contient aucun produit'],400);
            break;
        }
        if ($total <= 0) {
            jsonResponse(['success'=>false,'error'=>'Total invalide'],400);
            break;
        }
        // Génère ref depuis initiales+tel si fourni, sinon aléatoire
        if (!empty($b['ref'])) {
            $ref = $b['ref'];
        } else {
            $ref = 'CMD-' . strtoupper(substr(md5(uniqid()), 0, 6));
        }
        // Normalise heure : supporte hdebut+hfin OU heure seule
        $heure = !empty($b['hdebut']) ? (($b['hdebut']??'').' — '.($b['hfin']??'')) : ($b['heure']??'');
        // Champs supplémentaires stockés en note
        $extras = [];
        if (!empty($b['paiement']))      $extras[] = 'Paiement: '.$b['paiement'];
        if (!empty($b['cashPersonnes'])) $extras[] = 'Personnes: '.$b['cashPersonnes'];
        if (!empty($b['discount']))      $extras[] = 'Réduction: -$'.$b['discount'];
        if (!empty($b['shipping']))      $extras[] = 'Frais livraison: +$'.$b['shipping'];
        if (!empty($b['foretOfferts']))  $extras[] = 'Forêts offertes: x'.$b['foretOfferts'];
        $note = implode(' | ', $extras);
        if (!empty($b['note'])) $note = $b['note'].($note ? ' | '.$note : '');

        $orderId = null;
        $pdo->prepare("INSERT INTO ss_orders (ref,date_commande,prenom,nom,tel,iban,adresse,`date`,heure,note,items,total,status) VALUES (?,NOW(),?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$ref,$prenom,$nom,$tel,$b['iban']??'',$b['adresse']??'',$b['date']??'',$heure,$note,json_encode($items),$total,'pending']);
        $orderId = $pdo->lastInsertId();
        // Notifier le bot Discord
        $notifyData = [
            'type' => 'new_order_particulier',
            'order' => [
                'ref' => $ref, 'id' => $ref,
                'prenom' => $prenom, 'nom' => $nom, 'tel' => $tel,
                'adresse' => $b['adresse']??'', 'date' => $b['date']??'',
                'heure' => $heure, 'note' => $note,
                'items' => $items, 'total' => $total,
                'paiement' => $b['paiement']??'', 'status' => 'pending',
            ],
            'channelId' => null,
        ];
        try {
            $ch2 = curl_init('http://72.62.181.67:3001/notify');
            curl_setopt_array($ch2,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($notifyData),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>3,CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Bot-Secret: LTDSandyShores2025xK9pZm3qR77']]);
            curl_exec($ch2); curl_close($ch2);
        } catch(Exception $ne){}
        jsonResponse(['success'=>true,'ref'=>$ref,'id'=>$orderId]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

// [FIX] get_orders_particulier — était sans auth (RGPD), maintenant protégé
// [FIX] Alias get_orders_particulier → get_orders
case 'get_orders':
case 'get_orders_particulier':
    checkSecret();
    try {
        $pdo = getDB();
        $rows = $pdo->query("SELECT * FROM ss_orders ORDER BY date_commande DESC")->fetchAll();
        foreach ($rows as &$r) { $r['items'] = json_decode($r['items']??'[]', true) ?? []; }
        jsonResponse(['success'=>true,'orders'=>$rows]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'mark_delivered':
    try {
        getDB()->prepare("UPDATE ss_orders SET status='delivered' WHERE id=?")->execute([$body['id']]);
        jsonResponse(['success'=>true]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

// [NEW] mark_delivered_particulier — cherche par ref (pas id)
case 'mark_delivered_particulier':
    checkSecret();
    try {
        $pdo = getDB();
        $ref = $body['ref'] ?? '';
        $livreur = $body['livreur'] ?? '';
        $pdo->prepare("UPDATE ss_orders SET status='delivered', note=CONCAT(IFNULL(note,''),' | Livré par: ',?) WHERE ref=?")
            ->execute([$livreur, $ref]);
        jsonResponse(['success'=>true]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'delete_order':
    try {
        getDB()->prepare("DELETE FROM ss_orders WHERE id=?")->execute([$body['id']]);
        jsonResponse(['success'=>true]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

// [NEW] delete_order_particulier — cherche par ref
case 'delete_order_particulier':
    checkSecret();
    try {
        getDB()->prepare("DELETE FROM ss_orders WHERE ref=?")->execute([$body['ref']??'']);
        jsonResponse(['success'=>true]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

// ══════════════════════════════════════
//  PARTICULIER — PRODUITS
// ══════════════════════════════════════

case 'get_products':
    try {
        $row = getDB()->query("SELECT data FROM ss_products ORDER BY id DESC LIMIT 1")->fetch();
        jsonResponse(['success'=>true,'products'=> $row ? json_decode($row['data'],true) : []]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'get_products_particulier':
    try {
        $row = getDB()->query("SELECT data FROM ss_products ORDER BY id DESC LIMIT 1")->fetch();
        $all = $row ? json_decode($row['data'],true) : [];
        $part = array_values(array_filter($all, function($p){ return ($p['type']??'particulier')==='particulier'; }));
        jsonResponse(['success'=>true,'products'=>$part]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'get_products_entreprise':
    try {
        $row = getDB()->query("SELECT data FROM ss_products ORDER BY id DESC LIMIT 1")->fetch();
        $all = $row ? json_decode($row['data'],true) : [];
        $ent = array_values(array_filter($all, function($p){ return ($p['type']??'')==='entreprise'; }));
        jsonResponse(['success'=>true,'products'=>$ent]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'save_products':
    try {
        $pdo = getDB();
        $products = $body['products'] ?? [];
        $existing = $pdo->query("SELECT id FROM ss_products LIMIT 1")->fetch();
        if ($existing) { $pdo->prepare("UPDATE ss_products SET data=? WHERE id=?")->execute([json_encode($products),$existing['id']]); }
        else { $pdo->prepare("INSERT INTO ss_products(data) VALUES(?)")->execute([json_encode($products)]); }
        jsonResponse(['success'=>true]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

// [NEW] save_products_particulier — alias protégé
case 'save_products_particulier':
    checkSecret();
    try {
        $pdo = getDB();
        $products = $body['products'] ?? [];
        $existing = $pdo->query("SELECT id FROM ss_products LIMIT 1")->fetch();
        if ($existing) { $pdo->prepare("UPDATE ss_products SET data=? WHERE id=?")->execute([json_encode($products),$existing['id']]); }
        else { $pdo->prepare("INSERT INTO ss_products(data) VALUES(?)")->execute([json_encode($products)]); }
        jsonResponse(['success'=>true]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'reset_products':
    try {
        getDB()->exec("DELETE FROM ss_products");
        jsonResponse(['success'=>true]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

// [NEW] reset_products_particulier — alias protégé
case 'reset_products_particulier':
    checkSecret();
    try {
        getDB()->exec("DELETE FROM ss_products");
        jsonResponse(['success'=>true]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

// ══════════════════════════════════════
//  ENTREPRISE
// ══════════════════════════════════════

case 'get_companies':
    try {
        $row = getDB()->query("SELECT data FROM ent_state ORDER BY id DESC LIMIT 1")->fetch();
        $state = $row ? json_decode($row['data'],true) : [];
        $companies = $state['companies'] ?? [];
        jsonResponse(['success'=>true,'companies'=>$companies]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'save_companies':
    checkSecret();
    try {
        $pdo = getDB();
        $companies = $body['companies'] ?? [];
        $existing = $pdo->query("SELECT id,data FROM ent_state ORDER BY id DESC LIMIT 1")->fetch();
        if($existing){
            $state = json_decode($existing['data'],true) ?: [];
            $state['companies'] = $companies;
            $pdo->prepare("UPDATE ent_state SET data=? WHERE id=?")->execute([json_encode($state),$existing['id']]);
        } else {
            $state = ['companies'=>$companies];
            $pdo->prepare("INSERT INTO ent_state(data) VALUES(?)")->execute([json_encode($state)]);
        }
        jsonResponse(['success'=>true]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'ent_get_data':
    try {
        $row = getDB()->query("SELECT data FROM ent_state ORDER BY id DESC LIMIT 1")->fetch();
        jsonResponse(['success'=>true,'data'=> $row ? json_decode($row['data'],true) : null]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

// [FIX] ent_save_data — était $body['data'] (vide si entreprise.html envoie le state direct)
// Maintenant supporte les deux modes : avec ou sans clé 'data'
case 'ent_save_data':
    checkSecret();
    try {
        $pdo = getDB();
        $existing = $pdo->query("SELECT id FROM ent_state LIMIT 1")->fetch();
        // entreprise.html envoie le state directement (sans clé 'data')
        // L'ancien code faisait $body['data'] ?? [] ce qui écrasait tout avec []
        $state = isset($body['data']) ? $body['data'] : $body;
        $d = json_encode($state);
        if ($existing) { $pdo->prepare("UPDATE ent_state SET data=? WHERE id=?")->execute([$d,$existing['id']]); }
        else { $pdo->prepare("INSERT INTO ent_state(data) VALUES(?)")->execute([$d]); }
        jsonResponse(['success'=>true]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'ent_save_draft':
    try {
        $pdo = getDB();
        $pdo->exec("CREATE TABLE IF NOT EXISTS ent_drafts (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, login VARCHAR(100) NOT NULL UNIQUE, draft LONGTEXT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX idx_login (login)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        try { $pdo->exec("ALTER TABLE ent_drafts MODIFY COLUMN draft LONGTEXT NULL"); } catch(Exception $ex2){}
        $login = $body['login'] ?? '';
        $draft = json_encode($body['draft'] ?? []);
        $row = $pdo->prepare("SELECT id FROM ent_drafts WHERE login=?");
        $row->execute([$login]);
        $existing = $row->fetch();
        if ($existing) { $pdo->prepare("UPDATE ent_drafts SET draft=?,updated_at=NOW() WHERE login=?")->execute([$draft,$login]); }
        else { $pdo->prepare("INSERT INTO ent_drafts(login,draft,updated_at) VALUES(?,?,NOW())")->execute([$login,$draft]); }
        jsonResponse(['success'=>true]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'ent_get_draft':
    try {
        $pdo = getDB();
        // Créer table sans ON UPDATE (compatible toutes versions MariaDB)
        $pdo->exec("CREATE TABLE IF NOT EXISTS ent_drafts (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            login VARCHAR(100) NOT NULL UNIQUE,
            draft LONGTEXT,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $login = $body['login'] ?? '';
        if (!$login) { jsonResponse(['success'=>true,'draft'=>null]); break; }
        $stmt = $pdo->prepare("SELECT draft FROM ent_drafts WHERE login=? LIMIT 1");
        $stmt->execute([$login]);
        $row = $stmt->fetch();
        $draft = ($row && $row['draft']) ? json_decode($row['draft'], true) : null;
        jsonResponse(['success'=>true,'draft'=>$draft]);
    } catch (PDOException $e) {
        jsonResponse(['success'=>true,'draft'=>null]); // Retourner null plutôt que 500
    }
    break;

case 'ent_clear_draft':
    try {
        $pdo = getDB();
        $pdo->exec("CREATE TABLE IF NOT EXISTS ent_drafts (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, login VARCHAR(100) NOT NULL UNIQUE, draft LONGTEXT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX idx_login (login)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        try { $pdo->exec("ALTER TABLE ent_drafts MODIFY COLUMN draft LONGTEXT NULL"); } catch(Exception $ex2){}
        $pdo->prepare("DELETE FROM ent_drafts WHERE login=?")->execute([$body['login']??'']);
        jsonResponse(['success'=>true]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

// [NEW] ent_changer_statut — change le statut d'une commande entreprise dans ent_state
case 'ent_changer_statut':
    checkSecret();
    try {
        $pdo = getDB();
        $row_id = $body['id'] ?? $body['ref'] ?? '';
        $statut = $body['statut'] ?? $body['status'] ?? 'En attente';
        $statusMap = ['progress'=>'En préparation','done'=>'Prête','livree'=>'Livrée','annule'=>'Annulée','new'=>'Nouvelle','pending'=>'En attente'];
        if(isset($statusMap[$statut])) $statut = $statusMap[$statut];

        // 1. Créer table de suivi des statuts bot
        $pdo->exec("CREATE TABLE IF NOT EXISTS ent_order_status (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id VARCHAR(50) NOT NULL,
            statut VARCHAR(100) NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_order (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 2. Sauvegarder le statut dans la table dédiée
        $pdo->prepare("INSERT INTO ent_order_status(order_id,statut) VALUES(?,?) ON DUPLICATE KEY UPDATE statut=?,updated_at=NOW()")
            ->execute([$row_id, $statut, $statut]);

        // 3. Aussi mettre à jour ent_state si disponible
        $existing = $pdo->query("SELECT id,data FROM ent_state ORDER BY id DESC LIMIT 1")->fetch();
        if ($existing) {
            $state = json_decode($existing['data'], true) ?: [];
            $orders = $state['orders'] ?? [];
            foreach ($orders as &$o) {
                $oid = $o['id'] ?? $o['row'] ?? '';
                if ((string)$oid === (string)$row_id) {
                    $o['statut'] = $statut;
                    break;
                }
            }
            $state['orders'] = $orders;
            $pdo->prepare("UPDATE ent_state SET data=? WHERE id=?")->execute([json_encode($state), $existing['id']]);
        }

        jsonResponse(['success'=>true]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'ent_get_order_status':
    // Retourne les statuts mis à jour par le bot
    try {
        $pdo = getDB();
        $pdo->exec("CREATE TABLE IF NOT EXISTS ent_order_status (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id VARCHAR(50) NOT NULL,
            statut VARCHAR(100) NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_order (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $rows = $pdo->query("SELECT order_id, statut, updated_at FROM ent_order_status ORDER BY updated_at DESC")->fetchAll();
        jsonResponse(['success'=>true,'statuts'=>$rows]);
    } catch(PDOException $e){ jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

// [NEW] ent_repondre_commande — ajoute un message admin à une commande entreprise
case 'ent_repondre_commande':
    checkSecret();
    try {
        $pdo = getDB();
        $row_id = (int)($body['id'] ?? 0);
        $message = $body['message'] ?? '';
        $existing = $pdo->query("SELECT id,data FROM ent_state ORDER BY id DESC LIMIT 1")->fetch();
        if (!$existing) { jsonResponse(['success'=>false,'error'=>'État introuvable'],404); break; }
        $state = json_decode($existing['data'], true) ?: [];
        $orders = $state['orders'] ?? [];
        $found = false;
        foreach ($orders as &$o) {
            if ((int)($o['id'] ?? $o['row'] ?? -1) === $row_id) {
                $o['reponseAdmin'] = $message;
                $found = true;
                break;
            }
        }
        if (!$found) { jsonResponse(['success'=>false,'error'=>'Commande introuvable'],404); break; }
        $state['orders'] = $orders;
        $pdo->prepare("UPDATE ent_state SET data=? WHERE id=?")->execute([json_encode($state), $existing['id']]);
        jsonResponse(['success'=>true]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

// ══════════════════════════════════════
//  EMPLOYE — AUTH
// ══════════════════════════════════════

case 'emp_login':
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT * FROM emp_employes WHERE login_id=? AND ddn=?");
        $stmt->execute([$body['login']??'',$body['password']??'']);
        $emp = $stmt->fetch();
        if ($emp) {
            $adminPostes = ['Patron','Co-Patron','RH','Responsable Pompiste','Responsable des Ventes'];
            $isAdmin = in_array($emp['poste'], $adminPostes);
            $vendeurPostes = ['Responsable des Ventes','Vendeur Expérimenté','Vendeur Intermédiaire','Vendeur Novice'];
            $isVendeur = in_array($emp['poste'], $vendeurPostes);
            // Ajouter colonnes optionnelles si elles existent
            $avatar = $emp['avatar_url'] ?? '';
            $bio    = $emp['bio']        ?? '';
            $tel    = $emp['telephone']  ?? '';
            jsonResponse(['success'=>true,'isAdmin'=>$isAdmin,'isVendeur'=>$isVendeur,'employe'=>['id'=>$emp['id'],'prenom'=>$emp['prenom'],'nom'=>$emp['nom'],'poste'=>$emp['poste'],'login_id'=>$emp['login_id'],'ddn'=>$emp['ddn'],'numero'=>$emp['numero'],'avatar_url'=>$avatar,'bio'=>$bio,'telephone'=>$tel]]);
        } else {
            jsonResponse(['success'=>false,'error'=>'Identifiants incorrects'],401);
        }
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

// ══════════════════════════════════════
//  EMPLOYE — STATIONS
// ══════════════════════════════════════

case 'get_stations_public':
    try {
        $pdo = getDB();
        // Vérifier si photo_url existe — évite le try/catch imbriqué qui masquait les vraies erreurs
        $hasPhoto = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='emp_stations' AND COLUMN_NAME='photo_url'")->fetchColumn() > 0;
        $cols = $hasPhoto
            ? "id,nom,volume_actuel,volume_max,prix_vente,dernier_rav,photo_url"
            : "id,nom,volume_actuel,volume_max,prix_vente,dernier_rav";
        $rows = $pdo->query("SELECT $cols FROM emp_stations ORDER BY nom ASC")->fetchAll();
        jsonResponse(['success'=>true,'stations'=>$rows]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'emp_get_stations':
    try {
        $pdo = getDB();
        $rows = $pdo->query("SELECT * FROM emp_stations ORDER BY nom ASC")->fetchAll();
        foreach ($rows as &$r) {
            $r['volumeActuel']   = (float)$r['volume_actuel'];
            $r['volumeMax']      = (float)$r['volume_max'];
            $r['volumeManquant'] = $r['volumeMax'] - $r['volumeActuel'];
            $r['prixVente']      = (float)$r['prix_vente'];
            $r['prixAchat']      = (float)($r['prix_achat'] ?? 0);
            $r['photo_url']      = $r['photo_url'] ?? null;
        }
        jsonResponse(['success'=>true,'stations'=>$rows]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'emp_declarer_ravit':
    try {
        $pdo = getDB();
        $b = $body;
        $stmt = $pdo->prepare("SELECT * FROM emp_stations WHERE id=?");
        $stmt->execute([$b['station_id']]);
        $st = $stmt->fetch();
        if (!$st) { jsonResponse(['success'=>false,'error'=>'Station introuvable'],404); break; }
        if (isset($b['nouveau'])) {
            $newVol = min((float)$st['volume_max'], (float)$b['nouveau']);
            $litres = $newVol - (float)($b['ancien']??$st['volume_actuel']);
        } else {
            $litres = (float)($b['litres']??0);
            $newVol = min((float)$st['volume_max'], (float)$st['volume_actuel'] + $litres);
        }
        if ($litres < 0) $litres = 0;
        $pdo->prepare("UPDATE emp_stations SET volume_actuel=?, dernier_rav=NOW() WHERE id=?")->execute([$newVol,$b['station_id']]);
        $pdo->prepare("INSERT INTO emp_historique(station_id,station_nom,employe,litres_remplis,type_op,created_at) VALUES(?,?,?,?,'ravitaillement',NOW())")->execute([$b['station_id'],$st['nom'],$b['employe']??'',$litres]);
        jsonResponse(['success'=>true,'newVolume'=>$newVol,'litresRemplis'=>$litres]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'emp_maj_volume':
    try {
        getDB()->prepare("UPDATE emp_stations SET volume_actuel=? WHERE id=?")->execute([$body['volume']??0,$body['station_id']]);
        jsonResponse(['success'=>true]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'emp_majvol':
    // Alias utilisé par le bot Discord pour mettre à jour le volume d'une station
    checkSecret();
    try {
        $pdo = getDB();
        $stationId   = intval($body['stationId'] ?? 0);
        $nouveauVol  = floatval($body['nouveauVolume'] ?? 0);
        $ancienVol   = floatval($body['ancienVolume'] ?? 0);
        if(!$stationId){ jsonResponse(['success'=>false,'error'=>'stationId requis'],400); break; }
        // Mettre à jour le volume
        $pdo->prepare("UPDATE emp_stations SET volume_actuel=? WHERE id=?")->execute([$nouveauVol, $stationId]);
        // Enregistrer dans l'historique des opérations
        $st = $pdo->prepare("SELECT nom FROM emp_stations WHERE id=?");
        $st->execute([$stationId]);
        $station = $st->fetch();
        $stNom = $station['nom'] ?? 'Station #'.$stationId;
        $litres = round($ancienVol - $nouveauVol);
        if($litres > 0) {
            $pdo->prepare("INSERT INTO emp_historique(station_id,station_nom,employe,litres_remplis,type_op,created_at) VALUES(?,?,'Bot Discord',?,'redistribution',NOW())")
                ->execute([$stationId, $stNom, $litres]);
        }
        jsonResponse(['success'=>true]);
    } catch(PDOException $e){ jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'emp_get_historique':
    try {
        $rows = getDB()->query("SELECT * FROM emp_historique ORDER BY created_at DESC LIMIT 200")->fetchAll();
        jsonResponse(['success'=>true,'historique'=>$rows]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'emp_suppr_histo':
    checkSecret();
    try {
        getDB()->prepare("DELETE FROM emp_historique WHERE id=?")->execute([$body['id']]);
        jsonResponse(['success'=>true]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'emp_modif_histo':
    checkSecret();
    try {
        getDB()->prepare("UPDATE emp_historique SET litres_remplis=?,employe=? WHERE id=?")->execute([$body['litres']??0,$body['employe']??'',$body['id']]);
        jsonResponse(['success'=>true]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

// ══════════════════════════════════════
//  EMPLOYE — VEHICULES
// ══════════════════════════════════════

case 'emp_get_vehicules':
    try {
        $rows = getDB()->query("SELECT * FROM emp_vehicules ORDER BY modele ASC")->fetchAll();
        jsonResponse(['success'=>true,'vehicules'=>$rows]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'emp_prendre_veh':
    try {
        $pdo = getDB();
        $pdo->prepare("UPDATE emp_vehicules SET statut='en_service',utilisateur=? WHERE id=?")->execute([$body['employe']??'',$body['id']]);
        $pdo->prepare("INSERT INTO emp_veh_historique(vehicule_id,utilisateur,action) VALUES(?,?,'prise_en_charge')")->execute([$body['id'],$body['employe']??'']);
        jsonResponse(['success'=>true]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'emp_rendre_veh':
    try {
        $pdo = getDB();
        $pdo->prepare("UPDATE emp_vehicules SET statut='Disponible',utilisateur=NULL WHERE id=?")->execute([$body['id']]);
        $pdo->prepare("INSERT INTO emp_veh_historique(vehicule_id,utilisateur,action) VALUES(?,?,'rendu')")->execute([$body['id'],$body['employe']??'']);
        jsonResponse(['success'=>true]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'emp_ajouter_veh':
    checkSecret();
    try {
        $pdo = getDB();
        $pdo->prepare("INSERT INTO emp_vehicules(id_custom,modele,plaque,statut) VALUES(?,?,?,'Disponible')")->execute([$body['id_custom']??'',$body['modele']??'',$body['plaque']??'']);
        jsonResponse(['success'=>true,'id'=>$pdo->lastInsertId()]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'emp_modif_veh':
    checkSecret();
    try {
        getDB()->prepare("UPDATE emp_vehicules SET modele=?,plaque=? WHERE id=?")->execute([$body['modele']??'',$body['plaque']??'',$body['id']]);
        jsonResponse(['success'=>true]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'emp_suppr_veh':
    checkSecret();
    try {
        getDB()->prepare("DELETE FROM emp_vehicules WHERE id=?")->execute([$body['id']]);
        jsonResponse(['success'=>true]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'emp_histo_veh':
    try {
        $rows = getDB()->query("SELECT * FROM emp_veh_historique ORDER BY created_at DESC LIMIT 100")->fetchAll();
        jsonResponse(['success'=>true,'historique'=>$rows]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

// ══════════════════════════════════════
//  EMPLOYE — DEMANDES
// ══════════════════════════════════════

case 'emp_mes_demandes':
    try {
        $stmt = getDB()->prepare("SELECT * FROM emp_demandes WHERE employe_login=? ORDER BY created_at DESC");
        $stmt->execute([$body['login']??'']);
        jsonResponse(['success'=>true,'demandes'=>$stmt->fetchAll()]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'emp_toutes_demandes':
    checkSecret();
    try {
        $rows = getDB()->query("SELECT * FROM emp_demandes ORDER BY created_at DESC")->fetchAll();
        jsonResponse(['success'=>true,'demandes'=>$rows]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'emp_creer_demande':
    try {
        $pdo = getDB();
        $b = $body;
        $pdo->prepare("INSERT INTO emp_demandes(employe_login,employe_nom,type_demande,description,prix,photo_b64,statut,created_at) VALUES(?,?,?,?,?,?,'pending',NOW())")->execute([$b['login']??'',$b['nom']??'',$b['type']??'',$b['description']??'',$b['prix']??0,$b['photo']??'']);
        jsonResponse(['success'=>true,'id'=>$pdo->lastInsertId()]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'emp_traiter_demande':
    checkSecret();
    try {
        getDB()->prepare("UPDATE emp_demandes SET statut=?,note_traitement=?,traite_le=NOW() WHERE id=?")->execute([$body['statut']??'pending',$body['note']??'',$body['id']]);
        jsonResponse(['success'=>true]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'emp_suppr_demande':
    checkSecret();
    try {
        getDB()->prepare("DELETE FROM emp_demandes WHERE id=?")->execute([$body['id']]);
        jsonResponse(['success'=>true]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

// ══════════════════════════════════════
//  EMPLOYE — GESTION RH
// ══════════════════════════════════════

case 'emp_get_employes':
    try {
        $rows = getDB()->query("SELECT * FROM emp_employes ORDER BY numero ASC")->fetchAll();
        jsonResponse(['success'=>true,'employes'=>$rows]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'emp_ajouter_emp':
    checkSecret();
    try {
        $pdo = getDB();
        $b = $body;
        $pdo->prepare("INSERT INTO emp_employes(numero,prenom,nom,poste,login_id,ddn) VALUES(?,?,?,?,?,?)")->execute([$b['numero']??1,$b['prenom']??'',$b['nom']??'',$b['poste']??'',$b['login_id']??'',$b['ddn']??'']);
        jsonResponse(['success'=>true,'id'=>$pdo->lastInsertId()]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'emp_modif_emp':
    checkSecret();
    try {
        $b = $body;
        if (!empty($b['ddn'])) {
            getDB()->prepare("UPDATE emp_employes SET numero=?,prenom=?,nom=?,poste=?,login_id=?,ddn=? WHERE id=?")
                ->execute([$b['numero']??1,$b['prenom']??'',$b['nom']??'',$b['poste']??'',$b['login_id']??'',$b['ddn'],$b['id']]);
        } else {
            getDB()->prepare("UPDATE emp_employes SET numero=?,prenom=?,nom=?,poste=?,login_id=? WHERE id=?")
                ->execute([$b['numero']??1,$b['prenom']??'',$b['nom']??'',$b['poste']??'',$b['login_id']??'',$b['id']]);
        }
        jsonResponse(['success'=>true]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'emp_suppr_emp':
    checkSecret();
    try {
        getDB()->prepare("DELETE FROM emp_employes WHERE id=?")->execute([$body['id']]);
        jsonResponse(['success'=>true]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'emp_stats':
    try {
        $stations = getDB()->query("SELECT volume_actuel,volume_max FROM emp_stations")->fetchAll();
        $totalMax=0; $totalActuel=0;
        foreach($stations as $s){ $totalMax+=(float)$s['volume_max']; $totalActuel+=(float)$s['volume_actuel']; }
        jsonResponse(['success'=>true,'stats'=>['totalMax'=>$totalMax,'totalActuel'=>$totalActuel,'nbStations'=>count($stations)]]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'emp_classement':
    try {
        $p=$body['period']??'week';
        $w='';
        if($p==='week') $w="WHERE created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)";
        elseif($p==='month') $w="WHERE created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)";
        $r=getDB()->query("SELECT employe,COUNT(*) as nb_ops,SUM(litres_remplis) as total_litres FROM emp_historique $w GROUP BY employe ORDER BY total_litres DESC LIMIT 20")->fetchAll();
        $cls=[];
        foreach($r as $x){$cls[]=['prenom'=>$x['employe'],'nom'=>'','nbOps'=>(int)$x['nb_ops'],'totalLitres'=>(float)$x['total_litres']];}
        jsonResponse(['success'=>true,'classement'=>$cls]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

// [NEW] emp_get_toutes_commandes_emp — retourne les commandes entreprise pour l'admin employé
case 'emp_get_toutes_commandes_emp':
    checkSecret();
    try {
        $row = getDB()->query("SELECT data FROM ent_state ORDER BY id DESC LIMIT 1")->fetch();
        $state = $row ? json_decode($row['data'], true) : [];
        $orders = $state['orders'] ?? [];
        // Ajouter le champ row = id pour compatibilité JS
        foreach ($orders as &$o) {
            if (!isset($o['row'])) $o['row'] = $o['id'] ?? 0;
        }
        jsonResponse(['success'=>true,'commandes'=>array_values($orders)]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

// ══════════════════════════════════════
//  ADMIN
// ══════════════════════════════════════

case 'admin_get_products':
    checkSecret();
    try {
        $row = getDB()->query("SELECT data FROM ss_products ORDER BY id DESC LIMIT 1")->fetch();
        jsonResponse(['success'=>true,'products'=> $row ? json_decode($row['data'],true) : []]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'admin_save_products':
    checkSecret();
    try {
        $pdo = getDB();
        $products = $body['products'] ?? [];
        $existing = $pdo->query("SELECT id FROM ss_products LIMIT 1")->fetch();
        if ($existing) { $pdo->prepare("UPDATE ss_products SET data=? WHERE id=?")->execute([json_encode($products),$existing['id']]); }
        else { $pdo->prepare("INSERT INTO ss_products(data) VALUES(?)")->execute([json_encode($products)]); }
        jsonResponse(['success'=>true]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'admin_get_employes':
    checkSecret();
    try {
        jsonResponse(['success'=>true,'employes'=>getDB()->query("SELECT * FROM emp_employes ORDER BY numero ASC")->fetchAll()]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'admin_save_employe':
    checkSecret();
    try {
        $pdo = getDB();
        $b = $body;
        if (!empty($b['id'])) {
            if (!empty($b['ddn'])) {
                $pdo->prepare("UPDATE emp_employes SET numero=?,prenom=?,nom=?,poste=?,login_id=?,ddn=?,iban=? WHERE id=?")
                    ->execute([$b['numero']??1,$b['prenom']??'',$b['nom']??'',$b['poste']??'',$b['login_id']??'',$b['ddn'],$b['iban']??'',$b['id']]);
            } else {
                $pdo->prepare("UPDATE emp_employes SET numero=?,prenom=?,nom=?,poste=?,login_id=?,iban=? WHERE id=?")
                    ->execute([$b['numero']??1,$b['prenom']??'',$b['nom']??'',$b['poste']??'',$b['login_id']??'',$b['iban']??'',$b['id']]);
            }
        } else {
            $pdo->prepare("INSERT INTO emp_employes(numero,prenom,nom,poste,login_id,ddn,iban) VALUES(?,?,?,?,?,?,?)")
                ->execute([$b['numero']??1,$b['prenom']??'',$b['nom']??'',$b['poste']??'',$b['login_id']??'',$b['ddn']??'',$b['iban']??'']);
        }
        jsonResponse(['success'=>true]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'admin_delete_employe':
    checkSecret();
    try {
        getDB()->prepare("DELETE FROM emp_employes WHERE id=?")->execute([$body['id']]);
        jsonResponse(['success'=>true]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

// [NEW] Annuaire LTD — contacts visibles par les entreprises partenaires
// [NEW] Promotions — visibles sur l'accueil, gérées depuis l'admin
// ══ PRISE / FIN DE SERVICE ══
case 'emp_prendre_service':
    try {
        $pdo = getDB();
        $pdo->exec("CREATE TABLE IF NOT EXISTS emp_service (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            login_id VARCHAR(100) NOT NULL,
            prenom VARCHAR(100) NOT NULL,
            nom VARCHAR(100) NOT NULL,
            poste VARCHAR(100) DEFAULT '',
            station VARCHAR(150) DEFAULT '',
            debut DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            fin DATETIME NULL,
            actif TINYINT(1) NOT NULL DEFAULT 1,
            ca_debut DECIMAL(10,2) DEFAULT 0,
            ca_fin DECIMAL(10,2) DEFAULT 0,
            INDEX idx_actif (actif),
            INDEX idx_login (login_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Ajouter colonnes si manquantes
        foreach(['fin DATETIME NULL','ca_debut DECIMAL(10,2) DEFAULT 0','ca_fin DECIMAL(10,2) DEFAULT 0'] as $col){
            $cn = explode(' ',$col)[0];
            $ex = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='emp_service' AND COLUMN_NAME='$cn'")->fetchColumn();
            if(!$ex) $pdo->exec("ALTER TABLE emp_service ADD COLUMN $col");
        }
        $login   = $body['login_id'] ?? '';
        $prenom  = $body['prenom']   ?? '';
        $nom     = $body['nom']      ?? '';
        $poste   = $body['poste']    ?? '';
        $station = $body['station']  ?? 'LTD Sandy Shores';
        $ca_debut= floatval($body['ca_debut'] ?? 0);
        // Fin de l'éventuel service en cours
        $pdo->prepare("UPDATE emp_service SET actif=0, fin=NOW() WHERE login_id=? AND actif=1")->execute([$login]);
        $pdo->prepare("INSERT INTO emp_service(login_id,prenom,nom,poste,station,debut,actif,ca_debut,ca_fin) VALUES(?,?,?,?,?,NOW(),1,?,0)")
            ->execute([$login,$prenom,$nom,$poste,$station,$ca_debut]);
        jsonResponse(['success'=>true]);
    } catch(PDOException $e){ jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'emp_fin_service':
    try {
        $pdo = getDB();
        $login = $body['login_id'] ?? '';
        $ca_fin = floatval($body['ca_fin'] ?? 0);
        $pdo->prepare("UPDATE emp_service SET actif=0, fin=NOW(), ca_fin=? WHERE login_id=? AND actif=1")->execute([$ca_fin,$login]);
        jsonResponse(['success'=>true]);
    } catch(PDOException $e){ jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'emp_update_profil':
    try {
        $pdo = getDB();
        $login = $body['login_id'] ?? '';
        if(empty($login)) { jsonResponse(['success'=>false,'error'=>'login requis'],400); break; }
        // Créer colonnes si absentes
        foreach(['avatar_url VARCHAR(500) DEFAULT \'\'','bio VARCHAR(300) DEFAULT \'\'','telephone VARCHAR(50) DEFAULT \'\''] as $col){
            $colName = explode(' ',$col)[0];
            $exists = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='emp_employes' AND COLUMN_NAME='$colName'")->fetchColumn();
            if(!$exists) $pdo->exec("ALTER TABLE emp_employes ADD COLUMN $col");
        }
        $avatar = $body['avatar_url'] ?? '';
        $bio    = $body['bio']        ?? '';
        $tel    = $body['telephone']  ?? '';
        $pdo->prepare("UPDATE emp_employes SET avatar_url=?,bio=?,telephone=? WHERE login_id=?")
            ->execute([$avatar,$bio,$tel,$login]);
        jsonResponse(['success'=>true]);
    } catch(PDOException $e){ jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'admin_get_services':
    // Tous les services (admin seulement)
    try {
        $pdo = getDB();
        $pdo->exec("CREATE TABLE IF NOT EXISTS emp_service (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, login_id VARCHAR(100) NOT NULL, prenom VARCHAR(100) NOT NULL, nom VARCHAR(100) NOT NULL, poste VARCHAR(100) DEFAULT '', station VARCHAR(150) DEFAULT '', debut DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, fin DATETIME NULL, actif TINYINT(1) NOT NULL DEFAULT 1, ca_debut DECIMAL(10,2) DEFAULT 0, ca_fin DECIMAL(10,2) DEFAULT 0, INDEX idx_actif (actif)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $rows = $pdo->query("SELECT * FROM emp_service ORDER BY debut DESC LIMIT 200")->fetchAll();
        jsonResponse(['success'=>true,'services'=>$rows]);
    } catch(PDOException $e){ jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'admin_save_service':
    checkSecret();
    try {
        $pdo = getDB();
        $b = $body;
        if(!empty($b['id'])){
            $pdo->prepare("UPDATE emp_service SET prenom=?,nom=?,poste=?,station=?,debut=?,fin=?,ca_debut=?,ca_fin=?,actif=? WHERE id=?")
                ->execute([$b['prenom']??'',$b['nom']??'',$b['poste']??'',$b['station']??'',$b['debut']??null,$b['fin']??null,floatval($b['ca_debut']??0),floatval($b['ca_fin']??0),intval($b['actif']??0),$b['id']]);
        } else {
            $pdo->prepare("INSERT INTO emp_service(login_id,prenom,nom,poste,station,debut,fin,actif,ca_debut,ca_fin) VALUES(?,?,?,?,?,?,?,?,?,?)")
                ->execute([$b['login_id']??'',$b['prenom']??'',$b['nom']??'',$b['poste']??'',$b['station']??'LTD Sandy Shores',$b['debut']??date('Y-m-d H:i:s'),$b['fin']??null,intval($b['actif']??1),floatval($b['ca_debut']??0),floatval($b['ca_fin']??0)]);
        }
        jsonResponse(['success'=>true]);
    } catch(PDOException $e){ jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'admin_delete_service':
    checkSecret();
    try {
        getDB()->prepare("DELETE FROM emp_service WHERE id=?")->execute([$body['id']??0]);
        jsonResponse(['success'=>true]);
    } catch(PDOException $e){ jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;




// ══ FACTURES EMPLOYÉS ══════════════════════════════════

case 'emp_factures_create_table':
case 'emp_factures_get':
case 'emp_factures_add':
case 'emp_factures_stats':
case 'emp_factures_classement':
    try {
        $pdo = getDB();
        // Créer table si absente
        $pdo->exec("CREATE TABLE IF NOT EXISTS emp_factures (
            id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            facture_id      VARCHAR(50)  DEFAULT '',
            emetteur_nom    VARCHAR(100) DEFAULT '',
            emetteur_discord VARCHAR(30) DEFAULT '',
            destinataire_nom VARCHAR(100) DEFAULT '',
            destinataire_discord VARCHAR(30) DEFAULT '',
            montant         DECIMAL(12,2) DEFAULT 0,
            raison          VARCHAR(255) DEFAULT '',
            statut          VARCHAR(50)  DEFAULT 'confirmée',
            paiement        VARCHAR(50)  DEFAULT '',
            date_facture    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            discord_msg_id  VARCHAR(30)  DEFAULT '',
            channel_id      VARCHAR(30)  DEFAULT '',
            raw_text        TEXT,
            created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_msg (discord_msg_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        if ($action === 'emp_factures_add') {
            checkSecret();
            $b = $body;
            $msgId = $b['discord_msg_id'] ?? '';
            // Déduplication par message Discord
            if ($msgId) {
                $exists = $pdo->prepare("SELECT id FROM emp_factures WHERE discord_msg_id=?");
                $exists->execute([$msgId]);
                if ($exists->fetch()) { jsonResponse(['success'=>true,'duplicate'=>true]); break; }
            }
            $pdo->prepare("INSERT INTO emp_factures 
                (facture_id,emetteur_nom,emetteur_discord,destinataire_nom,destinataire_discord,montant,raison,statut,paiement,date_facture,discord_msg_id,channel_id,raw_text)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $b['facture_id']??'',
                    $b['emetteur_nom']??'',
                    $b['emetteur_discord']??'',
                    $b['destinataire_nom']??'',
                    $b['destinataire_discord']??'',
                    floatval($b['montant']??0),
                    $b['raison']??'',
                    $b['statut']??'confirmée',
                    $b['paiement']??'',
                    $b['date_facture']??date('Y-m-d H:i:s'),
                    $msgId,
                    $b['channel_id']??'',
                    $b['raw_text']??'',
                ]);
            jsonResponse(['success'=>true,'id'=>$pdo->lastInsertId()]);
            break;
        }

        if ($action === 'emp_factures_get') {
            $discordId = $body['discord_id'] ?? '';
            $loginId   = $body['login_id']   ?? '';
            $bodySecret = $body['secret']    ?? '';
            $limit     = intval($body['limit'] ?? 200);
            $offset    = intval($body['offset'] ?? 0);
            // Autoriser si secret dans le body (pour l'espace employé responsable)
            if ($bodySecret === API_SECRET) { $discordId = ''; $loginId = ''; }
            if ($discordId) {
                // Employé voit ses propres factures via Discord ID
                $rows = $pdo->prepare("SELECT * FROM emp_factures 
                    WHERE emetteur_discord=? OR destinataire_discord=?
                    ORDER BY date_facture DESC LIMIT $limit OFFSET $offset");
                $rows->execute([$discordId,$discordId]);
            } elseif ($loginId) {
                // Employé sans Discord — chercher via login
                $emp = $pdo->prepare("SELECT discord_id FROM emp_employes WHERE login_id=? LIMIT 1");
                $emp->execute([$loginId]);
                $empRow = $emp->fetch();
                $did = $empRow ? ($empRow['discord_id']??'') : '';
                if ($did) {
                    $rows = $pdo->prepare("SELECT * FROM emp_factures WHERE emetteur_discord=? OR destinataire_discord=? ORDER BY date_facture DESC LIMIT $limit OFFSET $offset");
                    $rows->execute([$did,$did]);
                } else {
                    jsonResponse(['success'=>true,'factures'=>[],'total'=>0]);
                    break;
                }
            } else {
                checkSecret();
                $rows = $pdo->prepare("SELECT * FROM emp_factures ORDER BY date_facture DESC LIMIT $limit OFFSET $offset");
                $rows->execute([]);
            }
            $total = $pdo->query("SELECT COUNT(*) FROM emp_factures")->fetchColumn();
            jsonResponse(['success'=>true,'factures'=>$rows->fetchAll(),'total'=>$total]);
            break;
        }

        if ($action === 'emp_factures_stats') {
            checkSecret();
            $discord = $body['discord_id'] ?? '';
            $since   = $body['since'] ?? '2000-01-01';
            $where   = $discord ? "WHERE emetteur_discord='$discord' AND date_facture>='$since'" : "WHERE date_facture>='$since'";
            $stats = $pdo->query("SELECT 
                COUNT(*) as nb_factures,
                SUM(montant) as ca_total,
                AVG(montant) as ca_moyen,
                MAX(montant) as max_facture,
                MIN(montant) as min_facture
                FROM emp_factures $where")->fetch();
            jsonResponse(['success'=>true,'stats'=>$stats]);
            break;
        }

        if ($action === 'emp_factures_classement') {
            checkSecret();
            $since   = $body['since'] ?? '2000-01-01';
            $orderBy = $body['order_by'] ?? 'ca_total';
            $allowed = ['ca_total','nb_factures','ca_moyen','max_facture'];
            if (!in_array($orderBy, $allowed)) $orderBy = 'ca_total';

            // Récupérer les stats par employé (émetteur)
            $rows = $pdo->query("SELECT 
                emetteur_discord,
                emetteur_nom,
                COUNT(*) as nb_factures,
                SUM(montant) as ca_total,
                AVG(montant) as ca_moyen,
                MAX(montant) as max_facture
                FROM emp_factures
                WHERE date_facture >= '$since'
                AND emetteur_discord != ''
                GROUP BY emetteur_discord, emetteur_nom
                ORDER BY $orderBy DESC
                LIMIT 50")->fetchAll();

            // Enrichir avec données de service (sessions + heures)
            $result = [];
            foreach($rows as $r){
                $disc = $r['emetteur_discord'];
                $svc = $pdo->prepare("SELECT COUNT(*) as sessions,
                    SUM(TIMESTAMPDIFF(MINUTE, debut, COALESCE(fin, NOW()))) as minutes_total
                    FROM emp_service WHERE actif=0 AND fin IS NOT NULL");
                // Chercher par discord_id dans emp_employes
                $emp = $pdo->prepare("SELECT * FROM emp_employes WHERE discord_id=? LIMIT 1");
                $emp->execute([$disc]);
                $empData = $emp->fetch();
                if($empData){
                    $svc2 = $pdo->prepare("SELECT COUNT(*) as sessions,
                        SUM(GREATEST(0, ca_fin-ca_debut)) as ca_service,
                        SUM(TIMESTAMPDIFF(MINUTE, debut, COALESCE(fin, NOW()))) as minutes
                        FROM emp_service WHERE login_id=? AND actif=0 AND fin IS NOT NULL");
                    $svc2->execute([$empData['login_id']]);
                    $svcData = $svc2->fetch();
                    $r['sessions']      = intval($svcData['sessions']??0);
                    $r['heures_service'] = round(($svcData['minutes']??0)/60, 1);
                    $r['poste']         = $empData['poste']??'';
                    $r['login_id']      = $empData['login_id']??'';
                }
                $result[] = $r;
            }
            jsonResponse(['success'=>true,'classement'=>$result]);
            break;
        }

        jsonResponse(['success'=>true,'message'=>'Table créée']);
    } catch(PDOException $e){ jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;


case 'emp_factures_update_statut':
    checkSecret();
    try {
        $pdo = getDB();
        $fid = $body['facture_id'] ?? '';
        $statut = $body['statut'] ?? '';
        $date_p = $body['date_paiement'] ?? null;
        if(!$fid || !$statut) { jsonResponse(['success'=>false,'error'=>'Paramètres manquants']); break; }
        $sql = "UPDATE emp_factures SET statut=?";
        $params = [$statut];
        if($date_p) { $sql .= ", date_paiement=?"; $params[] = $date_p; }
        $sql .= " WHERE facture_id=?";
        $params[] = $fid;
        $pdo->prepare($sql)->execute($params);
        jsonResponse(['success'=>true]);
    } catch(PDOException $e){ jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;


case 'emp_factures_pending_save':
    checkSecret();
    try {
        $pdo = getDB();
        $pdo->exec("CREATE TABLE IF NOT EXISTS emp_factures_pending (
            facture_id VARCHAR(50) PRIMARY KEY,
            msg_id VARCHAR(30) NOT NULL,
            channel_id VARCHAR(30) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $fid = $body['facture_id'] ?? '';
        $mid = $body['msg_id'] ?? '';
        $cid = $body['channel_id'] ?? '';
        if(!$fid||!$mid) { jsonResponse(['success'=>false]); break; }
        $pdo->prepare("INSERT INTO emp_factures_pending(facture_id,msg_id,channel_id) VALUES(?,?,?) ON DUPLICATE KEY UPDATE msg_id=?,channel_id=?")->execute([$fid,$mid,$cid,$mid,$cid]);
        jsonResponse(['success'=>true]);
    } catch(PDOException $e){ jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'emp_factures_pending_get':
    try {
        $pdo = getDB();
        $pdo->exec("CREATE TABLE IF NOT EXISTS emp_factures_pending (
            facture_id VARCHAR(50) PRIMARY KEY,
            msg_id VARCHAR(30) NOT NULL,
            channel_id VARCHAR(30) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $rows = $pdo->query("SELECT * FROM emp_factures_pending")->fetchAll();
        jsonResponse(['success'=>true,'pending'=>$rows]);
    } catch(PDOException $e){ jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'emp_factures_pending_delete':
    checkSecret();
    try {
        $pdo = getDB();
        $pdo->prepare("DELETE FROM emp_factures_pending WHERE facture_id=?")->execute([$body['facture_id']??'']);
        jsonResponse(['success'=>true]);
    } catch(PDOException $e){ jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;


case 'emp_factures_pending_list':
    try {
        $pdo = getDB();
        // Retourne toutes les factures en attente (statut pas payé/annulé)
        $pdo->exec("CREATE TABLE IF NOT EXISTS emp_factures (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            facture_id VARCHAR(50) DEFAULT '',
            emetteur_nom VARCHAR(100) DEFAULT '',
            emetteur_discord VARCHAR(30) DEFAULT '',
            destinataire_nom VARCHAR(100) DEFAULT '',
            destinataire_discord VARCHAR(30) DEFAULT '',
            montant DECIMAL(12,2) DEFAULT 0,
            raison VARCHAR(255) DEFAULT '',
            statut VARCHAR(50) DEFAULT 'En attente',
            paiement VARCHAR(50) DEFAULT '',
            date_facture DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            discord_msg_id VARCHAR(30) DEFAULT '',
            channel_id VARCHAR(30) DEFAULT '',
            raw_text TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_msg (discord_msg_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $rows = $pdo->query("SELECT facture_id, discord_msg_id, statut FROM emp_factures 
            WHERE statut NOT LIKE '%pay%' AND statut NOT LIKE '%annul%'
            AND discord_msg_id != ''
            ORDER BY date_facture DESC LIMIT 100")->fetchAll();
        jsonResponse(['success'=>true,'factures'=>$rows]);
    } catch(PDOException $e){ jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

// ══ ENDPOINTS NOUVELLES COMMANDES BOT ══
case 'get_order_by_ref':
    checkSecret();
    try {
        $ref = $body['ref'] ?? '';
        $row = getDB()->prepare("SELECT * FROM ss_orders WHERE ref=? LIMIT 1");
        $row->execute([$ref]);
        $order = $row->fetch();
        if($order){ $order['items']=json_decode($order['items']??'[]',true); jsonResponse(['success'=>true,'order'=>$order]); }
        else jsonResponse(['success'=>false,'error'=>'Commande introuvable']);
    } catch(PDOException $e){ jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'get_companies':
    try {
        $row = getDB()->query("SELECT data FROM ent_state ORDER BY id DESC LIMIT 1")->fetch();
        $state = $row ? json_decode($row['data'],true) : [];
        jsonResponse(['success'=>true,'companies'=>$state['companies']??[]]);
    } catch(PDOException $e){ jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'bot_get_classement':
    checkSecret();
    try {
        $pdo = getDB();
        $periode = $body['periode'] ?? 'week';
        $since = ['week'=>date('Y-m-d',strtotime('-7 days')),'month'=>date('Y-m-01'),'all'=>'2000-01-01'][$periode] ?? date('Y-m-01');
        $rows = $pdo->query("SELECT login_id, prenom, nom, poste,
            SUM(GREATEST(0, ca_fin - ca_debut)) AS ca_total,
            COUNT(*) AS sessions
            FROM emp_service
            WHERE actif=0 AND fin IS NOT NULL AND debut >= '$since'
            GROUP BY login_id, prenom, nom, poste
            ORDER BY ca_total DESC LIMIT 10")->fetchAll();
        jsonResponse(['success'=>true,'classement'=>$rows]);
    } catch(PDOException $e){ jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'get_employes_search':
    checkSecret();
    try {
        $q = '%'.($body['q']??'').'%';
        $rows = getDB()->prepare("SELECT * FROM emp_employes WHERE prenom LIKE ? OR nom LIKE ? OR login_id LIKE ? LIMIT 5");
        $rows->execute([$q,$q,$q]);
        jsonResponse(['success'=>true,'employes'=>$rows->fetchAll()]);
    } catch(PDOException $e){ jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'bot_get_archived':
    try {
        $pdo = getDB();
        $pdo->exec("CREATE TABLE IF NOT EXISTS bot_archived (id VARCHAR(50) PRIMARY KEY, archived_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $rows = $pdo->query("SELECT id FROM bot_archived")->fetchAll(PDO::FETCH_COLUMN);
        jsonResponse(['success'=>true,'ids'=>$rows]);
    } catch(PDOException $e){ jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'bot_mark_archived':
    checkSecret();
    try {
        $pdo = getDB();
        $pdo->exec("CREATE TABLE IF NOT EXISTS bot_archived (id VARCHAR(50) PRIMARY KEY, archived_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->prepare("INSERT IGNORE INTO bot_archived(id) VALUES(?)")->execute([$body['id']??'']);
        jsonResponse(['success'=>true]);
    } catch(PDOException $e){ jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;


case 'emp_link_discord':
    checkSecret();
    try {
        $pdo = getDB();
        $id = intval($body['id'] ?? 0);
        $did = $body['discord_id'] ?? '';
        if(!$id || !$did){ jsonResponse(['success'=>false,'error'=>'id et discord_id requis'],400); break; }
        // Ajouter colonne si absente
        $has = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='emp_employes' AND COLUMN_NAME='discord_id'")->fetchColumn();
        if(!$has) $pdo->exec("ALTER TABLE emp_employes ADD COLUMN discord_id VARCHAR(30) DEFAULT '' AFTER login_id");
        $pdo->prepare("UPDATE emp_employes SET discord_id=? WHERE id=?")->execute([$did, $id]);
        jsonResponse(['success'=>true]);
    } catch(PDOException $e){ jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;


case 'bot_get_msg_ids':
    try {
        $pdo = getDB();
        $pdo->exec("CREATE TABLE IF NOT EXISTS bot_meta (k VARCHAR(100) PRIMARY KEY, v LONGTEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $row = $pdo->prepare("SELECT v FROM bot_meta WHERE k='msg_ids' LIMIT 1");
        $row->execute();
        $r = $row->fetch();
        $data = $r ? json_decode($r['v'], true) : [];
        jsonResponse(['success'=>true,'data'=>$data??[]]);
    } catch(PDOException $e){ jsonResponse(['success'=>true,'data'=>[]]); }
    break;

case 'bot_save_msg_ids':
    checkSecret();
    try {
        $pdo = getDB();
        $pdo->exec("CREATE TABLE IF NOT EXISTS bot_meta (k VARCHAR(100) PRIMARY KEY, v LONGTEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $v = json_encode($body['data'] ?? []);
        $pdo->prepare("INSERT INTO bot_meta(k,v) VALUES('msg_ids',?) ON DUPLICATE KEY UPDATE v=?")->execute([$v,$v]);
        jsonResponse(['success'=>true]);
    } catch(PDOException $e){ jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;


case 'ent_debug_orders':
    checkSecret();
    try {
        $pdo = getDB();
        $existing = $pdo->query("SELECT id,data FROM ent_state ORDER BY id DESC LIMIT 1")->fetch();
        if(!$existing){ jsonResponse(['success'=>false,'error'=>'Pas de ent_state']); break; }
        $state = json_decode($existing['data'],true)?:[];
        $orders = $state['orders']??[];
        $ids = array_map(function($o){ return ['id'=>$o['id']??'','row'=>$o['row']??'']; }, array_slice($orders,0,10));
        jsonResponse(['success'=>true,'count'=>count($orders),'ids'=>$ids]);
    } catch(PDOException $e){ jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

// ══ BOT CONTRÔLE ══
case 'bot_control':
    checkSecret();
    try {
        $action = $body['action'] ?? '';
        $port   = getenv('BOT_NOTIFY_PORT') ?: '3001';
        $secret = 'LTDSandyShores2025xK9pZm3qR77';
        $url    = 'http://72.62.181.67:'.$port.'/'.ltrim($body['endpoint'] ?? $action, '/');
        $method = in_array($action, ['restart','stop','notify']) ? 'POST' : 'GET';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => $method==='POST' ? json_encode($body['data'] ?? []) : null,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json','X-Bot-Secret: '.$secret],
        ]);
        $resp     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);
        if ($err) { jsonResponse(['success'=>false,'error'=>'Bot injoignable: '.$err]); break; }
        $data = json_decode($resp, true);
        jsonResponse(['success'=>true,'code'=>$httpCode,'data'=>$data]);
    } catch(Exception $e){ jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

// ══ MEMBRES DISCORD (via bot) ══
case 'bot_get_members':
    checkSecret();
    try {
        $port   = getenv('BOT_NOTIFY_PORT') ?: '3001';
        $secret = 'LTDSandyShores2025xK9pZm3qR77';
        $ch = curl_init('http://72.62.181.67:'.$port.'/members');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_HTTPHEADER=>['X-Bot-Secret: '.$secret]]);
        $resp = curl_exec($ch); curl_close($ch);
        $data = json_decode($resp, true);
        jsonResponse($data ?: ['success'=>false,'error'=>'Bot injoignable']);
    } catch(Exception $e){ jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

// ══ EMPLOYÉ PAR DISCORD ID ══
case 'bot_get_employe_by_discord':
    try {
        $pdo = getDB();
        $did = $body['discord_id'] ?? '';
        if(!$did){ jsonResponse(['success'=>false,'error'=>'discord_id requis'],400); break; }
        $hasCol = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='emp_employes' AND COLUMN_NAME='discord_id'")->fetchColumn();
        if(!$hasCol){ jsonResponse(['success'=>false,'error'=>'Colonne discord_id absente']); break; }
        $stmt = $pdo->prepare("SELECT * FROM emp_employes WHERE discord_id=? LIMIT 1");
        $stmt->execute([$did]);
        $emp = $stmt->fetch();
        jsonResponse($emp ? ['success'=>true,'employe'=>$emp] : ['success'=>false,'error'=>'Employé non trouvé']);
    } catch(PDOException $e){ jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

// ══ CONFIG BOT DISCORD ══
case 'bot_get_config':
    try {
        $pdo = getDB();
        // Table dédiée pour la config bot — indépendante de ent_state
        $pdo->exec("CREATE TABLE IF NOT EXISTS bot_config (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            k VARCHAR(100) NOT NULL UNIQUE,
            v LONGTEXT NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $row = $pdo->prepare("SELECT v FROM bot_config WHERE k='main' LIMIT 1");
        $row->execute();
        $r = $row->fetch();
        $config = $r ? (json_decode($r['v'],true) ?: ['redistribMap'=>[]]) : ['redistribMap'=>[]];
        jsonResponse(['success'=>true,'config'=>$config]);
    } catch(PDOException $e){ jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'bot_save_config':
    checkSecret();
    try {
        $pdo = getDB();
        $pdo->exec("CREATE TABLE IF NOT EXISTS bot_config (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            k VARCHAR(100) NOT NULL UNIQUE,
            v LONGTEXT NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $v = json_encode($body['config'] ?? []);
        $pdo->prepare("INSERT INTO bot_config(k,v) VALUES('main',?) ON DUPLICATE KEY UPDATE v=?,updated_at=NOW()")
            ->execute([$v,$v]);
        jsonResponse(['success'=>true]);
    } catch(PDOException $e){ jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'emp_get_service_history':
    try {
        $pdo = getDB();
        $login = $body['login_id'] ?? $body['login'] ?? '';
        $pdo->exec("CREATE TABLE IF NOT EXISTS emp_service (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, login_id VARCHAR(100) NOT NULL, prenom VARCHAR(100) NOT NULL, nom VARCHAR(100) NOT NULL, poste VARCHAR(100) DEFAULT '', station VARCHAR(150) DEFAULT '', debut DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, fin DATETIME NULL, actif TINYINT(1) NOT NULL DEFAULT 1, INDEX idx_login (login_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Ajouter colonne fin si absente
        $hasFin = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='emp_service' AND COLUMN_NAME='fin'")->fetchColumn();
        if(!$hasFin) $pdo->exec("ALTER TABLE emp_service ADD COLUMN fin DATETIME NULL");
        $rows = $pdo->prepare("SELECT debut,fin,station,actif,ca_debut,ca_fin FROM emp_service WHERE login_id=? ORDER BY debut DESC LIMIT 100");
        $rows->execute([$login]);
        jsonResponse(['success'=>true,'history'=>$rows->fetchAll()]);
    } catch(PDOException $e){ jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'get_service_actif':
    try {
        $pdo = getDB();
        // Créer la table emp_service sans index inline (évite erreurs si table modifiée)
        $pdo->exec("CREATE TABLE IF NOT EXISTS emp_service (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            login_id VARCHAR(100) NOT NULL,
            prenom VARCHAR(100) NOT NULL,
            nom VARCHAR(100) NOT NULL,
            poste VARCHAR(100) DEFAULT '',
            station VARCHAR(150) DEFAULT '',
            debut DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            actif TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Toujours faire le SELECT simple d'abord, puis enrichir si possible
        $rows = $pdo->query("SELECT login_id, prenom, nom, poste, station, debut,
            '' AS avatar_url, '' AS bio, '' AS telephone
            FROM emp_service WHERE actif=1 ORDER BY debut ASC")->fetchAll();
        // Tenter d'enrichir avec avatar/bio/tel si emp_employes existe et a ces colonnes
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM emp_employes LIKE 'avatar_url'")->fetchColumn();
            if($cols !== false){
                $rows = $pdo->query("SELECT s.login_id, s.prenom, s.nom, s.poste, s.station, s.debut,
                    COALESCE(e.avatar_url,'') AS avatar_url,
                    COALESCE(e.bio,'') AS bio,
                    COALESCE(e.telephone,'') AS telephone
                    FROM emp_service s
                    LEFT JOIN emp_employes e ON e.login_id = s.login_id
                    WHERE s.actif=1 ORDER BY s.debut ASC")->fetchAll();
            }
        } catch(Exception $ex){ /* emp_employes absent ou colonnes manquantes — on garde le résultat simple */ }
        jsonResponse(['success'=>true,'vendeurs'=>$rows]);
    } catch(Exception $e){ jsonResponse(['success'=>true,'vendeurs'=>[]]); }
    break;

case 'get_promos':
    try {
        $row = getDB()->query("SELECT data FROM ent_state ORDER BY id DESC LIMIT 1")->fetch();
        $state = $row ? json_decode($row['data'], true) : [];
        $promos = $state['promos'] ?? [];
        jsonResponse(['success'=>true, 'promos'=>$promos]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'admin_get_promos':
    checkSecret();
    try {
        $row = getDB()->query("SELECT data FROM ent_state ORDER BY id DESC LIMIT 1")->fetch();
        $state = $row ? json_decode($row['data'], true) : [];
        jsonResponse(['success'=>true, 'promos'=>$state['promos'] ?? []]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'admin_save_promos':
    checkSecret();
    try {
        $pdo = getDB();
        $promos = $body['promos'] ?? [];
        $existing = $pdo->query("SELECT id,data FROM ent_state ORDER BY id DESC LIMIT 1")->fetch();
        if ($existing) {
            $state = json_decode($existing['data'], true) ?: [];
            $state['promos'] = $promos;
            $pdo->prepare("UPDATE ent_state SET data=? WHERE id=?")->execute([json_encode($state), $existing['id']]);
        } else {
            $pdo->prepare("INSERT INTO ent_state(data) VALUES(?)")->execute([json_encode(['promos'=>$promos])]);
        }
        jsonResponse(['success'=>true]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'admin_get_annuaire':
    checkSecret();
    try {
        $row = getDB()->query("SELECT data FROM ent_state ORDER BY id DESC LIMIT 1")->fetch();
        $state = $row ? json_decode($row['data'], true) : [];
        $contacts = $state['ltdContacts'] ?? [
            ['role'=>'Patron',         'name'=>'Dean DUGGAN',         'phone'=>'', 'note'=>'Disponible en journée'],
            ['role'=>'Co-Patron',      'name'=>'Constantin WHITAKER', 'phone'=>'', 'note'=>'Disponible en journée'],
            ['role'=>'Resp. Pompiste', 'name'=>'Blake MARS',          'phone'=>'', 'note'=>'Responsable stations'],
            ['role'=>'Livraison',      'name'=>'Service LTD',         'phone'=>'', 'note'=>'Suivi commande B2B'],
        ];
        jsonResponse(['success'=>true, 'contacts'=>$contacts]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'admin_save_annuaire':
    checkSecret();
    try {
        $pdo = getDB();
        $contacts = $body['contacts'] ?? [];
        $existing = $pdo->query("SELECT id,data FROM ent_state ORDER BY id DESC LIMIT 1")->fetch();
        if ($existing) {
            $state = json_decode($existing['data'], true) ?: [];
            $state['ltdContacts'] = $contacts;
            $pdo->prepare("UPDATE ent_state SET data=? WHERE id=?")->execute([json_encode($state), $existing['id']]);
        } else {
            $pdo->prepare("INSERT INTO ent_state(data) VALUES(?)")->execute([json_encode(['ltdContacts'=>$contacts])]);
        }
        jsonResponse(['success'=>true]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'admin_get_stations':
    checkSecret();
    try {
        jsonResponse(['success'=>true,'stations'=>getDB()->query("SELECT * FROM emp_stations ORDER BY nom ASC")->fetchAll()]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'admin_save_station':
    checkSecret();
    try {
        $pdo = getDB();
        $b = $body;
        if (!empty($b['id'])) {
            $photo = $b['photo_url'] ?? '';
            $pdo->prepare("UPDATE emp_stations SET nom=?,volume_max=?,prix_vente=?,prix_achat=?,photo_url=? WHERE id=?")->execute([$b['nom'],$b['volume_max']??5000,$b['prix_vente']??1.5,$b['prix_achat']??1.0,$photo,$b['id']]);
        } else {
            $photo = $b['photo_url'] ?? '';
            $pdo->prepare("INSERT INTO emp_stations(nom,volume_actuel,volume_max,prix_vente,prix_achat,photo_url) VALUES(?,0,?,?,?,?)")->execute([$b['nom'],$b['volume_max']??5000,$b['prix_vente']??1.5,$b['prix_achat']??1.0,$photo]);
        }
        jsonResponse(['success'=>true]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'admin_delete_station':
    checkSecret();
    try {
        getDB()->prepare("DELETE FROM emp_stations WHERE id=?")->execute([$body['id']]);
        jsonResponse(['success'=>true]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'admin_get_ent_orders':
    checkSecret();
    try {
        $row = getDB()->query("SELECT data FROM ent_state ORDER BY id DESC LIMIT 1")->fetch();
        $data = $row ? json_decode($row['data'], true) : [];
        $orders = $data['orders'] ?? [];
        jsonResponse(['success'=>true,'orders'=>$orders]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'admin_get_orders':
    checkSecret();
    try {
        $pdo = getDB();
        $rows = $pdo->query("SELECT * FROM ss_orders ORDER BY date_commande DESC")->fetchAll();
        foreach ($rows as &$r) { $r['items'] = json_decode($r['items']??'[]', true) ?? []; }
        jsonResponse(['success'=>true,'orders'=>$rows]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'admin_update_order_status':
    checkSecret();
    try {
        getDB()->prepare("UPDATE ss_orders SET status=? WHERE id=?")->execute([$body['status']??'pending',$body['id']]);
        jsonResponse(['success'=>true]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'admin_get_finances':
    checkSecret();
    try {
        $pdo = getDB();
        $days = (int)($_GET['days'] ?? 7);

        // Filtre période
        $where = $days > 0 ? "WHERE h.created_at >= DATE_SUB(NOW(), INTERVAL $days DAY)" : "";

        // Récupérer l'historique avec prix_vente et prix_achat de la station
        $rows = $pdo->query("
            SELECT
                h.id, h.station_id, h.station_nom, h.employe,
                h.litres_remplis, h.created_at,
                COALESCE(s.prix_vente, 0) AS prix_vente,
                COALESCE(s.prix_achat, 0) AS prix_achat
            FROM emp_historique h
            LEFT JOIN emp_stations s ON s.id = h.station_id
            $where
            ORDER BY h.created_at DESC
        ")->fetchAll();

        // Calculs globaux
        $totalLitres = 0; $totalCA = 0; $totalCout = 0; $nbRavits = 0;
        // Par station
        $byStation = [];
        // Par employé
        $byEmploye = [];

        foreach ($rows as $r) {
            $litres    = (float)$r['litres_remplis'];
            $pv        = (float)$r['prix_vente'];
            $pa        = (float)$r['prix_achat'];
            $ca        = $litres * $pv;
            $cout      = $litres * $pa;
            $benef     = $ca - $cout;
            $stNom     = $r['station_nom'];
            $emp       = $r['employe'] ?: '—';

            $totalLitres += $litres;
            $totalCA     += $ca;
            $totalCout   += $cout;
            $nbRavits++;

            // Par station
            if (!isset($byStation[$stNom])) {
                $byStation[$stNom] = ['litres'=>0,'ca'=>0,'cout'=>0,'benef'=>0,'ravits'=>0,'prix_vente'=>$pv,'prix_achat'=>$pa];
            }
            $byStation[$stNom]['litres'] += $litres;
            $byStation[$stNom]['ca']     += $ca;
            $byStation[$stNom]['cout']   += $cout;
            $byStation[$stNom]['benef']  += $benef;
            $byStation[$stNom]['ravits']++;

            // Par employé
            if (!isset($byEmploye[$emp])) {
                $byEmploye[$emp] = ['litres'=>0,'ca'=>0,'cout'=>0,'benef'=>0,'ravits'=>0];
            }
            $byEmploye[$emp]['litres'] += $litres;
            $byEmploye[$emp]['ca']     += $ca;
            $byEmploye[$emp]['cout']   += $cout;
            $byEmploye[$emp]['benef']  += $benef;
            $byEmploye[$emp]['ravits']++;
        }

        $totalBenef = $totalCA - $totalCout;
        $marge = $totalCA > 0 ? round(($totalBenef / $totalCA) * 100, 1) : 0;

        // Trier par CA décroissant
        uasort($byStation, function($a,$b){ return $b['ca'] <=> $a['ca']; });
        uasort($byEmploye, function($a,$b){ return $b['litres'] <=> $a['litres']; });

        jsonResponse([
            'success'    => true,
            'global'     => [
                'ca'          => round($totalCA, 2),
                'cout'        => round($totalCout, 2),
                'benef'       => round($totalBenef, 2),
                'litres'      => round($totalLitres, 0),
                'ravits'      => $nbRavits,
                'marge'       => $marge,
            ],
            'by_station' => $byStation,
            'by_employe' => $byEmploye,
        ]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'admin_delete_order':
    checkSecret();
    try {
        getDB()->prepare("DELETE FROM ss_orders WHERE id=?")->execute([$body['id']]);
        jsonResponse(['success'=>true]);
    } catch (PDOException $e) { jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

// [REMOVED] debug_employes et fix_login_ids — routes de diagnostic supprimées en prod




// ══ MESSAGE /STOCK — stocke l'ID du message Discord pour édition ══
case 'bot_get_stock_message':
    try {
        $pdo = getDB();
        $pdo->exec("CREATE TABLE IF NOT EXISTS bot_meta (k VARCHAR(100) PRIMARY KEY, v TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $row = $pdo->prepare("SELECT v FROM bot_meta WHERE k=?")->execute(['stock_message_id']) ? $pdo->query("SELECT v FROM bot_meta WHERE k='stock_message_id'")->fetch() : null;
        jsonResponse(['success'=>true, 'data'=>$row ? json_decode($row['v'],true) : null]);
    } catch(PDOException $e){ jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'bot_save_stock_message':
    checkSecret();
    try {
        $pdo = getDB();
        $pdo->exec("CREATE TABLE IF NOT EXISTS bot_meta (k VARCHAR(100) PRIMARY KEY, v TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $v = json_encode($body['data'] ?? []);
        $pdo->prepare("INSERT INTO bot_meta(k,v) VALUES('stock_message_id',?) ON DUPLICATE KEY UPDATE v=?")->execute([$v,$v]);
        jsonResponse(['success'=>true]);
    } catch(PDOException $e){ jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

// ══ HISTORIQUE REDISTRIBUTIONS ══
case 'bot_get_history':
    try {
        $pdo = getDB();
        // Créer table si absente
        $pdo->exec("CREATE TABLE IF NOT EXISTS bot_redistrib_history (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            redistrib_num INT NOT NULL,
            station_id INT NOT NULL,
            station_nom VARCHAR(150) DEFAULT '',
            montant DECIMAL(10,2) DEFAULT 0,
            prix_litre DECIMAL(10,2) DEFAULT 0,
            litres INT DEFAULT 0,
            volume_avant DECIMAL(10,2) DEFAULT 0,
            volume_apres DECIMAL(10,2) DEFAULT 0,
            discord_user VARCHAR(100) DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $limit = intval($body['limit'] ?? 100);
        $rows = $pdo->query("SELECT * FROM bot_redistrib_history ORDER BY created_at DESC LIMIT $limit")->fetchAll();
        jsonResponse(['success'=>true,'history'=>$rows]);
    } catch(PDOException $e){ jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'bot_add_history':
    checkSecret();
    try {
        $pdo = getDB();
        $pdo->exec("CREATE TABLE IF NOT EXISTS bot_redistrib_history (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            redistrib_num INT NOT NULL,
            station_id INT NOT NULL,
            station_nom VARCHAR(150) DEFAULT '',
            montant DECIMAL(10,2) DEFAULT 0,
            prix_litre DECIMAL(10,2) DEFAULT 0,
            litres INT DEFAULT 0,
            volume_avant DECIMAL(10,2) DEFAULT 0,
            volume_apres DECIMAL(10,2) DEFAULT 0,
            discord_user VARCHAR(100) DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $b = $body;
        $pdo->prepare("INSERT INTO bot_redistrib_history(redistrib_num,station_id,station_nom,montant,prix_litre,litres,volume_avant,volume_apres,discord_user) VALUES(?,?,?,?,?,?,?,?,?)")
            ->execute([
                intval($b['redistrib_num']??0),
                intval($b['station_id']??0),
                $b['station_nom']??'',
                floatval($b['montant']??0),
                floatval($b['prix_litre']??0),
                intval($b['litres']??0),
                floatval($b['volume_avant']??0),
                floatval($b['volume_apres']??0),
                $b['discord_user']??'bot',
            ]);
        jsonResponse(['success'=>true,'id'=>$pdo->lastInsertId()]);
    } catch(PDOException $e){ jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

// ══ ENDPOINTS BOT DISCORD ══
case 'bot_create_employe':
    checkSecret();
    try {
        $pdo = getDB();
        $discordId  = $body['discord_id']  ?? '';
        $discordTag = $body['discord_tag'] ?? '';
        $poste      = $body['poste']       ?? '';
        $prenom     = $body['prenom']      ?? $discordTag;
        $nom        = $body['nom']         ?? '(à compléter)';
        $numero     = intval($body['numero'] ?? 0);
        if(!$discordId || !$poste){ jsonResponse(['success'=>false,'error'=>'discord_id et poste requis'],400); break; }
        // Ajouter colonne discord_id si absente
        $hasDid = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='emp_employes' AND COLUMN_NAME='discord_id'")->fetchColumn();
        if(!$hasDid) $pdo->exec("ALTER TABLE emp_employes ADD COLUMN discord_id VARCHAR(30) DEFAULT '' AFTER login_id");
        // Vérifier si l'employé existe déjà
        $exists = $pdo->prepare("SELECT id,login_id FROM emp_employes WHERE discord_id=?");
        $exists->execute([$discordId]);
        $row = $exists->fetch();
        if($row){ jsonResponse(['success'=>false,'already_exists'=>true,'login_id'=>$row['login_id']]); break; }
        // Générer login_id unique
        $base = strtolower(preg_replace('/[^a-z0-9.]/i','',str_replace(' ','.',$prenom)));
        $base = $base ?: 'employe';
        $login_id = $base;
        $suffix = 1;
        while($pdo->prepare("SELECT id FROM emp_employes WHERE login_id=?")->execute([$login_id]) && $pdo->query("SELECT COUNT(*) FROM emp_employes WHERE login_id='$login_id'")->fetchColumn() > 0){
            $login_id = $base.$suffix++; if($suffix>99) break;
        }
        // Numéro auto si 0
        if($numero === 0){
            $max = $pdo->query("SELECT MAX(numero) FROM emp_employes")->fetchColumn();
            $numero = intval($max) + 1;
        }
        $pdo->prepare("INSERT INTO emp_employes(numero,prenom,nom,poste,login_id,discord_id) VALUES(?,?,?,?,?,?)")
            ->execute([$numero,$prenom,$nom,$poste,$login_id,$discordId]);
        jsonResponse(['success'=>true,'login_id'=>$login_id,'id'=>$pdo->lastInsertId()]);
    } catch(PDOException $e){ jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'bot_update_employe_role':
    checkSecret();
    try {
        $pdo = getDB();
        $discordId = $body['discord_id'] ?? '';
        $poste     = $body['poste']      ?? '';
        if(!$discordId || !$poste){ jsonResponse(['success'=>false,'error'=>'params requis'],400); break; }
        $pdo->prepare("UPDATE emp_employes SET poste=? WHERE discord_id=?")->execute([$poste,$discordId]);
        jsonResponse(['success'=>true]);
    } catch(PDOException $e){ jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;

case 'bot_deactivate_employe':
    checkSecret();
    try {
        $pdo = getDB();
        $discordId = $body['discord_id'] ?? '';
        if(!$discordId){ jsonResponse(['success'=>false,'error'=>'discord_id requis'],400); break; }
        // Mettre fin au service actif si en cours
        $pdo->prepare("UPDATE emp_service SET actif=0,fin=NOW() WHERE login_id=(SELECT login_id FROM emp_employes WHERE discord_id=?) AND actif=1")->execute([$discordId]);
        jsonResponse(['success'=>true]);
    } catch(PDOException $e){ jsonResponse(['success'=>false,'error'=>$e->getMessage()],500); }
    break;


// ══ BOT NOTIFY — Envoie une notification au bot Discord ══
case 'bot_notify':
    try {
        $data = $body;
        $botSecret = getenv('LTD_API_SECRET') ?: 'LTDSandyShores2025xK9pZm3qR77';
        $notifyPort = getenv('BOT_NOTIFY_PORT') ?: '3001';
        $url = 'http://72.62.181.67:'.$notifyPort.'/notify';
        // Appel HTTP local vers le bot
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Bot-Secret: '.$botSecret,
            ],
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if($httpCode === 200){
            $result = json_decode($resp, true);
            jsonResponse(['success'=>true,'bot'=>$result]);
        } else {
            // Fallback : le bot est peut-être down, on retourne quand même success
            jsonResponse(['success'=>true,'bot'=>['success'=>false,'error'=>'Bot unavailable (HTTP '.$httpCode.')']]);
        }
    } catch(Exception $e){
        jsonResponse(['success'=>true,'bot'=>['success'=>false,'error'=>$e->getMessage()]]);
    }
    break;

default:
    jsonResponse(['success'=>false,'error'=>'Action inconnue: '.$action],404);
    break;
}
