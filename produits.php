<?php
session_start();
require_once "config.php";

if (!isset($_SESSION["id"])) {
    header("Location: index.php");
    exit;
}

$nom = $_SESSION["nom"] ?? "Utilisateur";
$role = $_SESSION["role"] ?? "lecture";
$message = "";
$type = "";

function argent($n) {
    return number_format((float)$n, 0, ",", " ") . " FG";
}
function prochain_id($conn, $table) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $r = $conn->query("SELECT COALESCE(MAX(id),0)+1 AS id FROM $table");
    return (int)$r->fetch_assoc()["id"];
}
function montant_description($description, $label, $default=0) {
    preg_match('/'.preg_quote($label,'/').'\s*:\s*([0-9\s,\.]+)\s*FG/i', $description ?? "", $m);
    if (!isset($m[1])) return $default;
    return (float)str_replace([" ", ","], ["", "."], $m[1]);
}
function ref_achat($description, $fallbackId=0) {
    if (preg_match('/REF ACHAT\s*:\s*([A-Z0-9\-]+)/i', $description ?? "", $m)) return $m[1];
    return $fallbackId ? "M-$fallbackId" : "";
}
function achat_status($paye, $total) {
    if ($paye <= 0) return ["Non payé","success"];
    if ($paye < $total) return ["Partiellement payé","warning"];
    return ["Payé","success"];
}

/* Modifier un article */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["modifier_article"])) {
    $id=(int)($_POST["id"]??0);
    $nomArticle=trim($_POST["nom"]??"");
    $cat=trim($_POST["categorie"]??"");
    $prix=(float)($_POST["prix_achat"]??0);
    if($id<=0 || $nomArticle==="" || $prix<0){
        $message="Informations incorrectes."; $type="error";
    }else{
        $st=$conn->prepare("UPDATE produits SET nom=?, categorie=?, prix_achat=? WHERE id=?");
        $st->bind_param("ssdi",$nomArticle,$cat,$prix,$id);
        if($st->execute()){ $message="Article modifié."; $type="success"; } else { $message="Erreur : ".$st->error; $type="error"; }
        $st->close();
    }
}

/* Supprimer un achat non payé */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["supprimer_achat"])) {
    $mid=(int)($_POST["mouvement_id"]??0);
    $conn->begin_transaction();
    try{
        $st=$conn->prepare("SELECT * FROM mouvements WHERE id=? AND type='ENTREE' LIMIT 1");
        $st->bind_param("i",$mid); $st->execute(); $a=$st->get_result()->fetch_assoc(); $st->close();
        if(!$a) throw new Exception("Achat introuvable.");
        $desc=$a["description"]??"";
        $paye=montant_description($desc,"Payé");
        if($paye>0) throw new Exception("Cet achat est payé ou comporte une avance. Utilise Annuler.");
        $q=(int)$a["quantite"]; $pid=(int)$a["produit_id"];
        $st=$conn->prepare("UPDATE produits SET stock=stock-? WHERE id=? AND stock>=?");
        $st->bind_param("iii",$q,$pid,$q);
        if(!$st->execute() || $st->affected_rows===0) throw new Exception("Impossible de corriger le stock.");
        $st->close();
        $st=$conn->prepare("DELETE FROM mouvements WHERE id=?");
        $st->bind_param("i",$mid); if(!$st->execute()) throw new Exception($st->error); $st->close();
        $conn->commit(); $message="Achat supprimé et stock corrigé."; $type="success";
    }catch(Exception $e){$conn->rollback();$message="Impossible de supprimer : ".$e->getMessage();$type="error";}
}

