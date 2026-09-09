<?php
session_start();
require_once __DIR__ . '/config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Connexion à la base de données impossible.");
}

$conn->set_charset("utf8mb4");
mysqli_report(MYSQLI_REPORT_OFF);

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
    $allowed = ['produits','mouvements','ventes'];
    if (!in_array($table, $allowed, true)) return 1;

    $q = $conn->query("SELECT COALESCE(MAX(id),0)+1 AS n FROM `$table`");
    return (int)($q->fetch_assoc()['n'] ?? 1);
}

function parseMeta(string $desc): array {
    $out = [];

    foreach (explode('|', $desc) as $part) {
        if (strpos($part, '=') !== false) {
            [$k,$v] = explode('=', $part, 2);
            $out[trim($k)] = trim($v);
        }
    }

    return $out;
}

function invoiceRows(mysqli $conn, string $ref): array {
    $rows = [];
    $safe = $conn->real_escape_string($ref);

    $q = $conn->query("
        SELECT m.*, p.nom, p.categorie
        FROM mouvements m
        LEFT JOIN produits p ON p.id = m.produit_id
        WHERE m.type='ENTREE'
        AND m.description LIKE '%FACTURE=$safe|%'
        ORDER BY m.id ASC
    ");

    while ($q && ($r = $q->fetch_assoc())) {
        $rows[] = $r;
    }

    return $rows;
}

function flash($type, $msg){
    $_SESSION['flash'] = [
        'type' => $type,
        'msg'  => $msg
    ];
}

function nextPurchaseNumber(mysqli $conn): string {
    $year = date('Y');
    $max = 0;

    $q = $conn->query("
        SELECT description
        FROM mouvements
        WHERE type='ENTREE'
        AND description LIKE '%FACTURE=ACH-$year-%'
    ");

    while ($q && ($r = $q->fetch_assoc())) {
        if (preg_match('/FACTURE=ACH-' . $year . '-(\d+)/', $r['description'], $m)) {
            $n = (int)$m[1];
            if ($n > $max) $max = $n;
        }
    }

    return 'ACH-' . $year . '-' . str_pad($max + 1, 4, '0', STR_PAD_LEFT);
}

/*
|--------------------------------------------------------------------------
| IMPRESSION FACTURE
|--------------------------------------------------------------------------
*/
if (isset($_GET['imprimer'])) {

    $ref = cleanText($_GET['imprimer']);
    $rows = invoiceRows($conn, $ref);

    if (!$rows) {
        die("Facture introuvable.");
    }

    $meta = parseMeta($rows[0]['description']);

    $total = 0;
    foreach ($rows as $r) {
        $total += (float)$r['quantite'] * (float)$r['prix'];
    }

    $paid = (float)($meta['PAYE'] ?? 0);
    $rest = max(0, $total - $paid);

    $signature = isset($_GET['signature']) && $_GET['signature'] == '1';

    $status = $paid <= 0
        ? 'Non payée'
        : ($rest <= 0 ? 'Payée' : 'Partiellement payée');

    ?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=h($ref)?></title>

<style>
*{box-sizing:border-box}

body{
    margin:0;
    background:#eef2f7;
    font-family:Arial,Helvetica,sans-serif;
    color:#172033;
}

.toolbar{
    background:#102f4b;
    color:white;
    padding:14px;
    display:flex;
    justify-content:center;
    gap:10px;
    position:sticky;
    top:0;
    z-index:20;
}

.toolbar label{
    background:white;
    color:#102f4b;
    padding:9px 13px;
    border-radius:8px;
    cursor:pointer;
    font-weight:bold;
}

.toolbar button{
    border:0;
    background:#2563eb;
    color:white;
    padding:9px 15px;
    border-radius:8px;
    font-weight:bold;
    cursor:pointer;
}

.paper{
    width:210mm;
    min-height:297mm;
    margin:20px auto;
    background:white;
    padding:18mm;
    box-shadow:0 4px 20px #0002;
}

.header{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    border-bottom:3px solid #102f4b;
    padding-bottom:15px;
}

.logo{
    width:115px;
    height:auto;
    object-fit:contain;
}

.company{
    margin-left:15px;
    flex:1;
}

.company h1{
    margin:0;
    font-size:25px;
    color:#102f4b;
}

.company strong{
    color:#b8860b;
}

.company p{
    margin:5px 0;
    font-size:12px;
    line-height:1.5;
}

.invoice-title{
    text-align:right;
}

.invoice-title h2{
    margin:0;
    font-size:23px;
    color:#102f4b;
}

.invoice-title strong{
    color:#b8860b;
}

.info{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:15px;
    margin:20px 0;
}

.info-box{
    border:1px solid #dce4ed;
    padding:12px;
    border-radius:8px;
}

table{
    width:100%;
    border-collapse:collapse;
}

th{
    background:#102f4b;
    color:white;
    padding:10px;
    text-align:left;
}

td{
    padding:9px;
    border-bottom:1px solid #ddd;
}

.num{
    text-align:right;
}

.total-area{
    width:45%;
    margin-left:auto;
    margin-top:20px;
}

.total-line{
    display:flex;
    justify-content:space-between;
    padding:7px 0;
}

.total-final{
    border-top:2px solid #102f4b;
    font-size:18px;
    font-weight:bold;
    padding-top:10px;
}

.status{
    padding:5px 9px;
    border-radius:20px;
    font-weight:bold;
}

.paid{background:#dcfce7;color:#166534}
.partial{background:#fff3cd;color:#92400e}
.unpaid{background:#fee2e2;color:#991b1b}

.signature-area{
    margin-top:55px;
    display:flex;
    justify-content:flex-end;
}

.signature-box{
    width:220px;
    text-align:center;
}

.signature-img{
    width:170px;
    max-height:90px;
    object-fit:contain;
    display:block;
    margin:0 auto 8px;
}

.signature-line{
    height:80px;
    border-bottom:1px solid #333;
    margin-bottom:8px;
}

.signature-label{
    font-weight:bold;
}

.footer{
    margin-top:45px;
    padding-top:12px;
    border-top:1px solid #ddd;
    text-align:center;
    font-size:11px;
    color:#667085;
}

@media(max-width:800px){
    .paper{
        width:100%;
        min-height:auto;
        margin:0;
        padding:20px;
        box-shadow:none;
    }

    .header{
        display:block;
    }

    .invoice-title{
        text-align:left;
        margin-top:15px;
    }

    .info{
        grid-template-columns:1fr;
    }

    .total-area{
        width:100%;
    }
}

@media print{
    body{background:white}

    .toolbar{
        display:none;
    }

    .paper{
        margin:0;
        width:210mm;
        min-height:297mm;
        box-shadow:none;
    }
}
</style>
</head>

<body>

<div class="toolbar">

<label>
<input type="checkbox" id="signatureCheck" <?=$signature?'checked':''?>>
Ajouter la signature
</label>

<button onclick="imprimer()">🖨️ Imprimer / PDF</button>

</div>

<div class="paper">

<div class="header">

<div style="display:flex;align-items:flex-start">

<img src="assets/logo.png" class="logo" onerror="this.style.display='none'">

<div class="company">
<h1>LAMBEMAH <strong>GESTION</strong></h1>

<p>
<strong>Achats & Gestion commerciale</strong><br>
Téléphone : +224 611752767 / 622595362<br>
Email : konatelambetenin@gmail.com<br>
Adresse : KM 36
</p>
</div>

</div>

<div class="invoice-title">
<h2>FACTURE D'ACHAT</h2>
<strong><?=h($ref)?></strong>
</div>

</div>

<div class="info">

<div class="info-box">
<strong>Fournisseur</strong><br>
<?=h($meta['FOURNISSEUR'] ?? 'Non renseigné')?>
</div>

<div class="info-box">
<strong>Date</strong><br>
<?=h(date('d/m/Y H:i', strtotime($rows[0]['date_mouvement'] ?? 'now')))?>
</div>

</div>

<table>

<thead>
<tr>
<th>Désignation</th>
<th>Catégorie</th>
<th class="num">Qté</th>
<th class="num">Prix achat</th>
<th class="num">Montant</th>
</tr>
</thead>

<tbody>

<?php foreach($rows as $r):
$m = (float)$r['quantite'] * (float)$r['prix'];
?>

<tr>
<td><?=h($r['nom'] ?? 'Article')?></td>
<td><?=h($r['categorie'] ?? '')?></td>
<td class="num"><?=h($r['quantite'])?></td>
<td class="num"><?=money($r['prix'])?></td>
<td class="num"><?=money($m)?></td>
</tr>

<?php endforeach; ?>

</tbody>
</table>

<div class="total-area">

<div class="total-line">
<span>Total facture</span>
<strong><?=money($total)?></strong>
</div>

<div class="total-line">
<span>Déjà payé</span>
<strong><?=money($paid)?></strong>
</div>

<div class="total-line">
<span>Reste fournisseur</span>
<strong><?=money($rest)?></strong>
</div>

<div class="total-line total-final">
<span>Statut</span>

<span class="status <?=$rest<=0?'paid':($paid>0?'partial':'unpaid')?>">
<?=h($status)?>
</span>

</div>

</div>

<div class="signature-area">

<div class="signature-box">

<?php if($signature): ?>

<img src="assets/signature.png"
     class="signature-img"
     onerror="this.style.display='none'">

<?php else: ?>

<div class="signature-line"></div>

<?php endif; ?>

<div class="signature-label">Responsable</div>

</div>

</div>

<div class="footer">
LAMBEMAH GESTION — Document commercial
</div>

</div>

<script>
function imprimer(){
    window.print();
}

document.getElementById('signatureCheck').addEventListener('change', function(){

    const url = new URL(window.location.href);

    if(this.checked){
        url.searchParams.set('signature','1');
    }else{
        url.searchParams.delete('signature');
    }

    window.location.href = url.toString();
});
</script>

</body>
</html>
<?php
exit;
}

/*
|--------------------------------------------------------------------------
| ACTIONS
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        /*
        --------------------------------------------------------------
        AJOUT PRODUIT
        --------------------------------------------------------------
        */
        if(isset($_POST['ajouter_produit'])){

            $nom = cleanText($_POST['nom'] ?? '');
            $categorie = cleanText($_POST['categorie'] ?? '');
            $prixAchat = (float)($_POST['prix_achat'] ?? 0);
            $prixVente = (float)($_POST['prix_vente'] ?? 0);
            $stock = (int)($_POST['stock'] ?? 0);

            if($nom === ''){
                throw new Exception("Veuillez renseigner le nom de l'article.");
            }

            $id = nextId($conn,'produits');

            $stmt = $conn->prepare("
                INSERT INTO produits
                (id,nom,categorie,prix_achat,prix_vente,stock)
                VALUES (?,?,?,?,?,?)
            ");

            if(!$stmt){
                throw new Exception("Erreur préparation ajout produit : ".$conn->error);
            }

            $stmt->bind_param(
                'issddi',
                $id,
                $nom,
                $categorie,
                $prixAchat,
                $prixVente,
                $stock
            );

            if(!$stmt->execute()){
                throw new Exception("Erreur ajout produit : ".$stmt->error);
            }

            flash('ok',"Article ajouté avec succès.");
            header("Location: produits.php");
            exit;
        }

        /*
        --------------------------------------------------------------
        ENREGISTRER FACTURE ACHAT
        --------------------------------------------------------------
        */
        if(isset($_POST['save_achat'])){

            $fournisseur = cleanText($_POST['fournisseur'] ?? '');
            $editRef = cleanText($_POST['edit_ref'] ?? '');

            $ids = $_POST['produit_id'] ?? [];
            $qtys = $_POST['quantite'] ?? [];
            $prices = $_POST['prix'] ?? [];

            if($fournisseur === ''){
                throw new Exception("Veuillez renseigner le fournisseur.");
            }

            $lines = [];

            for($i=0; $i<count($ids); $i++){

                $pid = (int)($ids[$i] ?? 0);
                $q = (int)($qtys[$i] ?? 0);
                $p = (float)($prices[$i] ?? 0);

                if($pid > 0 && $q > 0 && $p >= 0){
                    $lines[] = [
                        'pid'=>$pid,
                        'q'=>$q,
                        'p'=>$p
                    ];
                }
            }

            if(!$lines){
                throw new Exception("Ajoutez au moins un article.");
            }

            /*
            ----------------------------------------------------------
            NOUVELLE FACTURE
            ----------------------------------------------------------
            */
            if($editRef === ''){

                $ref = nextPurchaseNumber($conn);
                $paid = 0;

                $conn->begin_transaction();

                try{

                    foreach($lines as $line){

                        $pid = $line['pid'];
                        $q = $line['q'];
                        $p = $line['p'];

                        $check = $conn->query("
                            SELECT id,nom
                            FROM produits
                            WHERE id=$pid
                        ");

                        if(!$check || !$check->num_rows){
                            throw new Exception("Article introuvable.");
                        }

                        $mid = nextId($conn,'mouvements');

                        $desc =
                            "FACTURE=".$conn->real_escape_string($ref).
                            "|FOURNISSEUR=".$conn->real_escape_string($fournisseur).
                            "|PAYE=0".
                            "|RESTE=0".
                            "|ACHAT";

                        $date = date('Y-m-d H:i:s');

                        $stmt = $conn->prepare("
                            INSERT INTO mouvements
                            (id,produit_id,type,quantite,prix,description,date_mouvement)
                            VALUES (?,?,'ENTREE',?,?,?,?)
                        ");

                        if(!$stmt){
                            throw new Exception("Erreur achat : ".$conn->error);
                        }

                        $stmt->bind_param(
                            'iiidss',
                            $mid,
                            $pid,
                            $q,
                            $p,
                            $desc,
                            $date
                        );

                        if(!$stmt->execute()){
                            throw new Exception("Erreur enregistrement achat : ".$stmt->error);
                        }

                        $newStock = $conn->query("
                            UPDATE produits
                            SET stock=stock+$q,
                                prix_achat=$p
                            WHERE id=$pid
                        ");

                        if(!$newStock){
                            throw new Exception("Erreur mise à jour du stock.");
                        }
                    }

                    $conn->commit();

                }catch(Throwable $e){

                    $conn->rollback();
                    throw $e;
                }

                flash('ok',"Facture $ref enregistrée avec succès.");

                header("Location: produits.php?facture=".urlencode($ref));
                exit;
            }

            /*
            ----------------------------------------------------------
            MODIFICATION FACTURE
            ----------------------------------------------------------
            */

            $old = invoiceRows($conn,$editRef);

            if(!$old){
                throw new Exception("Facture à modifier introuvable.");
            }

            $oldMeta = parseMeta($old[0]['description']);

            $oldPaid = (float)($oldMeta['PAYE'] ?? 0);

            /*
             * Une facture totalement payée est verrouillée.
             */
            $oldTotal = 0;

            foreach($old as $r){
                $oldTotal += (float)$r['quantite'] * (float)$r['prix'];
            }

            if($oldPaid >= $oldTotal - 0.01){
                throw new Exception(
                    "Cette facture est totalement payée et ne peut plus être modifiée."
                );
            }

            /*
             * Calcul nouveau total
             */
            $newTotal = 0;

            foreach($lines as $line){
                $newTotal += $line['q'] * $line['p'];
            }

            /*
             * On ne peut pas modifier une facture à un total inférieur
             * à ce qui a déjà été payé.
             */
            if($newTotal + 0.01 < $oldPaid){
                throw new Exception(
                    "Le nouveau total (" . money($newTotal) .
                    ") est inférieur au montant déjà payé (" .
                    money($oldPaid) . ")."
                );
            }

            $newRest = max(0,$newTotal-$oldPaid);

            $ref = $editRef;

            $conn->begin_transaction();

            try{

                /*
                 * Restaurer ancien stock
                 */
                foreach($old as $r){

                    $pid = (int)$r['produit_id'];
                    $q = (int)$r['quantite'];

                    $ok = $conn->query("
                        UPDATE produits
                        SET stock=stock-$q
                        WHERE id=$pid
                    ");

                    if(!$ok){
                        throw new Exception("Erreur restauration du stock.");
                    }
                }

                /*
                 * Supprimer anciennes lignes de la facture
                 */
                $safeRef = $conn->real_escape_string($ref);

                if(!$conn->query("
                    DELETE FROM mouvements
                    WHERE type='ENTREE'
                    AND description LIKE '%FACTURE=$safeRef|%'
                ")){
                    throw new Exception("Impossible de remplacer les anciennes lignes.");
                }

                /*
                 * Réinsérer les nouvelles lignes
                 */
                foreach($lines as $line){

                    $pid = $line['pid'];
                    $q = $line['q'];
                    $p = $line['p'];

                    $check = $conn->query("
                        SELECT id,nom
                        FROM produits
                        WHERE id=$pid
                    ");

                    if(!$check || !$check->num_rows){
                        throw new Exception("Article introuvable.");
                    }

                    $mid = nextId($conn,'mouvements');

                    $desc =
                        "FACTURE=".$conn->real_escape_string($ref).
                        "|FOURNISSEUR=".$conn->real_escape_string($fournisseur).
                        "|PAYE=".$oldPaid.
                        "|RESTE=".$newRest.
                        "|ACHAT";

                    $date = date('Y-m-d H:i:s');

                    $stmt = $conn->prepare("
                        INSERT INTO mouvements
                        (id,produit_id,type,quantite,prix,description,date_mouvement)
                        VALUES (?,?,'ENTREE',?,?,?,?)
                    ");

                    if(!$stmt){
                        throw new Exception("Erreur préparation modification.");
                    }

                    $stmt->bind_param(
                        'iiidss',
                        $mid,
                        $pid,
                        $q,
                        $p,
                        $desc,
                        $date
                    );

                    if(!$stmt->execute()){
                        throw new Exception("Erreur modification facture : ".$stmt->error);
                    }

                    $ok = $conn->query("
                        UPDATE produits
                        SET stock=stock+$q,
                            prix_achat=$p
                        WHERE id=$pid
                    ");

                    if(!$ok){
                        throw new Exception("Erreur mise à jour stock.");
                    }
                }

                $conn->commit();

            }catch(Throwable $e){

                $conn->rollback();
                throw $e;
            }

            flash(
                'ok',
                "Facture $ref modifiée. Paiement conservé : ".money($oldPaid)
            );

            header("Location: produits.php?facture=".urlencode($ref));
            exit;
        }

        /*
        --------------------------------------------------------------
        REGLEMENT FACTURE
        --------------------------------------------------------------
        */
        if(isset($_POST['regler_facture'])){

            $ref = cleanText($_POST['ref'] ?? '');
            $montant = (float)($_POST['montant'] ?? 0);

            $rows = invoiceRows($conn,$ref);

            if(!$rows){
                throw new Exception("Facture introuvable.");
            }

            $meta = parseMeta($rows[0]['description']);

            $total = 0;

            foreach($rows as $r){
                $total += (float)$r['quantite'] * (float)$r['prix'];
            }

            $oldPaid = (float)($meta['PAYE'] ?? 0);

            if($oldPaid >= $total - 0.01){
                throw new Exception("Cette facture est déjà totalement payée.");
            }

            if($montant <= 0){
                throw new Exception("Montant de règlement invalide.");
            }

            $newPaid = $oldPaid + $montant;

            if($newPaid > $total + 0.01){
                throw new Exception("Le règlement dépasse le montant restant.");
            }

            $rest = max(0,$total-$newPaid);

            $safe = $conn->real_escape_string($ref);
            $fournisseur = cleanText($meta['FOURNISSEUR'] ?? '');

            $desc =
                "FACTURE=$safe".
                "|FOURNISSEUR=".$conn->real_escape_string($fournisseur).
                "|PAYE=$newPaid".
                "|RESTE=$rest".
                "|ACHAT";

            $ok = $conn->query("
                UPDATE mouvements
                SET description='".$conn->real_escape_string($desc)."'
                WHERE type='ENTREE'
                AND description LIKE '%FACTURE=$safe|%'
            ");

            if(!$ok){
                throw new Exception("Erreur lors du règlement.");
            }

            flash('ok',"Règlement enregistré : ".money($montant));

            header("Location: produits.php?facture=".urlencode($ref));
            exit;
        }

        /*
        --------------------------------------------------------------
        SUPPRIMER FACTURE
        --------------------------------------------------------------
        */
        if(isset($_POST['supprimer_facture'])){

            $ref = cleanText($_POST['ref'] ?? '');

            $rows = invoiceRows($conn,$ref);

            if(!$rows){
                throw new Exception("Facture introuvable.");
            }

            $meta = parseMeta($rows[0]['description']);

            $paid = (float)($meta['PAYE'] ?? 0);

            if($paid > 0){
                throw new Exception(
                    "Une facture ayant un paiement ne peut pas être supprimée."
                );
            }

            $conn->begin_transaction();

            try{

                foreach($rows as $r){

                    $pid = (int)$r['produit_id'];
                    $q = (int)$r['quantite'];

                    if(!$conn->query("
                        UPDATE produits
                        SET stock=stock-$q
                        WHERE id=$pid
                    ")){
                        throw new Exception("Erreur correction du stock.");
                    }
                }

                $safe = $conn->real_escape_string($ref);

                if(!$conn->query("
                    DELETE FROM mouvements
                    WHERE type='ENTREE'
                    AND description LIKE '%FACTURE=$safe|%'
                ")){
                    throw new Exception("Erreur suppression facture.");
                }

                $conn->commit();

            }catch(Throwable $e){

                $conn->rollback();
                throw $e;
            }

            flash('ok',"Facture $ref supprimée.");

            header("Location: produits.php");
            exit;
        }

    }catch(Throwable $e){

        flash('err',$e->getMessage());

        header("Location: produits.php");
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| DONNEES
|--------------------------------------------------------------------------
*/

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$editRef = cleanText($_GET['modifier'] ?? '');
$selectedRef = cleanText($_GET['facture'] ?? '');

$editRows = $editRef ? invoiceRows($conn,$editRef) : [];
$detailRows = $selectedRef ? invoiceRows($conn,$selectedRef) : [];

$products = [];

$q = $conn->query("
    SELECT id,nom,categorie,prix_achat,prix_vente,stock
    FROM produits
    ORDER BY nom ASC
");

while($q && ($r=$q->fetch_assoc())){
    $products[]=$r;
}

/*
|--------------------------------------------------------------------------
| FACTURES
|--------------------------------------------------------------------------
*/

$factures = [];

$q = $conn->query("
    SELECT m.*,p.nom
    FROM mouvements m
    LEFT JOIN produits p ON p.id=m.produit_id
    WHERE m.type='ENTREE'
    ORDER BY m.id DESC
");

while($q && ($r=$q->fetch_assoc())){

    $meta = parseMeta($r['description']);

    $ref = $meta['FACTURE'] ?? ('ANCIEN-'.$r['id']);

    if(!isset($factures[$ref])){

        $factures[$ref] = [
            'ref'=>$ref,
            'fournisseur'=>$meta['FOURNISSEUR'] ?? 'Fournisseur non renseigné',
            'date'=>$r['date_mouvement'] ?? '',
            'total'=>0,
            'paye'=>(float)($meta['PAYE'] ?? 0),
            'articles'=>0
        ];
    }

    $factures[$ref]['total'] +=
        (float)$r['quantite'] * (float)$r['prix'];

    $factures[$ref]['articles']++;
}

$totalStock=0;
$stockValue=0;

foreach($products as $p){

    $totalStock += (int)$p['stock'];

    $stockValue +=
        (int)$p['stock'] * (float)$p['prix_achat'];
}

?>
<!DOCTYPE html>
<html lang="fr">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Achats / Stock — LAMBEMAH</title>

<style>

*{box-sizing:border-box}

body{
    margin:0;
    background:#f4f8fd;
    color:#14283d;
    font-family:Arial,Helvetica,sans-serif;
}

.layout{
    display:flex;
    min-height:100vh;
}

.side{
    width:260px;
    background:linear-gradient(180deg,#103454,#0b2840);
    color:white;
    padding:25px 18px;
    position:fixed;
    left:0;
    top:0;
    bottom:0;
}

.brand{
    font-size:28px;
    font-weight:bold;
}

.sub{
    color:#cbdced;
    margin-top:5px;
}

.nav{
    margin-top:30px;
}

.nav a{
    display:block;
    color:white;
    text-decoration:none;
    padding:12px 14px;
    margin:5px 0;
    border-radius:10px;
}

.nav a:hover,
.nav a.active{
    background:#2563eb;
}

.main{
    margin-left:260px;
    width:calc(100% - 260px);
    padding:25px;
}

.head{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:15px;
}

.head h1{
    margin:0;
}

.btn{
    display:inline-block;
    border:0;
    padding:10px 14px;
    border-radius:9px;
    background:#2563eb;
    color:white;
    text-decoration:none;
    font-weight:bold;
    cursor:pointer;
}

.btn.green{background:#059669}
.btn.danger{background:#dc2626}
.btn.secondary{background:#e7eef8;color:#102f4b}

.card{
    background:white;
    border:1px solid #e0e8f2;
    border-radius:15px;
    padding:20px;
    margin-top:18px;
    box-shadow:0 4px 18px #1234510d;
}

.stats{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:12px;
    margin-top:18px;
}

.stat{
    background:white;
    border:1px solid #e0e8f2;
    border-radius:14px;
    padding:16px;
}

.stat b{
    display:block;
    font-size:22px;
    margin-top:6px;
    color:#102f4b;
}

.formgrid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:10px;
}

.field label{
    display:block;
    font-size:13px;
    font-weight:bold;
    margin-bottom:5px;
}

.field input,
.field select{
    width:100%;
    padding:10px;
    border:1px solid #cbd8e7;
    border-radius:8px;
}

.line{
    display:grid;
    grid-template-columns:2fr 1fr 1fr 1fr auto;
    gap:8px;
    margin-bottom:8px;
    align-items:end;
}

.totalbox{
    text-align:right;
    font-size:20px;
    font-weight:bold;
    margin-top:10px;
}

.tablewrap{
    overflow:auto;
}

table{
    width:100%;
    border-collapse:collapse;
    min-width:850px;
}

th,td{
    padding:11px;
    border-bottom:1px solid #e4ebf3;
    text-align:left;
}

th{
    background:#f2f6fb;
    font-size:12px;
}

.money{
    font-weight:bold;
}

.status{
    padding:5px 9px;
    border-radius:20px;
    font-size:11px;
    font-weight:bold;
}

.paid{background:#dcfce7;color:#166534}
.partial{background:#fff3cd;color:#92400e}
.unpaid{background:#fee2e2;color:#991b1b}

.actions{
    display:flex;
    gap:6px;
    flex-wrap:wrap;
}

.flash{
    padding:12px;
    border-radius:9px;
    margin-top:15px;
}

.ok{
    background:#dcfce7;
    color:#166534;
}

.err{
    background:#fee2e2;
    color:#991b1b;
}

.mobile{
    display:none;
}

@media(max-width:850px){

    .side{
        display:none;
    }

    .main{
        margin:0;
        width:100%;
        padding:14px;
    }

    .mobile{
        display:block;
        margin-bottom:12px;
    }

    .head{
        display:block;
    }

    .head .btn{
        margin-top:10px;
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
        padding:14px;
    }

}

</style>

</head>

<body>

<div class="layout">

<aside class="side">

<div class="brand">LAMBEMAH</div>
<div class="sub">GESTION • PRESTATION</div>

<nav class="nav">

<a href="index.php">Accueil</a>
<a class="active" href="produits.php">Achats / Fournisseurs</a>
<a href="ventes.php">Ventes / Clients</a>
<a href="prestations.php">Prestations</a>
<a href="recettes.php">Recettes</a>
<a href="depenses.php">Dépenses</a>
<a href="statistiques.php">Statistiques</a>
<a href="utilisateurs.php">Équipe</a>
<a href="index.php?logout=1">Déconnexion</a>

</nav>

</aside>

<main class="main">

<div class="mobile">
<a class="btn secondary" href="index.php">☰ Menu</a>
</div>

<div class="head">

<div>
<h1>🧾 Achats & Fournisseurs</h1>
<p>Factures, paiements et stock.</p>
</div>

<div>
<a class="btn" href="?nouvel_achat=1">＋ Nouvelle facture</a>
<a class="btn secondary" href="?nouveau_produit=1">＋ Article</a>
</div>

</div>

<?php if($flash): ?>

<div class="flash <?=$flash['type']?>">
<?=h($flash['msg'])?>
</div>

<?php endif; ?>

<div class="stats">

<div class="stat">
Factures d'achat
<b><?=count($factures)?></b>
</div>

<div class="stat">
Articles en stock
<b><?=number_format($totalStock,0,',',' ')?></b>
</div>

<div class="stat">
Valeur du stock
<b><?=money($stockValue)?></b>
</div>

</div>

<?php if(isset($_GET['nouveau_produit'])): ?>

<div class="card">

<h2>➕ Nouvel article</h2>

<form method="post">

<input type="hidden" name="ajouter_produit" value="1">

<div class="formgrid">

<div class="field">
<label>Désignation</label>
<input name="nom" required>
</div>

<div class="field">
<label>Catégorie</label>
<input name="categorie">
</div>

<div class="field">
<label>Prix achat</label>
<input type="number" min="0" name="prix_achat" value="0">
</div>

<div class="field">
<label>Prix vente</label>
<input type="number" min="0" name="prix_vente" value="0">
</div>

<div class="field">
<label>Stock initial</label>
<input type="number" min="0" name="stock" value="0">
</div>

</div>

<br>

<button class="btn green">💾 Ajouter l'article</button>

<a class="btn secondary" href="produits.php">Annuler</a>

</form>

</div>

<?php endif; ?>

<?php if(isset($_GET['nouvel_achat']) || $editRows): ?>

<div class="card">

<h2>
<?= $editRows
    ? '✏️ Modifier la facture '.h($editRef)
    : '＋ Nouvelle facture d’achat'
?>
</h2>

<?php if($editRows):

$editMeta=parseMeta($editRows[0]['description']);
$editPaid=(float)($editMeta['PAYE']??0);
?>

<div class="flash ok">
Paiement déjà enregistré :
<strong><?=money($editPaid)?></strong>.
Il sera conservé après modification.
</div>

<?php endif; ?>

<form method="post">

<input type="hidden" name="save_achat" value="1">

<input type="hidden"
       name="edit_ref"
       value="<?=h($editRef)?>">

<div class="formgrid">

<div class="field">

<label>Fournisseur</label>

<input
name="fournisseur"
required
value="<?=h($editRows ? ($editMeta['FOURNISSEUR'] ?? '') : '')?>"
placeholder="Nom du fournisseur">

</div>

<div class="field">

<label>Référence</label>

<input
readonly
value="<?=h($editRef ?: 'Automatique')?>">

</div>

</div>

<h3>Articles de la facture</h3>

<div id="lines">

<?php

$base=$editRows ?: [
    [
        'produit_id'=>'',
        'quantite'=>1,
        'prix'=>''
    ]
];

foreach($base as $r):

?>

<div class="line">

<div class="field wide">

<label>Article</label>

<select name="produit_id[]" required>

<option value="">Choisir</option>

<?php foreach($products as $p): ?>

<option
value="<?=$p['id']?>"
<?=((int)$p['id']===(int)($r['produit_id']??0))?'selected':''?>>

<?=h($p['nom'])?> —
stock <?=h($p['stock'])?>

</option>

<?php endforeach; ?>

</select>

</div>

<div class="field">

<label>Quantité</label>

<input
type="number"
min="1"
name="quantite[]"
value="<?=h($r['quantite']??1)?>"
required>

</div>

<div class="field">

<label>Prix achat</label>

<input
type="number"
min="0"
name="prix[]"
value="<?=h($r['prix']??'')?>"
required>

</div>

<div class="field">

<label>Montant</label>

<input
readonly
class="lineTotal"
value="0 FG">

</div>

<button
type="button"
class="btn danger"
onclick="this.parentElement.remove();calc()">

×

</button>

</div>

<?php endforeach; ?>

</div>

<button
type="button"
class="btn secondary"
onclick="addLine()">

＋ Ajouter une ligne

</button>

<div class="totalbox">

Total :
<span id="grand">0 FG</span>

</div>

<br>

<button class="btn green">
💾 Enregistrer la facture
</button>

<a class="btn secondary" href="produits.php">
Annuler
</a>

</form>

</div>

<?php endif; ?>

<?php if($detailRows):

$meta=parseMeta($detailRows[0]['description']);

$total=0;

foreach($detailRows as $r){
    $total+=(float)$r['quantite']*(float)$r['prix'];
}

$paid=(float)($meta['PAYE']??0);
$rest=max(0,$total-$paid);

$status=$paid<=0
    ? 'Non payée'
    : ($rest<=0 ? 'Payée' : 'Partiellement payée');

?>

<div class="card">

<div class="head">

<div>

<h2>📄 Facture <?=h($selectedRef)?></h2>

<p>
<strong>Fournisseur :</strong>
<?=h($meta['FOURNISSEUR']??'—')?>
</p>

</div>

<div class="actions">

<a
class="btn secondary"
target="_blank"
href="?imprimer=<?=urlencode($selectedRef)?>">

🖨️ Imprimer / PDF

</a>

<?php if($rest>0): ?>

<a
class="btn secondary"
href="?modifier=<?=urlencode($selectedRef)?>">

✏️ Modifier

</a>

<?php endif; ?>

<?php if($paid<=0): ?>

<form method="post"
onsubmit="return confirm('Supprimer cette facture ?')">

<input type="hidden"
name="supprimer_facture"
value="1">

<input type="hidden"
name="ref"
value="<?=h($selectedRef)?>">

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
<th>Désignation</th>
<th>Qté</th>
<th>Prix achat</th>
<th>Montant</th>
</tr>

</thead>

<tbody>

<?php foreach($detailRows as $r): ?>

<tr>

<td><?=h($r['nom']??'Article')?></td>

<td><?=h($r['quantite'])?></td>

<td><?=money($r['prix'])?></td>

<td class="money">
<?=money(
    (float)$r['quantite']*(float)$r['prix']
)?>
</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

<div class="card">

<strong>Total :</strong>
<?=money($total)?>

&nbsp;&nbsp;

<strong>Payé :</strong>
<?=money($paid)?>

&nbsp;&nbsp;

<strong>Reste :</strong>
<?=money($rest)?>

<p>

<span class="status <?=$rest<=0?'paid':($paid>0?'partial':'unpaid')?>">
<?=h($status)?>
</span>

</p>

<?php if($rest>0): ?>

<form method="post">

<input type="hidden"
name="regler_facture"
value="1">

<input type="hidden"
name="ref"
value="<?=h($selectedRef)?>">

<div class="formgrid">

<div class="field">

<label>Montant à régler</label>

<input
type="number"
min="1"
max="<?=h($rest)?>"
step="1"
name="montant"
required>

</div>

<div style="display:flex;align-items:end">

<button class="btn green">
💰 Régler cette facture
</button>

</div>

</div>

</form>

<?php else: ?>

<div class="flash ok">
🔒 Facture totalement payée — modification verrouillée.
</div>

<?php endif; ?>

</div>

</div>

<?php endif; ?>

<div class="card">

<h2>📋 Factures d'achat</h2>

<div class="tablewrap">

<table>

<thead>

<tr>
<th>Fournisseur</th>
<th>Facture</th>
<th>Date</th>
<th>Articles</th>
<th>Total</th>
<th>Payé</th>
<th>Reste</th>
<th>Statut</th>
<th></th>
</tr>

</thead>

<tbody>

<?php foreach($factures as $f):

$rest=max(0,$f['total']-$f['paye']);

$st=$f['paye']<=0
    ? 'Non payée'
    : ($rest<=0 ? 'Payée' : 'Partiellement payée');

?>

<tr>

<td><b><?=h($f['fournisseur'])?></b></td>

<td><?=h($f['ref'])?></td>

<td>
<?=h(
    $f['date']
    ? date('d/m/Y',strtotime($f['date']))
    : '—'
)?>
</td>

<td><?=h($f['articles'])?></td>

<td class="money"><?=money($f['total'])?></td>

<td><?=money($f['paye'])?></td>

<td><?=money($rest)?></td>

<td>

<span class="status <?=$rest<=0?'paid':($f['paye']>0?'partial':'unpaid')?>">

<?=h($st)?>

</span>

</td>

<td>

<a
class="btn"
href="?facture=<?=urlencode($f['ref'])?>">

Ouvrir

</a>

</td>

</tr>

<?php endforeach; ?>

<?php if(!$factures): ?>

<tr>
<td colspan="9">
Aucune facture d'achat pour le moment.
</td>
</tr>

<?php endif; ?>

</tbody>

</table>

</div>

</div>

<div class="card">

<h2>📦 Stock actuel</h2>

<div class="tablewrap">

<table>

<thead>

<tr>
<th>Article</th>
<th>Catégorie</th>
<th>Prix achat</th>
<th>Prix vente</th>
<th>Stock</th>
</tr>

</thead>

<tbody>

<?php foreach($products as $p): ?>

<tr>

<td><?=h($p['nom'])?></td>

<td><?=h($p['categorie'])?></td>

<td><?=money($p['prix_achat'])?></td>

<td><?=money($p['prix_vente'])?></td>

<td><b><?=h($p['stock'])?></b></td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

</div>

</main>
</div>

<script>

function fmt(n){
    return new Intl.NumberFormat('fr-FR')
        .format(Math.round(n))+' FG';
}

function calc(){

    let total=0;

    document.querySelectorAll('#lines .line').forEach(line=>{

        const q=parseFloat(
            line.querySelector('[name="quantite[]"]')?.value || 0
        );

        const p=parseFloat(
            line.querySelector('[name="prix[]"]')?.value || 0
        );

        const montant=q*p;

        total+=montant;

        const field=line.querySelector('.lineTotal');

        if(field){
            field.value=fmt(montant);
        }

    });

    const grand=document.getElementById('grand');

    if(grand){
        grand.textContent=fmt(total);
    }
}

function addLine(){

    const box=document.getElementById('lines');

    const source=box.querySelector('.line');

    if(!source) return;

    const clone=source.cloneNode(true);

    clone.querySelectorAll('input').forEach(input=>{

        if(input.name==='quantite[]'){
            input.value=1;
        }

        else if(input.name==='prix[]'){
            input.value='';
        }

        else if(input.classList.contains('lineTotal')){
            input.value='0 FG';
        }

    });

    const select=clone.querySelector('select');

    if(select){
        select.selectedIndex=0;
    }

    box.appendChild(clone);

    calc();
}

document.addEventListener('input',calc);

calc();

</script>

</body>
</html>
