<?php
session_start();
require_once __DIR__ . '/config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Connexion à la base de données impossible.');
}
$conn->set_charset('utf8mb4');
mysqli_report(MYSQLI_REPORT_OFF);

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function money($v): string { return number_format((float)$v, 0, ',', ' ') . ' FG'; }
function cleanText($v): string { return trim(preg_replace('/[|\r\n]+/', ' ', (string)$v)); }
function flash(string $type, string $msg): void { $_SESSION['flash'] = ['type'=>$type,'msg'=>$msg]; }

function nextId(mysqli $conn, string $table): int {
    $allowed = ['produits','mouvements','ventes','depenses','recettes'];
    if (!in_array($table, $allowed, true)) throw new Exception('Table non autorisée.');
    $q = $conn->query("SELECT MAX(id) AS max_id FROM `$table`");
    if (!$q) throw new Exception('Impossible de lire le prochain identifiant : ' . $conn->error);
    $r = $q->fetch_assoc();
    return ((int)($r['max_id'] ?? 0)) + 1;
}

function parseMeta(string $desc): array {
    $o = [];
    foreach (explode('|', $desc) as $p) {
        $p = trim($p);
        if ($p === '') continue;
        if (strpos($p, '=') !== false) {
            [$k,$v] = explode('=', $p, 2);
            $o[strtoupper(trim($k))] = trim($v);
            continue;
        }
        if (preg_match('/^Facture\s*:\s*(.+)$/i', $p, $m)) $o['FACTURE'] = trim($m[1]);
        if (preg_match('/^Fournisseur\s*:\s*(.+)$/i', $p, $m)) $o['FOURNISSEUR'] = trim($m[1]);
        if (preg_match('/^Pay[ée]e?\s*:\s*([0-9\s,.]+)\s*FG/i', $p, $m)) $o['PAYE'] = (float)str_replace([' ', ','], ['', '.'], $m[1]);
        if (preg_match('/^Reste fournisseur\s*:\s*([0-9\s,.]+)\s*FG/i', $p, $m)) $o['RESTE'] = (float)str_replace([' ', ','], ['', '.'], $m[1]);
    }
    return $o;
}

function invoiceRows(mysqli $conn, string $ref): array {
    $safe = $conn->real_escape_string($ref);
    $rows = [];
    $sql = "SELECT m.*, p.nom, p.categorie FROM mouvements m
            LEFT JOIN produits p ON p.id=m.produit_id
            WHERE m.type='ENTREE'
              AND (m.description LIKE '%FACTURE=$safe|%'
                   OR m.description LIKE '%FACTURE=$safe%'
                   OR m.description LIKE '%Facture : $safe%')
            ORDER BY m.id ASC";
    $q = $conn->query($sql);
    while ($q && ($r = $q->fetch_assoc())) $rows[] = $r;
    return $rows;
}

function purchaseRef(mysqli $conn): string {
    $year = date('Y');
    $max = 0;
    $q = $conn->query("SELECT description FROM mouvements WHERE type='ENTREE'");
    while ($q && ($r = $q->fetch_assoc())) {
        if (preg_match('/FACTURE=ACH-' . preg_quote($year,'/') . '-(\d{4})\|/', $r['description'] ?? '', $m)) {
            $max = max($max, (int)$m[1]);
        }
    }
    return 'ACH-' . $year . '-' . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
}

function deletePurchaseRows(mysqli $conn, string $ref): void {
    $safe = $conn->real_escape_string($ref);
    $q = $conn->query("DELETE FROM mouvements WHERE type='ENTREE' AND (description LIKE '%FACTURE=$safe|%' OR description LIKE '%FACTURE=$safe%' OR description LIKE '%Facture : $safe%')");
    if (!$q) throw new Exception($conn->error);
}

function restorePurchaseStock(mysqli $conn, array $rows): void {
    foreach ($rows as $r) {
        $pid = (int)$r['produit_id'];
        $qty = (int)$r['quantite'];
        $st = $conn->prepare('UPDATE produits SET stock = stock - ? WHERE id = ?');
        if (!$st) throw new Exception($conn->error);
        $st->bind_param('ii', $qty, $pid);
        if (!$st->execute()) { $e=$st->error; $st->close(); throw new Exception($e); }
        $st->close();
    }
}

/* =========================
   IMPRESSION
   ========================= */
