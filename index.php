<?php
session_start();
require_once __DIR__ . '/config.php';

/* Déconnexion AVANT toute vérification de session */
if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p["path"], $p["domain"], $p["secure"], $p["httponly"]);
    }
    session_destroy();
    header('Location: index.php');
    exit;
}

/* Connexion */
$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_submit'])) {
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $loginError = 'Veuillez remplir tous les champs.';
    } else {
        $stmt = $conn->prepare("SELECT id, nom, username, mot_de_passe, role FROM utilisateurs WHERE username=? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $res = $stmt->get_result();
            $u = $res ? $res->fetch_assoc() : null;
            $stmt->close();

            if ($u) {
                $stored = (string)$u['mot_de_passe'];
                $valid = password_verify($password, $stored);

                if (!$valid) {
                    $valid = hash_equals($stored, $password);
                }

                if ($valid) {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = (int)$u['id'];
                    $_SESSION['utilisateur_id'] = (int)$u['id'];
                    $_SESSION['id_utilisateur'] = (int)$u['id'];
                    $_SESSION['nom'] = $u['nom'];
                    $_SESSION['username'] = $u['username'];
                    $_SESSION['role'] = $u['role'];

                    header('Location: index.php');
                    exit;
                }
            }

            $loginError = 'Nom d’utilisateur ou mot de passe incorrect.';
        } else {
            $loginError = 'Erreur de connexion à la base de données.';
        }
    }
}

$loggedIn =
    isset($_SESSION['user_id']) ||
    isset($_SESSION['utilisateur_id']) ||
    isset($_SESSION['id_utilisateur']);

if (!$loggedIn) :
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Connexion - LAMBEMAH GESTION</title>

<style>
*{
    box-sizing:border-box;
}

body{
    margin:0;
    min-height:100vh;
    display:flex;
    align-items:center;
    justify-content:center;
    background:#eef4fa;
    font-family:Arial,sans-serif;
    color:#172033;
}

.box{
    width:min(410px,92%);
    background:#fff;
    border-radius:18px;
    padding:30px;
    box-shadow:0 12px 35px rgba(7,26,53,.12);
    border:1px solid #dfe8f2;
}

.logo{
    text-align:center;
    margin-bottom:22px;
}

.logo b{
    font-size:24px;
    color:#071a35;
}

.logo small{
    display:block;
    color:#718096;
    margin-top:6px;
}

label{
    display:block;
    font-size:13px;
    font-weight:bold;
    margin:14px 0 7px;
}

input{
    width:100%;
    padding:13px;
    border:1px solid #d5deea;
    border-radius:9px;
    font-size:15px;
    outline:none;
}

input:focus{
    border-color:#1479e8;
}

button{
    width:100%;
    margin-top:20px;
    border:0;
    background:#1479e8;
    color:#fff;
    padding:13px;
    border-radius:9px;
    font-size:15px;
    font-weight:bold;
    cursor:pointer;
}

.error{
    background:#fff0f0;
    color:#c0392b;
    border:1px solid #f1caca;
    padding:11px;
    border-radius:8px;
    font-size:13px;
    margin-bottom:12px;
}
</style>
</head>

<body>

<div class="box">

    <div class="logo">
        <b>LAMBEMAH GESTION</b>
        <small>Connexion à votre espace</small>
    </div>

    <?php if($loginError): ?>
        <div class="error"><?=h($loginError)?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">

        <label>Nom d’utilisateur</label>
        <input
            type="text"
            name="username"
            required
            autofocus
        >

        <label>Mot de passe</label>
        <input
            type="password"
            name="password"
            required
        >

        <button
            type="submit"
            name="login_submit"
        >
            Se connecter
        </button>

    </form>

</div>

</body>
</html>

<?php
exit;
endif;

