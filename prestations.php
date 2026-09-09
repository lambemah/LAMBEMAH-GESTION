<?php
session_start();
require_once __DIR__.'/config.php';
if(!isset($_SESSION['id'])){header('Location: index.php');exit;}
mysqli_report(MYSQLI_REPORT_OFF);

function h($x){return htmlspecialchars((string)$x,ENT_QUOTES,'UTF-8');}
function money($x){return number_format((float)$x,0,',',' ').' FG';}
function parseP($s){
    $d=['ref'=>'','client'=>'','note'=>'','lignes'=>[],'total'=>0,'a4'=>0,'cout'=>0,'benefice'=>0,'paid'=>0];
    if(preg_match('/REF=([^|]+)/',$s,$m))$d['ref']=$m[1];
    if(preg_match('/DATA=(.+)$/',$s,$m)){
        $j=base64_decode($m[1],true);$x=$j?json_decode($j,true):null;
        if(is_array($x))return array_merge($d,$x);
    }
    if(preg_match('/Client\s*:\s*([^|]+)/i',$s,$m))$d['client']=trim($m[1]);
    if(preg_match('/Coût DTF total\s*:\s*([0-9 ]+)/i',$s,$m))$d['cout']=(float)str_replace(' ','',$m[1]);
    if(preg_match('/Bénéfice\s*:\s*([0-9 ]]+)/i',$s,$m))$d['benefice']=(float)str_replace(' ','',$m[1]);
    if(preg_match('/PAYE=([0-9.]+)/i',$s,$m))$d['paid']=(float)$m[1];
    if(preg_match('/Paye\s*:\s*([0-9 ]+)/i',$s,$m))$d['paid']=(float)str_replace(' ','',$m[1]);
    return $d;
}
function encodeP($d,$ref){
    $j=base64_encode(json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    return 'REF='.$ref.'|DATA='.$j;
}
function nextRef($conn){
    $y=date('Y');$max=0;
    $r=$conn->query("SELECT description FROM recettes WHERE libelle LIKE 'Prestation DTF%'");
    if($r)while($x=$r->fetch_assoc())if(preg_match('/REF=PREST-'.$y.'-(\d+)/',$x['description'],$m))$max=max($max,(int)$m[1]);
    return 'PREST-'.$y.'-'.str_pad($max+1,4,'0',STR_PAD_LEFT);
}

$msg='';$err='';$edit=null;$editId=(int)($_GET['edit']??0);

if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save'){
    $client=trim($_POST['client']??'');$note=trim($_POST['note']??'');
    $arts=$_POST['article']??[];$qty=$_POST['qty']??[];$prix=$_POST['prix']??[];$a4=$_POST['a4']??[];
    $l=[];$total=0;$a4Total=0;
    for($i=0;$i<count($arts);$i++){
        $a=trim($arts[$i]??'');$q=(int)($qty[$i]??0);$p=(float)($prix[$i]??0);$u=(float)($a4[$i]??0);
        if($a===''&&$q===0&&$p===0)continue;
        if($a===''||$q<1||$p<0||$u<0){$err='Vérifie les lignes.';break;}
        $m=$q*$p;$aa=$q*$u;$l[]=['article'=>$a,'qty'=>$q,'prix'=>$p,'a4'=>$u,'montant'=>$m,'a4_total'=>$aa];$total+=$m;$a4Total+=$aa;
    }
    if(!$err&&$client==='')$err='Indique le client.';
    if(!$err&&!$l)$err='Ajoute un article.';
    if(!$err&&$total<=0)$err='Le total doit être supérieur à 0.';
    if(!$err){
        $cout=$a4Total*5000;$benef=$total-$cout;
        $data=['client'=>$client,'note'=>$note,'lignes'=>$l,'total'=>$total,'a4'=>$a4Total,'cout'=>$cout,'benefice'=>$benef,'paid'=>0];
        $id=(int)($_POST['id']??0);$ref='';
        if($id){
            $st=$conn->prepare("SELECT description FROM recettes WHERE id=? AND libelle LIKE 'Prestation DTF%'");
            $st->bind_param('i',$id);$st->execute();$old=$st->get_result()->fetch_assoc();$st->close();
            $od=$old?parseP($old['description']):[];
            $oldPaid=(float)($od['paid']??0);
            if(!$old||empty($od['ref']))$err='Cette ancienne prestation garde son historique mais ne peut pas être recalculée automatiquement.';
            elseif($oldPaid>0 && $oldPaid>$total+0.01)$err='Le paiement déjà reçu dépasse le nouveau total.';
            elseif($oldPaid>=$total-0.01)$err='Cette prestation est totalement payée et verrouillée.';
            else { $data['paid']=$oldPaid; $ref=$od['ref']; }
        }else $ref=nextRef($conn);
        if(!$err){
            $desc=encodeP($data,$ref);$lib='Prestation DTF - '.$client;
            $conn->begin_transaction();
            try{
                if($id){
                    $st=$conn->prepare("UPDATE recettes SET libelle=?,montant=?,description=? WHERE id=?");
                    $st->bind_param('sdsi',$lib,$total,$desc,$id);if(!$st->execute())throw new Exception('Modification impossible.');$st->close();
                    $like='%REF='.$ref.'%';$st=$conn->prepare("DELETE FROM depenses WHERE description LIKE ?");$st->bind_param('s',$like);$st->execute();$st->close();
                }else{
                    $st=$conn->prepare("INSERT INTO recettes(libelle,montant,description) VALUES(?,?,?)");
                    $st->bind_param('sds',$lib,$total,$desc);if(!$st->execute())throw new Exception('Enregistrement impossible.');$st->close();
                }
                if($cout>0){
                    $dd='REF='.$ref.'|COUT_DTF='.$cout.'|A4='.$a4Total.'|CLIENT='.$client;
                    $dl='DTF fournisseur - '.$client;
                    $st=$conn->prepare("INSERT INTO depenses(libelle,montant,description) VALUES(?,?,?)");
                    $st->bind_param('sds',$dl,$cout,$dd);if(!$st->execute())throw new Exception('Coût DTF impossible.');$st->close();
                }
                $conn->commit();$msg=$id?'Prestation modifiée.':'Prestation enregistrée.';
            }catch(Throwable $e){$conn->rollback();$err=$e->getMessage();}
        }
    }
}

