<?php
session_start();
require_once __DIR__.'/config.php';
mysqli_report(MYSQLI_REPORT_OFF);

if(!isset($_SESSION['id'])){ header('Location: index.php'); exit; }

function h($v){ return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
function money($v){ return number_format((float)$v,0,',',' ').' FG'; }
function cleanNum($v){ return (float)str_replace([' ', ','],['','.'],(string)$v); }
function nextId($conn,$table){
    $r=$conn->query("SELECT COALESCE(MAX(id),0)+1 n FROM `$table`");
    return $r ? (int)$r->fetch_assoc()['n'] : 1;
}
function parseP($s){
    $d=['ref'=>'','client'=>'','note'=>'','lignes'=>[],'total'=>0,'a4'=>0,'cout'=>0,'benefice'=>0,'paid'=>0];
    if(preg_match('/REF=([^|]+)/',$s,$m)) $d['ref']=$m[1];
    if(preg_match('/DATA=(.+)$/',$s,$m)){
        $j=base64_decode($m[1],true); $x=$j?json_decode($j,true):null;
        if(is_array($x)) return array_merge($d,$x);
    }
    if(preg_match('/Client\s*:\s*([^|]+)/i',$s,$m)) $d['client']=trim($m[1]);
    if(preg_match('/PAYE=([0-9.]+)/i',$s,$m)) $d['paid']=(float)$m[1];
    if(preg_match('/Paye\s*:\s*([0-9 ]+)/i',$s,$m)) $d['paid']=(float)str_replace(' ','',$m[1]);
    return $d;
}
function encodeP($d,$ref){
    return 'REF='.$ref.'|DATA='.base64_encode(json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}
function nextRef($conn){
    $y=date('Y'); $max=0;
    $r=$conn->query("SELECT description FROM recettes WHERE libelle LIKE 'Prestation DTF%' ");
    if($r) while($x=$r->fetch_assoc()) if(preg_match('/REF=PREST-'.$y.'-(\d+)/',$x['description'],$m)) $max=max($max,(int)$m[1]);
    return 'PREST-'.$y.'-'.str_pad($max+1,4,'0',STR_PAD_LEFT);
}
function dtfProductId($conn){
    $r=$conn->query("SELECT id FROM produits WHERE LOWER(nom) LIKE '%dtf%' OR LOWER(categorie) LIKE '%dtf%' ORDER BY id LIMIT 1");
    if($r && ($x=$r->fetch_assoc())) return (int)$x['id'];
    return 0;
}
function oldA4($d){ return max(0,(int)ceil((float)($d['a4']??0))); }

$msg=''; $err=''; $edit=null; $editId=(int)($_GET['edit']??0);

/* ENREGISTRER / MODIFIER */
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save'){
    $client=trim($_POST['client']??'');
    $note=trim($_POST['note']??'');
    $arts=$_POST['article']??[]; $qty=$_POST['qty']??[]; $prix=$_POST['prix']??[]; $a4=$_POST['a4']??[];
    $lignes=[]; $total=0; $a4Brut=0;

    for($i=0;$i<count($arts);$i++){
        $article=trim($arts[$i]??''); $q=(int)($qty[$i]??0); $p=cleanNum($prix[$i]??0); $u=cleanNum($a4[$i]??0);
        if($article==='' && $q===0 && $p===0) continue;
        if($article==='' || $q<1 || $p<0 || $u<=0){ $err='Vérifie les articles et la consommation A4.'; break; }
        $montant=$q*$p; $cons=$q*$u;
        $lignes[]=['article'=>$article,'qty'=>$q,'prix'=>$p,'a4'=>$u,'montant'=>$montant,'a4_total'=>$cons];
        $total+=$montant; $a4Brut+=$cons;
    }
    $a4Total=(int)ceil($a4Brut-0.000001);
    if(!$err && $client==='') $err='Indique le client.';
    if(!$err && !$lignes) $err='Ajoute au moins un article.';
    if(!$err && $total<=0) $err='Le total doit être supérieur à 0.';

    $id=(int)($_POST['id']??0); $oldData=null; $oldA4=0; $paid=0; $ref='';
    if(!$err && $id){
        $st=$conn->prepare("SELECT id,libelle,montant,description FROM recettes WHERE id=? AND libelle LIKE 'Prestation DTF%' LIMIT 1");
        $st->bind_param('i',$id); $st->execute(); $old=$st->get_result()->fetch_assoc(); $st->close();
        if(!$old) $err='Prestation introuvable.';
        else{
            $oldData=parseP($old['description']); $oldA4=oldA4($oldData); $paid=(float)($oldData['paid']??0); $ref=$oldData['ref']??'';
            if(!$ref) $err='Cette ancienne prestation n’est pas dans le nouveau format.';
            elseif($paid >= (float)$old['montant']-0.01) $err='Cette prestation est totalement payée et verrouillée.';
            elseif($paid>$total+0.01) $err='Le paiement déjà reçu dépasse le nouveau total.';
        }
    } elseif(!$err) $ref=nextRef($conn);

    if(!$err){
        $cout=$a4Total*5000; $benef=$total-$cout;
        $data=['client'=>$client,'note'=>$note,'lignes'=>$lignes,'total'=>$total,'a4'=>$a4Total,'cout'=>$cout,'benefice'=>$benef,'paid'=>$paid];
        $desc=encodeP($data,$ref); $lib='Prestation DTF - '.$client;
        $dtfId=dtfProductId($conn);
        $conn->begin_transaction();
        try{
            /* Restauration du stock DTF lors d’une modification */
            if($id && $dtfId && $oldA4>0){
                $st=$conn->prepare("UPDATE produits SET stock=stock+? WHERE id=?"); $st->bind_param('ii',$oldA4,$dtfId); if(!$st->execute()) throw new Exception('Stock DTF impossible.'); $st->close();
            }
            if($id){
                $st=$conn->prepare("UPDATE recettes SET libelle=?,montant=?,description=? WHERE id=?");
                $st->bind_param('sdsi',$lib,$total,$desc,$id); if(!$st->execute()) throw new Exception('Modification impossible.'); $st->close();
                $like='%REF='.$ref.'%';
                $st=$conn->prepare("DELETE FROM depenses WHERE description LIKE ?"); $st->bind_param('s',$like); $st->execute(); $st->close();
                $st=$conn->prepare("DELETE FROM mouvements WHERE description LIKE ?"); $st->bind_param('s',$like); $st->execute(); $st->close();
            }else{
                $rid=nextId($conn,'recettes');
                $st=$conn->prepare("INSERT INTO recettes(id,libelle,montant,description) VALUES(?,?,?,?)");
                $st->bind_param('isds',$rid,$lib,$total,$desc); if(!$st->execute()) throw new Exception('Enregistrement impossible.'); $st->close();
            }
            if($cout>0){
                $did=nextId($conn,'depenses'); $dd='REF='.$ref.'|COUT_DTF='.$cout.'|A4='.$a4Total.'|CLIENT='.$client;
                $dl='DTF fournisseur - '.$client;
                $st=$conn->prepare("INSERT INTO depenses(id,libelle,montant,description) VALUES(?,?,?,?)");
                $st->bind_param('isds',$did,$dl,$cout,$dd); if(!$st->execute()) throw new Exception('Coût DTF impossible.'); $st->close();
            }
            /* Déduction du stock DTF */
            if($dtfId && $a4Total>0){
                $st=$conn->prepare("UPDATE produits SET stock=stock-? WHERE id=? AND stock>=?");
                $st->bind_param('iii',$a4Total,$dtfId,$a4Total); if(!$st->execute() || $st->affected_rows<1) throw new Exception('Stock DTF insuffisant ou produit DTF introuvable.'); $st->close();
                $mid=nextId($conn,'mouvements'); $md='REF='.$ref.'|PRESTATION|CLIENT='.$client.'|A4='.$a4Total;
                $st=$conn->prepare("INSERT INTO mouvements(id,produit_id,type,quantite,prix,description,date_mouvement) VALUES(?,?, 'SORTIE', ?, ?, ?, NOW())");
                $zero=5000; $st->bind_param('iiids',$mid,$dtfId,$a4Total,$zero,$md); if(!$st->execute()) throw new Exception('Mouvement DTF impossible.'); $st->close();
            }
            $conn->commit(); $msg=$id?'Prestation modifiée.':'Prestation enregistrée.';
        }catch(Throwable $e){ $conn->rollback(); $err=$e->getMessage(); }
    }
}

/* PAIEMENT */
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='pay'){
    $id=(int)($_POST['id']??0); $mode=$_POST['mode']??'advance'; $montant=cleanNum($_POST['montant']??0);
    $st=$conn->prepare("SELECT id,montant,description FROM recettes WHERE id=? AND libelle LIKE 'Prestation DTF%' LIMIT 1");
    $st->bind_param('i',$id); $st->execute(); $p=$st->get_result()->fetch_assoc(); $st->close();
    if(!$p) $err='Prestation introuvable.';
    else{
        $d=parseP($p['description']); $tot=(float)$p['montant']; $paid=(float)($d['paid']??0); $reste=max(0,$tot-$paid);
        if($reste<=0.01) $err='Cette prestation est déjà totalement payée.';
        else{
            $newPaid=$paid;
            if($mode==='total') $newPaid=$tot;
            elseif($montant<=0) $err='Indique le montant de l’avance.';
            elseif($montant>$reste+0.01) $err='L’avance dépasse le reste à payer.';
            else $newPaid=min($tot,$paid+$montant);
            if(!$err){
                $d['paid']=$newPaid; $d['total']=$tot; $d['ref']=$d['ref']?:'PREST-'.date('Y').'-'.$id;
                $desc=encodeP($d,$d['ref']);
                $st=$conn->prepare("UPDATE recettes SET description=? WHERE id=?"); $st->bind_param('si',$desc,$id);
                if($st->execute()) $msg=$newPaid>=$tot-0.01?'Prestation totalement payée.':'Avance enregistrée. Reste : '.money($tot-$newPaid).'.'; else $err='Paiement impossible.';
                $st->close();
            }
        }
    }
}

