<?php
session_start();
require_once "config.php";

if (!isset($_SESSION["id"])) { header("Location: index.php"); exit; }

$nom=$_SESSION["nom"]??"Utilisateur"; $role=$_SESSION["role"]??"lecture"; $message=""; $type="";
function argent($n){return number_format((float)$n,0,","," ")." FG";}
function prochain_id($conn,$table){$table=preg_replace('/[^a-zA-Z0-9_]/','',$table);$r=$conn->query("SELECT COALESCE(MAX(id),0)+1 AS id FROM $table");return (int)$r->fetch_assoc()["id"];}
function montant_description($d,$label,$default=0){preg_match('/'.preg_quote($label,'/').'\s*:\s*([0-9\s,\.]+)\s*FG/i',$d??"",$m);return isset($m[1])?(float)str_replace([" ",","],["","."],$m[1]):$default;}
function ref_vente($d,$id=0){if(preg_match('/REF VENTE\s*:\s*([A-Z0-9\-]+)/i',$d??"",$m))return $m[1];return $id?"V-$id":"";}

/* Modifier une ligne non payée */
if($_SERVER["REQUEST_METHOD"]==="POST"&&isset($_POST["modifier_vente"])){
    $id=(int)($_POST["vente_id"]??0);$pid=(int)($_POST["produit_id"]??0);$q=(int)($_POST["quantite"]??0);$pu=(float)($_POST["prix_unitaire"]??0);$client=trim($_POST["client"]??"");
    $conn->begin_transaction();
    try{
        $st=$conn->prepare("SELECT * FROM ventes WHERE id=? LIMIT 1");$st->bind_param("i",$id);$st->execute();$old=$st->get_result()->fetch_assoc();$st->close();
        if(!$old)throw new Exception("Vente introuvable.");
        $oldPaid=montant_description($old["description"]??"","Payé");
        if($oldPaid>0)throw new Exception("Cette vente est déjà payée ou comporte une avance. Elle est verrouillée.");
        $st=$conn->prepare("SELECT nom,stock FROM produits WHERE id=? LIMIT 1");$st->bind_param("i",$pid);$st->execute();$p=$st->get_result()->fetch_assoc();$st->close();
        if(!$p)throw new Exception("Article introuvable.");
        $oldPid=(int)$old["produit_id"];$oldQ=(int)$old["quantite"];
        $st=$conn->prepare("UPDATE produits SET stock=stock+? WHERE id=?");$st->bind_param("ii",$oldQ,$oldPid);if(!$st->execute())throw new Exception($st->error);$st->close();
        $available=(int)$p["stock"]+($pid===$oldPid?$oldQ:0);
        if($available<$q)throw new Exception("Stock insuffisant.");
        $st=$conn->prepare("UPDATE produits SET stock=stock-? WHERE id=?");$st->bind_param("ii",$q,$pid);if(!$st->execute())throw new Exception($st->error);$st->close();
        $ref=ref_vente($old["description"],$id);$amount=$q*$pu;
        $desc="VENTE | REF VENTE : $ref | Client : ".($client?: "Client comptoir")." | Désignation : ".$p["nom"]." | Total facture : ".argent($amount)." | Payé : 0 FG | Reste : ".argent($amount);
        $st=$conn->prepare("UPDATE ventes SET produit_id=?,quantite=?,prix_unitaire=?,montant=?,description=? WHERE id=?");$st->bind_param("iiddsi",$pid,$q,$pu,$amount,$desc,$id);if(!$st->execute())throw new Exception($st->error);$st->close();
        $conn->commit();$message="Vente modifiée."; $type="success";
    }catch(Exception $e){$conn->rollback();$message="Impossible de modifier : ".$e->getMessage();$type="error";}
}

/* Supprimer une ligne non payée */
if($_SERVER["REQUEST_METHOD"]==="POST"&&isset($_POST["supprimer_vente"])){
    $id=(int)($_POST["vente_id"]??0);$conn->begin_transaction();
    try{
        $st=$conn->prepare("SELECT * FROM ventes WHERE id=? LIMIT 1");$st->bind_param("i",$id);$st->execute();$v=$st->get_result()->fetch_assoc();$st->close();
        if(!$v)throw new Exception("Vente introuvable.");
        if(montant_description($v["description"]??"","Payé")>0)throw new Exception("Cette vente est payée ou comporte une avance. Elle ne peut pas être supprimée.");
        $st=$conn->prepare("UPDATE produits SET stock=stock+? WHERE id=?");$st->bind_param("ii",$v["quantite"],$v["produit_id"]);if(!$st->execute())throw new Exception($st->error);$st->close();
        $st=$conn->prepare("DELETE FROM ventes WHERE id=?");$st->bind_param("i",$id);if(!$st->execute())throw new Exception($st->error);$st->close();
        $conn->commit();$message="Vente supprimée et stock restauré."; $type="success";
    }catch(Exception $e){$conn->rollback();$message="Impossible de supprimer : ".$e->getMessage();$type="error";}
}