/* Annuler un achat */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["annuler_achat"])) {
    $mid=(int)($_POST["mouvement_id"]??0);
    $conn->begin_transaction();
    try{
        $st=$conn->prepare("SELECT * FROM mouvements WHERE id=? AND type='ENTREE' LIMIT 1");
        $st->bind_param("i",$mid); $st->execute(); $a=$st->get_result()->fetch_assoc(); $st->close();
        if(!$a) throw new Exception("Achat introuvable.");
        $desc=$a["description"]??"";
        if(stripos($desc,"ANNULÉ")!==false || stripos($desc,"ANNULE")!==false) throw new Exception("Cet achat est déjà annulé.");
        $q=(int)$a["quantite"]; $pid=(int)$a["produit_id"];
        $st=$conn->prepare("UPDATE produits SET stock=stock-? WHERE id=? AND stock>=?");
        $st->bind_param("iii",$q,$pid,$q);
        if(!$st->execute() || $st->affected_rows===0) throw new Exception("Impossible de corriger le stock.");
        $st->close();
        $new=$desc." | ANNULÉ LE ".date("d/m/Y H:i");
        $st=$conn->prepare("UPDATE mouvements SET description=? WHERE id=?");
        $st->bind_param("si",$new,$mid); if(!$st->execute()) throw new Exception($st->error); $st->close();
        $conn->commit(); $message="Achat annulé. Le stock a été restauré."; $type="success";
    }catch(Exception $e){$conn->rollback();$message="Impossible d'annuler : ".$e->getMessage();$type="error";}
}

/* Régler un achat */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["regler_achat"])) {
    $ref=trim($_POST["ref_achat"]??"");
    $versement=(float)($_POST["versement"]??0);
    if($ref==="" || $versement<=0){
        $message="Montant de règlement incorrect."; $type="error";
    }else{
        $conn->begin_transaction();
        try{
            if (preg_match('/^M-(\d+)$/', $ref, $rm)) {
                $midLegacy=(int)$rm[1];
                $st=$conn->prepare("SELECT * FROM mouvements WHERE id=? AND type='ENTREE' LIMIT 1");
                $st->bind_param("i",$midLegacy);
            } else {
                $st=$conn->prepare("SELECT * FROM mouvements WHERE type='ENTREE' AND description LIKE ? ORDER BY id ASC");
                $like="%REF ACHAT : ".$ref."%"; $st->bind_param("s",$like);
            }
            $st->execute();
            $res=$st->get_result(); $rows=[]; $total=0; $paye=0;
            while($r=$res->fetch_assoc()){ $rows[]=$r; $total += (float)$r["prix"]*(int)$r["quantite"]; $paye=max($paye,montant_description($r["description"],"Payé")); }
            $st->close();
            if(!$rows) throw new Exception("Achat introuvable.");
            $reste=max(0,$total-$paye);
            if($versement>$reste) throw new Exception("Le règlement dépasse le reste à payer.");
            $nouveau=$paye+$versement; $nouveauReste=max(0,$total-$nouveau);
            foreach($rows as $r){
                $desc=$r["description"];
                $desc=preg_replace('/\s*\|\s*Payé\s*:\s*[0-9\s,\.]+\s*FG/i',"",$desc);
                $desc=preg_replace('/\s*\|\s*Reste fournisseur\s*:\s*[0-9\s,\.]+\s*FG/i',"",$desc);
                $desc=rtrim($desc," |");
                $desc.=" | Payé : ".argent($nouveau)." | Reste fournisseur : ".argent($nouveauReste);
                $st=$conn->prepare("UPDATE mouvements SET description=? WHERE id=?");
                $st->bind_param("si",$desc,$r["id"]); if(!$st->execute()) throw new Exception($st->error); $st->close();
            }
            $conn->commit();
            $message=$nouveauReste<=0 ? "Achat totalement payé. Facture soldée." : "Règlement enregistré. Reste : ".argent($nouveauReste);
            $type="success";
        }catch(Exception $e){$conn->rollback();$message="Impossible d'enregistrer le règlement : ".$e->getMessage();$type="error";}
    }
}

