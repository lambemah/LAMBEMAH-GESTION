<?php
session_start();
require_once __DIR__.'/config.php';
if(!isset($_SESSION['id'])){header('Location: index.php');exit;}
mysqli_report(MYSQLI_REPORT_OFF);

function h($x){return htmlspecialchars((string)$x,ENT_QUOTES,'UTF-8');}
function money($x){return number_format((float)$x,0,',',' ').' FG';}
function parseP($s){
    $d=['ref'=>'','client'=>'','note'=>'','lignes'=>[],'total'=>0,'a4'=>0,'cout'=>0,'benefice'=>0];
    if(preg_match('/REF=([^|]+)/',$s,$m))$d['ref']=$m[1];
    if(preg_match('/DATA=(.+)$/',$s,$m)){
        $j=base64_decode($m[1],true);$x=$j?json_decode($j,true):null;
        if(is_array($x))return array_merge($d,$x);
    }
    if(preg_match('/Client\s*:\s*([^|]+)/i',$s,$m))$d['client']=trim($m[1]);
    if(preg_match('/Coût DTF total\s*:\s*([0-9 ]+)/i',$s,$m))$d['cout']=(float)str_replace(' ','',$m[1]);
    if(preg_match('/Bénéfice\s*:\s*([0-9 ]+)/i',$s,$m))$d['benefice']=(float)str_replace(' ','',$m[1]);
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
        $data=['client'=>$client,'note'=>$note,'lignes'=>$l,'total'=>$total,'a4'=>$a4Total,'cout'=>$cout,'benefice'=>$benef];
        $id=(int)($_POST['id']??0);$ref='';
        if($id){
            $st=$conn->prepare("SELECT description FROM recettes WHERE id=? AND libelle LIKE 'Prestation DTF%'");
            $st->bind_param('i',$id);$st->execute();$old=$st->get_result()->fetch_assoc();$st->close();
            $od=$old?parseP($old['description']):[];
            if(!$old||empty($od['ref']))$err='Cette ancienne prestation garde son historique mais ne peut pas être recalculée automatiquement.';
            else $ref=$od['ref'];
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
if($editId&&!$err){
    $st=$conn->prepare("SELECT id,libelle,montant,description,date_recette FROM recettes WHERE id=? LIMIT 1");$st->bind_param('i',$editId);$st->execute();$edit=$st->get_result()->fetch_assoc();$st->close();
    if($edit&&stripos($edit['libelle'],'Prestation DTF')!==false)$edit['data']=parseP($edit['description']);else $edit=null;
}

$nb=0;$total=0;$couts=0;
$r=$conn->query("SELECT COUNT(*) n,COALESCE(SUM(montant),0) t FROM recettes WHERE libelle LIKE 'Prestation DTF%'");if($r){$x=$r->fetch_assoc();$nb=(int)$x['n'];$total=(float)$x['t'];}
$r=$conn->query("SELECT COALESCE(SUM(montant),0) t FROM depenses WHERE libelle LIKE 'DTF fournisseur - %'");if($r)$couts=(float)$r->fetch_assoc()['t'];
$benef=$total-$couts;$items=[];
$r=$conn->query("SELECT id,libelle,montant,description,date_recette FROM recettes WHERE libelle LIKE 'Prestation DTF%' ORDER BY id DESC LIMIT 30");if($r)while($x=$r->fetch_assoc())$items[]=$x;
?>
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Prestations</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f4f7fb;font-family:Arial;color:#163047}.wrap{max-width:1200px;margin:auto;padding:15px}.top{display:flex;justify-content:space-between;align-items:center}.top h1{font-size:21px;margin:0}.top p{font-size:10px;color:#83909c}.btn{background:#1769e8;color:#fff;border:0;border-radius:8px;padding:9px 12px;font-size:10px;font-weight:bold;text-decoration:none;cursor:pointer}.cards{display:grid;grid-template-columns:repeat(4,1fr);gap:9px;margin:12px 0}.card,.box{background:#fff;border:1px solid #e1e9f0;border-radius:13px}.card{padding:12px}.card small{font-size:9px;color:#8795a1}.card b{display:block;margin-top:4px;font-size:16px}.grid{display:grid;grid-template-columns:400px 1fr;gap:12px}.box{padding:14px}.box h2{font-size:14px;margin:0 0 11px}.group{margin-bottom:9px}.label{display:block;font-size:9px;font-weight:bold;color:#627381;margin-bottom:4px}input,textarea{width:100%;padding:8px;border:1px solid #dce5ec;border-radius:7px;font-size:10px}textarea{min-height:50px}.line{display:grid;grid-template-columns:1.5fr .5fr .8fr .6fr 22px;gap:4px;margin-bottom:5px}.line input{padding:7px}.head{font-size:8px;color:#82909b}.x{border:0;border-radius:6px;background:#eef2f5;color:#d33}.tot{background:#eef8ff;border-radius:8px;padding:8px;font-size:10px;line-height:1.8;margin:9px 0}.tot b{font-size:12px}.actions{display:flex;gap:5px}.actions>*{flex:1;text-align:center}table{width:100%;border-collapse:collapse}th{font-size:8px;color:#778692;text-align:left;background:#f6f9fb;padding:8px}td{font-size:9px;padding:8px;border-bottom:1px solid #edf1f4}.green{color:#15935a}.blue{color:#1769e8}.warn,.msg{font-size:9px;padding:8px;border-radius:8px;margin:8px 0}.warn{background:#fff8df;color:#786100}.ok{background:#eaf8ef;color:#187244}.err{background:#fff0f0;color:#b52d2d}.table{overflow:auto}
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
<div class="box"><h2>Historique</h2><div class="table"><table><thead><tr><th>Client</th><th>Articles</th><th>Total</th><th>DTF</th><th>Bénéfice</th><th>Date</th><th></th></tr></thead><tbody>
<?php foreach($items as $p):$d=parseP($p['description']);$new=!empty($d['ref']);?><tr><td><b><?=h($d['client']?:preg_replace('/^Prestation DTF\s*-\s*/i','',$p['libelle']))?></b></td><td><?=$new?count($d['lignes']).' ligne(s) • '.$d['a4'].' A4':'Ancien format'?></td><td class="blue"><b><?=money($p['montant'])?></b></td><td><?=$new?money($d['cout']):'—'?></td><td class="green"><?=$new?money($d['benefice']):'—'?></td><td><?=h(date('d/m/Y',strtotime($p['date_recette']??'now')))?></td><td><?php if($new):?><a class="btn" href="?edit=<?=$p['id']?>">Modifier</a><?php endif;?></td></tr><?php endforeach;?>
<?php if(!$items):?><tr><td colspan="7">Aucune prestation.</td></tr><?php endif;?></tbody></table></div></div></div></div>
<script>
const f=n=>new Intl.NumberFormat('fr-FR').format(Math.round(n))+' FG';
function calc(){let t=0,a=0;document.querySelectorAll('#lines .line').forEach(r=>{let q=+r.querySelector('[name="qty[]"]').value||0,p=+r.querySelector('[name="prix[]"]').value||0,u=+r.querySelector('[name="a4[]"]').value||0;t+=q*p;a+=q*u});total.textContent=f(t);a4.textContent=a;cout.textContent=f(a*5000);benef.textContent=f(t-a*5000)}
function add(){let d=document.createElement('div');d.className='line';d.innerHTML='<input name="article[]" placeholder="Article"><input type="number" name="qty[]" min="1" value="1"><input type="number" name="prix[]" min="0" step="500" value="0"><input type="number" name="a4[]" min="0" step=".5" value="1"><button type="button" class="x">×</button>';d.querySelector('.x').onclick=()=>{d.remove();calc()};d.querySelectorAll('input').forEach(x=>x.oninput=calc);lines.appendChild(d);calc()}
document.querySelectorAll('#lines input').forEach(x=>x.oninput=calc);calc();
</script></body></html>