/* Annuler une vente payée/avec avance */
if($_SERVER["REQUEST_METHOD"]==="POST"&&isset($_POST["annuler_vente"])){
    $id=(int)($_POST["vente_id"]??0);$conn->begin_transaction();
    try{
        $st=$conn->prepare("SELECT * FROM ventes WHERE id=? LIMIT 1");$st->bind_param("i",$id);$st->execute();$v=$st->get_result()->fetch_assoc();$st->close();
        if(!$v)throw new Exception("Vente introuvable.");
        if(stripos($v["description"]??"","ANNULÉ")!==false||stripos($v["description"]??"","ANNULEE")!==false)throw new Exception("Vente déjà annulée.");
        $st=$conn->prepare("UPDATE produits SET stock=stock+? WHERE id=?");$st->bind_param("ii",$v["quantite"],$v["produit_id"]);if(!$st->execute())throw new Exception($st->error);$st->close();
        $d=($v["description"]??"")." | ANNULÉE LE ".date("d/m/Y H:i");
        $st=$conn->prepare("UPDATE ventes SET description=? WHERE id=?");$st->bind_param("si",$d,$id);if(!$st->execute())throw new Exception($st->error);$st->close();
        $conn->commit();$message="Vente annulée et stock restauré."; $type="success";
    }catch(Exception $e){$conn->rollback();$message="Impossible d'annuler : ".$e->getMessage();$type="error";}
}

/* Régler une facture */
if($_SERVER["REQUEST_METHOD"]==="POST"&&isset($_POST["regler_vente"])){
    $ref=trim($_POST["ref_vente"]??"");$versement=(float)($_POST["versement"]??0);
    $conn->begin_transaction();
    try{
        if($ref===""||$versement<=0)throw new Exception("Montant de règlement incorrect.");
        if (preg_match('/^V-(\d+)$/', $ref, $rm)) {
            $vidLegacy=(int)$rm[1];
            $st=$conn->prepare("SELECT * FROM ventes WHERE id=? LIMIT 1");
            $st->bind_param("i",$vidLegacy);
        } else {
            $st=$conn->prepare("SELECT * FROM ventes WHERE description LIKE ? ORDER BY id ASC");
            $like="%REF VENTE : ".$ref."%";$st->bind_param("s",$like);
        }
        $st->execute();$res=$st->get_result();$rows=[];$total=0;$paid=0;
        while($r=$res->fetch_assoc()){if(stripos($r["description"],"ANNULÉ")!==false)continue;$rows[]=$r;$total+=(float)$r["montant"]; $paid=max($paid,montant_description($r["description"],"Payé"));}
        $st->close();if(!$rows)throw new Exception("Facture introuvable.");
        $reste=max(0,$total-$paid);if($versement>$reste)throw new Exception("Le règlement dépasse le reste à payer.");
        $newPaid=$paid+$versement;$newRest=max(0,$total-$newPaid);
        foreach($rows as $r){
            $d=$r["description"]; $d=preg_replace('/\s*\|\s*Total facture\s*:\s*[0-9\s,\.]+\s*FG/i',"",$d);$d=preg_replace('/\s*\|\s*Payé\s*:\s*[0-9\s,\.]+\s*FG/i',"",$d);$d=preg_replace('/\s*\|\s*Reste\s*:\s*[0-9\s,\.]+\s*FG/i',"",$d);$d=rtrim($d," |");
            $d.=" | Total facture : ".argent($total)." | Payé : ".argent($newPaid)." | Reste : ".argent($newRest);
            $st=$conn->prepare("UPDATE ventes SET description=? WHERE id=?");$st->bind_param("si",$d,$r["id"]);if(!$st->execute())throw new Exception($st->error);$st->close();
        }
        $conn->commit();$message=$newRest<=0?"Facture totalement payée.":"Paiement enregistré. Reste : ".argent($newRest);$type="success";
    }catch(Exception $e){$conn->rollback();$message="Impossible d'enregistrer le paiement : ".$e->getMessage();$type="error";}
}