if (isset($_GET['imprimer'])) {
    $ref = cleanText($_GET['imprimer']);
    $rows = invoiceRows($conn, $ref);
    if (!$rows) die('Facture introuvable.');
    $meta = parseMeta($rows[0]['description']);
    $total = 0;
    foreach ($rows as $r) $total += (float)$r['quantite'] * (float)$r['prix'];
    $paid = (float)($meta['PAYE'] ?? 0);
    $rest = max(0, $total - $paid);
    ?>
<!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=h($ref)?> — LAMBEMAH GESTION</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#edf3f9;color:#172b40;font:14px Arial,sans-serif}.printbar{max-width:900px;margin:14px auto;background:#fff;border:1px solid #dbe5ef;border-radius:12px;padding:10px 14px;display:flex;justify-content:space-between;align-items:center;gap:10px}.printbar button{border:0;border-radius:8px;padding:10px 14px;background:#1675e8;color:#fff;font-weight:700;cursor:pointer}.paper{max-width:900px;margin:12px auto 30px;background:#fff;padding:36px;box-shadow:0 6px 22px #17324a12}.head{display:flex;justify-content:space-between;gap:24px;border-bottom:2px solid #1675e8;padding-bottom:16px}.brandline{display:flex;gap:14px;align-items:center}.brandline img{width:76px;height:76px;object-fit:contain}.brand{font-size:24px;font-weight:900;color:#123b70}.gold{font-size:11px;color:#b77905;font-weight:800;letter-spacing:1.2px}.contact{font-size:11px;line-height:1.6;color:#64748b;margin-top:4px}.factbox{background:#edf6ff;border-left:4px solid #1675e8;border-radius:10px;padding:12px 15px;min-width:210px}.factbox b{font-size:21px;color:#123b70}.info{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin:18px 0}.box{border:1px solid #dbe5ef;border-radius:10px;padding:12px}.table{width:100%;border-collapse:collapse;border:1px solid #dbe5ef}.table th,.table td{padding:10px;border-bottom:1px solid #e6edf3}.table th{background:#f2f6fa;text-transform:uppercase;color:#5b7083;font-size:11px;text-align:left}.num{text-align:right}.totals{max-width:360px;margin:18px 0 0 auto}.row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #e5edf3}.grand{font-size:17px;font-weight:900;border-top:2px solid #17324a;border-bottom:0}.signature{margin-top:58px;display:flex;justify-content:flex-end}.sigbox{width:220px;text-align:center}.space{height:92px;display:flex;align-items:center;justify-content:center}.space img{max-width:180px;max-height:82px;object-fit:contain}.sigline{border-top:1px solid #40556a;padding-top:7px;font-weight:700}.hidden{display:none!important}@media print{body{background:#fff}.printbar{display:none}.paper{margin:0;max-width:none;box-shadow:none;padding:15mm}}@media(max-width:650px){.paper{margin:8px;padding:18px}.head{display:block}.factbox{margin-top:14px}.info{grid-template-columns:1fr}.table{font-size:11px}}
</style></head><body>
<div class="printbar"><label><input type="checkbox" id="addSignature"> Ajouter la signature</label><button onclick="window.print()">🖨️ Imprimer / PDF</button></div>
<div class="paper"><div class="head"><div class="brandline"><img src="/assets/logo.png" alt="LAMBEMAH" onerror="this.style.display='none'"><div><div class="brand">LAMBEMAH GESTION</div><div class="gold">GESTION • ACHATS</div><div class="contact">+224 611 752 767 / 622 595 362<br>konatelambetenin@gmail.com<br>KM 36, Guinée</div></div></div><div class="factbox"><b>FACTURE D'ACHAT</b><br>N° <?=h($ref)?><br><small><?=h(date('d/m/Y H:i',strtotime($rows[0]['date_mouvement']??'now')))?></small></div></div>
<div class="info"><div class="box"><b>Fournisseur</b><br><?=h($meta['FOURNISSEUR']??'—')?></div><div class="box"><b>Paiement</b><br><?=money($paid)?> payé — <?=money($rest)?> restant</div></div>
<table class="table"><thead><tr><th>Désignation</th><th>Catégorie</th><th class="num">Qté</th><th class="num">Prix achat</th><th class="num">Montant</th></tr></thead><tbody><?php foreach($rows as $r): $m=(float)$r['quantite']*(float)$r['prix']; ?><tr><td><?=h($r['nom']??'Article')?></td><td><?=h($r['categorie']??'')?></td><td class="num"><?=h($r['quantite'])?></td><td class="num"><?=money($r['prix'])?></td><td class="num"><?=money($m)?></td></tr><?php endforeach; ?></tbody></table>
<div class="totals"><div class="row"><span>Total</span><b><?=money($total)?></b></div><div class="row"><span>Déjà payé</span><b><?=money($paid)?></b></div><div class="row grand"><span>Reste fournisseur</span><b><?=money($rest)?></b></div></div>
<div class="signature"><div class="sigbox"><div class="space"><img id="signatureImg" class="hidden" src="/assets/signature.png" alt="Signature"></div><div class="sigline">Responsable</div></div></div></div>
<script>const c=document.getElementById('addSignature'),img=document.getElementById('signatureImg');c.addEventListener('change',()=>img.classList.toggle('hidden',!c.checked));</script></body></html>
<?php exit; }