/* CHARGEMENT MODIFICATION */
if($editId && !$err){
    $st=$conn->prepare("SELECT id,libelle,montant,description,date_recette FROM recettes WHERE id=? AND libelle LIKE 'Prestation DTF%' LIMIT 1");
    $st->bind_param('i',$editId); $st->execute(); $edit=$st->get_result()->fetch_assoc(); $st->close();
    if($edit){ $edit['data']=parseP($edit['description']); if((float)($edit['data']['paid']??0)>=(float)$edit['montant']-0.01){$edit=null;$err='Cette prestation est totalement payée et verrouillée.';} }
}

/* IMPRESSION */
if(isset($_GET['imprimer'])){
    $iid=(int)$_GET['imprimer'];
    $st=$conn->prepare("SELECT id,libelle,montant,description,date_recette FROM recettes WHERE id=? AND libelle LIKE 'Prestation DTF%' LIMIT 1");
    $st->bind_param('i',$iid); $st->execute(); $print=$st->get_result()->fetch_assoc(); $st->close();
    if(!$print) die('Prestation introuvable.');
    $pd=parseP($print['description']); $paid=(float)($pd['paid']??0); $reste=max(0,(float)$print['montant']-$paid);
    $client=$pd['client']??preg_replace('/^Prestation DTF\s*-\s*/i','',$print['libelle']); $lignes=$pd['lignes']??[];
    ?>
    <!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Facture <?=h($pd['ref']??'')?></title>
    <style>
    *{box-sizing:border-box}body{margin:0;background:#eef3f8;font-family:Arial,sans-serif;color:#102d45}.printbar{max-width:210mm;margin:14px auto;display:flex;justify-content:space-between;align-items:center;background:#fff;padding:10px 14px;border:1px solid #d9e4ee;border-radius:10px}.printbar label{font-size:12px}.printbar button{border:0;background:#1769e8;color:#fff;border-radius:8px;padding:10px 15px;font-weight:700;cursor:pointer}.paper{width:210mm;min-height:297mm;margin:10px auto 30px;background:#fff;padding:15mm;box-shadow:0 2px 18px #102d4518}.head{display:flex;justify-content:space-between;gap:20px;border-bottom:2px solid #1769e8;padding-bottom:10px}.logo{width:135px;height:70px;object-fit:contain}.brand{text-align:right;font-size:10px;line-height:1.65}.brand b{font-size:18px;color:#102d45}.title{display:flex;justify-content:space-between;align-items:flex-start;margin:22px 0 14px}.title h1{font-size:22px;margin:0}.meta{font-size:10px;text-align:right;line-height:1.6}.client{background:#f2f7fb;border:1px solid #dce7ef;padding:10px;border-radius:8px;font-size:11px;margin-bottom:14px}.client b{font-size:13px}.table{width:100%;border-collapse:collapse;border:1px solid #cbd8e2}.table th{background:#eaf2f8;border:1px solid #cbd8e2;padding:9px;font-size:9px;text-align:left}.table td{border:1px solid #d8e1e8;padding:9px;font-size:10px}.right{text-align:right}.totals{width:280px;margin:15px 0 0 auto;font-size:11px;line-height:2}.total{font-size:15px;font-weight:700;border-top:2px solid #17324a;padding-top:5px}.note{margin-top:20px;background:#f7f9fb;border:1px solid #e0e7ed;padding:10px;border-radius:8px;font-size:10px}.signature{display:flex;justify-content:flex-end;margin-top:48px}.sigbox{width:220px;text-align:center}.space{height:70px;display:flex;align-items:center;justify-content:center}.space img{max-width:175px;max-height:65px;object-fit:contain}.sigline{border-top:1px solid #34495e;padding-top:6px;font-size:10px}.hidden{display:none}@media print{body{background:#fff}.printbar{display:none}.paper{width:auto;min-height:auto;margin:0;box-shadow:none;padding:12mm}}
    </style></head><body>
    <div class="printbar"><label><input type="checkbox" id="addSignature"> Ajouter la signature</label><button onclick="window.print()">🖨️ Imprimer / PDF</button></div>
    <div class="paper">
      <div class="head"><img class="logo" src="/assets/logo.png" alt="LAMBEMAH"><div class="brand"><b>LAMBEMAH GESTION</b><br>GESTION • PRESTATION<br>+224 611752767 / 622595362<br>konatelambetenin@gmail.com<br>KM 36</div></div>
      <div class="title"><h1>FACTURE DE PRESTATION</h1><div class="meta"><b><?=h($pd['ref']??'PRESTATION')?></b><br><?=h(date('d/m/Y',strtotime($print['date_recette']??'now')))?></div></div>
      <div class="client">Client : <b><?=h($client)?></b></div>
      <table class="table"><thead><tr><th>Article</th><th>Qté</th><th>Prix/u</th><th>A4/u</th><th class="right">Montant</th></tr></thead><tbody>
      <?php foreach($lignes as $li):?><tr><td><?=h($li['article']??'')?></td><td><?=h($li['qty']??0)?></td><td><?=money($li['prix']??0)?></td><td><?=h($li['a4']??0)?></td><td class="right"><?=money($li['montant']??0)?></td></tr><?php endforeach;?>
      </tbody></table>
      <div class="totals"><div>Total : <b><?=money($print['montant'])?></b></div><div>Payé : <b><?=money($paid)?></b></div><div>Reste : <b><?=money($reste)?></b></div><div class="total">Net à payer : <?=money($print['montant'])?></div></div>
      <?php if(!empty($pd['note'])):?><div class="note"><b>Note :</b> <?=h($pd['note'])?></div><?php endif;?>
      <div class="signature"><div class="sigbox"><div class="space"><img id="signatureImg" class="hidden" src="/assets/signature.png" alt="Signature"></div><div class="sigline">Responsable</div></div></div>
    </div><script>document.getElementById('addSignature').addEventListener('change',function(){document.getElementById('signatureImg').classList.toggle('hidden',!this.checked)});</script>
    </body></html><?php exit;
}

/* STATISTIQUES */
$nb=0;$total=0;$couts=0;$stockDtf=0;
$r=$conn->query("SELECT COUNT(*) n,COALESCE(SUM(montant),0) t FROM recettes WHERE libelle LIKE 'Prestation DTF%'"); if($r){$x=$r->fetch_assoc();$nb=(int)$x['n'];$total=(float)$x['t'];}
$r=$conn->query("SELECT COALESCE(SUM(montant),0) t FROM depenses WHERE libelle LIKE 'DTF fournisseur - %'"); if($r)$couts=(float)$r->fetch_assoc()['t'];
$benef=$total-$couts;
$dtfId=dtfProductId($conn); if($dtfId){$st=$conn->prepare("SELECT stock FROM produits WHERE id=?");$st->bind_param('i',$dtfId);$st->execute();$stockDtf=(int)($st->get_result()->fetch_assoc()['stock']??0);$st->close();}
$items=[];$r=$conn->query("SELECT id,libelle,montant,description,date_recette FROM recettes WHERE libelle LIKE 'Prestation DTF%' ORDER BY id DESC LIMIT 50");if($r)while($x=$r->fetch_assoc())$items[]=$x;
?>
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Prestations - LAMBEMAH GESTION</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f3f7fb;color:#102d45;font-family:Arial,sans-serif}.layout{display:flex;min-height:100vh}.sidebar{width:245px;background:#062842;color:#fff;position:fixed;left:0;top:0;bottom:0;padding:26px 14px;overflow:auto}.brandSide{display:flex;align-items:center;gap:12px;padding:0 8px 28px}.brandIcon{width:52px;height:52px;border-radius:16px;background:#1598e8;display:flex;align-items:center;justify-content:center;font-size:27px}.brandSide b{font-size:21px;letter-spacing:.2px}.brandSide small{display:block;color:#61b9ef;font-size:11px;margin-top:2px}.nav a{display:flex;align-items:center;gap:13px;color:#fff;text-decoration:none;padding:13px 15px;border-radius:12px;margin:4px 0;font-size:15px}.nav a:hover,.nav a.active{background:#13527a}.nav a.active{border-left:3px solid #1ca7f5;padding-left:12px}.account{margin-top:28px;background:#13527a;border-radius:13px;padding:13px 15px}.account b{font-size:13px}.account small{display:block;color:#d9edf8;margin-top:5px}.logout{display:block;text-align:center;background:#0e4b70;color:#ffb1b1!important;margin-top:12px!important}.main{margin-left:245px;width:calc(100% - 245px);padding:30px 34px 40px}.top{display:flex;justify-content:space-between;align-items:center}.top h1{margin:0;font-size:28px}.top p{margin:7px 0 0;color:#778b9b;font-size:13px}.top .home{background:#1769e8;color:#fff;text-decoration:none;border-radius:9px;padding:11px 16px;font-size:12px;font-weight:700}.cards{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin:24px 0}.card{background:#fff;border:1px solid #dbe5ed;border-radius:15px;padding:20px 24px;box-shadow:0 4px 18px #17324a0b}.card small{font-size:11px;color:#7d8f9d;text-transform:uppercase}.card b{display:block;font-size:25px;margin-top:9px}.card:nth-child(2) b{color:#1478bc}.card:nth-child(4) b{color:#0a9560}.grid{display:grid;grid-template-columns:minmax(350px,43%) 1fr;gap:18px}.box{background:#fff;border:1px solid #dbe5ed;border-radius:15px;padding:20px;box-shadow:0 4px 18px #17324a08}.box h2{margin:0 0 16px;font-size:19px}.group{margin-bottom:12px}.label{display:block;font-size:10px;font-weight:700;color:#667c8c;margin-bottom:5px}.input,textarea{width:100%;border:1px solid #cddbe5;border-radius:8px;padding:10px 11px;font-size:12px;color:#102d45;background:#fff}.input:focus,textarea:focus{outline:2px solid #d7efff;border-color:#1b91dd}.lineHead,.line{display:grid;grid-template-columns:1.45fr .55fr .85fr .65fr 30px;gap:6px}.lineHead{font-size:9px;color:#718594;margin-bottom:4px}.line{margin-bottom:6px}.line input{width:100%;border:1px solid #d3dfe7;border-radius:7px;padding:9px 7px;font-size:11px}.x{border:0;border-radius:7px;background:#f0f3f5;color:#e33;font-size:17px}.add{display:inline-block;margin-top:4px;border:0;background:#e9f4ff;color:#1769e8;border-radius:8px;padding:9px 12px;font-size:11px;font-weight:700;cursor:pointer}.summary{background:#eef8ff;border:1px solid #d6eaf8;border-radius:9px;padding:10px 12px;font-size:11px;line-height:1.8;margin:13px 0}.summary b{font-size:13px}.actions{display:flex;gap:7px}.btn{border:0;border-radius:8px;padding:10px 13px;background:#1769e8;color:#fff;text-decoration:none;font-size:11px;font-weight:700;cursor:pointer}.btn.gray{background:#edf1f4;color:#526777}.tableWrap{overflow:auto}.history{width:100%;border-collapse:collapse;min-width:820px}.history th{background:#eaf1f6;color:#587083;font-size:10px;text-align:left;padding:11px;border:1px solid #ccd9e2}.history td{font-size:10px;padding:11px;border:1px solid #d7e1e8;vertical-align:middle}.history tr:nth-child(even) td{background:#fbfdfe}.clientName{font-weight:700}.blue{color:#1769e8}.green{color:#0a9560}.state{font-weight:700;font-size:9px}.state.wait{color:#a27600}.state.av{color:#1769e8}.state.ok{color:#0a9560}.rowBtns{display:flex;gap:5px;flex-wrap:wrap}.rowBtns .btn{padding:8px 9px;font-size:9px}.modal{display:none;position:fixed;inset:0;background:#09233888;align-items:center;justify-content:center;z-index:20}.modalbox{width:min(350px,92%);background:#fff;border-radius:14px;padding:18px;box-shadow:0 15px 50px #0003}.modalbox h3{margin:0 0 12px;font-size:16px}.msg{padding:10px;border-radius:9px;font-size:11px;margin:12px 0}.ok{background:#eaf8ef;color:#187244}.err{background:#fff0f0;color:#b52d2d}.rule{font-size:9px;color:#7d8e99;line-height:1.5;margin-top:10px}@media(max-width:1050px){.sidebar{width:210px}.main{margin-left:210px;width:calc(100% - 210px);padding:25px}.cards{grid-template-columns:repeat(2,1fr)}.grid{grid-template-columns:1fr}}@media(max-width:700px){.layout{display:block}.sidebar{position:relative;width:100%;padding:12px}.brandSide{padding-bottom:10px}.nav{display:grid;grid-template-columns:repeat(4,1fr);gap:3px}.nav a{font-size:10px;justify-content:center;padding:9px 4px;gap:3px;margin:0}.account,.logout{display:none}.main{margin:0;width:100%;padding:15px}.top h1{font-size:22px}.top p{font-size:11px}.cards{gap:8px;margin:15px 0}.card{padding:12px}.card b{font-size:17px}.box{padding:12px}.grid{gap:10px}.history{min-width:800px}.lineHead,.line{grid-template-columns:1.3fr .55fr .8fr .6fr 28px}.line input{font-size:10px;padding:8px}.actions .btn{flex:1;text-align:center}}
</style></head><body>
<div class="layout">
<aside class="sidebar"><div class="brandSide"><div class="brandIcon">💼</div><div><b>LAMBEMAH</b><small>GESTION • PRESTATION</small></div></div><nav class="nav">
<a href="index.php">🏠 <span>Accueil</span></a><a href="produits.php">📦 <span>Produits</span></a><a href="ventes.php">💰 <span>Ventes</span></a><a class="active" href="prestations.php">🖨️ <span>Prestations</span></a><a href="recettes.php">💵 <span>Recettes</span></a><a href="depenses.php">💸 <span>Dépenses</span></a><a href="statistiques.php">📊 <span>Statistiques</span></a><a href="utilisateurs.php">👥 <span>Équipe</span></a></nav>
<div class="account"><b><?=h($_SESSION['nom']??'Utilisateur')?></b><small><?=h($_SESSION['role']??'admin')?></small></div><a class="nav logout" href="index.php?logout=1">🚪 Déconnexion</a></aside>
<main class="main"><div class="top"><div><h1>🖨️ Prestations</h1><p>Articles du client + impression DTF.</p></div><a class="home" href="index.php">Accueil</a></div>
<?php if($msg):?><div class="msg ok"><?=h($msg)?></div><?php endif;?><?php if($err):?><div class="msg err"><?=h($err)?></div><?php endif;?>
<div class="cards"><div class="card"><small>Prestations</small><b><?=$nb?></b></div><div class="card"><small>Chiffre d'affaires</small><b><?=money($total)?></b></div><div class="card"><small>DTF consommé</small><b><?=money($couts)?></b></div><div class="card"><small>Bénéfice</small><b><?=money($benef)?></b></div></div>
<div class="grid"><section class="box"><h2><?=$edit?'Modifier la prestation':'Nouvelle prestation'?></h2><form method="post"><input type="hidden" name="action" value="save"><?php if($edit):?><input type="hidden" name="id" value="<?=$edit['id']?>"><?php endif;?>
<div class="group"><label class="label">CLIENT</label><input class="input" name="client" required value="<?=h($edit['data']['client']??'')?>" placeholder="Nom du client"></div>
<div class="group"><label class="label">ARTICLES DU CLIENT</label><div class="lineHead"><span>Article</span><span>Qté</span><span>Prix/u</span><span>A4/u</span><span></span></div><div id="lines">
<?php $ls=$edit['data']['lignes']??[['article'=>'','qty'=>1,'prix'=>0,'a4'=>1]]; foreach($ls as $z):?><div class="line"><input name="article[]" value="<?=h($z['article']??'')?>" placeholder="T-shirt, pull..." required><input type="number" name="qty[]" min="1" value="<?=h($z['qty']??1)?>"><input type="number" name="prix[]" min="0" step="500" value="<?=h($z['prix']??0)?>"><input type="number" name="a4[]" min="0.5" step="0.5" value="<?=h($z['a4']??1)?>"><button type="button" class="x" onclick="this.parentElement.remove();calc()">×</button></div><?php endforeach;?></div><button type="button" class="add" onclick="addLine()">+ Article</button></div>
<div class="group"><label class="label">NOTE</label><textarea name="note" rows="3" placeholder="Ex : articles apportés par le client..."><?=h($edit['data']['note']??'')?></textarea></div>
<div class="summary">Total : <b id="total">0 FG</b><br>A4 consommés : <b id="a4">0</b> • Coût DTF : <b id="cout">0 FG</b><br>Bénéfice : <b id="benef">0 FG</b></div>
<div class="actions"><?php if($edit):?><a class="btn gray" href="prestations.php">Annuler</a><?php endif;?><button class="btn" type="submit"><?=$edit?'Enregistrer':'Valider'?></button></div><div class="rule">DTF : 5 000 FG/A4. Grand : 1 A4 ou plus. Enfant/képi : 0,5 A4/u lorsque 2 designs tiennent sur 1 A4. Le prix de prestation est libre.</div>
</form></section>
<section class="box"><h2>Historique</h2><div class="tableWrap"><table class="history"><thead><tr><th>Client</th><th>Articles</th><th>Total</th><th>Payé</th><th>Reste</th><th>État</th><th>Actions</th></tr></thead><tbody>
<?php foreach($items as $p):$d=parseP($p['description']);$new=!empty($d['ref']);$paid=(float)($d['paid']??0);$reste=max(0,(float)$p['montant']-$paid);$status=$reste<=0.01?'TOUT PAYÉ':($paid>0?'AVANCE':'NON PAYÉ');$cls=$reste<=0.01?'ok':($paid>0?'av':'wait');?><tr><td class="clientName"><?=h($d['client']?:preg_replace('/^Prestation DTF\s*-\s*/i','',$p['libelle']))?></td><td><?=$new?count($d['lignes']).' ligne(s) • '.(int)($d['a4']??0).' A4':'Ancien format'?></td><td class="blue"><b><?=money($p['montant'])?></b></td><td><?=money($paid)?></td><td class="<?= $reste<=0.01?'green':'' ?>"><b><?=money($reste)?></b></td><td><span class="state <?=$cls?>"><?=$status?></span></td><td><div class="rowBtns"><a class="btn" href="?imprimer=<?=$p['id']?>">Imprimer</a><?php if($reste>0.01):?><button type="button" class="btn" onclick="openPay(<?=$p['id']?>,<?=json_encode(round($reste))?>)">Avance</button><form method="post" style="display:inline"><input type="hidden" name="action" value="pay"><input type="hidden" name="id" value="<?=$p['id']?>"><input type="hidden" name="mode" value="total"><button class="btn" type="submit">Tout payé</button></form><?php if($new):?><a class="btn gray" href="?edit=<?=$p['id']?>">Modifier</a><?php endif;?><?php endif;?></div></td></tr><?php endforeach;?><?php if(!$items):?><tr><td colspan="7">Aucune prestation.</td></tr><?php endif;?></tbody></table></div></section></div></main></div>
<div class="modal" id="payModal"><div class="modalbox"><h3>Enregistrer une avance</h3><form method="post"><input type="hidden" name="action" value="pay"><input type="hidden" name="id" id="payId"><label class="label">Montant de l'avance</label><input class="input" type="number" name="montant" id="payAmount" min="500" step="500" required><div class="actions" style="margin-top:12px"><button type="button" class="btn gray" onclick="closePay()">Annuler</button><button class="btn" type="submit">Enregistrer</button></div></form></div></div>
<script>
const moneyJS=n=>new Intl.NumberFormat('fr-FR').format(Math.round(n))+' FG';
function calc(){let t=0,a=0;document.querySelectorAll('#lines .line').forEach(r=>{let q=+r.querySelector('[name="qty[]"]').value||0,p=+r.querySelector('[name="prix[]"]').value||0,u=+r.querySelector('[name="a4[]"]').value||0;t+=q*p;a+=q*u});a=Math.ceil(a-0.000001);document.getElementById('total').textContent=moneyJS(t);document.getElementById('a4').textContent=a;document.getElementById('cout').textContent=moneyJS(a*5000);document.getElementById('benef').textContent=moneyJS(t-a*5000)}
function addLine(){let d=document.createElement('div');d.className='line';d.innerHTML='<input name="article[]" placeholder="T-shirt, pull..." required><input type="number" name="qty[]" min="1" value="1"><input type="number" name="prix[]" min="0" step="500" value="0"><input type="number" name="a4[]" min="0.5" step="0.5" value="1"><button type="button" class="x">×</button>';d.querySelector('.x').onclick=()=>{d.remove();calc()};d.querySelectorAll('input').forEach(x=>x.oninput=calc);document.getElementById('lines').appendChild(d);calc()}
function openPay(id,reste){document.getElementById('payId').value=id;document.getElementById('payAmount').value=Math.min(reste,Math.max(500,reste));document.getElementById('payAmount').max=reste;document.getElementById('payModal').style.display='flex'}function closePay(){document.getElementById('payModal').style.display='none'}
document.querySelectorAll('#lines input').forEach(x=>x.oninput=calc);calc();
</script></body></html>