/* Nouvelle vente */
if($_SERVER["REQUEST_METHOD"]==="POST"&&isset($_POST["enregistrer_vente"])){
    $client=trim($_POST["client"]??"");$paye=(float)($_POST["paye"]??0);$ids=$_POST["produit_id"]??[];$qs=$_POST["quantite"]??[];$prs=$_POST["prix"]??[];$lignes=[];$total=0;
    for($i=0;$i<count($ids);$i++){ $pid=(int)($ids[$i]??0);$q=(int)($qs[$i]??0);$pu=(float)($prs[$i]??0);if($pid>0&&$q>0&&$pu>=0){$lignes[]=["pid"=>$pid,"q"=>$q,"pu"=>$pu];$total+=$q*$pu;}}
    if(!$lignes){$message="Ajoute au moins un article."; $type="error";}elseif($paye<0||$paye>$total){$message="Le montant payé est incorrect."; $type="error";}else{
        $conn->begin_transaction();
        try{
            $date=date("Y-m-d H:i:s");$ref="V-".date("YmdHis")."-".prochain_id($conn,"ventes");$reste=$total-$paye;
            foreach($lignes as $l){
                $st=$conn->prepare("SELECT nom,stock FROM produits WHERE id=? LIMIT 1");$st->bind_param("i",$l["pid"]);$st->execute();$p=$st->get_result()->fetch_assoc();$st->close();
                if(!$p)throw new Exception("Article introuvable.");if((int)$p["stock"]<$l["q"])throw new Exception("Stock insuffisant pour ".$p["nom"]);
                $amount=$l["q"]*$l["pu"];$desc="VENTE | REF VENTE : $ref | Client : ".($client?: "Client comptoir")." | Désignation : ".$p["nom"]." | Total facture : ".argent($total)." | Payé : ".argent($paye)." | Reste : ".argent($reste);
                $id=prochain_id($conn,"ventes");$st=$conn->prepare("INSERT INTO ventes (id,produit_id,quantite,prix_unitaire,montant,description,date_vente) VALUES (?,?,?,?,?,?,?)");$st->bind_param("iiiddss",$id,$l["pid"],$l["q"],$l["pu"],$amount,$desc,$date);if(!$st->execute())throw new Exception($st->error);$st->close();
                $st=$conn->prepare("UPDATE produits SET stock=stock-? WHERE id=?");$st->bind_param("ii",$l["q"],$l["pid"]);if(!$st->execute())throw new Exception($st->error);$st->close();
            }
            $conn->commit();$message="Vente enregistrée : ".argent($total)." | Payé : ".argent($paye)." | Reste : ".argent($reste);$type="success";
        }catch(Exception $e){$conn->rollback();$message="Erreur : ".$e->getMessage();$type="error";}
    }
}

/* Edition */
$vente_edit=null;
if(isset($_GET["modifier"])){ $id=(int)$_GET["modifier"];if($id>0){$st=$conn->prepare("SELECT * FROM ventes WHERE id=? LIMIT 1");$st->bind_param("i",$id);$st->execute();$vente_edit=$st->get_result()->fetch_assoc();$st->close();} }

