<?php
session_start();
require_once __DIR__ . '/config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Connexion à la base de données impossible.');
}
$conn->set_charset('utf8mb4');

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function money($v){ return number_format((float)$v, 0, ',', ' ') . ' FG'; }
function cleanText($v){ return trim(preg_replace('/[|\r\n]+/', ' ', (string)$v)); }
function nextId(mysqli $conn, string $table): int {
    $allowed = ['produits','mouvements','ventes'];
    if (!in_array($table, $allowed, true)) return 1;
    $r = $conn->query("SELECT COALESCE(MAX(id),0)+1 n FROM `$table`");
    return (int)($r->fetch_assoc()['n'] ?? 1);
}
function parseMeta(string $desc): array {
    $out=[];
    foreach (explode('|', $desc) as $part) {
        if (strpos($part,'=') !== false) {
            [$k,$v]=explode('=', $part,2); $out[$k]=trim($v);
        }
    }
    return $out;
}
function invoiceRows(mysqli $conn, string $ref): array {
    $rows=[];
    $safe=$conn->real_escape_string($ref);
    $q=$conn->query("SELECT m.*, p.nom, p.categorie FROM mouvements m LEFT JOIN produits p ON p.id=m.produit_id WHERE m.type='ENTREE' AND m.description LIKE '%FACTURE=$safe|%' ORDER BY m.id ASC");
    while($q && ($r=$q->fetch_assoc())) $rows[]=$r;
    return $rows;
}
function flash($type,$msg){ $_SESSION['flash']=['type'=>$type,'msg'=>$msg]; }