/* Paiements : avance ou solde total */
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='pay'){
    $id=(int)($_POST['id']??0);
    $mode=$_POST['mode']??'advance';
    $montant=(float)($_POST['montant']??0);

    $st=$conn->prepare("SELECT id,libelle,montant,description FROM recettes WHERE id=? AND libelle LIKE 'Prestation DTF%' LIMIT 1");
    $st->bind_param('i',$id);
    $st->execute();
    $p=$st->get_result()->fetch_assoc();
    $st->close();

    if(!$p){
        $err='Prestation introuvable.';
    }else{
        $d=parseP($p['description']);
        $total=(float)$p['montant'];
        $paid=(float)($d['paid']??0);
        $reste=max(0,$total-$paid);

        if($reste<=0.01){
            $err='Cette prestation est déjà totalement payée.';
        }else{
            if($mode==='total'){
                $nouveauPaye=$total;
            }else{
                if($montant<=0){
                    $err='Indique le montant de l’avance.';
                    $nouveauPaye=$paid;
                }elseif($montant>$reste+0.01){
                    $err='L’avance dépasse le reste à payer.';
                    $nouveauPaye=$paid;
                }else{
                    $nouveauPaye=min($total,$paid+$montant);
                }
            }

            if(!$err){
                $d['paid']=$nouveauPaye;
                $d['total']=$total;
                $d['ref']=$d['ref'] ?: 'PREST-'.date('Y').'-'.$id;
                $desc=encodeP($d,$d['ref']);

                $st=$conn->prepare("UPDATE recettes SET description=? WHERE id=?");
                $st->bind_param('si',$desc,$id);

                if($st->execute()){
                    $st->close();
                    if($nouveauPaye >= $total-0.01){
                        $msg='Prestation totalement payée.';
                    }else{
                        $msg='Avance enregistrée. Reste : '.money($total-$nouveauPaye).'.';
                    }
                }else{
                    $err='Paiement impossible.';
                    $st->close();
                }
            }
        }
    }
}