/* Nouvel article */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["ajouter_produit"])) {
    $designation=trim($_POST["nom"]??""); $categorie=trim($_POST["categorie"]??"");
    $prix=(float)($_POST["prix_achat"]??0); $qte=(int)($_POST["stock_initial"]??0); $fournisseur=trim($_POST["fournisseur"]??"");
    if($designation==="" || $prix<0 || $qte<0){$message="Les valeurs saisies sont incorrectes."; $type="error";}
    else{
        $st=$conn->prepare("SELECT id FROM produits WHERE nom=? LIMIT 1"); $st->bind_param("s",$designation); $st->execute(); $exists=$st->get_result()->num_rows>0; $st->close();
        if($exists){$message="Cet article existe déjà. Utilise Enregistrer un achat."; $type="error";}
        else{
            $conn->begin_transaction();
            try{
                $pid=prochain_id($conn,"produits"); $pv=0;
                $st=$conn->prepare("INSERT INTO produits (id,nom,categorie,prix_achat,prix_vente,stock) VALUES (?,?,?,?,?,?)");
                $st->bind_param("issddi",$pid,$designation,$categorie,$prix,$pv,$qte);
                if(!$st->execute()) throw new Exception($st->error); $st->close();
                if($qte>0){
                    $mid=prochain_id($conn,"mouvements"); $date=date("Y-m-d H:i:s"); $ref="A-".date("YmdHis")."-".$mid;
                    $total=$prix*$qte;
                    $desc="ACHAT | REF ACHAT : $ref | Fournisseur : ".($fournisseur?: "Non renseigné")." | Désignation : $designation | Total achat : ".argent($total)." | Payé : 0 FG | Reste fournisseur : ".argent($total);
                    $st=$conn->prepare("INSERT INTO mouvements (id,produit_id,type,quantite,prix,description,date_mouvement) VALUES (?,?,'ENTREE',?,?,?,?)");
                    $st->bind_param("iiidss",$mid,$pid,$qte,$prix,$desc,$date);
                    if(!$st->execute()) throw new Exception($st->error); $st->close();
                }
                $conn->commit(); $message="Article ajouté."; $type="success";
            }catch(Exception $e){$conn->rollback();$message="Erreur : ".$e->getMessage();$type="error";}
        }
    }
}

/* Achat multi-articles */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["enregistrer_achat"])) {
    $fournisseur=trim($_POST["fournisseur"]??""); $paye=(float)($_POST["paye"]??0);
    $ids=$_POST["produit_id"]??[]; $qs=$_POST["quantite"]??[]; $prs=$_POST["prix"]??[];
    $lignes=[];$total=0;
    for($i=0;$i<count($ids);$i++){ $pid=(int)($ids[$i]??0);$q=(int)($qs[$i]??0);$pu=(float)($prs[$i]??0); if($pid>0&&$q>0&&$pu>=0){$lignes[]=["pid"=>$pid,"q"=>$q,"pu"=>$pu];$total+=$q*$pu;}}
    if($fournisseur===""){$message="Le fournisseur est obligatoire."; $type="error";}
    elseif(!$lignes){$message="Ajoute au moins un article."; $type="error";}
    elseif($paye<0||$paye>$total){$message="Le montant payé est incorrect."; $type="error";}
    else{
        $conn->begin_transaction();
        try{
            $ref="A-".date("YmdHis")."-".prochain_id($conn,"mouvements"); $date=date("Y-m-d H:i:s"); $reste=$total-$paye;
            foreach($lignes as $l){
                $st=$conn->prepare("SELECT nom FROM produits WHERE id=? LIMIT 1");$st->bind_param("i",$l["pid"]);$st->execute();$p=$st->get_result()->fetch_assoc();$st->close();
                if(!$p) throw new Exception("Article introuvable.");
                $mid=prochain_id($conn,"mouvements");
                $desc="ACHAT | REF ACHAT : $ref | Fournisseur : $fournisseur | Désignation : ".$p["nom"]." | Total achat : ".argent($total)." | Payé : ".argent($paye)." | Reste fournisseur : ".argent($reste);
                $st=$conn->prepare("INSERT INTO mouvements (id,produit_id,type,quantite,prix,description,date_mouvement) VALUES (?,?,'ENTREE',?,?,?,?)");
                $st->bind_param("iiidss",$mid,$l["pid"],$l["q"],$l["pu"],$desc,$date);if(!$st->execute())throw new Exception($st->error);$st->close();
                $st=$conn->prepare("UPDATE produits SET stock=stock+?,prix_achat=? WHERE id=?");$st->bind_param("idi",$l["q"],$l["pu"],$l["pid"]);if(!$st->execute())throw new Exception($st->error);$st->close();
            }
            $conn->commit();$message="Achat enregistré : ".argent($total)." | Payé : ".argent($paye)." | Reste : ".argent($reste);$type="success";
        }catch(Exception $e){$conn->rollback();$message="Erreur : ".$e->getMessage();$type="error";}
    }
}