/* =========================
   POST
   ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';

        /* ---------- PRODUIT ---------- */
        if ($action === 'create_product' || $action === 'update_product') {
            $id = (int)($_POST['id'] ?? 0);
            $nom = cleanText($_POST['nom'] ?? '');
            $categorie = cleanText($_POST['categorie'] ?? '');
            $prixAchat = (float)($_POST['prix_achat'] ?? 0);
            $prixVente = (float)($_POST['prix_vente'] ?? 0);
            $stock = max(0, (int)($_POST['stock'] ?? 0));
            if ($nom === '') throw new Exception('Le nom du produit est obligatoire.');
            if ($prixAchat < 0 || $prixVente < 0) throw new Exception('Les prix ne peuvent pas être négatifs.');

            if ($action === 'create_product') {
                $conn->begin_transaction();
                try {
                    $pid = nextId($conn, 'produits');
                    $st = $conn->prepare('INSERT INTO produits (id, nom, categorie, prix_achat, prix_vente, stock) VALUES (?,?,?,?,?,?)');
                    if (!$st) throw new Exception($conn->error);
                    $st->bind_param('issddi', $pid, $nom, $categorie, $prixAchat, $prixVente, $stock);
                    if (!$st->execute()) { $e=$st->error; $st->close(); throw new Exception($e); }
                    $st->close();
                    if ($stock > 0) {
                        $mid = nextId($conn, 'mouvements');
                        $desc = 'PRODUIT_INIT=1|ARTICLE=' . $nom;
                        $date = date('Y-m-d H:i:s');
                        $st = $conn->prepare("INSERT INTO mouvements (id, produit_id, type, quantite, prix, description, date_mouvement) VALUES (?,?, 'ENTREE',?,?,?,?)");
                        if (!$st) throw new Exception($conn->error);
                        $st->bind_param('iiidss', $mid, $pid, $stock, $prixAchat, $desc, $date);
                        if (!$st->execute()) { $e=$st->error; $st->close(); throw new Exception($e); }
                        $st->close();
                    }
                    $conn->commit();
                } catch (Throwable $e) { $conn->rollback(); throw $e; }
                flash('ok','Produit ajouté avec succès.');
                header('Location: produits.php'); exit;
            }

            $st = $conn->prepare('UPDATE produits SET nom=?, categorie=?, prix_achat=?, prix_vente=? WHERE id=?');
            if (!$st) throw new Exception($conn->error);
            $st->bind_param('ssddi', $nom, $categorie, $prixAchat, $prixVente, $id);
            if (!$st->execute()) { $e=$st->error; $st->close(); throw new Exception($e); }
            $st->close();
            flash('ok','Produit modifié.');
            header('Location: produits.php'); exit;
        }

        /* ---------- ACHAT ---------- */
        if ($action === 'save_purchase') {
            $editRef = cleanText($_POST['edit_ref'] ?? '');
            $fourn = cleanText($_POST['fournisseur'] ?? '');
            $ids = $_POST['produit_id'] ?? [];
            $qtys = $_POST['quantite'] ?? [];
            $prices = $_POST['prix'] ?? [];
            if ($fourn === '') throw new Exception('Veuillez renseigner le fournisseur.');
            $lines=[];
            for($i=0;$i<count($ids);$i++){
                $pid=(int)($ids[$i]??0); $q=(int)($qtys[$i]??0); $p=(float)($prices[$i]??0);
                if($pid>0 && $q>0 && $p>=0) $lines[]=['pid'=>$pid,'q'=>$q,'p'=>$p];
            }
            if (!$lines) throw new Exception('Ajoutez au moins un article.');

            $old=[]; $oldPaid=0; $ref=$editRef;
            if($editRef!==''){
                $old=invoiceRows($conn,$editRef);
                if(!$old) throw new Exception('Facture à modifier introuvable.');
                $om=parseMeta($old[0]['description']);
                $oldPaid=(float)($om['PAYE']??0);
                $newTotal=0; foreach($lines as $l) $newTotal += $l['q']*$l['p'];
                if($oldPaid>0 && $newTotal+0.01<$oldPaid) throw new Exception('Le nouveau total ne peut pas être inférieur au montant déjà payé : '.money($oldPaid).'.');
                if($oldPaid>0 && $oldPaid > 0.01 && count($old) > 0) {
                    // Modification autorisée tant que la facture n'est pas totalement payée.
                    $oldTotal=0; foreach($old as $r) $oldTotal += (float)$r['quantite']*(float)$r['prix'];
                    if($oldTotal - $oldPaid <= 0.01) throw new Exception('Cette facture est totalement payée et verrouillée.');
                }
            } else {
                $ref=purchaseRef($conn);
            }

            $newTotal=0; foreach($lines as $l) $newTotal += $l['q']*$l['p'];
            $newRest=max(0,$newTotal-$oldPaid);

            $conn->begin_transaction();
            try{
                if($old){
                    restorePurchaseStock($conn,$old);
                    deletePurchaseRows($conn,$editRef);
                }

                foreach($lines as $l){
                    $pid=$l['pid']; $q=$l['q']; $p=$l['p'];
                    $chk=$conn->query("SELECT id, nom, stock FROM produits WHERE id=$pid LIMIT 1");
                    if(!$chk || !$chk->num_rows) throw new Exception('Article introuvable.');
                    $mid=nextId($conn,'mouvements');
                    $desc='FACTURE='.$ref.'|FOURNISSEUR='.cleanText($fourn).'|PAYE='.$oldPaid.'|RESTE='.$newRest.'|ACHAT';
                    $date=date('Y-m-d H:i:s');
                    $st=$conn->prepare("INSERT INTO mouvements (id,produit_id,type,quantite,prix,description,date_mouvement) VALUES (?,?, 'ENTREE',?,?,?,?)");
                    if(!$st) throw new Exception($conn->error);
                    $st->bind_param('iiidss',$mid,$pid,$q,$p,$desc,$date);
                    if(!$st->execute()){ $e=$st->error; $st->close(); throw new Exception($e); }
                    $st->close();
                    $st=$conn->prepare('UPDATE produits SET stock=stock+?, prix_achat=? WHERE id=?');
                    if(!$st) throw new Exception($conn->error);
                    $st->bind_param('idi',$q,$p,$pid);
                    if(!$st->execute()){ $e=$st->error; $st->close(); throw new Exception($e); }
                    $st->close();
                }

                $conn->commit();
            } catch(Throwable $e){ $conn->rollback(); throw $e; }
            flash('ok','Facture '.$ref.' enregistrée.');
            header('Location: produits.php?facture='.urlencode($ref)); exit;
        }

        /* ---------- REGLEMENT ---------- */
        if ($action === 'pay_purchase') {
            $ref=cleanText($_POST['ref']??'');
            $montant=(float)($_POST['montant']??0);
            $rows=invoiceRows($conn,$ref);
            if(!$rows) throw new Exception('Facture introuvable.');
            $meta=parseMeta($rows[0]['description']);
            $total=0; foreach($rows as $r) $total += (float)$r['quantite']*(float)$r['prix'];
            $old=(float)($meta['PAYE']??0);
            $rest=max(0,$total-$old);
            if($rest<=0.01) throw new Exception('Cette facture est déjà totalement payée.');
            if($montant<=0 || $montant>$rest+0.01) throw new Exception('Montant de règlement invalide.');
            $new=$old+$montant; $newRest=max(0,$total-$new);
            $safe=$conn->real_escape_string($ref); $fourn=cleanText($meta['FOURNISSEUR']??'');
            $desc='FACTURE='.$ref.'|FOURNISSEUR='.$fourn.'|PAYE='.$new.'|RESTE='.$newRest.'|ACHAT';
            $conn->begin_transaction();
            try{
                $safeDesc=$conn->real_escape_string($desc);
                $q=$conn->query("UPDATE mouvements SET description='$safeDesc' WHERE type='ENTREE' AND (description LIKE '%FACTURE=$safe|%' OR description LIKE '%FACTURE=$safe%' OR description LIKE '%Facture : $safe%')");
                if(!$q) throw new Exception($conn->error);
                $conn->commit();
            } catch(Throwable $e){$conn->rollback();throw $e;}
            flash('ok','Règlement enregistré.');
            header('Location: produits.php?facture='.urlencode($ref)); exit;
        }

        /* ---------- SUPPRESSION ACHAT ---------- */
        if ($action === 'delete_purchase') {
            $ref=cleanText($_POST['ref']??'');
            $rows=invoiceRows($conn,$ref);
            if(!$rows) throw new Exception('Facture introuvable.');
            $meta=parseMeta($rows[0]['description']);
            if((float)($meta['PAYE']??0)>0.01) throw new Exception('Impossible de supprimer une facture déjà payée en partie.');
            $conn->begin_transaction();
            try{
                restorePurchaseStock($conn,$rows);
                deletePurchaseRows($conn,$ref);
                $conn->commit();
            } catch(Throwable $e){$conn->rollback();throw $e;}
            flash('ok','Facture supprimée.');
            header('Location: produits.php'); exit;
        }
    } catch(Throwable $e){
        flash('err',$e->getMessage());
        header('Location: produits.php'); exit;
    }
}