if($editId&&!$err){
    $st=$conn->prepare("SELECT id,libelle,montant,description,date_recette FROM recettes WHERE id=? LIMIT 1");$st->bind_param('i',$editId);$st->execute();$edit=$st->get_result()->fetch_assoc();$st->close();
    if($edit&&stripos($edit['libelle'],'Prestation DTF')!==false){$edit['data']=parseP($edit['description']); if(($edit['data']['paid']??0)>=(float)$edit['montant']-0.01){$edit=null;$err='Cette prestation est totalement payée et verrouillée.';}}else $edit=null;
}

/* Impression d'une prestation */
if(isset($_GET['imprimer'])){
    $iid=(int)$_GET['imprimer'];
    $st=$conn->prepare("SELECT id,libelle,montant,description,date_recette FROM recettes WHERE id=? AND libelle LIKE 'Prestation DTF%' LIMIT 1");
    $st->bind_param('i',$iid);$st->execute();$print=$st->get_result()->fetch_assoc();$st->close();
    if(!$print){die('Prestation introuvable.');}
    $pd=parseP($print['description']);
    $paid=(float)($pd['paid']??0);$reste=max(0,(float)$print['montant']-$paid);
    $client=$pd['client']??preg_replace('/^Prestation DTF\s*-\s*/i','',$print['libelle']);
    $lignes=$pd['lignes']??[];
    ?>
    <!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Prestation <?=h($pd['ref']??'')?></title>
    <style>
    *{box-sizing:border-box}body{margin:0;background:#eef2f6;font-family:Arial;color:#152b40}.paper{width:210mm;min-height:297mm;margin:20px auto;background:#fff;padding:18mm;box-shadow:0 2px 14px #0001}.head{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #1769e8;padding-bottom:12px}.logo{width:125px;max-height:70px;object-fit:contain}.brand{text-align:right;font-size:10px;line-height:1.7;color:#536575}.brand b{font-size:18px;color:#142b40}.title{margin:22px 0 12px;display:flex;justify-content:space-between}.title h1{font-size:22px;margin:0}.meta{font-size:10px;line-height:1.7}.client{background:#f5f8fb;border-radius:8px;padding:10px;margin-bottom:15px;font-size:11px}.client b{font-size:13px}.table{width:100%;border-collapse:collapse}.table th{font-size:9px;background:#edf3f8;padding:9px;text-align:left}.table td{font-size:10px;padding:9px;border-bottom:1px solid #e8edf1}.right{text-align:right}.totals{margin-top:15px;margin-left:auto;width:270px;font-size:11px;line-height:2}.total{font-size:15px;font-weight:bold;border-top:2px solid #17324a;padding-top:5px}.note{margin-top:20px;font-size:10px;background:#f7f9fb;padding:10px;border-radius:8px}.signature{margin-top:45px;display:flex;justify-content:flex-end}.sigbox{width:220px;text-align:center}.space{height:65px}.space img{max-width:170px;max-height:60px;object-fit:contain}.sigline{border-top:1px solid #34495e;padding-top:6px;font-size:10px}.hidden{display:none}.printbar{width:210mm;margin:12px auto;display:flex;justify-content:space-between;align-items:center}.printbar button{background:#1769e8;color:white;border:0;border-radius:7px;padding:10px 14px;font-weight:bold}.printbar label{font-size:11px}@media print{body{background:#fff}.paper{margin:0;box-shadow:none;width:auto;min-height:auto}.printbar{display:none}}
    </style></head><body>
    <div class="printbar"><label><input type="checkbox" id="addSignature"> Ajouter la signature</label><button onclick="window.print()">🖨️ Imprimer / PDF</button></div>
    <div class="paper">
      <div class="head"><div><img class="logo" src="assets/logo.png" alt="LAMBEMAH"></div><div class="brand"><b>LAMBEMAH GESTION</b><br>GESTION • PRESTATION<br>+224 611752767 / 622595362<br>konatelambetenin@gmail.com<br>KM 36</div></div>
      <div class="title"><h1>FACTURE DE PRESTATION</h1><div class="meta"><b><?=h($pd['ref']??'PRESTATION')?></b><br><?=h(date('d/m/Y',strtotime($print['date_recette']??'now')))?></div></div>
      <div class="client"><b>Client : <?=h($client)?></b></div>
      <table class="table"><thead><tr><th>Article</th><th>Qté</th><th>Prix/u</th><th>A4/u</th><th class="right">Montant</th></tr></thead><tbody>
      <?php foreach($lignes as $li):?><tr><td><?=h($li['article']??'')?></td><td><?=h($li['qty']??0)?></td><td><?=money($li['prix']??0)?></td><td><?=h($li['a4']??0)?></td><td class="right"><?=money($li['montant']??0)?></td></tr><?php endforeach;?>
      </tbody></table>
      <div class="totals"><div>Total : <b><?=money($print['montant'])?></b></div><div>Payé : <b><?=money($paid)?></b></div><div>Reste : <b><?=money($reste)?></b></div><div class="total">Net à payer : <?=money($print['montant'])?></div></div>
      <?php if(!empty($pd['note'])):?><div class="note"><b>Note :</b> <?=h($pd['note'])?></div><?php endif;?>
      <div class="signature"><div class="sigbox"><div class="space"><img id="signatureImg" class="hidden" src="assets/signature.png" alt="Signature"></div><div class="sigline">Responsable</div></div></div>
    </div>
    <script>const c=document.getElementById('addSignature'),img=document.getElementById('signatureImg');c.addEventListener('change',()=>img.classList.toggle('hidden',!c.checked));</script>
    </body></html><?php exit;
}

$nb=0;$total=0;$couts=0;
$r=$conn->query("SELECT COUNT(*) n,COALESCE(SUM(montant),0) t FROM recettes WHERE libelle LIKE 'Prestation DTF%'");if($r){$x=$r->fetch_assoc();$nb=(int)$x['n'];$total=(float)$x['t'];}
$r=$conn->query("SELECT COALESCE(SUM(montant),0) t FROM depenses WHERE libelle LIKE 'DTF fournisseur - %'");if($r)$couts=(float)$r->fetch_assoc()['t'];
$benef=$total-$couts;$items=[];
$r=$conn->query("SELECT id,libelle,montant,description,date_recette FROM recettes WHERE libelle LIKE 'Prestation DTF%' ORDER BY id DESC LIMIT 30");if($r)while($x=$r->fetch_assoc())$items[]=$x;
?>
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Prestations</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f4f7fb;font-family:Arial;color:#163047}.wrap{max-width:1200px;margin:auto;padding:15px}.top{display:flex;justify-content:space-between;align-items:center}.top h1{font-size:21px;margin:0}.top p{font-size:10px;color:#83909c}.btn{background:#1769e8;color:#fff;border:0;border-radius:8px;padding:9px 12px;font-size:10px;font-weight:bold;text-decoration:none;cursor:pointer}.cards{display:grid;grid-template-columns:repeat(4,1fr);gap:9px;margin:12px 0}.card,.box{background:#fff;border:1px solid #e1e9f0;border-radius:13px}.card{padding:12px}.card small{font-size:9px;color:#8795a1}.card b{display:block;margin-top:4px;font-size:16px}.grid{display:grid;grid-template-columns:400px 1fr;gap:12px}.box{padding:14px}.box h2{font-size:14px;margin:0 0 11px}.group{margin-bottom:9px}.label{display:block;font-size:9px;font-weight:bold;color:#627381;margin-bottom:4px}input,textarea{width:100%;padding:8px;border:1px solid #dce5ec;border-radius:7px;font-size:10px}textarea{min-height:50px}.line{display:grid;grid-template-columns:1.5fr .5fr .8fr .6fr 22px;gap:4px;margin-bottom:5px}.line input{padding:7px}.head{font-size:8px;color:#82909b}.x{border:0;border-radius:6px;background:#eef2f5;color:#d33}.tot{background:#eef8ff;border-radius:8px;padding:8px;font-size:10px;line-height:1.8;margin:9px 0}.tot b{font-size:12px}.actions{display:flex;gap:5px}.actions>*{flex:1;text-align:center}table{width:100%;border-collapse:collapse}th{font-size:8px;color:#778692;text-align:left;background:#f6f9fb;padding:8px}td{font-size:9px;padding:8px;border-bottom:1px solid #edf1f4}.green{color:#15935a}.blue{color:#1769e8}.modal{display:none;position:fixed;inset:0;background:#0005;align-items:center;justify-content:center}.modalbox{background:#fff;border-radius:13px;padding:16px;width:min(340px,92%)}.modalbox h3{font-size:14px;margin:0 0 10px}.warn,.msg{font-size:9px;padding:8px;border-radius:8px;margin:8px 0}.warn{background:#fff8df;color:#786100}.ok{background:#eaf8ef;color:#187244}.err{background:#fff0f0;color:#b52d2d}.table{overflow:auto}
@media(max-width:850px){.grid{grid-template-columns:1fr}.cards{grid-template-columns:1fr 1fr}}@media(max-width:600px){.wrap{padding:9px}.card{padding:10px}.grid{gap:9px}.box{padding:11px}.table table{min-width:650px}}
</style></head><body><div class="wrap">
<div class="top"><div><h1>Prestations</h1><p>Articles client • DTF • bénéfice</p></div><a class="btn" href="index.php">Accueil</a></div>
<?php if($msg):?><div class="msg ok"><?=h($msg)?></div><?php endif;?><?php if($err):?><div class="msg err"><?=h($err)?></div><?php endif;?>
<div class="cards"><div class="card"><small>Prestations</small><b><?=$nb?></b></div><div class="card"><small>Total</small><b><?=money($total)?></b></div><div class="card"><small>DTF</small><b><?=money($couts)?></b></div><div class="card"><small>Bénéfice</small><b class="green"><?=money($benef)?></b></div></div>
<div class="grid"><div class="box"><h2><?=$edit?'Modifier':'Nouvelle prestation'?></h2><form method="post"><input type="hidden" name="action" value="save"><?php if($edit):?><input type="hidden" name="id" value="<?=$edit['id']?>"><?php endif;?>
<div class="group"><label class="label">CLIENT</label><input name="client" required value="<?=h($edit['data']['client']??'')?>" placeholder="Nom"></div>
<div class="group"><label class="label">ARTICLES</label><div class="line head"><span>Article</span><span>Qté</span><span>Prix/u</span><span>A4/u</span><span></span></div><div id="lines">
<?php $ls=$edit['data']['lignes']??[['article'=>'','qty'=>1,'prix'=>0,'a4'=>1]];foreach($ls as $z):?><div class="line"><input name="article[]" value="<?=h($z['article']??'')?>"><input type="number" name="qty[]" min="1" value="<?=h($z['qty']??1)?>"><input type="number" name="prix[]" min="0" step="500" value="<?=h($z['prix']??0)?>"><input type="number" name="a4[]" min="0" step=".5" value="<?=h($z['a4']??1)?>"><button type="button" class="x" onclick="this.parentElement.remove();calc()">×</button></div><?php endforeach;?></div>
<button type="button" class="btn" style="background:#eaf3ff;color:#1769e8" onclick="add()">+ Article</button></div>
<div class="group"><label class="label">NOTE</label><textarea name="note"><?=h($edit['data']['note']??'')?></textarea></div>
<div class="tot">Total : <b id="total">0 FG</b><br>A4 : <b id="a4">0</b> • DTF : <b id="cout">0 FG</b><br>Bénéfice : <b id="benef">0 FG</b></div>
<div class="actions"><?php if($edit):?><a class="btn" style="background:#edf1f4;color:#536575" href="prestations.php">Annuler</a><?php endif;?><button class="btn" type="submit"><?= $edit?'Enregistrer':'Valider'?></button></div>
<p style="font-size:8px;color:#8996a0">DTF = 5 000 FG/A4 • grand : 1 A4 ou + • enfant/képi : 0,5 A4/u si 2 designs sur 1 A4.</p>
</form></div>
<div class="box"><h2>Historique</h2><div class="table"><table><thead><tr><th>Client</th><th>Articles</th><th>Total</th><th>Payé</th><th>Reste</th><th>État</th><th></th></tr></thead><tbody>
<?php foreach($items as $p):$d=parseP($p['description']);$new=!empty($d['ref']);$paid=(float)($d['paid']??0);$reste=max(0,(float)$p['montant']-$paid);$status=$reste<=0.01?'TOUT PAYÉ':($paid>0?'AVANCE':'NON PAYÉ');?><tr><td><b><?=h($d['client']?:preg_replace('/^Prestation DTF\s*-\s*/i','',$p['libelle']))?></b></td><td><?=$new?count($d['lignes']).' ligne(s) • '.$d['a4'].' A4':'Ancien format'?></td><td class="blue"><b><?=money($p['montant'])?></b></td><td><?=money($paid)?></td><td class="<?= $reste>0.01?'':'green' ?>"><b><?=money($reste)?></b></td><td><b><?=$status?></b></td><td><div style="display:flex;gap:4px;flex-wrap:wrap"><a class="btn" style="background:#eef5ff;color:#1769e8" href="?imprimer=<?=$p['id']?>">Imprimer</a><?php if($reste>0.01):?><button type="button" class="btn" onclick="openPay(<?=$p['id']?>,<?=json_encode($reste)?>)">Avance</button><form method="post" style="display:inline"><input type="hidden" name="action" value="pay"><input type="hidden" name="id" value="<?=$p['id']?>"><input type="hidden" name="mode" value="total"><button class="btn" type="submit">Tout payé</button></form><?php if($new):?><a class="btn" style="background:#edf1f4;color:#536575" href="?edit=<?=$p['id']?>">Modifier</a><?php endif;?><?php else:?><span class="green">✓</span><?php endif;?></div></td></tr><?php endforeach;?>
<?php if(!$items):?><tr><td colspan="7">Aucune prestation.</td></tr><?php endif;?></tbody></table></div></div></div></div>
<div class="modal" id="payModal"><div class="modalbox"><h3>Avance</h3><form method="post"><input type="hidden" name="action" value="pay"><input type="hidden" name="id" id="payId"><label class="label">Montant</label><input type="number" name="montant" id="payAmount" min="1" step="500" required><div class="actions" style="margin-top:10px"><button type="button" class="btn" style="background:#edf1f4;color:#536575" onclick="closePay()">Annuler</button><button class="btn" type="submit">Enregistrer</button></div></form></div></div>
<script>
function openPay(id,reste){document.getElementById("payId").value=id;document.getElementById("payAmount").value=Math.round(reste);document.getElementById("payAmount").max=Math.round(reste);document.getElementById("payModal").style.display="flex"}
function closePay(){document.getElementById("payModal").style.display="none"}
const f=n=>new Intl.NumberFormat('fr-FR').format(Math.round(n))+' FG';
function calc(){let t=0,a=0;document.querySelectorAll('#lines .line').forEach(r=>{let q=+r.querySelector('[name="qty[]"]').value||0,p=+r.querySelector('[name="prix[]"]').value||0,u=+r.querySelector('[name="a4[]"]').value||0;t+=q*p;a+=q*u});total.textContent=f(t);a4.textContent=a;cout.textContent=f(a*5000);benef.textContent=f(t-a*5000)}
function add(){let d=document.createElement('div');d.className='line';d.innerHTML='<input name="article[]" placeholder="Article"><input type="number" name="qty[]" min="1" value="1"><input type="number" name="prix[]" min="0" step="500" value="0"><input type="number" name="a4[]" min="0" step=".5" value="1"><button type="button" class="x">×</button>';d.querySelector('.x').onclick=()=>{d.remove();calc()};d.querySelectorAll('input').forEach(x=>x.oninput=calc);lines.appendChild(d);calc()}
document.querySelectorAll('#lines input').forEach(x=>x.oninput=calc);calc();
</script></body></html>
