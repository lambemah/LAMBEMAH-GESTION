
2d8b1cdc-1574-46b4-b369-eb402688de00.png

52304608-7b57-48ad-affc-e4bb2da0865f.png

23f71b5a-2855-44c7-90b4-e0d06e127f57.png
ET POURQUOI  CE  TRUCS  ROUGE

420d9bb6-7ef2-429e-a07c-5acb7b679d2c.png
 pcest xa

Capture d'écran 2026-09-04 175446(1).png
AH SA NE MARCE PAS? REGARDE LAPPLICATION DE BIK

1c944542-b9de-44e0-8ed5-4f79c7b4e387.png
VRAIMENT JE NAI PLUS LE COURAGE DE CONTINUER AVEC TOI,RIEN NE MARHE WESH,l les caractere ne sont bonnes,les instructions ne sont pas respecter seulement la table prestaion qui me plai ici sinon vraiment

66f626e0-e951-43ec-a1d4-e896f31f6aa2.png
IL nya meme plus possibilité de saisir des nouveau produits,les cases ou mettre les valeurs sont restratrainte, en gros je napprecie rien ic à part prestation pp

66f626e0-e951-43ec-a1d4-e896f31f6aa2.png
IL nya meme plus possibilité de saisir des nouveau produits,les cases ou mettre les valeurs sont restratrainte, en gros je napprecie rien ic à part prestation pp,meme quand on modifie et actualise sa ne marche pas

f928a6b7-07e5-4728-bb08-d798b1165cb4.png
FINALEMENT JE VAIS ABANDONNER XA BHA VRAIMENT?TOI REGARDE COMMENT CEST GROP,moi je souhaite une police vraiment petite

f928a6b7-07e5-4728-bb08-d798b1165cb4.png
FINALEMENT JE VAIS ABANDONNER XA BHA VRAIMENT?TOI REGARDE COMMENT CEST GROP,moi je souhaite une police vraiment petite pour bien travailler mais là

d00577db-9893-4660-bcf0-e009f776744d.png

b5a8e5c7-c9b6-47ec-9c6d-04bd90903a83.png

Oui, tu es bien sur le bon commit 910fd1c.

Ne touche pas au code affiché.

Fais exactement ceci :
Remonte tout en haut de cette page.

En haut du commit, cherche le bouton Revert.

Clique sur Revert.

GitHub va ouvrir une page de confirmation.

Clique sur Revert une deuxième fois pour créer le nouveau commit.

⚠️ Ne clique pas sur le + bleu à gauche du code et ne modifie rien dans le fichier.

Une fois que tu vois la page de confirmation du Revert, envoie-moi la capture et je te dis le dernier clic.


TU NE PEUX PAS ME DIRIGER DIRECTEMENT VERS LABAS

Oui. J’ai vérifié : GitHub ne montre pas de bouton Revert sur cette page dans ton état actuel, donc je ne vais pas t’inventer un lien qui ne fonctionne pas. 

Le moyen le plus direct est de restaurer le fichier avec le commit parent 2c17cc4, puisque 910fd1c a pour parent 2c17cc4. 

👉 Ouvre directement la version précédente de produits.php :


Ensuite Edit (✏️) → Ctrl+A → copie tout → remets-le dans main/produits.php → Commit changes.

Ça remettra produits.php dans l’état juste avant le commit 910fd1c. 


TU NE PEUX MENVOYER LE FICHIER DIRECTEMENT ICI STP

Oui. Je te l’envoie directement ici.

👉 

Cette version est syntaxiquement valide et correspond à la version stable que nous avions préparée avant le dernier problème.

Ne modifie rien d’autre. Remplace uniquement le contenu de produits.php par celui-ci, puis fais Commit changes.

Après le déploiement, on vérifie uniquement Produits / Achats.



ENVOIS LE LIEN POUR QUE JE COLLE CE CODE

Oui. Voici le lien direct pour modifier produits.php :

👉 

Ensuite :

Ctrl + A → efface tout → colle le contenu de produits_stable.php → Commit changes.

Le fichier est ici si tu dois le récupérer :



Bibliothèque
/
produits_stable.php


1
2
3
4
5
6
7
8
9
10
11
12
13
14
15
16
17
18
19
20
21
22
23
24
25
26
<?php
session_start();
require_once __DIR__ . '/config.php';
if (!isset($conn) || !($conn instanceof mysqli)) die('Connexion à la base de données impossible.');
$conn->set_charset('utf8mb4');
mysqli_report(MYSQLI_REPORT_OFF);

function h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function money($v){return number_format((float)$v,0,',',' ').' FG';}
function cleanText($v){return trim(preg_replace('/[|\r\n]+/',' ',(string)$v));}
function nextId(mysqli $conn,string $table):int{
    static $nextIds=[];
    if(!in_array($table,['produits','mouvements','ventes'],true)) return 1;
    if(!isset($nextIds[$table])){
        $q=$conn->query("SELECT COALESCE(MAX(id),0)+1 AS n FROM `$table`");
        if(!$q) throw new Exception($conn->error);
        $row=$q->fetch_assoc();
        $nextIds[$table]=(int)($row['n']??1);
    }
    return $nextIds[$table]++;
}
function parseMeta(string $desc):array{
    $o=[];
    foreach(explode('|',$desc) as $p){
        $p=trim($p);
        if(strpos($p,'=')!==false){[$k,$v]=explode('=',$p,2);$o[trim($k)]=trim($v);continue;}