/* =========================
   AFFICHAGE
   ========================= */
$flash=$_SESSION['flash']??null; unset($_SESSION['flash']);
$editRef=cleanText($_GET['modifier']??'');
$selectedRef=cleanText($_GET['facture']??'');
$editRows=$editRef?invoiceRows($conn,$editRef):[];
$detailRows=$selectedRef?invoiceRows($conn,$selectedRef):[];
$editProductId=(int)($_GET['modifier_produit']??0);

$products=[];
$q=$conn->query('SELECT id,nom,categorie,prix_achat,prix_vente,stock FROM produits ORDER BY nom ASC');
while($q&&($r=$q->fetch_assoc())) $products[]=$r;

$editProduct=null;
if($editProductId){
    $st=$conn->prepare('SELECT id,nom,categorie,prix_achat,prix_vente,stock FROM produits WHERE id=? LIMIT 1');
    $st->bind_param('i',$editProductId);$st->execute();$editProduct=$st->get_result()->fetch_assoc();$st->close();
}

$factures=[];
$q=$conn->query("SELECT m.*,p.nom FROM mouvements m LEFT JOIN produits p ON p.id=m.produit_id WHERE m.type='ENTREE' ORDER BY m.id DESC");
while($q&&($r=$q->fetch_assoc())){
    $m=parseMeta($r['description']);
    $ref=$m['FACTURE']??('ANCIEN-'.$r['id']);
    if(!isset($factures[$ref])){
        $factures[$ref]=['ref'=>$ref,'fournisseur'=>$m['FOURNISSEUR']??'Fournisseur','date'=>$r['date_mouvement']??'','total'=>0,'paye'=>(float)($m['PAYE']??0),'articles'=>0];
    }
    $factures[$ref]['total']+=(float)$r['quantite']*(float)$r['prix'];
    $factures[$ref]['articles']++;
}