$article_edit=null;
if(isset($_GET["modifier"])){
    $id=(int)$_GET["modifier"]; if($id>0){$st=$conn->prepare("SELECT id,nom,categorie,prix_achat,stock FROM produits WHERE id=? LIMIT 1");$st->bind_param("i",$id);$st->execute();$article_edit=$st->get_result()->fetch_assoc();$st->close();}
}
$produits=$conn->query("SELECT id,nom,categorie,prix_achat,stock FROM produits ORDER BY nom ASC");
$mouvements=$conn->query("SELECT m.*,p.nom AS produit_nom FROM mouvements m LEFT JOIN produits p ON p.id=m.produit_id WHERE m.type='ENTREE' AND m.description NOT LIKE '%ANNULÉ LE%' AND m.description NOT LIKE '%ANNULATION ACHAT%' ORDER BY m.id DESC LIMIT 80");
?>
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>LAMBEMAH • Achats / Stock</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
body{margin:0;background:#f4f7fb;font-family:Arial,sans-serif;color:#172033}.sidebar{position:fixed;left:0;top:0;width:245px;height:100vh;background:#102a43;padding:20px 15px;color:#fff}.logo{font-size:22px;font-weight:bold;padding:5px 10px 20px}.logo small{display:block;font-size:11px;color:#9cc8ff;margin-top:4px}.menu a{display:flex;gap:10px;align-items:center;color:#dbeafe;text-decoration:none;padding:11px;border-radius:9px;margin-bottom:4px}.menu a:hover,.menu a.active{background:#1d4ed8;color:#fff}.main{margin-left:245px;padding:25px}.box{background:#fff;border-radius:15px;padding:20px;margin-bottom:20px;box-shadow:0 3px 15px rgba(0,0,0,.05)}.box-title{font-size:19px;font-weight:bold;margin-bottom:15px}.form-control,.form-select{min-height:44px}.ligne{background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;padding:12px;margin-bottom:10px}.total{background:#eef5ff;border-radius:10px;padding:15px;font-size:20px;font-weight:bold}.article-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:18px;margin-bottom:10px}.stock-ok{background:#dcfce7;color:#15803d;padding:7px 12px;border-radius:20px;font-weight:bold}.stock-low{background:#fef3c7;color:#b45309;padding:7px 12px;border-radius:20px;font-weight:bold}.stock-zero{background:#fee2e2;color:#b91c1c;padding:7px 12px;border-radius:20px;font-weight:bold}.reste{color:#b42318}.pay-btn{white-space:nowrap}@media(max-width:768px){.sidebar{position:static;width:100%;height:auto}.menu{display:grid;grid-template-columns:repeat(4,1fr)}.menu a{flex-direction:column;justify-content:center;font-size:10px;text-align:center}.main{margin-left:0;padding:14px}.table{font-size:13px}}
</style></head><body>
<aside class="sidebar"><div class="logo">LAMBEMAH<small>GESTION • PRESTATION</small></div><nav class="menu">
<a href="index.php"><i class="bi bi-house"></i>Accueil</a><a href="produits.php" class="active"><i class="bi bi-box"></i>Achats / Stock</a><a href="ventes.php"><i class="bi bi-cart-check"></i>Ventes</a><a href="prestations.php"><i class="bi bi-printer"></i>Prestations</a><a href="recettes.php"><i class="bi bi-cash-coin"></i>Recettes</a><a href="depenses.php"><i class="bi bi-wallet2"></i>Dépenses</a><a href="statistiques.php"><i class="bi bi-bar-chart"></i>Statistiques</a><?php if($role==="admin"): ?><a href="utilisateurs.php"><i class="bi bi-people"></i>Équipe</a><?php endif; ?><a href="index.php?logout=1"><i class="bi bi-box-arrow-right"></i>Déconnexion</a>
</nav></aside>
<main class="main"><h1>📦 Achats / Stock</h1><p class="text-muted">Un achat peut être non payé, partiellement payé ou totalement payé.</p>
<?php if($message): ?><div class="alert <?= $type==="success"?"alert-success":"alert-danger" ?>"><?= htmlspecialchars($message) ?></div><?php endif; ?>

<?php if($article_edit): ?><div class="box"><div class="box-title">✏️ Modifier l'article</div><form method="post"><input type="hidden" name="modifier_article" value="1"><input type="hidden" name="id" value="<?= (int)$article_edit["id"] ?>"><div class="row g-3"><div class="col-md-4"><label>Désignation</label><input name="nom" class="form-control" value="<?= htmlspecialchars($article_edit["nom"]) ?>" required></div><div class="col-md-3"><label>Catégorie</label><input name="categorie" class="form-control" value="<?= htmlspecialchars($article_edit["categorie"]??"") ?>"></div><div class="col-md-3"><label>Prix achat</label><input type="number" name="prix_achat" class="form-control" min="0" value="<?= (float)$article_edit["prix_achat"] ?>" required></div></div><button class="btn btn-primary mt-3">Enregistrer</button> <a class="btn btn-light mt-3" href="produits.php">Annuler</a></form></div><?php endif; ?>

<div class="box"><div class="box-title">➕ Ajouter un nouvel article</div><form method="post"><input type="hidden" name="ajouter_produit" value="1"><div class="row g-3"><div class="col-md-4"><label>Désignation</label><input name="nom" class="form-control" required></div><div class="col-md-3"><label>Catégorie</label><input name="categorie" class="form-control"></div><div class="col-md-2"><label>Prix d'achat</label><input type="number" name="prix_achat" class="form-control" min="0" required></div><div class="col-md-2"><label>Quantité initiale</label><input type="number" name="stock_initial" class="form-control" min="0" value="0"></div><div class="col-md-6"><label>Fournisseur</label><input name="fournisseur" class="form-control"></div></div><button class="btn btn-primary mt-3"><i class="bi bi-save"></i> Ajouter l'article</button></form></div>

<div class="box"><div class="box-title">🛒 Enregistrer un achat</div><form method="post"><input type="hidden" name="enregistrer_achat" value="1"><div class="mb-3"><label class="fw-bold">Fournisseur</label><input name="fournisseur" class="form-control" required></div><div id="lignes"><div class="ligne"><div class="row g-2 align-items-end"><div class="col-md-5"><label>Article</label><select name="produit_id[]" class="form-select" required><option value="">Choisir</option><?php $pf=$conn->query("SELECT id,nom,stock FROM produits ORDER BY nom");while($p=$pf->fetch_assoc()): ?><option value="<?= (int)$p["id"] ?>"><?= htmlspecialchars($p["nom"]) ?> — Stock <?= (int)$p["stock"] ?></option><?php endwhile; ?></select></div><div class="col-md-2"><label>Prix achat</label><input type="number" name="prix[]" class="form-control prix" min="0" value="0" oninput="calculer()" required></div><div class="col-md-2"><label>Quantité</label><input type="number" name="quantite[]" class="form-control quantite" min="1" value="1" oninput="calculer()" required></div><div class="col-md-2"><label>Montant</label><input type="text" class="form-control montant" value="0 FG" readonly></div><div class="col-md-1"><button type="button" class="btn btn-danger" onclick="supprimerLigne(this)">🗑️</button></div></div></div></div><button type="button" class="btn btn-outline-primary mb-3" onclick="ajouterLigne()">➕ Ajouter un autre article</button><div class="total mb-3">TOTAL ACHAT : <span id="total">0 FG</span></div><label class="fw-bold">Montant payé / avance</label><input type="number" name="paye" id="paye" class="form-control mb-3" min="0" value="0" oninput="calculer()"><div class="total mb-3">RESTE : <span id="reste" class="reste">0 FG</span></div><button class="btn btn-primary btn-lg">✓ Enregistrer l'achat</button></form></div>

<div class="box"><div class="box-title">📦 Stock actuel</div><input id="recherche" oninput="rechercherArticle()" class="form-control mb-3" placeholder="Rechercher un article..."><?php while($p=$produits->fetch_assoc()): $s=(int)$p["stock"]; ?><div class="article-card" data-nom="<?= htmlspecialchars(strtolower($p["nom"])) ?>"><div class="row align-items-center"><div class="col-md-4"><strong><?= htmlspecialchars($p["nom"]) ?></strong><div class="text-muted"><?= htmlspecialchars($p["categorie"]??"") ?></div></div><div class="col-md-2 mt-2 mt-md-0"><small>Prix achat</small><div><strong><?= argent($p["prix_achat"]) ?></strong></div></div><div class="col-md-3 mt-2 mt-md-0"><?php if($s<=0): ?><span class="stock-zero">Rupture</span><?php elseif($s<=5): ?><span class="stock-low">Faible : <?= $s ?></span><?php else: ?><span class="stock-ok">Stock : <?= $s ?></span><?php endif; ?></div><div class="col-md-3 text-md-end mt-2 mt-md-0"><a href="produits.php?modifier=<?= (int)$p["id"] ?>" class="btn btn-sm btn-outline-primary">✏️ Modifier</a></div></div></div><?php endwhile; ?></div>

<div class="box"><div class="box-title">🕘 Derniers achats</div><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Article</th><th>Fournisseur</th><th>Prix</th><th>Qté</th><th>Montant</th><th>Paiement</th><th>Date</th><th>Action</th></tr></thead><tbody>
<?php if($mouvements && $mouvements->num_rows): while($m=$mouvements->fetch_assoc()): $desc=$m["description"]??"";$f="Non renseigné";if(preg_match('/Fournisseur\s*:\s*(.*?)\s*\|/i',$desc,$fm))$f=$fm[1];$totalLine=(float)$m["prix"]*(int)$m["quantite"];if(preg_match('/Total achat\s*:\s*([0-9\s,\.]+)\s*FG/i',$desc,$tm))$totalLine=(float)str_replace([" ",","],["","."],$tm[1]);$paid=montant_description($desc,"Payé");$rest=max(0,$totalLine-$paid);$ref=ref_achat($desc,(int)$m["id"]);$ann=(stripos($desc,"ANNULÉ")!==false||stripos($desc,"ANNULE")!==false);[$label,$badge]=achat_status($paid,$totalLine); ?>
<tr><td><strong><?= htmlspecialchars($m["produit_nom"]??"Article") ?></strong><?php if($ann): ?><br><span class="badge text-bg-secondary">Annulé</span><?php endif; ?></td><td><?= htmlspecialchars($f) ?></td><td><?= argent($m["prix"]) ?></td><td><?= (int)$m["quantite"] ?></td><td><strong><?= argent((float)$m["prix"]*(int)$m["quantite"]) ?></strong></td><td><?php if($ann): ?><span class="badge text-bg-secondary">Annulé</span><?php elseif($rest<=0): ?><span class="badge text-bg-success">Payé</span><?php elseif($paid>0): ?><span class="badge text-bg-warning">Partiellement payé</span><?php else: ?><span class="badge text-bg-success">Non payé</span><?php endif; ?><br><small>Payé : <?= argent($paid) ?><br>Reste : <?= argent($rest) ?></small></td><td><?= !empty($m["date_mouvement"])?date("d/m/Y H:i",strtotime($m["date_mouvement"])):"-" ?></td><td>
<?php if(!$ann && $rest>0): ?><button class="btn btn-sm btn-success pay-btn" onclick="ouvrirReglement('<?= htmlspecialchars($ref,ENT_QUOTES) ?>','<?= htmlspecialchars($f,ENT_QUOTES) ?>',<?= $rest ?>)">💰 Régler</button><?php endif; ?>
<?php if(!$ann && $paid<=0): ?><form method="post" class="d-inline" onsubmit="return confirm('Supprimer cet achat ? Le stock sera corrigé.')"><input type="hidden" name="supprimer_achat" value="1"><input type="hidden" name="mouvement_id" value="<?= (int)$m["id"] ?>"><button class="btn btn-sm btn-outline-danger">🗑️</button></form><?php elseif(!$ann && $paid>0): ?><form method="post" class="d-inline" onsubmit="return confirm('Annuler cet achat ? Le stock sera corrigé.')"><input type="hidden" name="annuler_achat" value="1"><input type="hidden" name="mouvement_id" value="<?= (int)$m["id"] ?>"><button class="btn btn-sm btn-outline-secondary">Annuler</button></form><?php endif; ?></td></tr>
<?php endwhile; else: ?><tr><td colspan="8" class="text-center">Aucun achat enregistré.</td></tr><?php endif; ?>
</tbody></table></div></div>

<div class="modal fade" id="modalReglement" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="post"><div class="modal-header"><h5 class="modal-title">💰 Régler un achat</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="regler_achat" value="1"><input type="hidden" name="ref_achat" id="ref_achat"><p>Fournisseur : <strong id="nom_fournisseur"></strong></p><p>Reste à payer : <strong id="reste_modal"></strong></p><label>Montant du règlement</label><input type="number" name="versement" id="versement" class="form-control" min="1" required></div><div class="modal-footer"><button type="submit" class="btn btn-primary">Enregistrer le paiement</button></div></form></div></div></div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function argent(n){return new Intl.NumberFormat("fr-FR").format(n)+" FG";}
function calculer(){let t=0;document.querySelectorAll(".ligne").forEach(l=>{let p=parseFloat(l.querySelector(".prix").value)||0,q=parseInt(l.querySelector(".quantite").value)||0,m=p*q;l.querySelector(".montant").value=argent(m);t+=m;});document.getElementById("total").textContent=argent(t);let pay=parseFloat(document.getElementById("paye").value)||0;document.getElementById("reste").textContent=argent(Math.max(0,t-pay));}
function ajouterLigne(){let c=document.getElementById("lignes"),n=c.querySelector(".ligne").cloneNode(true);n.querySelectorAll("input").forEach(i=>{if(i.classList.contains("prix"))i.value=0;else if(i.classList.contains("quantite"))i.value=1;else if(i.classList.contains("montant"))i.value="0 FG";});n.querySelector("select").selectedIndex=0;c.appendChild(n);calculer();}
function supprimerLigne(b){let l=document.querySelectorAll(".ligne");if(l.length<=1)return alert("Il faut garder au moins un article.");b.closest(".ligne").remove();calculer();}
function rechercherArticle(){let q=document.getElementById("recherche").value.toLowerCase().trim();document.querySelectorAll(".article-card").forEach(a=>a.style.display=(a.dataset.nom||"").includes(q)?"":"none");}
function ouvrirReglement(ref,fournisseur,reste){document.getElementById("ref_achat").value=ref;document.getElementById("nom_fournisseur").textContent=fournisseur;document.getElementById("reste_modal").textContent=argent(reste);let v=document.getElementById("versement");v.max=reste;v.value=reste;new bootstrap.Modal(document.getElementById("modalReglement")).show();}
document.addEventListener("DOMContentLoaded",calculer);
</script></body></html>
