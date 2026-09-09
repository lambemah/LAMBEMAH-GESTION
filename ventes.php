<?php
session_start();
require_once __DIR__ . '/config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Connexion à la base de données impossible.');
}

$conn->set_charset('utf8mb4');

function h($v){
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function money($v){
    return number_format((float)$v, 0, ',', ' ') . ' FG';
}

function cleanText($v){
    return trim(preg_replace('/[|\r\n]+/', ' ', (string)$v));
}

function nextId(mysqli $conn, string $table): int {
    if (!in_array($table, ['produits','mouvements','ventes'], true)) {
        return 1;
    }

    $r = $conn->query("SELECT COALESCE(MAX(id),0)+1 n FROM `$table`");

    return (int)($r->fetch_assoc()['n'] ?? 1);
}

function parseMeta(string $desc): array {
    $o = [];

    foreach (explode('|', $desc) as $p) {
        if (strpos($p, '=') !== false) {
            [$k,$v] = explode('=', $p, 2);
            $o[$k] = trim($v);
        }
    }

    return $o;
}

function invoiceRows(mysqli $conn, string $ref): array {
    $rows = [];

    $safe = $conn->real_escape_string($ref);

    $q = $conn->query("
        SELECT v.*, p.nom, p.categorie
        FROM ventes v
        LEFT JOIN produits p ON p.id = v.produit_id
        WHERE v.description LIKE '%FACTURE=$safe|%'
        ORDER BY v.id ASC
    ");

    while ($q && ($r = $q->fetch_assoc())) {
        $rows[] = $r;
    }

    return $rows;
}

function flash($t,$m){
    $_SESSION['flash'] = [
        'type' => $t,
        'msg'  => $m
    ];
}


/* =========================================================
   IMPRESSION FACTURE
========================================================= */

if(isset($_GET['imprimer'])){

    $ref = cleanText($_GET['imprimer']);
    $rows = invoiceRows($conn,$ref);

    if(!$rows){
        die('Facture introuvable.');
    }

    $meta = parseMeta($rows[0]['description']);

    $total = 0;

    foreach($rows as $r){
        $total += (float)$r['quantite'] * (float)$r['prix_unitaire'];
    }

    $paid = (float)($meta['PAYE'] ?? 0);
    $rest = max(0,$total-$paid);

    $status = $paid <= 0
        ? 'Non payée'
        : ($rest <= 0 ? 'Payée' : 'Partiellement payée');
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title><?=h($ref)?></title>

<style>

:root{
    --blue:#2563eb;
    --navy:#123451;
    --bg:#f5f8fc;
    --line:#e4ebf3;
}

*{
    box-sizing:border-box;
}

body{
    margin:0;
    font:14px Arial,sans-serif;
    background:var(--bg);
    color:#24364b;
}

.paper{
    max-width:900px;
    margin:30px auto;
    background:#fff;
    padding:35px;
    border-radius:12px;
}

.top{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    border-bottom:2px solid #123451;
    padding-bottom:18px;
}

.brand{
    font-size:28px;
    font-weight:800;
    color:#123451;
}

.title{
    text-align:right;
    font-size:20px;
    font-weight:800;
}

.title small{
    font-size:13px;
    font-weight:500;
}

.info{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:15px;
    margin:20px 0;
}

.box{
    border:1px solid var(--line);
    padding:12px;
    border-radius:8px;
}

table{
    width:100%;
    border-collapse:collapse;
}

th,td{
    padding:10px;
    border-bottom:1px solid var(--line);
    text-align:left;
}

th{
    background:#f4f7fb;
}

.num{
    text-align:right;
}

.totals{
    margin-top:20px;
    margin-left:auto;
    max-width:350px;
}

.totals .line{
    display:flex;
    justify-content:space-between;
    padding:8px 0;
}

.grand{
    border-top:2px solid #123451;
    font-size:17px;
    font-weight:800;
}

.status{
    padding:5px 9px;
    border-radius:20px;
    font-weight:700;
}

.actions{
    margin-top:25px;
}

.actions button{
    background:#2563eb;
    color:#fff;
    border:0;
    padding:10px 15px;
    border-radius:8px;
    cursor:pointer;
}

@media(max-width:700px){

    .paper{
        margin:0;
        border-radius:0;
        padding:18px;
    }

    .top{
        display:block;
    }

    .title{
        text-align:left;
        margin-top:15px;
    }

    .info{
        grid-template-columns:1fr;
    }

}

@media print{

    body{
        background:#fff;
    }

    .paper{
        margin:0;
        max-width:none;
        padding:0;
    }

    .actions{
        display:none;
    }

}

</style>
</head>

<body>

<div class="paper">

<div class="top">

<div>
<div class="brand">LAMBEMAH</div>
<div>GESTION • VENTES</div>
</div>

<div class="title">
FACTURE DE VENTE<br>
<small><?=h($ref)?></small>
</div>

</div>

<div class="info">

<div class="box">
<b>Client</b><br>
<?=h($meta['CLIENT'] ?? 'Client comptant')?>
</div>

<div class="box">
<b>Date</b><br>
<?=h(date('d/m/Y H:i',strtotime($rows[0]['date_vente'] ?? 'now')))?>
</div>

</div>

<table>

<thead>

<tr>
<th>Désignation</th>
<th>Catégorie</th>
<th class="num">Qté</th>
<th class="num">PVU</th>
<th class="num">Montant</th>
</tr>

</thead>

<tbody>

<?php foreach($rows as $r):

$m = (float)$r['quantite'] * (float)$r['prix_unitaire'];

?>

<tr>

<td><?=h($r['nom'] ?? 'Article')?></td>

<td><?=h($r['categorie'] ?? '')?></td>

<td class="num"><?=h($r['quantite'])?></td>

<td class="num"><?=money($r['prix_unitaire'])?></td>

<td class="num"><?=money($m)?></td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

<div class="totals">

<div class="line">
<span>Total facture</span>
<b><?=money($total)?></b>
</div>

<div class="line">
<span>Déjà encaissé</span>
<b><?=money($paid)?></b>
</div>

<div class="line">
<span>Reste client</span>
<b><?=money($rest)?></b>
</div>

<div class="line grand">
<span>Statut</span>
<span class="status"><?=h($status)?></span>
</div>

</div>

<div class="actions">
<button onclick="window.print()">
🖨️ Imprimer / Enregistrer en PDF
</button>
</div>

</div>

</body>
</html>

<?php
exit;
}


/* =========================================================
   TRAITEMENTS POST
========================================================= */

if($_SERVER['REQUEST_METHOD']==='POST'){

try{


/* =========================================================
   ENREGISTRER / MODIFIER UNE FACTURE
========================================================= */

if(isset($_POST['save_vente'])){

    $client = cleanText($_POST['client'] ?? 'Client comptant');

    $editRef = cleanText($_POST['edit_ref'] ?? '');

    $ids    = $_POST['produit_id'] ?? [];
    $qtys   = $_POST['quantite'] ?? [];
    $prices = $_POST['prix_unitaire'] ?? [];

    $lines = [];

    for($i=0;$i<count($ids);$i++){

        $pid = (int)$ids[$i];
        $q   = (int)($qtys[$i] ?? 0);
        $p   = (float)($prices[$i] ?? 0);

        if($pid > 0 && $q > 0 && $p > 0){

            $lines[] = [
                'pid'=>$pid,
                'q'=>$q,
                'p'=>$p
            ];

        }
    }

    if(!$lines){
        throw new Exception('Ajoutez au moins un article.');
    }


    /* =====================================================
       MODE MODIFICATION
    ===================================================== */

    if($editRef !== ''){

        $old = invoiceRows($conn,$editRef);

        if(!$old){
            throw new Exception('Facture à modifier introuvable.');
        }

        $oldMeta = parseMeta($old[0]['description']);

        $oldPaid = (float)($oldMeta['PAYE'] ?? 0);

        $oldTotal = 0;

        foreach($old as $r){
            $oldTotal += (float)$r['quantite'] * (float)$r['prix_unitaire'];
        }

        $oldRest = max(0,$oldTotal-$oldPaid);


        /*
         * UNE FACTURE TOTALEMENT PAYÉE EST VERROUILLÉE
         */

        if($oldRest <= 0){

            throw new Exception(
                'Cette facture est totalement payée et est verrouillée.'
            );

        }


        /*
         * CALCUL DU NOUVEAU TOTAL
         */

        $newTotal = 0;

        foreach($lines as $l){
            $newTotal += $l['q'] * $l['p'];
        }


        /*
         * ON NE PEUT PAS AVOIR UN NOUVEAU TOTAL
         * INFÉRIEUR À CE QUI A DÉJÀ ÉTÉ PAYÉ
         */

        if($newTotal + 0.01 < $oldPaid){

            throw new Exception(
                'Le nouveau total ('.money($newTotal).') est inférieur au montant déjà encaissé ('.money($oldPaid).').'
            );

        }


        /*
         * TRANSACTION
         */

        $conn->begin_transaction();

        try{

            /*
             * 1. RESTAURER L'ANCIEN STOCK
             */

            foreach($old as $r){

                $pid = (int)$r['produit_id'];
                $q   = (int)$r['quantite'];

                $stmtStock = $conn->prepare(
                    "UPDATE produits SET stock = stock + ? WHERE id = ?"
                );

                $stmtStock->bind_param(
                    'ii',
                    $q,
                    $pid
                );

                if(!$stmtStock->execute()){
                    throw new Exception(
                        'Erreur lors de la restauration du stock.'
                    );
                }

                $stmtStock->close();
            }


            /*
             * 2. SUPPRIMER LES ANCIENNES LIGNES
             */

            $safe = $conn->real_escape_string($editRef);

            if(!$conn->query(
                "DELETE FROM ventes
                 WHERE description LIKE '%FACTURE=$safe|%'"
            )){
                throw new Exception(
                    'Impossible de supprimer les anciennes lignes de vente.'
                );
            }


            /*
             * 3. SUPPRIMER LES ANCIENS MOUVEMENTS DE SORTIE
             */

            if(!$conn->query(
                "DELETE FROM mouvements
                 WHERE type='SORTIE'
                 AND description LIKE '%FACTURE=$safe|%'"
            )){
                throw new Exception(
                    'Impossible de supprimer les anciens mouvements.'
                );
            }


            /*
             * 4. RECRÉER LA FACTURE AVEC LE MÊME NUMÉRO
             */

            $ref = $editRef;

            foreach($lines as $l){

                $pid = $l['pid'];
                $q   = $l['q'];
                $p   = $l['p'];

                /*
                 * Vérifier produit
                 */

                $stmtProd = $conn->prepare(
                    "SELECT id,nom,stock
                     FROM produits
                     WHERE id = ?
                     FOR UPDATE"
                );

                $stmtProd->bind_param('i',$pid);
                $stmtProd->execute();

                $prodResult = $stmtProd->get_result();

                if(!$prodResult || !$prodResult->num_rows){

                    throw new Exception(
                        'Article introuvable.'
                    );
                }

                $prod = $prodResult->fetch_assoc();

                $stock = (int)$prod['stock'];

                if($stock < $q){

                    throw new Exception(
                        'Stock insuffisant pour '.$prod['nom'].
                        ' (stock disponible : '.$stock.').'
                    );

                }

                $stmtProd->close();


                /*
                 * DESCRIPTION AVEC PAIEMENT CONSERVÉ
                 */

                $rest = max(0,$newTotal-$oldPaid);

                $desc =
                    "FACTURE=".$ref.
                    "|CLIENT=".cleanText($client).
                    "|PAYE=".$oldPaid.
                    "|RESTE=".$rest.
                    "|VENTE";


                /*
                 * INSERT VENTE
                 */

                $vid = nextId($conn,'ventes');

                $date = date('Y-m-d H:i:s');

                $mont = $q*$p;

                $stmt = $conn->prepare(
                    "INSERT INTO ventes
                    (id,produit_id,quantite,prix_unitaire,montant,description,date_vente)
                    VALUES (?,?,?,?,?,?,?)"
                );

                $stmt->bind_param(
                    'iiiddss',
                    $vid,
                    $pid,
                    $q,
                    $p,
                    $mont,
                    $desc,
                    $date
                );

                if(!$stmt->execute()){

                    throw new Exception(
                        'Erreur lors de l'enregistrement de la vente.'
                    );

                }

                $stmt->close();


                /*
                 * INSERT MOUVEMENT SORTIE
                 */

                $mid = nextId($conn,'mouvements');

                $md =
                    "FACTURE=".$ref.
                    "|CLIENT=".cleanText($client).
                    "|SORTIE VENTE";


                $stmt2 = $conn->prepare(
                    "INSERT INTO mouvements
                    (id,produit_id,type,quantite,prix,description,date_mouvement)
                    VALUES (?,?,'SORTIE',?,?,?,?)"
                );

                $stmt2->bind_param(
                    'iiidss',
                    $mid,
                    $pid,
                    $q,
                    $p,
                    $md,
                    $date
                );

                if(!$stmt2->execute()){

                    throw new Exception(
                        'Erreur lors de l'enregistrement du mouvement.'
                    );

                }

                $stmt2->close();


                /*
                 * DIMINUER LE STOCK
                 */

                $stmtStock2 = $conn->prepare(
                    "UPDATE produits
                     SET stock = stock - ?
                     WHERE id = ?"
                );

                $stmtStock2->bind_param(
                    'ii',
                    $q,
                    $pid
                );

                if(!$stmtStock2->execute()){

                    throw new Exception(
                        'Erreur lors de la mise à jour du stock.'
                    );

                }

                $stmtStock2->close();

            }


            $conn->commit();

            flash(
                'ok',
                'Facture '.$ref.' modifiée avec succès. Le paiement déjà encaissé a été conservé.'
            );

            header(
                'Location: ventes.php?facture='.urlencode($ref)
            );

            exit;


        }catch(Throwable $e){

            $conn->rollback();

            throw $e;
        }


    }


    /* =====================================================
       NOUVELLE FACTURE
    ===================================================== */

    else{

        $ref =
            'VEN-'.
            date('Ymd-His').
            '-'.
            nextId($conn,'ventes');


        $newTotal = 0;

        foreach($lines as $l){
            $newTotal += $l['q']*$l['p'];
        }


        $conn->begin_transaction();

        try{

            foreach($lines as $l){

                $pid = $l['pid'];
                $q   = $l['q'];
                $p   = $l['p'];


                /*
                 * PRODUIT
                 */

                $stmtProd = $conn->prepare(
                    "SELECT id,nom,stock
                     FROM produits
                     WHERE id = ?
                     FOR UPDATE"
                );

                $stmtProd->bind_param(
                    'i',
                    $pid
                );

                $stmtProd->execute();

                $prodResult = $stmtProd->get_result();

                if(!$prodResult || !$prodResult->num_rows){

                    throw new Exception(
                        'Article introuvable.'
                    );

                }

                $prod = $prodResult->fetch_assoc();

                $stock = (int)$prod['stock'];

                if($stock < $q){

                    throw new Exception(
                        'Stock insuffisant pour '.$prod['nom'].
                        ' (stock disponible : '.$stock.').'
                    );

                }

                $stmtProd->close();


                /*
                 * FACTURE NON PAYÉE
                 */

                $desc =
                    "FACTURE=".$ref.
                    "|CLIENT=".cleanText($client).
                    "|PAYE=0".
                    "|RESTE=".$newTotal.
                    "|VENTE";


                /*
                 * VENTE
                 */

                $vid = nextId($conn,'ventes');

                $date = date('Y-m-d H:i:s');

                $mont = $q*$p;

                $stmt = $conn->prepare(
                    "INSERT INTO ventes
                    (id,produit_id,quantite,prix_unitaire,montant,description,date_vente)
                    VALUES (?,?,?,?,?,?,?)"
                );

                $stmt->bind_param(
                    'iiiddss',
                    $vid,
                    $pid,
                    $q,
                    $p,
                    $mont,
                    $desc,
                    $date
                );

                if(!$stmt->execute()){

                    throw new Exception(
                        'Erreur lors de l’enregistrement de la vente.'
                    );

                }

                $stmt->close();


                /*
                 * MOUVEMENT
                 */

                $mid = nextId($conn,'mouvements');

                $md =
                    "FACTURE=".$ref.
                    "|CLIENT=".cleanText($client).
                    "|SORTIE VENTE";


                $stmt2 = $conn->prepare(
                    "INSERT INTO mouvements
                    (id,produit_id,type,quantite,prix,description,date_mouvement)
                    VALUES (?,?,'SORTIE',?,?,?,?)"
                );

                $stmt2->bind_param(
                    'iiidss',
                    $mid,
                    $pid,
                    $q,
                    $p,
                    $md,
                    $date
                );

                if(!$stmt2->execute()){

                    throw new Exception(
                        'Erreur lors de l’enregistrement du mouvement.'
                    );

                }

                $stmt2->close();


                /*
                 * DIMINUER STOCK
                 */

                $stmtStock = $conn->prepare(
                    "UPDATE produits
                     SET stock = stock - ?
                     WHERE id = ?"
                );

                $stmtStock->bind_param(
                    'ii',
                    $q,
                    $pid
                );

                if(!$stmtStock->execute()){

                    throw new Exception(
                        'Erreur lors de la mise à jour du stock.'
                    );

                }

                $stmtStock->close();

            }


            /*
             * CORRIGER RESTE DE LA FACTURE
             * POUR TOUTES LES LIGNES
             */

            $safe = $conn->real_escape_string($ref);

            $conn->query(
                "UPDATE ventes
                 SET description = REPLACE(
                    REPLACE(
                        REPLACE(description,
                        '|RESTE=0','|RESTE=".$newTotal."'),
                        '|PAYE=0','|PAYE=0'),
                    '|VENTE','|VENTE'
                 )
                 WHERE description LIKE '%FACTURE=$safe|%'"
            );


            $conn->commit();

            flash(
                'ok',
                'Facture '.$ref.' enregistrée avec succès.'
            );

            header(
                'Location: ventes.php?facture='.urlencode($ref)
            );

            exit;


        }catch(Throwable $e){

            $conn->rollback();

            throw $e;
        }

    }

}


/* =========================================================
   ENCAISSEMENT
========================================================= */

if(isset($_POST['encaisser_facture'])){

    $ref = cleanText($_POST['ref'] ?? '');

    $montant = (float)($_POST['montant'] ?? 0);

    $rows = invoiceRows($conn,$ref);

    if(!$rows){
        throw new Exception('Facture introuvable.');
    }

    $meta = parseMeta($rows[0]['description']);

    $total = 0;

    foreach($rows as $r){

        $total +=
            (float)$r['quantite'] *
            (float)$r['prix_unitaire'];

    }

    $old = (float)($meta['PAYE'] ?? 0);

    $new = $old + $montant;

    if($montant <= 0){

        throw new Exception(
            'Le montant encaissé doit être supérieur à 0.'
        );

    }

    if($new > $total + 0.01){

        throw new Exception(
            'Le montant encaissé dépasse le reste de la facture.'
        );

    }

    $rest = max(0,$total-$new);

    $safeRef =
        $conn->real_escape_string($ref);

    $client =
        cleanText(
            $meta['CLIENT'] ??
            'Client comptant'
        );

    $safeClient =
        $conn->real_escape_string($client);


    $newDescription =
        "FACTURE=".$safeRef.
        "|CLIENT=".$safeClient.
        "|PAYE=".$new.
        "|RESTE=".$rest.
        "|VENTE";


    $sql = "
        UPDATE ventes
        SET description = '$newDescription'
        WHERE description LIKE '%FACTURE=$safeRef|%'
    ";

    if(!$conn->query($sql)){

        throw new Exception(
            'Erreur lors de l’enregistrement de l’encaissement.'
        );

    }


    flash(
        'ok',
        'Encaissement de '.money($montant).' enregistré.'
    );

    header(
        'Location: ventes.php?facture='.urlencode($ref)
    );

    exit;

}


/* =========================================================
   SUPPRESSION FACTURE
========================================================= */

if(isset($_POST['supprimer_facture'])){

    $ref = cleanText($_POST['ref'] ?? '');

    $rows = invoiceRows($conn,$ref);

    if(!$rows){

        throw new Exception(
            'Facture introuvable.'
        );

    }

    $meta =
        parseMeta(
            $rows[0]['description']
        );

    $paid =
        (float)($meta['PAYE'] ?? 0);


    if($paid > 0){

        throw new Exception(
            'Impossible de supprimer une facture ayant déjà reçu un paiement.'
        );

    }


    $conn->begin_transaction();

    try{

        /*
         * RESTAURER STOCK
         */

        foreach($rows as $r){

            $pid = (int)$r['produit_id'];

            $q = (int)$r['quantite'];

            $stmtStock =
                $conn->prepare(
                    "UPDATE produits
                     SET stock = stock + ?
                     WHERE id = ?"
                );

            $stmtStock->bind_param(
                'ii',
                $q,
                $pid
            );

            $stmtStock->execute();

            $stmtStock->close();

        }


        $safe =
            $conn->real_escape_string($ref);


        /*
         * SUPPRIMER VENTES
         */

        if(!$conn->query(
            "DELETE FROM ventes
             WHERE description LIKE '%FACTURE=$safe|%'"
        )){

            throw new Exception(
                'Impossible de supprimer la facture.'
            );

        }


        /*
         * SUPPRIMER MOUVEMENTS
         */

        if(!$conn->query(
            "DELETE FROM mouvements
             WHERE type='SORTIE'
             AND description LIKE '%FACTURE=$safe|%'"
        )){

            throw new Exception(
                'Impossible de supprimer les mouvements.'
            );

        }


        $conn->commit();

    }catch(Throwable $e){

        $conn->rollback();

        throw $e;

    }


    flash(
        'ok',
        'Facture '.$ref.' supprimée.'
    );

    header(
        'Location: ventes.php'
    );

    exit;

}


}catch(Throwable $e){

    flash(
        'err',
        $e->getMessage()
    );

    header(
        'Location: ventes.php'
    );

    exit;

}

}


/* =========================================================
   DONNÉES
========================================================= */

$flash =
    $_SESSION['flash'] ?? null;

unset($_SESSION['flash']);


$editRef =
    cleanText(
        $_GET['modifier'] ?? ''
    );

$selectedRef =
    cleanText(
        $_GET['facture'] ?? ''
    );


$editRows =
    $editRef
    ? invoiceRows($conn,$editRef)
    : [];


$detailRows =
    $selectedRef
    ? invoiceRows($conn,$selectedRef)
    : [];


/* =========================================================
   FACTURES
========================================================= */

$factures = [];

$q =
    $conn->query(
        "SELECT v.*,p.nom
         FROM ventes v
         LEFT JOIN produits p ON p.id=v.produit_id
         ORDER BY v.id DESC"
    );

while($q && ($r=$q->fetch_assoc())){

    $m =
        parseMeta(
            $r['description']
        );

    $ref =
        $m['FACTURE'] ??
        ('ANCIEN-'.$r['id']);


    if(!isset($factures[$ref])){

        $factures[$ref] = [
            'ref'=>$ref,
            'client'=>$m['CLIENT'] ?? 'Client comptant',
            'date'=>$r['date_vente'] ?? '',
            'total'=>0,
            'paye'=>(float)($m['PAYE'] ?? 0),
            'articles'=>0
        ];

    }


    $factures[$ref]['total'] +=
        (float)$r['quantite'] *
        (float)$r['prix_unitaire'];


    $factures[$ref]['articles']++;

}


/* =========================================================
   PRODUITS
========================================================= */

$products = [];

$q =
    $conn->query(
        "SELECT id,nom,categorie,prix_achat,stock
         FROM produits
         ORDER BY nom ASC"
    );

while($q && ($r=$q->fetch_assoc())){

    $products[]=$r;

}

?>
<!doctype html>

<html lang="fr">

<head>

<meta charset="utf-8">

<meta name="viewport"
content="width=device-width,initial-scale=1">

<title>Ventes — LAMBEMAH</title>

<style>

:root{
    --blue:#2563eb;
    --navy:#102f4b;
    --sky:#eef5ff;
    --green:#059669;
    --red:#dc2626;
}

*{
    box-sizing:border-box;
}

body{
    margin:0;
    font-family:Inter,Arial,sans-serif;
    background:#f4f8fd;
    color:#14283d;
}

.layout{
    display:flex;
    min-height:100vh;
}

.side{
    width:270px;
    background:linear-gradient(180deg,#103454,#0b2840);
    color:#fff;
    padding:28px 20px;
    position:fixed;
    inset:0 auto 0 0;
}

.brand{
    font-size:29px;
    font-weight:800;
}

.sub{
    margin-top:7px;
    color:#d8e7f6;
}

.nav{
    margin-top:40px;
}

.nav a{
    display:block;
    color:#fff;
    text-decoration:none;
    padding:14px 16px;
    border-radius:13px;
    margin:7px 0;
    font-size:17px;
}

.nav a.active,
.nav a:hover{
    background:#2563eb;
}

.main{
    margin-left:270px;
    width:calc(100% - 270px);
    padding:30px;
}

.head{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:20px;
}

.head h1{
    margin:0;
    font-size:30px;
}

.btn{
    border:0;
    border-radius:10px;
    padding:11px 15px;
    background:var(--blue);
    color:#fff;
    font-weight:700;
    cursor:pointer;
    text-decoration:none;
    display:inline-block;
}

.btn.secondary{
    background:#e8f0fb;
    color:var(--navy);
}

.btn.danger{
    background:#fee2e2;
    color:#991b1b;
}

.btn.green{
    background:#059669;
}

.card{
    background:#fff;
    border:1px solid #e1eaf5;
    border-radius:18px;
    padding:22px;
    margin-top:22px;
    box-shadow:0 7px 25px #1e3a5f0a;
}

.stats{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:15px;
    margin-top:22px;
}

.stat{
    background:linear-gradient(135deg,#fff,#f1f7ff);
    border:1px solid #dfebfa;
    border-radius:16px;
    padding:18px;
}

.stat b{
    font-size:25px;
    display:block;
    margin-top:7px;
}

.tablewrap{
    overflow:auto;
}

table{
    width:100%;
    border-collapse:collapse;
    min-width:780px;
}

th,td{
    padding:14px 12px;
    border-bottom:1px solid #e5edf6;
    text-align:left;
}

th{
    background:#f3f7fc;
    color:#49627a;
    font-size:13px;
    text-transform:uppercase;
}

.money{
    font-weight:800;
}

.status{
    padding:7px 10px;
    border-radius:999px;
    font-size:12px;
    font-weight:800;
    white-space:nowrap;
}

.paid{
    background:#dcfce7;
    color:#166534;
}

.partial{
    background:#fff7ed;
    color:#9a3412;
}

.unpaid{
    background:#fee2e2;
    color:#991b1b;
}

.locked{
    background:#e5e7eb;
    color:#374151;
}

.actions{
    display:flex;
    gap:7px;
    flex-wrap:wrap;
}

.flash{
    padding:13px 16px;
    border-radius:12px;
    margin:16px 0;
}

.ok{
    background:#dcfce7;
    color:#166534;
}

.err{
    background:#fee2e2;
    color:#991b1b;
}

.formgrid{
    display:grid;
    grid-template-columns:1.2fr 1fr;
    gap:10px;
}

.field label{
    display:block;
    font-weight:700;
    font-size:13px;
    margin-bottom:6px;
}

.field input,
.field select{
    width:100%;
    padding:11px;
    border:1px solid #cbd8e7;
    border-radius:9px;
    background:#fff;
}

.line{
    display:grid;
    grid-template-columns:2fr 1fr 1fr 1fr auto;
    gap:8px;
    align-items:end;
    margin-bottom:9px;
}

.totalbox{
    display:flex;
    justify-content:flex-end;
    font-size:22px;
    font-weight:800;
    padding-top:12px;
}

.detailhead{
    display:flex;
    justify-content:space-between;
    gap:20px;
    align-items:flex-start;
}

.paybox{
    background:#f1f7ff;
    border:1px solid #d5e5fb;
    padding:18px;
    border-radius:14px;
    margin-top:18px;
}

.mobile{
    display:none;
}

.editnotice{
    background:#fff7ed;
    color:#9a3412;
    border:1px solid #fed7aa;
    padding:12px;
    border-radius:10px;
    margin-bottom:15px;
}

.locknotice{
    background:#f3f4f6;
    color:#374151;
    padding:12px;
    border-radius:10px;
    margin-top:12px;
}

@media(max-width:850px){

    .side{
        display:none;
    }

    .main{
        margin:0;
        width:100%;
        padding:16px;
    }

    .mobile{
        display:block;
        margin-bottom:10px;
    }

    .head{
        display:block;
    }

    .head h1{
        font-size:23px;
    }

    .head .btn{
        margin-top:12px;
    }

    .stats{
        grid-template-columns:1fr;
    }

    .formgrid{
        grid-template-columns:1fr;
    }

    .line{
        grid-template-columns:1fr 1fr;
    }

    .line .wide{
        grid-column:1/-1;
    }

    .card{
        padding:15px;
    }

    .detailhead{
        display:block;
    }

}

</style>

</head>

<body>

<div class="layout">

<aside class="side">

<div class="brand">
LAMBEMAH
</div>

<div class="sub">
GESTION • PRESTATION
</div>

<nav class="nav">

<a href="index.php">
Accueil
</a>

<a href="produits.php">
Achats / Fournisseurs
</a>

<a class="active" href="ventes.php">
Ventes / Clients
</a>

<a href="prestations.php">
Prestations
</a>

<a href="recettes.php">
Recettes
</a>

<a href="depenses.php">
Dépenses
</a>

<a href="statistiques.php">
Statistiques
</a>

<a href="utilisateurs.php">
Équipe
</a>

<a href="index.php?logout=1">
Déconnexion
</a>

</nav>

</aside>


<main class="main">


<div class="mobile">

<a class="btn secondary" href="index.php">
☰ Menu
</a>

</div>


<div class="head">

<div>

<h1>
🛒 Ventes & Clients
</h1>

<p>
Chaque vente = une facture indépendante.
</p>

</div>

<a class="btn"
href="?nouvelle_vente=1">
＋ Nouvelle facture de vente
</a>

</div>


<?php if($flash): ?>

<div class="flash <?=h($flash['type'])?>">
<?=h($flash['msg'])?>
</div>

<?php endif; ?>


<div class="stats">

<div class="stat">
Factures de vente
<b><?=count($factures)?></b>
</div>

<div class="stat">
Articles vendus
<b><?=array_sum(array_column($factures,'articles'))?></b>
</div>

<div class="stat">
Total facturé
<b>
<?=money(array_sum(array_column($factures,'total')))?>
</b>
</div>

</div>


<?php if(isset($_GET['nouvelle_vente']) || $editRows): ?>

<div class="card">

<h2>

<?=$editRows
    ? '✏️ Modifier la facture '.h($editRef)
    : '＋ Nouvelle facture de vente'
?>

</h2>


<?php if($editRows):

$editMeta =
parseMeta(
    $editRows[0]['description']
);

$editPaid =
(float)($editMeta['PAYE'] ?? 0);

$editTotal = 0;

foreach($editRows as $er){

    $editTotal +=
        (float)$er['quantite'] *
        (float)$er['prix_unitaire'];

}

$editRest =
max(0,$editTotal-$editPaid);

?>

<div class="editnotice">

<b>Modification autorisée</b><br>

Cette facture n'est pas totalement payée.
Montant déjà encaissé :
<b><?=money($editPaid)?></b>.

<br>

Le paiement déjà encaissé sera conservé après la modification.

</div>

<?php endif; ?>


<form method="post">

<input
type="hidden"
name="save_vente"
value="1"
>

<input
type="hidden"
name="edit_ref"
value="<?=h($editRef)?>"
>


<div class="formgrid">


<div class="field">

<label>
Client
</label>

<input
name="client"
value="<?=h(
    $editRows
    ? ($editMeta['CLIENT'] ?? 'Client comptant')
    : ''
)?>"
placeholder="Nom du client"
required
>

</div>


<div class="field">

<label>
Référence
</label>

<input
readonly
value="<?=h($editRef ?: 'Automatique')?>"
>

</div>

</div>


<h3>
Articles de la facture
</h3>


<div id="lines">


<?php

$base =
$editRows
? $editRows
: [
    [
        'produit_id'=>'',
        'quantite'=>1,
        'prix_unitaire'=>''
    ]
];

foreach($base as $r):

?>

<div class="line">


<div class="field wide">

<label>
Article
</label>

<select
name="produit_id[]"
required
>

<option value="">
Choisir
</option>

<?php foreach($products as $p): ?>

<option
value="<?=$p['id']?>"
<?=((int)$p['id'] === (int)($r['produit_id'] ?? 0))
    ? 'selected'
    : ''
?>
>

<?=h($p['nom'])?>
—
stock <?=$p['stock']?>

</option>

<?php endforeach; ?>

</select>

</div>


<div class="field">

<label>
Quantité
</label>

<input
type="number"
min="1"
name="quantite[]"
value="<?=h($r['quantite'] ?? 1)?>"
required
>

</div>


<div class="field">

<label>
PV unitaire
</label>

<input
type="number"
min="1"
name="prix_unitaire[]"
value="<?=h($r['prix_unitaire'] ?? '')?>"
required
>

</div>


<div class="field">

<label>
Montant
</label>

<input
readonly
class="lineTotal"
value="0 FG"
>

</div>


<button
type="button"
class="btn danger"
onclick="this.parentElement.remove();calc()"
>

×

</button>


</div>

<?php endforeach; ?>


</div>


<button
type="button"
class="btn secondary"
onclick="addLine()"
>

＋ Ajouter une ligne

</button>


<div class="totalbox">

Total :
<span id="grand">
0 FG
</span>

</div>


<?php if($editRows): ?>

<div style="margin-top:10px;color:#64748b;font-size:13px">

Ancien montant encaissé :
<b><?=money($editPaid)?></b>

</div>

<?php endif; ?>


<br>


<button class="btn green">

💾
<?=$editRows
    ? 'Enregistrer la correction'
    : 'Enregistrer la facture'
?>

</button>


<a
class="btn secondary"
href="ventes.php"
>

Annuler

</a>


</form>

</div>

<?php endif; ?>


<?php

if($detailRows):

$meta =
parseMeta(
    $detailRows[0]['description']
);

$total = 0;

foreach($detailRows as $r){

    $total +=
        (float)$r['quantite'] *
        (float)$r['prix_unitaire'];

}

$paid =
(float)($meta['PAYE'] ?? 0);

$rest =
max(0,$total-$paid);

$status =
$paid <= 0
? 'Non payée'
: ($rest <= 0
    ? 'Payée'
    : 'Partiellement payée');

?>


<div class="card">

<div class="detailhead">

<div>

<h2>
📄 Facture <?=h($selectedRef)?>
</h2>

<p>
<b>Client :</b>
<?=h($meta['CLIENT'] ?? 'Client comptant')?>
</p>

</div>


<div class="actions">


<a
class="btn secondary"
target="_blank"
href="?imprimer=<?=urlencode($selectedRef)?>"
>

🖨️ Imprimer / PDF

</a>


<?php if($rest > 0): ?>

<a
class="btn secondary"
href="?modifier=<?=urlencode($selectedRef)?>"
>

✏️ Modifier

</a>


<form
method="post"
onsubmit="return confirm('Supprimer toute cette facture ?')"
>

<input
type="hidden"
name="supprimer_facture"
value="1"
>

<input
type="hidden"
name="ref"
value="<?=h($selectedRef)?>"
>

<button class="btn danger">

🗑️ Supprimer

</button>

</form>


<?php endif; ?>


</div>

</div>


<div class="tablewrap">

<table>

<thead>

<tr>

<th>
Désignation
</th>

<th>
Qté
</th>

<th>
PVU
</th>

<th>
Montant
</th>

</tr>

</thead>


<tbody>

<?php foreach($detailRows as $r): ?>

<tr>

<td>
<?=h($r['nom'] ?? 'Article')?>
</td>

<td>
<?=h($r['quantite'])?>
</td>

<td>
<?=money($r['prix_unitaire'])?>
</td>

<td class="money">

<?=money(
    (float)$r['quantite'] *
    (float)$r['prix_unitaire']
)?>

</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>


<div class="paybox">


<div
style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px"
>

<div>

<small>
Total
</small>

<br>

<b>
<?=money($total)?>
</b>

</div>


<div>

<small>
Encaissé
</small>

<br>

<b>
<?=money($paid)?>
</b>

</div>


<div>

<small>
Reste
</small>

<br>

<b>
<?=money($rest)?>
</b>

</div>

</div>


<p>

Statut :

<span
class="status <?=$rest<=0
    ? 'paid'
    : ($paid>0
        ? 'partial'
        : 'unpaid')?>"
>

<?=h($status)?>

</span>

</p>


<?php if($rest > 0): ?>


<form
method="post"
class="formgrid"
>

<input
type="hidden"
name="encaisser_facture"
value="1"
>

<input
type="hidden"
name="ref"
value="<?=h($selectedRef)?>"
>


<div class="field">

<label>
Montant encaissé
</label>

<input
type="number"
min="1"
max="<?=h($rest)?>"
name="montant"
required
>

</div>


<div>

<button
class="btn green"
style="margin-top:23px"
>

💰 Encaisser cette facture

</button>

</div>


</form>


<?php else: ?>

<div class="locknotice">

🔒
<b>Facture totalement payée.</b>

<br>

Cette facture est maintenant verrouillée.
Pour une correction comptable, il faudra passer par une opération d'annulation/correction.

</div>

<?php endif; ?>


</div>

</div>

<?php endif; ?>


<div class="card">

<h2>
👥 Clients & factures
</h2>

<p>
Cliquez sur une facture pour voir ses articles et gérer son encaissement.
</p>


<div class="tablewrap">

<table>

<thead>

<tr>

<th>Client</th>
<th>Facture</th>
<th>Date</th>
<th>Articles</th>
<th>Total</th>
<th>Encaissé</th>
<th>Reste</th>
<th>Statut</th>
<th>Actions</th>

</tr>

</thead>


<tbody>


<?php foreach($factures as $f):

$rest =
max(0,$f['total']-$f['paye']);

$st =
$f['paye']<=0
? 'Non payée'
: ($rest<=0
    ? 'Payée'
    : 'Partiellement payée');

?>


<tr>


<td>

<b>
<?=h($f['client'])?>
</b>

</td>


<td>
<?=h($f['ref'])?>
</td>


<td>

<?=h(
    $f['date']
    ? date('d/m/Y',strtotime($f['date']))
    : '—'
)?>

</td>


<td>
<?=h($f['articles'])?>
</td>


<td class="money">
<?=money($f['total'])?>
</td>


<td>
<?=money($f['paye'])?>
</td>


<td>
<?=money($rest)?>
</td>


<td>

<span
class="status <?=$rest<=0
    ? 'paid'
    : ($f['paye']>0
        ? 'partial'
        : 'unpaid')?>"
>

<?=h($st)?>

</span>

</td>


<td>

<div class="actions">


<a
class="btn"
href="?facture=<?=urlencode($f['ref'])?>"
>

Ouvrir

</a>


<?php if($rest > 0): ?>

<a
class="btn secondary"
href="?modifier=<?=urlencode($f['ref'])?>"
>

✏️ Modifier

</a>

<?php else: ?>

<span
class="status locked"
>

🔒 Verrouillée

</span>

<?php endif; ?>


</div>

</td>


</tr>


<?php endforeach; ?>


<?php if(!$factures): ?>

<tr>

<td colspan="9">

Aucune facture de vente.

</td>

</tr>

<?php endif; ?>


</tbody>

</table>

</div>

</div>


</main>

</div>


<script>

function fmt(n){

    return new Intl.NumberFormat('fr-FR')
        .format(Math.round(n))
        + ' FG';

}


function calc(){

    let t = 0;

    document
    .querySelectorAll('.line')
    .forEach(l => {

        let q =
            parseFloat(
                l.querySelector(
                    '[name="quantite[]"]'
                )?.value || 0
            );

        let p =
            parseFloat(
                l.querySelector(
                    '[name="prix_unitaire[]"]'
                )?.value || 0
            );

        let m = q*p;

        t += m;

        let x =
            l.querySelector('.lineTotal');

        if(x){
            x.value = fmt(m);
        }

    });


    let g =
        document.getElementById('grand');

    if(g){
        g.textContent = fmt(t);
    }

}


function addLine(){

    let b =
        document.getElementById('lines');

    let s =
        b.querySelector('.line');

    if(!s){
        return;
    }

    let x =
        s.cloneNode(true);


    x.querySelectorAll('input')
    .forEach(i => {

        if(i.name === 'quantite[]'){
            i.value = 1;
        }

        else if(i.name === 'prix_unitaire[]'){
            i.value = '';
        }

        else if(i.classList.contains('lineTotal')){
            i.value = '0 FG';
        }

    });


    x.querySelector('select')
        .selectedIndex = 0;


    b.appendChild(x);

    calc();

}


document.addEventListener(
    'input',
    calc
);


calc();

</script>


</body>

</html>