/* Facture imprimable */
$facture=null;$facture_lignes=[];
if(isset($_GET["facture"])){
    $id=(int)$_GET["facture"];
    if($id>0){
        $st=$conn->prepare("SELECT * FROM ventes WHERE id=? LIMIT 1");$st->bind_param("i",$id);$st->execute();$one=$st->get_result()->fetch_assoc();$st->close();
        if($one){$ref=ref_vente($one["description"],$id);$like="%REF VENTE : ".$ref."%";$st=$conn->prepare("SELECT v.*,p.nom AS produit_nom FROM ventes v LEFT JOIN produits p ON p.id=v.produit_id WHERE v.description LIKE ? ORDER BY v.id ASC");$st->bind_param("s",$like);$st->execute();$rr=$st->get_result();while($r=$rr->fetch_assoc())$facture_lignes[]=$r;$st->close();$facture=$one;}
    }
}
$produits=$conn->query("SELECT id,nom,stock FROM produits ORDER BY nom ASC");
$ventes=$conn->query("SELECT v.*,p.nom AS produit_nom FROM ventes v LEFT JOIN produits p ON p.id=v.produit_id ORDER BY v.id DESC LIMIT 100");
?>
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>LAMBEMAH • Ventes</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
body{margin:0;background:#f4f7fb;font-family:Arial,sans-serif;color:#172033}.sidebar{position:fixed;left:0;top:0;width:245px;height:100vh;background:#102a43;padding:20px 15px;color:#fff}.logo{font-size:22px;font-weight:bold;padding:5px 10px 20px}.logo small{display:block;font-size:11px;color:#9cc8ff}.menu a{display:flex;gap:10px;color:#dbeafe;text-decoration:none;padding:11px;border-radius:9px;margin-bottom:4px}.menu a:hover,.menu a.active{background:#1d4ed8;color:#fff}.main{margin-left:245px;padding:25px}.box{background:#fff;border-radius:15px;padding:20px;margin-bottom:20px;box-shadow:0 3px 15px rgba(0,0,0,.05)}.ligne{background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;padding:12px;margin-bottom:10px}.total{background:#eef5ff;border-radius:10px;padding:15px;font-size:20px;font-weight:bold}.reste{color:#b42318}.badge{font-size:12px}@media(max-width:768px){.sidebar{position:static;width:100%;height:auto}.menu{display:grid;grid-template-columns:repeat(4,1fr)}.menu a{flex-direction:column;justify-content:center;font-size:10px;text-align:center}.main{margin-left:0;padding:14px}.table{font-size:12px}}
@media print{.sidebar,.no-print{display:none!important}.main{margin:0;padding:0}.box{box-shadow:none}}
</style></head><body>
<aside class="sidebar"><div class="logo">LAMBEMAH<small>GESTION • PRESTATION</small></div><nav class="menu"><a href="index.php"><i class="bi bi-house"></i>Accueil</a><a href="produits.php"><i class="bi bi-box"></i>Achats / Stock</a><a href="ventes.php" class="active"><i class="bi bi-cart-check"></i>Ventes</a><a href="prestations.php"><i class="bi bi-printer"></i>Prestations</a><a href="recettes.php"><i class="bi bi-cash-coin"></i>Recettes</a><a href="depenses.php"><i class="bi bi-wallet2"></i>Dépenses</a><a href="statistiques.php"><i class="bi bi-bar-chart"></i>Statistiques</a><?php if($role==="admin"): ?><a href="utilisateurs.php"><i class="bi bi-people"></i>Équipe</a><?php endif; ?><a href="index.php?logout=1"><i class="bi bi-box-arrow-right"></i>Déconnexion</a></nav></aside>
<main class="main">
<?php if($facture): $d=$facture["description"]??"";$client="Client comptoir";if(preg_match('/Client\s*:\s*(.*?)\s*\|/i',$d,$m))$client=$m[1];$ref=ref_vente($d,$facture["id"]);$totalFact=0;$paid=0;foreach($facture_lignes as $fl){$totalFact+=(float)$fl["montant"]; $paid=max($paid,montant_description($fl["description"],"Payé"));}$rest=max(0,$totalFact-$paid); ?>
<div class="box"><div class="d-flex justify-content-between no-print"><h2>🧾 Facture <?= htmlspecialchars($ref) ?></h2><button class="btn btn-primary" onclick="window.print()">🖨️ Imprimer</button></div><hr><p><strong>Client :</strong> <?= htmlspecialchars($client) ?></p><table class="table"><thead><tr><th>Article</th><th>Qté</th><th>PU</th><th>Total</th></tr></thead><tbody><?php foreach($facture_lignes as $fl): ?><tr><td><?= htmlspecialchars($fl["produit_nom"]??"Article") ?></td><td><?= (int)$fl["quantite"] ?></td><td><?= argent($fl["prix_unitaire"]) ?></td><td><?= argent($fl["montant"]) ?></td></tr><?php endforeach; ?></tbody></table><h4 class="text-end">Total : <?= argent($totalFact) ?></h4><p class="text-end">Payé : <?= argent($paid) ?> — Reste : <strong><?= argent($rest) ?></strong></p><a href="ventes.php" class="btn btn-light no-print">← Retour</a></div>
<?php else: ?>
<h1>🛒 Ventes</h1><p class="text-muted">Une vente peut être non payée, partiellement payée ou totalement payée.</p>
<?php if($message): ?><div class="alert <?= $type==="success"?"alert-success":"alert-danger" ?>"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if($vente_edit): $edPaid=montant_description($vente_edit["description"]??"","Payé"); ?>
<div class="box"><h4>✏️ Modifier la vente</h4><?php if($edPaid>0): ?><div class="alert alert-warning">Cette vente est verrouillée car elle comporte déjà un paiement.</div><?php else: ?><form method="post"><input type="hidden" name="modifier_vente" value="1"><input type="hidden" name="vente_id" value="<?= (int)$vente_edit["id"] ?>"><div class="row g-3"><div class="col-md-4"><label>Client</label><input name="client" class="form-control" value="<?php if(preg_match('/Client\s*:\s*(.*?)\s*\|/i',$vente_edit["description"],$m))echo htmlspecialchars($m[1]); ?>"></div><div class="col-md-4"><label>Article</label><select name="produit_id" class="form-select"><?php $pe=$conn->query("SELECT id,nom,stock FROM produits ORDER BY nom");while($p=$pe->fetch_assoc()): ?><option value="<?= (int)$p["id"] ?>" <?= (int)$p["id"]===(int)$vente_edit["produit_id"]?"selected":"" ?>><?= htmlspecialchars($p["nom"]) ?> — Stock <?= (int)$p["stock"] ?></option><?php endwhile; ?></select></div><div class="col-md-2"><label>Prix unitaire</label><input type="number" name="prix_unitaire" class="form-control" value="<?= (float)$vente_edit["prix_unitaire"] ?>" min="0"></div><div class="col-md-2"><label>Quantité</label><input type="number" name="quantite" class="form-control" value="<?= (int)$vente_edit["quantite"] ?>" min="1"></div></div><button class="btn btn-primary mt-3">Enregistrer</button> <a href="ventes.php" class="btn btn-light mt-3">Annuler</a></form><?php endif; ?></div><?php endif; ?>

<div class="box"><h4>➕ Nouvelle vente</h4><form method="post"><input type="hidden" name="enregistrer_vente" value="1"><div class="mb-3"><label>Client</label><input name="client" class="form-control" placeholder="Nom du client"></div><div id="lignes"><div class="ligne"><div class="row g-2 align-items-end"><div class="col-md-5"><label>Article</label><select name="produit_id[]" class="form-select" required><option value="">Choisir</option><?php $pf=$conn->query("SELECT id,nom,stock FROM produits ORDER BY nom");while($p=$pf->fetch_assoc()): ?><option value="<?= (int)$p["id"] ?>"><?= htmlspecialchars($p["nom"]) ?> — Stock <?= (int)$p["stock"] ?></option><?php endwhile; ?></select></div><div class="col-md-2"><label>Prix de vente</label><input type="number" name="prix[]" class="form-control prix" min="0" value="0" oninput="calculer()" required></div><div class="col-md-2"><label>Quantité</label><input type="number" name="quantite[]" class="form-control quantite" min="1" value="1" oninput="calculer()" required></div><div class="col-md-2"><label>Montant</label><input type="text" class="form-control montant" value="0 FG" readonly></div><div class="col-md-1"><button type="button" class="btn btn-danger" onclick="supprimerLigne(this)">🗑️</button></div></div></div></div><button type="button" class="btn btn-outline-primary mb-3" onclick="ajouterLigne()">➕ Ajouter un autre article</button><div class="total mb-3">TOTAL : <span id="total">0 FG</span></div><label>Montant payé / avance</label><input type="number" name="paye" id="paye" class="form-control mb-3" min="0" value="0" oninput="calculer()"><div class="total mb-3">RESTE : <span id="reste" class="reste">0 FG</span></div><button class="btn btn-primary btn-lg">✓ Enregistrer la vente</button></form></div>

<div class="box"><h4>🕘 Dernières ventes</h4><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Article</th><th>Client</th><th>PU</th><th>Qté</th><th>Montant</th><th>Paiement</th><th>Date</th><th>Action</th></tr></thead><tbody>
<?php if($ventes&&$ventes->num_rows): while($v=$ventes->fetch_assoc()): $d=$v["description"]??"";$client="Client comptoir";if(preg_match('/Client\s*:\s*(.*?)\s*\|/i',$d,$cm))$client=$cm[1];$totalFact=(float)$v["montant"];if(preg_match('/Total facture\s*:\s*([0-9\s,\.]+)\s*FG/i',$d,$tm))$totalFact=(float)str_replace([" ",","],["","."],$tm[1]);$paid=montant_description($d,"Payé");$rest=max(0,$totalFact-$paid);$ref=ref_vente($d,(int)$v["id"]);$ann=(stripos($d,"ANNULÉE")!==false||stripos($d,"ANNULEE")!==false); ?>
<tr><td><strong><?= htmlspecialchars($v["produit_nom"]??"Article") ?></strong><?php if($ann): ?><br><span class="badge text-bg-secondary">Annulée</span><?php endif; ?></td><td><?= htmlspecialchars($client) ?></td><td><?= argent($v["prix_unitaire"]) ?></td><td><?= (int)$v["quantite"] ?></td><td><strong><?= argent($v["montant"]) ?></strong></td><td><?php if($ann): ?><span class="badge text-bg-secondary">Annulée</span><?php elseif($rest<=0): ?><span class="badge text-bg-success">Payé</span><?php elseif($paid>0): ?><span class="badge text-bg-warning">Partiellement payé</span><?php else: ?><span class="badge text-bg-success">Non payé</span><?php endif; ?><br><small>Payé : <?= argent($paid) ?><br>Reste : <?= argent($rest) ?></small></td><td><?= !empty($v["date_vente"])?date("d/m/Y H:i",strtotime($v["date_vente"])):"-" ?></td><td class="text-nowrap"><a class="btn btn-sm btn-outline-primary" href="ventes.php?facture=<?= (int)$v["id"] ?>">🧾</a><?php if(!$ann&&$rest>0): ?><button class="btn btn-sm btn-success" onclick="ouvrirReglement('<?= htmlspecialchars($ref,ENT_QUOTES) ?>','<?= htmlspecialchars($client,ENT_QUOTES) ?>',<?= $rest ?>)">💰</button><?php endif; ?><?php if(!$ann&&$paid<=0): ?><a class="btn btn-sm btn-outline-primary" href="ventes.php?modifier=<?= (int)$v["id"] ?>">✏️</a><form method="post" class="d-inline" onsubmit="return confirm('Supprimer cette vente ?')"><input type="hidden" name="supprimer_vente" value="1"><input type="hidden" name="vente_id" value="<?= (int)$v["id"] ?>"><button class="btn btn-sm btn-outline-danger">🗑️</button></form><?php elseif(!$ann&&$paid>0): ?><form method="post" class="d-inline" onsubmit="return confirm('Annuler cette vente ? Le stock sera restauré.')"><input type="hidden" name="annuler_vente" value="1"><input type="hidden" name="vente_id" value="<?= (int)$v["id"] ?>"><button class="btn btn-sm btn-outline-secondary">Annuler</button></form><?php endif; ?></td></tr>
<?php endwhile;else: ?><tr><td colspan="8" class="text-center">Aucune vente enregistrée.</td></tr><?php endif; ?></tbody></table></div></div>

<div class="modal fade" id="modalReglement" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="post"><div class="modal-header"><h5 class="modal-title">💰 Régler la facture</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="regler_vente" value="1"><input type="hidden" name="ref_vente" id="ref_vente"><p>Client : <strong id="nom_client"></strong></p><p>Reste : <strong id="reste_modal"></strong></p><label>Montant du règlement</label><input type="number" name="versement" id="versement" class="form-control" min="1" required></div><div class="modal-footer"><button class="btn btn-primary">Enregistrer le paiement</button></div></form></div></div></div>
<?php endif; ?>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script><script>
function argent(n){return new Intl.NumberFormat("fr-FR").format(n)+" FG";}
function calculer(){let t=0;document.querySelectorAll(".ligne").forEach(l=>{let p=parseFloat(l.querySelector(".prix").value)||0,q=parseInt(l.querySelector(".quantite").value)||0,m=p*q;l.querySelector(".montant").value=argent(m);t+=m;});document.getElementById("total").textContent=argent(t);let pay=parseFloat(document.getElementById("paye").value)||0;document.getElementById("reste").textContent=argent(Math.max(0,t-pay));}
function ajouterLigne(){let c=document.getElementById("lignes"),n=c.querySelector(".ligne").cloneNode(true);n.querySelectorAll("input").forEach(i=>{if(i.classList.contains("prix"))i.value=0;else if(i.classList.contains("quantite"))i.value=1;else if(i.classList.contains("montant"))i.value="0 FG";});n.querySelector("select").selectedIndex=0;c.appendChild(n);calculer();}
function supprimerLigne(b){let l=document.querySelectorAll(".ligne");if(l.length<=1)return alert("Il faut garder au moins un article.");b.closest(".ligne").remove();calculer();}
function ouvrirReglement(ref,client,reste){document.getElementById("ref_vente").value=ref;document.getElementById("nom_client").textContent=client;document.getElementById("reste_modal").textContent=argent(reste);let v=document.getElementById("versement");v.max=reste;v.value=reste;new bootstrap.Modal(document.getElementById("modalReglement")).show();}
document.addEventListener("DOMContentLoaded",calculer);
</script></body></html>