$totalStock=0;$stockValue=0;$low=0;$out=0;
foreach($products as $p){$totalStock+=(int)$p['stock'];$stockValue+=(int)$p['stock']*(float)$p['prix_achat'];if((int)$p['stock']<=0)$out++;elseif((int)$p['stock']<=5)$low++;}
?>
<!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Achats & Fournisseurs — LAMBEMAH</title>
<style>
:root{--navy:#092a46;--blue:#1976e8;--soft:#eef5fc;--line:#d9e4ee;--green:#0b9f6d;--red:#dc3f3f;--text:#17324a}*{box-sizing:border-box}body{margin:0;font-family:Arial,Helvetica,sans-serif;background:#f3f7fb;color:var(--text)}a{text-decoration:none;color:inherit}.layout{display:flex;min-height:100vh}.side{width:270px;position:fixed;inset:0 auto 0 0;background:linear-gradient(180deg,#103b5f,#082a45);color:#fff;padding:28px 20px;overflow:auto}.brand{font-size:28px;font-weight:900}.sub{color:#d6e7f5;margin-top:6px}.nav{margin-top:40px}.nav a{display:block;padding:12px 15px;border-radius:12px;margin:6px 0;font-size:16px}.nav a:hover,.nav a.active{background:#236ce5}.main{margin-left:270px;width:calc(100% - 270px);padding:30px;max-width:1700px}.head{display:flex;justify-content:space-between;gap:15px;align-items:flex-start}.head h1{margin:0;font-size:30px}.head p{margin:6px 0;color:#708296}.btn{border:0;border-radius:9px;padding:11px 14px;background:var(--blue);color:#fff;font-weight:800;cursor:pointer;display:inline-block}.btn.secondary{background:#e7eff8;color:var(--navy)}.btn.green{background:var(--green)}.btn.danger{background:#fee2e2;color:#9a1e1e}.flash{margin-top:16px;padding:12px 14px;border-radius:10px}.ok{background:#dcfce7;color:#166534}.err{background:#fee2e2;color:#991b1b}.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-top:20px}.stat{background:#fff;border:1px solid var(--line);border-radius:14px;padding:16px}.stat small{color:#6d8091}.stat b{display:block;font-size:22px;margin-top:6px}.card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:20px;margin-top:18px;box-shadow:0 6px 20px #1234510a}.sectionTitle{display:flex;justify-content:space-between;align-items:center;gap:10px}.sectionTitle h2{margin:0;font-size:20px}.formgrid{display:grid;grid-template-columns:1.2fr 1fr 1fr 1fr;gap:10px}.field label{display:block;font-size:12px;font-weight:800;margin-bottom:6px}.field input,.field select{width:100%;padding:12px;border:1px solid #cbd8e4;border-radius:9px;background:#fff;font-size:14px;min-height:44px}.wide{grid-column:span 2}.productForm{display:grid;grid-template-columns:1.3fr 1fr 1fr 1fr .9fr auto;gap:10px;align-items:end}.purchaseLine{display:grid;grid-template-columns:2fr .8fr 1fr .9fr auto;gap:8px;align-items:end;margin-bottom:9px}.lineTotal{background:#f7fafc}.totalbox{display:flex;justify-content:flex-end;font-size:22px;font-weight:900;margin-top:12px}.actions{display:flex;gap:7px;flex-wrap:wrap}.tablewrap{overflow:auto}table{width:100%;border-collapse:collapse;min-width:800px}th,td{padding:12px 10px;border-bottom:1px solid var(--line);text-align:left}th{background:#f3f7fb;color:#5e7184;font-size:11px;text-transform:uppercase}.money{font-weight:800}.tag{padding:6px 9px;border-radius:999px;font-size:11px;font-weight:800}.paid{background:#dcfce7;color:#166534}.partial{background:#fff7ed;color:#9a3412}.unpaid{background:#fee2e2;color:#991b1b}.mobile{display:none}.helper{color:#738497;font-size:11px;margin-top:8px}.twoCols{display:grid;grid-template-columns:1fr 1.55fr;gap:18px}.paybox{background:#eef6ff;border:1px solid #d5e5f5;border-radius:12px;padding:16px;margin-top:16px}.paygrid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}.paygrid small{color:#6b7e8f}.paygrid b{font-size:18px}.prodCard{background:#f9fbfd;border:1px solid #e1e9f0;border-radius:12px;padding:14px}.prodCard h3{margin:0 0 10px;font-size:16px}.empty{padding:20px;text-align:center;color:#708296}@media(max-width:1050px){.side{width:220px}.main{margin-left:220px;width:calc(100% - 220px);padding:22px}.stats{grid-template-columns:repeat(2,1fr)}.twoCols{grid-template-columns:1fr}.productForm{grid-template-columns:1.4fr 1fr 1fr 1fr 1fr auto}}@media(max-width:800px){.side{display:none}.main{margin:0;width:100%;padding:14px}.mobile{display:block}.head{display:block}.head .btn{margin-top:10px}.stats{grid-template-columns:1fr 1fr;gap:8px}.stat{padding:11px}.stat b{font-size:16px}.productForm{grid-template-columns:1fr 1fr}.purchaseLine{grid-template-columns:1fr 1fr}.purchaseLine .wide{grid-column:1/-1}.formgrid{grid-template-columns:1fr}.wide{grid-column:auto}.card{padding:14px}.paygrid{grid-template-columns:1fr 1fr}.actions .btn{flex:1;text-align:center}.tablewrap{margin:0 -2px}table{min-width:760px}}@media(max-width:450px){.stats{grid-template-columns:1fr}.productForm{grid-template-columns:1fr}.purchaseLine{grid-template-columns:1fr 1fr}.purchaseLine button{grid-column:1/-1}.head h1{font-size:22px}.sectionTitle{display:block}.sectionTitle .actions{margin-top:10px}}
</style></head>
<body><div class="layout">
<aside class="side"><div class="brand">LAMBEMAH</div><div class="sub">GESTION • PRESTATION</div><nav class="nav"><a href="index.php">Accueil</a><a class="active" href="produits.php">Achats / Fournisseurs</a><a href="ventes.php">Ventes / Clients</a><a href="prestations.php">Prestations</a><a href="recettes.php">Recettes</a><a href="depenses.php">Dépenses</a><a href="statistiques.php">Statistiques</a><a href="utilisateurs.php">Équipe</a><a href="index.php?logout=1">Déconnexion</a></nav></aside>
<main class="main">
<div class="mobile"><a class="btn secondary" href="index.php">☰ Menu</a></div>
<div class="head"><div><h1>📦 Achats & Fournisseurs</h1><p>Produits, achats, stock et règlements.</p></div><div class="actions"><a class="btn" href="?nouvel_achat=1">＋ Nouvelle facture d’achat</a><a class="btn secondary" href="#nouveauProduit">＋ Nouveau produit</a></div></div>
<?php if($flash): ?><div class="flash <?=h($flash['type'])?>"><?=h($flash['msg'])?></div><?php endif; ?>
<div class="stats"><div class="stat"><small>Factures d’achat</small><b><?=count($factures)?></b></div><div class="stat"><small>Articles en stock</small><b><?=number_format($totalStock,0,',',' ')?></b></div><div class="stat"><small>Valeur du stock</small><b><?=money($stockValue)?></b></div><div class="stat"><small>Alertes</small><b><?=$low?> faible · <?=$out?> rupture</b></div></div>

<div class="twoCols">
<section class="card" id="nouveauProduit">
<div class="sectionTitle"><h2><?=$editProduct?'✏️ Modifier le produit':'＋ Nouveau produit'?></h2><?php if($editProduct): ?><a class="btn secondary" href="produits.php#nouveauProduit">Annuler</a><?php endif; ?></div>
<p class="helper">Ajoute ici un nouvel article avant de créer une facture d’achat.</p>
<form method="post" style="margin-top:14px">
<input type="hidden" name="action" value="<?=$editProduct?'update_product':'create_product'?>"><?php if($editProduct): ?><input type="hidden" name="id" value="<?=$editProduct['id']?>"><?php endif; ?>
<div class="field"><label>Nom du produit</label><input name="nom" required value="<?=h($editProduct['nom']??'')?>" placeholder="Ex. T-shirt enfant"></div>
<div class="field" style="margin-top:10px"><label>Catégorie</label><input name="categorie" value="<?=h($editProduct['categorie']??'')?>" placeholder="Ex. T-shirt, Pull, Képi..."></div>
<div class="productForm" style="margin-top:10px">
<div class="field"><label>Prix d’achat</label><input type="number" min="0" step="1" name="prix_achat" value="<?=h($editProduct['prix_achat']??'')?>" required></div>
<div class="field"><label>Prix de vente</label><input type="number" min="0" step="1" name="prix_vente" value="<?=h($editProduct['prix_vente']??'')?>" required></div>
<div class="field"><label>Stock initial</label><input type="number" min="0" step="1" name="stock" value="<?=h($editProduct['stock']??0)?>"></div>
<div></div><div></div>
</div>
<div class="actions" style="margin-top:12px"><button class="btn green" type="submit"><?=$editProduct?'Enregistrer les modifications':'Ajouter le produit'?></button></div>
</form>
</section>

<section class="card">
<div class="sectionTitle"><h2>📋 Produits & stock</h2></div>
<div class="tablewrap" style="margin-top:12px"><table><thead><tr><th>Produit</th><th>Catégorie</th><th>Prix achat</th><th>Prix vente</th><th>Stock</th><th></th></tr></thead><tbody>
<?php foreach($products as $p): ?><tr><td><b><?=h($p['nom'])?></b></td><td><?=h($p['categorie']?:'—')?></td><td class="money"><?=money($p['prix_achat'])?></td><td class="money"><?=money($p['prix_vente'])?></td><td><span class="tag <?=((int)$p['stock']<=0?'unpaid':((int)$p['stock']<=5?'partial':'paid'))?>"><?=number_format((int)$p['stock'],0,',',' ')?></span></td><td><a class="btn secondary" href="?modifier_produit=<?=$p['id']?>#nouveauProduit">Modifier</a></td></tr><?php endforeach; ?>
<?php if(!$products): ?><tr><td colspan="6" class="empty">Aucun produit.</td></tr><?php endif; ?></tbody></table></div>
</section>
</div>

<?php if(isset($_GET['nouvel_achat']) || $editRows): ?>
<section class="card">
<div class="sectionTitle"><h2><?=$editRows?'✏️ Modifier la facture '.$editRef:'＋ Nouvelle facture d’achat'?></h2></div>
<form method="post"><input type="hidden" name="action" value="save_purchase"><input type="hidden" name="edit_ref" value="<?=h($editRef)?>">
<div class="formgrid" style="margin-top:14px"><div class="field"><label>Fournisseur</label><input name="fournisseur" required value="<?=h($editRows?(parseMeta($editRows[0]['description'])['FOURNISSEUR']??''):'')?>" placeholder="Nom du fournisseur"></div><div class="field"><label>Référence</label><input readonly value="<?=h($editRef?:'Automatique')?>"></div></div>
<h3>Articles</h3><div id="purchaseLines">
<?php $base=$editRows?:[['produit_id'=>'','quantite'=>1,'prix'=>'']]; foreach($base as $r): ?><div class="purchaseLine"><div class="field wide"><label>Article</label><select name="produit_id[]" required><option value="">Choisir</option><?php foreach($products as $p): ?><option value="<?=$p['id']?>" <?=((int)$p['id']===(int)($r['produit_id']??0))?'selected':''?>><?=h($p['nom'])?> — stock <?=$p['stock']?></option><?php endforeach; ?></select></div><div class="field"><label>Quantité</label><input type="number" min="1" name="quantite[]" value="<?=h($r['quantite']??1)?>" required></div><div class="field"><label>Prix achat</label><input type="number" min="0" name="prix[]" value="<?=h($r['prix']??'')?>" required></div><div class="field"><label>Montant</label><input readonly class="lineTotal" value="0 FG"></div><button class="btn danger" type="button" onclick="this.parentElement.remove();calcPurchase()">×</button></div><?php endforeach; ?></div>
<div class="actions"><button class="btn secondary" type="button" onclick="addPurchaseLine()">＋ Ajouter une ligne</button></div><div class="totalbox">Total : <span id="purchaseGrand">0 FG</span></div><div style="margin-top:12px" class="actions"><button class="btn green" type="submit">💾 Enregistrer la facture</button><a class="btn secondary" href="produits.php">Annuler</a></div></form>
</section>
<?php endif; ?>

<?php if($detailRows): $meta=parseMeta($detailRows[0]['description']); $total=0; foreach($detailRows as $r)$total+=(float)$r['quantite']*(float)$r['prix']; $paid=(float)($meta['PAYE']??0); $rest=max(0,$total-$paid); $status=$rest<=0.01?'Payée':($paid>0?'Partiellement payée':'Non payée'); ?>
<section class="card"><div class="sectionTitle"><h2>📄 Facture <?=h($selectedRef)?></h2><div class="actions"><a class="btn secondary" target="_blank" href="?imprimer=<?=urlencode($selectedRef)?>">🖨️ Imprimer / PDF</a><?php if($rest>0.01): ?><a class="btn secondary" href="?modifier=<?=urlencode($selectedRef)?>">✏️ Modifier</a><?php endif; ?><?php if($paid<=0.01): ?><form method="post" onsubmit="return confirm('Supprimer toute cette facture ?')"><input type="hidden" name="action" value="delete_purchase"><input type="hidden" name="ref" value="<?=h($selectedRef)?>"><button class="btn danger">🗑️ Supprimer</button></form><?php endif; ?></div></div><p><b>Fournisseur :</b> <?=h($meta['FOURNISSEUR']??'—')?></p>
<div class="tablewrap"><table><thead><tr><th>Désignation</th><th>Qté</th><th>Prix achat</th><th>Montant</th></tr></thead><tbody><?php foreach($detailRows as $r): ?><tr><td><?=h($r['nom']??'Article')?></td><td><?=h($r['quantite'])?></td><td><?=money($r['prix'])?></td><td class="money"><?=money((float)$r['quantite']*(float)$r['prix'])?></td></tr><?php endforeach; ?></tbody></table></div><div class="paybox"><div class="paygrid"><div><small>Total</small><br><b><?=money($total)?></b></div><div><small>Déjà payé</small><br><b><?=money($paid)?></b></div><div><small>Reste</small><br><b><?=money($rest)?></b></div></div><p><span class="tag <?=$rest<=0.01?'paid':($paid>0?'partial':'unpaid')?>"><?=h($status)?></span></p><?php if($rest>0.01): ?><form method="post" class="formgrid"><input type="hidden" name="action" value="pay_purchase"><input type="hidden" name="ref" value="<?=h($selectedRef)?>"><div class="field"><label>Montant à régler</label><input type="number" min="1" max="<?=h($rest)?>" step="1" name="montant" required></div><div><button class="btn green" type="submit">💰 Enregistrer le règlement</button></div></form><?php endif; ?></div></section>
<?php endif; ?>

<section class="card"><div class="sectionTitle"><h2>🧾 Achats / fournisseurs</h2><span class="helper">Toutes les factures non totalement payées restent modifiables.</span></div><div class="tablewrap" style="margin-top:10px"><table><thead><tr><th>Fournisseur</th><th>Facture</th><th>Date</th><th>Articles</th><th>Total</th><th>Payé</th><th>Reste</th><th>Statut</th><th></th></tr></thead><tbody><?php foreach($factures as $f): $rest=max(0,$f['total']-$f['paye']); $st=$rest<=0.01?'Payée':($f['paye']>0?'Partiellement payée':'Non payée'); ?><tr><td><b><?=h($f['fournisseur'])?></b></td><td><?=h($f['ref'])?></td><td><?=h($f['date']?date('d/m/Y',strtotime($f['date'])):'—')?></td><td><?=h($f['articles'])?></td><td class="money"><?=money($f['total'])?></td><td><?=money($f['paye'])?></td><td><?=money($rest)?></td><td><span class="tag <?=$rest<=0.01?'paid':($f['paye']>0?'partial':'unpaid')?>"><?=h($st)?></span></td><td><a class="btn" href="?facture=<?=urlencode($f['ref'])?>">Ouvrir</a></td></tr><?php endforeach; ?><?php if(!$factures): ?><tr><td colspan="9" class="empty">Aucune facture d’achat pour le moment.</td></tr><?php endif; ?></tbody></table></div></section>

</main></div>
<script>
function moneyJS(n){return new Intl.NumberFormat('fr-FR').format(Math.round(n))+' FG';}
function calcPurchase(){let t=0;document.querySelectorAll('#purchaseLines .purchaseLine').forEach(l=>{let q=parseFloat(l.querySelector('[name="quantite[]"]')?.value||0),p=parseFloat(l.querySelector('[name="prix[]"]')?.value||0),m=q*p;t+=m;let out=l.querySelector('.lineTotal');if(out)out.value=moneyJS(m);});let g=document.getElementById('purchaseGrand');if(g)g.textContent=moneyJS(t);}
function addPurchaseLine(){let b=document.getElementById('purchaseLines');let s=b.querySelector('.purchaseLine');if(!s)return;let x=s.cloneNode(true);x.querySelectorAll('input').forEach(i=>{if(i.name==='quantite[]')i.value=1;else if(i.name==='prix[]')i.value='';else if(i.classList.contains('lineTotal'))i.value='0 FG';});let sel=x.querySelector('select');if(sel)sel.selectedIndex=0;b.appendChild(x);calcPurchase();}
document.addEventListener('input',calcPurchase);calcPurchase();
</script></body></html>