function h($v){
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function money($v){
    return number_format((float)$v, 0, ',', ' ') . ' FG';
}

$userName = $_SESSION['nom'] ?? $_SESSION['username'] ?? 'Utilisateur';
$role = $_SESSION['role'] ?? 'Utilisateur';

/* =========================
   INDICATEURS
   ========================= */

$caVentes = 0;
$nbVentes = 0;

$q = $conn->query("
    SELECT
        COUNT(*) n,
        COALESCE(SUM(montant),0) total
    FROM ventes
");

if($q){
    $r = $q->fetch_assoc();

    $nbVentes = (int)$r['n'];
    $caVentes = (float)$r['total'];
}


$caPrestations = 0;
$nbPrestations = 0;

$q = $conn->query("
    SELECT
        COUNT(*) n,
        COALESCE(SUM(montant),0) total
    FROM recettes
    WHERE libelle LIKE 'Prestation DTF%'
");

if($q){
    $r = $q->fetch_assoc();

    $nbPrestations = (int)$r['n'];
    $caPrestations = (float)$r['total'];
}


$recettesManuelles = 0;

$q = $conn->query("
    SELECT
        COALESCE(SUM(montant),0) total
    FROM recettes
    WHERE libelle NOT LIKE 'Prestation DTF%'
");

if($q){
    $recettesManuelles = (float)$q->fetch_assoc()['total'];
}


$depenses = 0;

$q = $conn->query("
    SELECT
        COALESCE(SUM(montant),0) total
    FROM depenses
");

if($q){
    $depenses = (float)$q->fetch_assoc()['total'];
}


/*
 * Coût estimatif des marchandises vendues.
 * Le prix d'achat actuel du produit sert de référence
 * lorsque le coût historique n'est pas encodé dans la vente.
 */

$cogs = 0;

$q = $conn->query("
    SELECT
        v.quantite,
        p.prix_achat
    FROM ventes v
    LEFT JOIN produits p
        ON p.id = v.produit_id
");

if($q){

    while($r = $q->fetch_assoc()){

        $cogs +=
            (float)$r['quantite']
            *
            (float)($r['prix_achat'] ?? 0);
    }
}


$caTotal =
    $caVentes
    +
    $caPrestations
    +
    $recettesManuelles;


/*
 * Les coûts DTF sont déjà dans depenses :
 * on ne les soustrait pas une deuxième fois.
 */

$benefice =
    $caTotal
    -
    $cogs
    -
    $depenses;


/* =========================
   STOCK
   ========================= */

$stockQte = 0;
$stockValeur = 0;
$produitsCount = 0;
$rupture = 0;
$faible = 0;

$q = $conn->query("
    SELECT
        id,
        nom,
        categorie,
        prix_achat,
        prix_vente,
        stock
    FROM produits
    ORDER BY nom ASC
");

$produits = [];

if($q){

    while($r = $q->fetch_assoc()){

        $produits[] = $r;

        $produitsCount++;

        $stockQte += (int)$r['stock'];

        $stockValeur +=
            (float)$r['stock']
            *
            (float)$r['prix_achat'];

        if((int)$r['stock'] <= 0){

            $rupture++;

        }elseif((int)$r['stock'] <= 5){

            $faible++;
        }
    }
}


/* =========================
   DERNIÈRES VENTES
   ========================= */

$recentSales = [];

$q = $conn->query("
    SELECT
        v.id,
        v.quantite,
        v.prix_unitaire,
        v.montant,
        v.date_vente,
        p.nom
    FROM ventes v
    LEFT JOIN produits p
        ON p.id = v.produit_id
    ORDER BY v.id DESC
    LIMIT 6
");

if($q){

    while($r = $q->fetch_assoc()){

        $recentSales[] = $r;
    }
}


/* =========================
   DERNIÈRES PRESTATIONS
   ========================= */

$recentPrestations = [];

$q = $conn->query("
    SELECT
        id,
        libelle,
        montant,
        date_recette,
        description
    FROM recettes
    WHERE libelle LIKE 'Prestation DTF%'
    ORDER BY id DESC
    LIMIT 5
");

if($q){

    while($r = $q->fetch_assoc()){

        $recentPrestations[] = $r;
    }
}


/* =========================
   PRODUITS LES PLUS VENDUS
   ========================= */

$topProducts = [];

$q = $conn->query("
    SELECT
        p.nom,
        SUM(v.quantite) qte,
        SUM(v.montant) ca
    FROM ventes v
    LEFT JOIN produits p
        ON p.id = v.produit_id
    GROUP BY
        v.produit_id,
        p.nom
    ORDER BY qte DESC
    LIMIT 5
");

if($q){

    while($r = $q->fetch_assoc()){

        $topProducts[] = $r;
    }
}

?>
<!doctype html>
<html lang="fr">

<head>

<meta charset="utf-8">

<meta
    name="viewport"
    content="width=device-width,initial-scale=1"
>

<title>
    Tableau de bord - LAMBEMAH GESTION
</title>

<style>

*{
    box-sizing:border-box;
}

body{
    margin:0;
    font-family:Arial,Helvetica,sans-serif;
    background:#f4f7fb;
    color:#172033;
}

a{
    text-decoration:none;
    color:inherit;
}

.layout{
    display:flex;
    min-height:100vh;
}


/* =========================
   SIDEBAR
   ========================= */

.sidebar{
    width:245px;
    background:#071a35;
    color:#fff;
    padding:22px 14px;
    position:fixed;
    inset:0 auto 0 0;
}

.brand{
    padding:8px 12px 25px;
    border-bottom:1px solid rgba(255,255,255,.1);
    margin-bottom:16px;
}

.brand b{
    font-size:20px;
    letter-spacing:.5px;
}

.brand small{
    display:block;
    color:#9eb8da;
    margin-top:5px;
}

.nav a{
    display:flex;
    align-items:center;
    gap:12px;
    padding:12px 14px;
    border-radius:10px;
    margin:5px 0;
    color:#dbe7f8;
    font-size:14px;
}

.nav a:hover,
.nav a.active{
    background:#1479e8;
    color:#fff;
}


/* =========================
   MAIN
   ========================= */

.main{
    margin-left:245px;
    width:calc(100% - 245px);
    padding:25px;
}

.top{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:20px;
}

.top h1{
    margin:0;
    font-size:26px;
}

.top p{
    margin:6px 0 0;
    color:#68758a;
}

.user{
    background:#fff;
    padding:9px 13px;
    border-radius:10px;
    border:1px solid #dfe6ef;
    font-size:13px;
}


/* =========================
   GRANDES CARTES
   ========================= */

.grid{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:15px;
}

.card{
    background:#fff;
    border:1px solid #e1e8f1;
    border-radius:14px;
    padding:18px;
    box-shadow:0 4px 15px rgba(20,45,80,.04);
}

.card small{
    display:block;
    color:#68758a;
    margin-bottom:8px;
}

.card b{
    font-size:22px;
}

.blue{
    border-left:5px solid #1479e8;
}

.green{
    border-left:5px solid #17a673;
}

.orange{
    border-left:5px solid #e89b21;
}

.red{
    border-left:5px solid #dc4b4b;
}

.kpi{
    margin-top:8px;
    color:#536176;
    font-size:12px;
}


/* =========================
   PETITS INDICATEURS
   ========================= */

.badges{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-top:15px;
}

.badge{
    background:#fff;
    border:1px solid #e0e7ef;
    border-radius:10px;
    padding:10px 13px;
    font-size:13px;
}

.badge b{
    margin-left:5px;
}


/* =========================
   SECTIONS
   ========================= */

.sections{
    display:grid;
    grid-template-columns:1.3fr 1fr;
    gap:16px;
    margin-top:18px;
}

.title{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:12px;
}

.title h2{
    font-size:17px;
    margin:0;
}

.btn{
    background:#1479e8;
    color:#fff;
    padding:8px 12px;
    border-radius:8px;
    font-size:12px;
}

.btn.gray{
    background:#eef2f7;
    color:#34445b;
}


/* =========================
   TABLEAUX
   ========================= */

.tablewrap{
    overflow:auto;
}

table{
    width:100%;
    border-collapse:collapse;
    font-size:13px;
}

th,
td{
    padding:11px 9px;
    border-bottom:1px solid #e8edf3;
    text-align:left;
    white-space:nowrap;
}

th{
    background:#f2f6fb;
    color:#4c5b70;
}

.money{
    text-align:right;
    font-weight:bold;
}


/* =========================
   ACCÈS RAPIDE
   ========================= */

.quick{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:10px;
}

.quick a{
    background:#fff;
    border:1px solid #e1e8f1;
    border-radius:12px;
    padding:15px;
    text-align:center;
    font-weight:bold;
    color:#17345c;
}

.quick span{
    display:block;
    font-size:23px;
    margin-bottom:7px;
}

.note{
    font-size:11px;
    color:#758196;
    margin-top:10px;
    line-height:1.5;
}


/* =========================
   TABLETTE
   ========================= */

@media(max-width:1000px){

    .grid{
        grid-template-columns:repeat(2,1fr);
    }

    .sections{
        grid-template-columns:1fr;
    }

    .quick{
        grid-template-columns:repeat(2,1fr);
    }
}


/* =========================
   TÉLÉPHONE
   ========================= */

@media(max-width:700px){

    .sidebar{
        width:62px;
        padding:12px 6px;
    }

    .brand{
        padding:7px 4px 18px;
        margin-bottom:10px;
        text-align:center;
    }

    .brand b{
        font-size:0;
    }

    .brand b:after{
        content:'LTK';
        font-size:16px;
    }

    .brand small,
    .nav span{
        display:none;
    }

    .nav a{
        justify-content:center;
        padding:11px 5px;
        border-radius:9px;
        margin:4px 0;
        font-size:17px;
    }

    .main{
        margin-left:62px;
        width:calc(100% - 62px);
        padding:10px;
    }

    .top{
        align-items:flex-start;
        gap:8px;
        margin-bottom:10px;
    }

    .top h1{
        font-size:18px;
    }

    .top p{
        font-size:10.5px;
        margin-top:4px;
    }

    .user{
        font-size:10px;
        padding:7px 9px;
    }

    .grid{
        grid-template-columns:1fr 1fr;
        gap:7px;
    }

    .card{
        padding:10px;
        border-radius:12px;
    }

    .card small{
        font-size:10px;
        margin-bottom:5px;
    }

    .card b{
        font-size:15px;
    }

    .kpi{
        font-size:9px;
        margin-top:5px;
    }

    .badges{
        display:grid;
        grid-template-columns:1fr 1fr;
        gap:6px;
        margin-top:9px;
    }

    .badge{
        padding:8px 8px;
        border-radius:9px;
        font-size:10px;
        white-space:nowrap;
        overflow:hidden;
        text-overflow:ellipsis;
    }

    .badge b{
        margin-left:3px;
    }

    .sections{
        gap:9px;
        margin-top:10px;
    }

    .title{
        margin-bottom:8px;
    }

    .title h2{
        font-size:14px;
    }

    .btn{
        padding:6px 8px;
        font-size:10px;
    }

    .quick{
        grid-template-columns:1fr 1fr;
        gap:7px;
    }

    .quick a{
        padding:10px 6px;
        border-radius:9px;
        font-size:11px;
    }

    .quick span{
        font-size:18px;
        margin-bottom:4px;
    }

    table{
        font-size:10px;
    }

    th,
    td{
        padding:8px 6px;
    }

    .note{
        font-size:9px;
    }
}


/* =========================
   PETIT TÉLÉPHONE
   ========================= */

@media(max-width:420px){

    .main{
        padding:8px;
    }

    .top h1{
        font-size:17px;
    }

    .top p{
        font-size:9.5px;
    }

    .top .user{
        display:none;
    }

    .grid{
        grid-template-columns:1fr 1fr;
        gap:6px;
    }

    .card{
        padding:9px;
    }

    .card b{
        font-size:14px;
    }

    .badges{
        gap:5px;
    }

    .badge{
        font-size:9.5px;
        padding:7px 6px;
    }

    .quick{
        gap:6px;
    }

    .quick a{
        font-size:10.5px;
        padding:9px 5px;
    }

    .sections{
        gap:8px;
    }
}

</style>

</head>

<body>

<div class="layout">


<!-- =========================
     SIDEBAR
     ========================= -->

<aside class="sidebar">

    <div class="brand">

        <b>LAMBEMAH GESTION</b>

        <small>
            Gestion simple & professionnelle
        </small>

    </div>


    <nav class="nav">

        <a
            class="active"
            href="index.php"
        >
            🏠
            <span>Tableau de bord</span>
        </a>


        <a href="produits.php">

            📦

            <span>
                Produits / Achats
            </span>

        </a>


        <a href="ventes.php">

            💰

            <span>
                Ventes
            </span>

        </a>


        <a href="prestations.php">

            🖨️

            <span>
                Prestations
            </span>

        </a>


        <a href="recettes.php">

            💵

            <span>
                Recettes
            </span>

        </a>


        <a href="depenses.php">

            💸

            <span>
                Dépenses
            </span>

        </a>


        <a href="statistiques.php">

            📊

            <span>
                Statistiques
            </span>

        </a>


        <a href="utilisateurs.php">

            👥

            <span>
                Équipe
            </span>

        </a>


        <a href="?logout=1">

            🚪

            <span>
                Déconnexion
            </span>

        </a>

    </nav>

</aside>


<!-- =========================
     CONTENU
     ========================= -->

<main class="main">


    <div class="top">

        <div>

            <h1>
                📊 Tableau de bord
            </h1>

            <p>
                Vue rapide de l'activité de LAMBEMAH GESTION.
            </p>

        </div>


        <div class="user">

            👤
            <?=h($userName)?>
            ·
            <?=h($role)?>

        </div>

    </div>


    <!-- =========================
         GRANDES CARTES
         ========================= -->

    <div class="grid">


        <div class="card blue">

            <small>
                Chiffre d'affaires total
            </small>

            <b>
                <?=money($caTotal)?>
            </b>

            <div class="kpi">
                Ventes + prestations + recettes
            </div>

        </div>


        <div class="card green">

            <small>
                Ventes
            </small>

            <b>
                <?=money($caVentes)?>
            </b>

            <div class="kpi">
                <?=$nbVentes?> ligne(s) de vente
            </div>

        </div>


        <div class="card orange">

            <small>
                Prestations DTF
            </small>

            <b>
                <?=money($caPrestations)?>
            </b>

            <div class="kpi">
                <?=$nbPrestations?> prestation(s)
            </div>

        </div>


        <div class="card red">

            <small>
                Dépenses
            </small>

            <b>
                <?=money($depenses)?>
            </b>

            <div class="kpi">
                Toutes les dépenses enregistrées
            </div>

        </div>

    </div>


    <!-- =========================
         PETITS INDICATEURS
         ========================= -->

    <div class="badges">


        <div class="badge">

            💼
            Bénéfice estimé

            <b>
                <?=money($benefice)?>
            </b>

        </div>


        <div class="badge">

            📦
            Stock

            <b>
                <?=number_format($stockQte,0,',',' ')?>
                unité(s)
            </b>

        </div>


        <div class="badge">

            💰
            Valeur du stock

            <b>
                <?=money($stockValeur)?>
            </b>

        </div>


        <div class="badge">

            ⚠️
            Stock faible

            <b>
                <?=$faible?>
            </b>

        </div>


        <div class="badge">

            ⛔
            Rupture

            <b>
                <?=$rupture?>
            </b>

        </div>

    </div>


    <!-- =========================
         ACCÈS RAPIDE
         ========================= -->

    <section
        class="card"
        style="margin-top:18px"
    >

        <div class="title">

            <h2>
                ⚡ Accès rapide
            </h2>

        </div>


        <div class="quick">


            <a href="ventes.php?nouvelle_vente=1">

                <span>
                    💰
                </span>

                Nouvelle vente

            </a>


            <a href="prestations.php">

                <span>
                    🖨️
                </span>

                Nouvelle prestation

            </a>


            <a href="produits.php">

                <span>
                    📦
                </span>

                Produits / achats

            </a>


            <a href="statistiques.php">

                <span>
                    📊
                </span>

                Voir les statistiques

            </a>


        </div>

    </section>


    <!-- =========================
         DERNIÈRES ACTIVITÉS
         ========================= -->

    <div class="sections">


        <!-- DERNIÈRES VENTES -->

        <section class="card">

            <div class="title">

                <h2>
                    🧾 Dernières ventes
                </h2>

                <a
                    class="btn gray"
                    href="ventes.php"
                >
                    Tout voir
                </a>

            </div>


            <div class="tablewrap">

                <table>

                    <thead>

                        <tr>

                            <th>
                                Article
                            </th>

                            <th>
                                Qté
                            </th>

                            <th>
                                Montant
                            </th>

                            <th>
                                Date
                            </th>

                        </tr>

                    </thead>


                    <tbody>


                    <?php
                    if($recentSales):
                        foreach($recentSales as $r):
                    ?>

                        <tr>

                            <td>
                                <?=h($r['nom']??'Article')?>
                            </td>

                            <td>
                                <?=h($r['quantite'])?>
                            </td>

                            <td class="money">
                                <?=money($r['montant'])?>
                            </td>

                            <td>
                                <?=h(substr((string)$r['date_vente'],0,16))?>
                            </td>

                        </tr>

                    <?php
                        endforeach;
                    else:
                    ?>

                        <tr>

                            <td colspan="4">
                                Aucune vente enregistrée.
                            </td>

                        </tr>

                    <?php
                    endif;
                    ?>

                    </tbody>

                </table>

            </div>

        </section>


        <!-- DERNIÈRES PRESTATIONS -->

        <section class="card">

            <div class="title">

                <h2>
                    🖨️ Dernières prestations
                </h2>

                <a
                    class="btn gray"
                    href="prestations.php"
                >
                    Tout voir
                </a>

            </div>


            <div class="tablewrap">

                <table>

                    <thead>

                        <tr>

                            <th>
                                Client
                            </th>

                            <th>
                                Montant
                            </th>

                            <th>
                                Date
                            </th>

                        </tr>

                    </thead>


                    <tbody>


                    <?php

                    if($recentPrestations):

                        foreach($recentPrestations as $r):

                            $client =
                                preg_replace(
                                    '/^Prestation DTF\s*-\s*/i',
                                    '',
                                    $r['libelle']
                                );

                    ?>

                        <tr>

                            <td>
                                <?=h($client)?>
                            </td>

                            <td class="money">
                                <?=money($r['montant'])?>
                            </td>

                            <td>
                                <?=h(substr((string)$r['date_recette'],0,16))?>
                            </td>

                        </tr>

                    <?php

                        endforeach;

                    else:

                    ?>

                        <tr>

                            <td colspan="3">
                                Aucune prestation enregistrée.
                            </td>

                        </tr>

                    <?php
                    endif;
                    ?>

                    </tbody>

                </table>

            </div>

        </section>

    </div>


    <!-- =========================
         TOP PRODUITS
         ========================= -->

    <section
        class="card"
        style="margin-top:16px"
    >

        <div class="title">

            <h2>
                🏆 Produits les plus vendus
            </h2>

            <a
                class="btn gray"
                href="produits.php"
            >
                Gérer le stock
            </a>

        </div>


        <div class="tablewrap">

            <table>

                <thead>

                    <tr>

                        <th>
                            Produit
                        </th>

                        <th>
                            Quantité vendue
                        </th>

                        <th>
                            CA
                        </th>

                    </tr>

                </thead>


                <tbody>


                <?php

                if($topProducts):

                    foreach($topProducts as $r):

                ?>

                    <tr>

                        <td>
                            <?=h($r['nom']??'Produit')?>
                        </td>

                        <td>
                            <?=number_format(
                                (float)$r['qte'],
                                0,
                                ',',
                                ' '
                            )?>
                        </td>

                        <td class="money">
                            <?=money($r['ca'])?>
                        </td>

                    </tr>

                <?php

                    endforeach;

                else:

                ?>

                    <tr>

                        <td colspan="3">
                            Pas encore de données.
                        </td>

                    </tr>

                <?php
                endif;
                ?>

                </tbody>

            </table>

        </div>


        <div class="note">

            Le bénéfice affiché est une estimation :
            le coût des marchandises vendues est calculé
            à partir du prix d'achat actuellement enregistré
            dans Produits.

            Les coûts DTF déjà enregistrés dans Dépenses
            ne sont pas déduits une deuxième fois.

        </div>

    </section>


</main>

</div>

</body>
</html>