if (isset($_GET['imprimer'])) {
    $ref=cleanText($_GET['imprimer']);
    $rows=invoiceRows($conn,$ref);
    if (!$rows) die('Facture introuvable.');
    $meta=parseMeta($rows[0]['description']);
    $total=0; foreach($rows as $r) $total+=(float)$r['quantite']*(float)$r['prix'];
    $paid=(float)($meta['PAYE']??0); $rest=max(0,$total-$paid);
    $status=$paid<=0?'Non payée':($rest<=0?'Payée':'Partiellement payée');
    ?><!doctype html><html lang="fr"><head><meta charset="utf-8"><title><?=h($ref)?></title><style>
:root{--blue:#2563eb;--navy:#123451;--bg:#f5f8fc;--line:#e4ebf3;--green:#059669;--red:#dc2626}*{box-sizing:border-box}body{margin:0;font:14px Inter,Arial,sans-serif;background:var(--bg);color:#24364b}.layout{min-height:100vh}.side{width:235px;background:linear-gradient(180deg,#123a5b,#0d2b43);color:#fff;padding:20px 15px;position:fixed;inset:0 auto 0 0}.brand{font-size:23px;font-weight:800;letter-spacing:.3px}.sub{font-size:12px;color:#c9d9e8;margin-top:4px}.nav{margin-top:25px}.nav a{display:block;color:#fff;text-decoration:none;padding:10px 12px;border-radius:9px;margin:4px 0;font-size:14px}.nav a.active,.nav a:hover{background:var(--blue)}.main{margin-left:235px;width:calc(100% - 235px);padding:22px;max-width:1600px}.head{display:flex;justify-content:space-between;align-items:center;gap:15px}.head h1{margin:0;font-size:24px;color:var(--navy)}.head p{margin:5px 0 0;color:#66788b;font-size:13px}.btn{border:0;border-radius:8px;padding:8px 11px;background:var(--blue);color:#fff;font-weight:700;font-size:13px;cursor:pointer;text-decoration:none;display:inline-block;white-space:nowrap}.btn.secondary{background:#eaf1fa;color:var(--navy)}.btn.danger{background:#fee2e2;color:#991b1b}.btn.green{background:var(--green)}.card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:16px;margin-top:16px;box-shadow:0 4px 16px #1234510d}.card h2{font-size:18px;margin:0 0 8px}.card h3{font-size:15px;margin:16px 0 8px}.stats{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:15px}.stat{background:#fff;border:1px solid var(--line);border-radius:11px;padding:12px 14px;color:#647589;font-size:12px}.stat b{display:block;color:var(--navy);font-size:19px;margin-top:4px}.tablewrap{overflow:auto}table{width:100%;border-collapse:collapse;min-width:700px}th,td{padding:9px 10px;border-bottom:1px solid var(--line);text-align:left;font-size:13px}th{background:#f7f9fc;color:#617286;font-size:11px;text-transform:uppercase;letter-spacing:.2px}.money{font-weight:700;color:var(--navy)}.status{padding:4px 7px;border-radius:20px;font-size:11px;font-weight:800;white-space:nowrap}.paid{background:#dcfce7;color:#166534}.partial{background:#fff4df;color:#9a3412}.unpaid{background:#fee2e2;color:#991b1b}.actions{display:flex;gap:5px;flex-wrap:wrap}.flash{padding:10px 12px;border-radius:9px;margin:12px 0;font-size:13px}.ok{background:#dcfce7;color:#166534}.err{background:#fee2e2;color:#991b1b}.formgrid{display:grid;grid-template-columns:1.2fr 1fr;gap:9px}.field label{display:block;font-weight:700;font-size:12px;margin-bottom:4px}.field input,.field select{width:100%;padding:8px 9px;border:1px solid #cbd7e4;border-radius:7px;background:#fff;font-size:13px}.line{display:grid;grid-template-columns:2fr 1fr 1fr 1fr auto;gap:7px;align-items:end;margin-bottom:7px}.totalbox{display:flex;justify-content:flex-end;font-size:18px;font-weight:800;padding-top:9px}.detailhead{display:flex;justify-content:space-between;gap:15px;align-items:flex-start}.detailtable{min-width:0}.detailtable th,.detailtable td{padding:8px}.paybox{background:#f3f7fd;border:1px solid #d9e5f2;padding:13px;border-radius:10px;margin-top:13px}.mobile{display:none}@media(max-width:850px){.side{display:none}.main{margin:0;width:100%;padding:12px}.mobile{display:block;margin-bottom:10px}.head{display:block}.head h1{font-size:20px}.head .btn{margin-top:10px}.stats{grid-template-columns:repeat(3,1fr);gap:7px}.stat{padding:9px}.stat b{font-size:16px}.card{padding:12px;margin-top:12px}.card h2{font-size:16px}.formgrid{grid-template-columns:1fr}.line{grid-template-columns:1fr 1fr}.line .wide{grid-column:1/-1}.line .field:nth-child(4){grid-column:1/-1}.line>.btn{align-self:end}.actions .btn{font-size:11px;padding:7px 8px}th,td{padding:8px;font-size:12px}table{min-width:620px}.detailhead{display:block}.paybox{font-size:12px}.paybox .line{grid-template-columns:1fr 1fr 1fr!important}.totalbox{font-size:17px}}
</style></head><body><div class="paper"><div class="top"><div><div class="brand">LAMBEMAH</div><div>GESTION • ACHATS</div></div><div class="title">FACTURE D'ACHAT<br><small><?=h($ref)?></small></div></div>
    <div class="info"><div class="box"><b>Fournisseur</b><br><?=h($meta['FOURNISSEUR']??'—')?></div><div class="box"><b>Date</b><br><?=h(date('d/m/Y H:i',strtotime($rows[0]['date_mouvement']??'now')))?></div></div>
    <table><thead><tr><th>Désignation</th><th>Catégorie</th><th class="num">Qté</th><th class="num">Prix achat</th><th class="num">Montant</th></tr></thead><tbody><?php foreach($rows as $r): $m=(float)$r['quantite']*(float)$r['prix'];?><tr><td><?=h($r['nom']??'Article')?></td><td><?=h($r['categorie']??'')?></td><td class="num"><?=h($r['quantite'])?></td><td class="num"><?=money($r['prix'])?></td><td class="num"><?=money($m)?></td></tr><?php endforeach;?></tbody></table>
    <div class="totals"><div class="line"><span>Total facture</span><b><?=money($total)?></b></div><div class="line"><span>Déjà payé</span><b><?=money($paid)?></b></div><div class="line"><span>Reste fournisseur</span><b><?=money($rest)?></b></div><div class="line grand"><span>Statut</span><span class="status"><?=h($status)?></span></div></div><div class="actions"><button onclick="window.print()">🖨️ Imprimer / Enregistrer en PDF</button></div></div></body></html><?php exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if (isset($_POST['save_achat'])) {
            $ref=cleanText($_POST['facture_ref']??'');
            $fourn=cleanText($_POST['fournisseur']??'');
            $editRef=cleanText($_POST['edit_ref']??'');
            $ids=$_POST['produit_id']??[]; $qtys=$_POST['quantite']??[]; $prices=$_POST['prix']??[];
            if($fourn==='') throw new Exception('Veuillez renseigner le fournisseur.');
            $lines=[];
            for($i=0;$i<count($ids);$i++){
                $pid=(int)$ids[$i]; $q=(int)($qtys[$i]??0); $p=(float)($prices[$i]??0);
                if($pid>0 && $q>0 && $p>=0) $lines[]=['pid'=>$pid,'q'=>$q,'p'=>$p];
            }
            if(!$lines) throw new Exception('Ajoutez au moins un article.');
            if($editRef!==''){
                $old=invoiceRows($conn,$editRef);
                if(!$old) throw new Exception('Facture à modifier introuvable.');
                $oldMeta=parseMeta($old[0]['description']); $oldPaid=(float)($oldMeta['PAYE']??0);
                if($oldPaid>0) throw new Exception('Cette facture est protégée car un paiement existe déjà.');
                foreach($old as $r){ $pid=(int)$r['produit_id']; $q=(int)$r['quantite']; $conn->query("UPDATE produits SET stock=stock-$q WHERE id=$pid"); }
                $safe=$conn->real_escape_string($editRef); $conn->query("DELETE FROM mouvements WHERE type='ENTREE' AND description LIKE '%FACTURE=$safe|%'");
                $ref=$editRef;
            } else {
                $ref='ACH-'.date('Ymd-His').'-'.nextId($conn,'mouvements');
            }
            $conn->begin_transaction();
            try{
                foreach($lines as $l){
                    $pid=$l['pid']; $q=$l['q']; $p=$l['p'];
                    $chk=$conn->query("SELECT id,nom FROM produits WHERE id=$pid"); if(!$chk || !$chk->num_rows) throw new Exception('Article introuvable.');
                    $mid=nextId($conn,'mouvements'); $desc="FACTURE=$ref|FOURNISSEUR=".cleanText($fourn)."|PAYE=0|RESTE=0|ACHAT";
                    $stmt=$conn->prepare("INSERT INTO mouvements (id,produit_id,type,quantite,prix,description,date_mouvement) VALUES (?,?, 'ENTREE',?,?,?,NOW())");
                    $stmt->bind_param('iiids',$mid,$pid,$q,$p,$desc); $stmt->execute();
                    $conn->query("UPDATE produits SET stock=stock+$q, prix_achat=".(float)$p." WHERE id=$pid");
                }
                $conn->commit(); flash('ok','Facture '.$ref.' enregistrée avec succès.');
            }catch(Throwable $e){$conn->rollback();throw $e;}
            header('Location: produits.php?facture='.urlencode($ref)); exit;
        }
        if(isset($_POST['regler_facture'])){
            $ref=cleanText($_POST['ref']??''); $montant=(float)($_POST['montant']??0); $rows=invoiceRows($conn,$ref);
            if(!$rows) throw new Exception('Facture introuvable.');
            $meta=parseMeta($rows[0]['description']); $total=0; foreach($rows as $r)$total+=(float)$r['quantite']*(float)$r['prix'];
            $oldPaid=(float)($meta['PAYE']??0); $newPaid=$oldPaid+$montant;
            if($montant<=0 || $newPaid>$total+0.01) throw new Exception('Montant de règlement invalide.');
            $rest=max(0,$total-$newPaid); $safe=$conn->real_escape_string($ref);
            $fourn=cleanText($meta['FOURNISSEUR']??''); $conn->query("UPDATE mouvements SET description='FACTURE=$safe|FOURNISSEUR=".$conn->real_escape_string($fourn)."|PAYE=$newPaid|RESTE=$rest|ACHAT' WHERE type='ENTREE' AND description LIKE '%FACTURE=$safe|%'");
            flash('ok','Règlement enregistré.'); header('Location: produits.php?facture='.urlencode($ref)); exit;
        }
        if(isset($_POST['supprimer_facture'])){
            $ref=cleanText($_POST['ref']??''); $rows=invoiceRows($conn,$ref); if(!$rows) throw new Exception('Facture introuvable.');
            $meta=parseMeta($rows[0]['description']); if((float)($meta['PAYE']??0)>0) throw new Exception('Impossible de supprimer une facture ayant un paiement.');
            $conn->begin_transaction(); try{foreach($rows as $r){$pid=(int)$r['produit_id'];$q=(int)$r['quantite'];$conn->query("UPDATE produits SET stock=stock-$q WHERE id=$pid");}$safe=$conn->real_escape_string($ref);$conn->query("DELETE FROM mouvements WHERE type='ENTREE' AND description LIKE '%FACTURE=$safe|%'");$conn->commit();}catch(Throwable $e){$conn->rollback();throw $e;}
            flash('ok','Facture supprimée.'); header('Location: produits.php'); exit;
        }
    } catch(Throwable $e){ flash('err',$e->getMessage()); header('Location: produits.php'); exit; }
}

$flash=$_SESSION['flash']??null; unset($_SESSION['flash']);
$editRef=cleanText($_GET['modifier']??''); $selectedRef=cleanText($_GET['facture']??'');
$editRows=$editRef?invoiceRows($conn,$editRef):[]; $detailRows=$selectedRef?invoiceRows($conn,$selectedRef):[];

$factures=[]; $q=$conn->query("SELECT m.*,p.nom FROM mouvements m LEFT JOIN produits p ON p.id=m.produit_id WHERE m.type='ENTREE' ORDER BY m.id DESC");
while($q && ($r=$q->fetch_assoc())){ $meta=parseMeta($r['description']); $ref=$meta['FACTURE']??('ANCIEN-'.$r['id']); if(!isset($factures[$ref]))$factures[$ref]=['ref'=>$ref,'fournisseur'=>$meta['FOURNISSEUR']??'Fournisseur non renseigné','date'=>$r['date_mouvement']??'','total'=>0,'paye'=>(float)($meta['PAYE']??0),'articles'=>0]; $factures[$ref]['total']+=(float)$r['quantite']*(float)$r['prix']; $factures[$ref]['articles']++; if(empty($factures[$ref]['date']))$factures[$ref]['date']=$r['date_mouvement']??''; }
$products=[];$q=$conn->query("SELECT id,nom,categorie,prix_achat,stock FROM produits ORDER BY nom ASC");while($q&&($r=$q->fetch_assoc()))$products[]=$r;
$stockValue=0;$totalStock=0;foreach($products as $p){$totalStock+=(int)$p['stock'];$stockValue+=(int)$p['stock']*(float)$p['prix_achat'];}
?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Achats / Fournisseurs — LAMBEMAH</title><style>
:root{--blue:#2563eb;--navy:#102f4b;--sky:#eef5ff;--green:#059669;--red:#dc2626;--gold:#d97706}*{box-sizing:border-box}body{margin:0;font-family:Inter,Arial,sans-serif;background:#f4f8fd;color:#14283d}.layout{display:flex;min-height:100vh}.side{width:270px;background:linear-gradient(180deg,#103454,#0b2840);color:white;padding:28px 20px;position:fixed;inset:0 auto 0 0}.brand{font-size:29px;font-weight:800}.sub{margin-top:7px;color:#d8e7f6}.nav{margin-top:40px}.nav a{display:block;color:#fff;text-decoration:none;padding:14px 16px;border-radius:13px;margin:7px 0;font-size:17px}.nav a.active,.nav a:hover{background:#2563eb}.main{margin-left:270px;width:calc(100% - 270px);padding:30px}.head{display:flex;justify-content:space-between;align-items:center;gap:20px}.head h1{margin:0;font-size:30px}.btn{border:0;border-radius:10px;padding:11px 15px;background:var(--blue);color:white;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block}.btn.secondary{background:#e8f0fb;color:var(--navy)}.btn.danger{background:#fee2e2;color:#991b1b}.btn.green{background:#059669}.card{background:#fff;border:1px solid #e1eaf5;border-radius:18px;padding:22px;margin-top:22px;box-shadow:0 7px 25px #1e3a5f0a}.stats{display:grid;grid-template-columns:repeat(3,1fr);gap:15px}.stat{background:linear-gradient(135deg,#fff,#f1f7ff);border:1px solid #dfebfa;border-radius:16px;padding:18px}.stat b{font-size:25px;display:block;margin-top:7px}.tablewrap{overflow:auto}table{width:100%;border-collapse:collapse;min-width:760px}th,td{padding:14px 12px;border-bottom:1px solid #e5edf6;text-align:left}th{background:#f3f7fc;color:#49627a;font-size:13px;text-transform:uppercase}.money{font-weight:800}.status{padding:7px 10px;border-radius:999px;font-size:12px;font-weight:800}.paid{background:#dcfce7;color:#166534}.partial{background:#fff7ed;color:#9a3412}.unpaid{background:#fee2e2;color:#991b1b}.actions{display:flex;gap:7px;flex-wrap:wrap}.flash{padding:13px 16px;border-radius:12px;margin:16px 0}.ok{background:#dcfce7;color:#166534}.err{background:#fee2e2;color:#991b1b}.formgrid{display:grid;grid-template-columns:1.2fr 1fr 1fr 1fr;gap:10px}.field label{display:block;font-weight:700;font-size:13px;margin-bottom:6px}.field input,.field select{width:100%;padding:11px;border:1px solid #cbd8e7;border-radius:9px;background:white}.line{display:grid;grid-template-columns:2fr 1fr 1fr 1fr auto;gap:8px;align-items:end;margin-bottom:9px}.totalbox{display:flex;justify-content:flex-end;font-size:22px;font-weight:800;padding-top:12px}.detailhead{display:flex;justify-content:space-between;gap:20px;align-items:flex-start}.detailtable{min-width:0}.detailtable th,.detailtable td{padding:11px}.paybox{background:#f1f7ff;border:1px solid #d5e5fb;padding:18px;border-radius:14px;margin-top:18px}.mobile{display:none}@media(max-width:850px){.side{display:none}.main{margin:0;width:100%;padding:16px}.mobile{display:block}.head{align-items:flex-start}.stats{grid-template-columns:1fr}.formgrid{grid-template-columns:1fr}.line{grid-template-columns:1fr 1fr}.line .wide{grid-column:1/-1}.actions .btn{font-size:12px;padding:9px}.card{padding:15px}}
</style></head><body><div class="layout"><aside class="side"><div class="brand">LAMBEMAH</div><div class="sub">GESTION • PRESTATION</div><nav class="nav"><a href="index.php">Accueil</a><a class="active" href="produits.php">Achats / Fournisseurs</a><a href="ventes.php">Ventes / Clients</a><a href="prestations.php">Prestations</a><a href="recettes.php">Recettes</a><a href="depenses.php">Dépenses</a><a href="statistiques.php">Statistiques</a><a href="utilisateurs.php">Équipe</a><a href="index.php?logout=1">Déconnexion</a></nav></aside><main class="main"><div class="mobile"><a class="btn secondary" href="index.php">☰ Menu</a></div><div class="head"><div><h1>🧾 Achats & Fournisseurs</h1><p>Chaque achat = une facture indépendante.</p></div><a class="btn" href="?nouvel_achat=1">＋ Nouvelle facture d'achat</a></div><?php if($flash):?><div class="flash <?=h($flash['type'])?>"><?=h($flash['msg'])?></div><?php endif;?>
<div class="stats"><div class="stat">Factures d'achat<b><?=count($factures)?></b></div><div class="stat">Articles en stock<b><?=number_format($totalStock,0,',',' ')?></b></div><div class="stat">Valeur du stock<b><?=money($stockValue)?></b></div></div>
<?php if(isset($_GET['nouvel_achat'])||$editRows): ?><div class="card"><h2><?= $editRows?'✏️ Modifier la facture '.h($editRef):'＋ Nouvelle facture d’achat'?></h2><form method="post"><input type="hidden" name="save_achat" value="1"><input type="hidden" name="edit_ref" value="<?=h($editRef)?>"><div class="formgrid"><div class="field"><label>Fournisseur</label><input required name="fournisseur" value="<?=h($editRows?parseMeta($editRows[0]['description'])['FOURNISSEUR']??'':'')?>" placeholder="Nom du fournisseur"></div><div class="field"><label>Référence</label><input readonly name="facture_ref" value="<?=h($editRef?:'Automatique')?>"></div></div><h3>Articles de la facture</h3><div id="lines"><?php $base=$editRows?:[['produit_id'=>'','quantite'=>'1','prix'=>'']];foreach($base as $r):?><div class="line"><div class="field wide"><label>Article</label><select name="produit_id[]" required><option value="">Choisir</option><?php foreach($products as $p):?><option value="<?=$p['id']?>" <?=((int)$p['id']===(int)($r['produit_id']??0))?'selected':''?>><?=h($p['nom'])?> — stock <?=$p['stock']?></option><?php endforeach;?></select></div><div class="field"><label>Quantité</label><input type="number" min="1" name="quantite[]" value="<?=h($r['quantite']??1)?>" required></div><div class="field"><label>Prix achat</label><input type="number" min="0" name="prix[]" value="<?=h($r['prix']??'')?>" required></div><div class="field"><label>Montant</label><input readonly class="lineTotal" value="0 FG"></div><button type="button" class="btn danger" onclick="this.parentElement.remove();calc()">×</button></div><?php endforeach;?></div><button type="button" class="btn secondary" onclick="addLine()">＋ Ajouter une ligne</button><div class="totalbox">Total : <span id="grand">0 FG</span></div><br><button class="btn green">💾 Enregistrer la facture</button><a class="btn secondary" href="produits.php">Annuler</a></form></div><?php endif;?>
<?php if($detailRows): $meta=parseMeta($detailRows[0]['description']);$total=0;foreach($detailRows as $r)$total+=(float)$r['quantite']*(float)$r['prix'];$paid=(float)($meta['PAYE']??0);$rest=max(0,$total-$paid);$status=$paid<=0?'Non payée':($rest<=0?'Payée':'Partiellement payée');?><div class="card"><div class="detailhead"><div><h2>📄 Facture <?=h($selectedRef)?></h2><p><b>Fournisseur :</b> <?=h($meta['FOURNISSEUR']??'—')?></p></div><div class="actions"><a class="btn secondary" href="?imprimer=<?=urlencode($selectedRef)?>" target="_blank">🖨️ Imprimer / PDF</a><?php if($paid<=0):?><a class="btn secondary" href="?modifier=<?=urlencode($selectedRef)?>">✏️ Modifier</a><form method="post" onsubmit="return confirm('Supprimer toute cette facture ?');"><input type="hidden" name="supprimer_facture" value="1"><input type="hidden" name="ref" value="<?=h($selectedRef)?>"><button class="btn danger">🗑️ Supprimer</button></form><?php endif;?></div></div><div class="tablewrap"><table class="detailtable"><thead><tr><th>Désignation</th><th>Qté</th><th>Prix achat</th><th>Montant</th></tr></thead><tbody><?php foreach($detailRows as $r):?><tr><td><?=h($r['nom']??'Article')?></td><td><?=h($r['quantite'])?></td><td><?=money($r['prix'])?></td><td class="money"><?=money((float)$r['quantite']*(float)$r['prix'])?></td></tr><?php endforeach;?></tbody></table></div><div class="paybox"><div class="line" style="grid-template-columns:1fr 1fr 1fr"><div><small>Total</small><br><b><?=money($total)?></b></div><div><small>Payé</small><br><b><?=money($paid)?></b></div><div><small>Reste</small><br><b><?=money($rest)?></b></div></div><p>Statut : <span class="status <?=$rest<=0?'paid':($paid>0?'partial':'unpaid')?>"><?=h($status)?></span></p><?php if($rest>0):?><form method="post" class="formgrid"><input type="hidden" name="regler_facture" value="1"><input type="hidden" name="ref" value="<?=h($selectedRef)?>"><div class="field"><label>Montant à régler</label><input type="number" min="1" max="<?=h($rest)?>" step="1" name="montant" required></div><div><button class="btn green">💰 Régler cette facture</button></div></form><?php endif;?></div></div><?php endif;?>
<div class="card"><h2>📋 Fournisseurs & factures</h2><p>Cliquez sur une facture pour voir toutes ses lignes, son total et son règlement.</p><div class="tablewrap"><table><thead><tr><th>Fournisseur</th><th>Facture</th><th>Date</th><th>Articles</th><th>Total</th><th>Payé</th><th>Reste</th><th>Statut</th><th></th></tr></thead><tbody><?php foreach($factures as $f):$rest=max(0,$f['total']-$f['paye']);$st=$f['paye']<=0?'Non payée':($rest<=0?'Payée':'Partiellement payée');?><tr><td><b><?=h($f['fournisseur'])?></b></td><td><?=h($f['ref'])?></td><td><?=h($f['date']?date('d/m/Y',strtotime($f['date'])):'—')?></td><td><?=h($f['articles'])?></td><td class="money"><?=money($f['total'])?></td><td><?=money($f['paye'])?></td><td><?=money($rest)?></td><td><span class="status <?=$rest<=0?'paid':($f['paye']>0?'partial':'unpaid')?>"><?=h($st)?></span></td><td><a class="btn" href="?facture=<?=urlencode($f['ref'])?>">Ouvrir</a></td></tr><?php endforeach;?><?php if(!$factures):?><tr><td colspan="9">Aucune facture d'achat pour le moment.</td></tr><?php endif;?></tbody></table></div></div>
<div class="card"><h2>📦 Stock actuel</h2><div class="tablewrap"><table><thead><tr><th>Article</th><th>Catégorie</th><th>Prix achat</th><th>Stock</th></tr></thead><tbody><?php foreach($products as $p):?><tr><td><?=h($p['nom'])?></td><td><?=h($p['categorie'])?></td><td><?=money($p['prix_achat'])?></td><td><b><?=h($p['stock'])?></b></td></tr><?php endforeach;?></tbody></table></div></div>
</main></div><script>function fmt(n){return new Intl.NumberFormat('fr-FR').format(Math.round(n))+' FG'}function calc(){let total=0;document.querySelectorAll('.line').forEach(l=>{let q=parseFloat(l.querySelector('[name="quantite[]"]')?.value||0),p=parseFloat(l.querySelector('[name="prix[]"]')?.value||0),m=q*p;total+=m;let t=l.querySelector('.lineTotal');if(t)t.value=fmt(m)});let g=document.getElementById('grand');if(g)g.textContent=fmt(total)}function addLine(){let box=document.getElementById('lines'),src=box.querySelector('.line');let x=src.cloneNode(true);x.querySelectorAll('input').forEach(i=>{if(i.name==='quantite[]')i.value=1;else if(i.name==='prix[]')i.value='';else if(i.classList.contains('lineTotal'))i.value='0 FG'});x.querySelector('select').selectedIndex=0;box.appendChild(x);calc()}document.addEventListener('input',calc);calc();</script></body></html>
