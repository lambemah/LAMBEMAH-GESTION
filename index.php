<?php
session_start();
require_once __DIR__ . '/config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Connexion à la base de données impossible.');
}

$conn->set_charset('utf8mb4');

function h($v){
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function money($v){
    return number_format((float)$v, 0, ',', ' ') . ' FG';
}

/* =========================================================
   DÉCONNEXION
   ========================================================= */

if (isset($_GET['logout'])) {

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $p['path'],
            $p['domain'],
            $p['secure'],
            $p['httponly']
        );
    }

    session_destroy();

    header('Location: index.php');
    exit;
}

/* =========================================================
   CONNEXION
   ========================================================= */

$loginError = '';

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['login_submit'])
) {

    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {

        $loginError = 'Veuillez remplir tous les champs.';

    } else {

        $stmt = $conn->prepare("
            SELECT id, nom, username, mot_de_passe, role
            FROM utilisateurs
            WHERE username = ?
            LIMIT 1
        ");

        if ($stmt) {

            $stmt->bind_param('s', $username);
            $stmt->execute();

            $r = $stmt->get_result();
            $u = $r ? $r->fetch_assoc() : null;

            $stmt->close();

            if ($u) {

                $stored = (string)$u['mot_de_passe'];

                $valid =
                    password_verify($password, $stored)
                    || hash_equals($stored, $password);

                if ($valid) {

                    session_regenerate_id(true);

                    $_SESSION['user_id'] = (int)$u['id'];
                    $_SESSION['utilisateur_id'] = (int)$u['id'];
                    $_SESSION['id_utilisateur'] = (int)$u['id'];
                    $_SESSION['id'] = (int)$u['id'];

                    $_SESSION['nom'] = $u['nom'];
                    $_SESSION['username'] = $u['username'];
                    $_SESSION['role'] = $u['role'];

                    header('Location: index.php');
                    exit;
                }
            }

            $loginError =
                'Nom d’utilisateur ou mot de passe incorrect.';

        } else {

            $loginError =
                'Erreur de connexion à la base de données.';
        }
    }
}

/* =========================================================
   VÉRIFICATION SESSION
   ========================================================= */

$loggedIn =
    isset($_SESSION['user_id'])
    || isset($_SESSION['utilisateur_id'])
    || isset($_SESSION['id_utilisateur'])
    || isset($_SESSION['id']);


/* =========================================================
   ÉCRAN DE CONNEXION
   ========================================================= */

if (!$loggedIn):
?>

<!doctype html>
<html lang="fr">

<head>

<meta charset="utf-8">

<meta
    name="viewport"
    content="width=device-width,initial-scale=1"
>

<title>Connexion — LAMBEMAH GESTION</title>

<style>

/* =========================================================
   CONNEXION
   ========================================================= */

:root{
    --navy:#071f38;
    --navy2:#0d3558;
    --blue:#1678e8;
    --gold:#d9aa3f;
    --white:#ffffff;
    --text:#17283b;
    --muted:#758397;
    --line:#d9e3ec;
}

*{
    box-sizing:border-box;
}

html,
body{
    margin:0;
    min-height:100%;
    font-family:
        Inter,
        Arial,
        sans-serif;
}

/* Fond élégant 100% CSS
   Pas de grosse image = application légère */

body{
    min-height:100vh;
    display:grid;
    place-items:center;
    padding:18px;

    color:var(--text);

    background:
        radial-gradient(
            circle at 15% 15%,
            rgba(22,120,232,.20),
            transparent 32%
        ),
        radial-gradient(
            circle at 85% 80%,
            rgba(217,170,63,.15),
            transparent 30%
        ),
        linear-gradient(
            135deg,
            #061d35 0%,
            #0b3152 48%,
            #102c45 100%
        );

    position:relative;
    overflow:hidden;
}

/* décor léger */

body::before{
    content:"";
    position:fixed;
    width:420px;
    height:420px;
    border:1px solid rgba(217,170,63,.20);
    border-radius:50%;
    top:-220px;
    left:-180px;
}

body::after{
    content:"";
    position:fixed;
    width:520px;
    height:520px;
    border:1px solid rgba(255,255,255,.08);
    border-radius:50%;
    right:-280px;
    bottom:-270px;
}

/* carte */

.login{
    width:min(410px,100%);

    padding:28px;

    border:1px solid rgba(255,255,255,.28);
    border-radius:24px;

    background:rgba(255,255,255,.94);

    box-shadow:
        0 25px 70px rgba(0,0,0,.30);

    position:relative;
    z-index:2;
}

/* marque */

.brand{
    text-align:center;
}

.logo{
    width:68px;
    height:68px;
    object-fit:contain;
    display:block;
    margin:0 auto 12px;
}

.brand h1{
    margin:0;

    color:var(--navy);

    font-size:25px;
    line-height:1.1;
    letter-spacing:.3px;
}

.brand h1::after{
    content:"";
    display:block;

    width:55px;
    height:2px;

    margin:10px auto 8px;

    background:var(--gold);
}

.brand p{
    margin:0 0 22px;

    color:var(--muted);

    font-size:12px;
}

/* erreur */

.error{
    background:#fff1f1;
    border:1px solid #efcccc;
    color:#a52323;

    border-radius:10px;

    padding:10px 12px;
    margin-bottom:12px;

    font-size:12px;
}

/* champs */

label{
    display:block;

    margin:12px 0 6px;

    font-size:12px;
    font-weight:800;

    color:var(--navy);
}

input{
    width:100%;
    height:46px;

    border:1px solid var(--line);
    border-radius:11px;

    padding:0 13px;

    background:#fff;

    color:var(--text);

    font-size:13px;

    outline:none;

    transition:.2s;
}

input:focus{
    border-color:var(--blue);

    box-shadow:
        0 0 0 3px rgba(22,120,232,.10);
}

/* bouton */

button{
    width:100%;
    height:46px;

    margin-top:19px;

    border:0;
    border-radius:11px;

    background:
        linear-gradient(
            135deg,
            #1678e8,
            #0c62c7
        );

    color:#fff;

    font-size:13px;
    font-weight:800;

    cursor:pointer;

    box-shadow:
        0 8px 18px rgba(22,120,232,.22);
}

button:active{
    transform:translateY(1px);
}

/* =========================================================
   MOBILE
   ========================================================= */

@media(max-width:500px){

    body{
        padding:12px;
    }

    .login{
        width:100%;
        padding:22px 18px;
        border-radius:19px;
    }

    .logo{
        width:55px;
        height:55px;
        margin-bottom:9px;
    }

    .brand h1{
        font-size:21px;
    }

    .brand p{
        font-size:11px;
        margin-bottom:17px;
    }

    label{
        font-size:11px;
        margin-top:10px;
    }

    input{
        height:43px;
        font-size:12px;
    }

    button{
        height:43px;
        font-size:12px;
        margin-top:16px;
    }
}

</style>

</head>

<body>

<div class="login">

    <div class="brand">

        <img
            class="logo"
            src="/assets/logo.png"
            alt="LAMBEMAH"
            onerror="this.style.display='none'"
        >

        <h1>LAMBEMAH GESTION</h1>

        <p>
            Connexion à votre espace
        </p>

    </div>


    <?php if($loginError): ?>

        <div class="error">
            <?= h($loginError) ?>
        </div>

    <?php endif; ?>


    <form method="post" autocomplete="off">

        <label>
            Nom d’utilisateur
        </label>

        <input
            type="text"
            name="username"
            autocomplete="username"
            required
            autofocus
        >


        <label>
            Mot de passe
        </label>

        <input
            type="password"
            name="password"
            autocomplete="current-password"
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


/* =========================================================
   INDICATEURS
   ========================================================= */

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


/* =========================================================
   PRESTATIONS DTF
   ========================================================= */

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


/* =========================================================
   RECETTES MANUELLES
   ========================================================= */

$recettesManuelles = 0;

$q = $conn->query("
    SELECT
        COALESCE(SUM(montant),0) total
    FROM recettes
    WHERE libelle NOT LIKE 'Prestation DTF%'
");

if($q){

    $recettesManuelles =
        (float)$q->fetch_assoc()['total'];
}


/* =========================================================
   DÉPENSES
   ========================================================= */

$depenses = 0;

$q = $conn->query("
    SELECT
        COALESCE(SUM(montant),0) total
    FROM depenses
");

if($q){

    $depenses =
        (float)$q->fetch_assoc()['total'];
}


/* =========================================================
   COÛT DES ARTICLES VENDUS
   ========================================================= */

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


/* =========================================================
   TOTAL
   ========================================================= */

$caTotal =
    $caVentes
    +
    $caPrestations
    +
    $recettesManuelles;


/* =========================================================
   BÉNÉFICE ESTIMÉ
   ========================================================= */

$benefice =
    $caTotal
    -
    $cogs
    -
    $depenses;


/* =========================================================
   STOCK
   ========================================================= */

$stockQte = 0;
$stockValeur = 0;
$faible = 0;
$rupture = 0;

$q = $conn->query("
    SELECT
        stock,
        prix_achat
    FROM produits
");

if($q){

    while($r = $q->fetch_assoc()){

        $s = (int)$r['stock'];

        $stockQte += $s;

        $stockValeur +=
            $s * (float)$r['prix_achat'];

        if($s <= 0){

            $rupture++;

        } elseif($s <= 5){

            $faible++;
        }
    }
}


/* =========================================================
   DERNIÈRES VENTES
   ========================================================= */

$recentSales = [];

$q = $conn->query("
    SELECT
        v.id,
        v.quantite,
        v.montant,
        v.date_vente,
        p.nom
    FROM ventes v
    LEFT JOIN produits p
        ON p.id = v.produit_id
    ORDER BY v.id DESC
    LIMIT 5
");

if($q){

    while($r = $q->fetch_assoc()){

        $recentSales[] = $r;
    }
}


/* =========================================================
   DERNIÈRES PRESTATIONS
   ========================================================= */

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


/* =========================================================
   UTILISATEUR
   ========================================================= */

$userName =
    $_SESSION['nom']
    ?? $_SESSION['username']
    ?? 'Utilisateur';

$role =
    $_SESSION['role']
    ?? 'admin';

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
    Tableau de bord — LAMBEMAH GESTION
</title>

<style>

/* =========================================================
   TABLEAU DE BORD
   ========================================================= */

:root{

    --navy:#071f38;
    --navy2:#0d3558;

    --blue:#1678e8;
    --blueSoft:#edf5ff;

    --gold:#d9aa3f;

    --green:#0aa36f;
    --orange:#e5a226;
    --red:#dc4d55;

    --text:#172b40;
    --muted:#748296;

    --line:#dce6ef;

    --bg:#f4f8fc;
}

*{
    box-sizing:border-box;
}

html,
body{
    margin:0;

    font-family:
        Inter,
        Arial,
        sans-serif;

    background:var(--bg);
    color:var(--text);
}

a{
    text-decoration:none;
    color:inherit;
}


/* =========================================================
   STRUCTURE
   ========================================================= */

.app{
    min-height:100vh;
    display:flex;
}


/* =========================================================
   SIDEBAR
   ========================================================= */

.side{

    width:205px;

    position:fixed;

    inset:0 auto 0 0;

    background:
        linear-gradient(
            180deg,
            #061d35,
            #0a2e4c
        );

    color:#fff;

    padding:17px 10px;

    overflow:auto;

    box-shadow:
        5px 0 25px rgba(0,0,0,.08);

    z-index:10;
}

.brand{

    display:flex;

    align-items:center;

    gap:9px;

    padding:
        3px 7px
        17px;

    border-bottom:
        1px solid
        rgba(255,255,255,.10);
}

.brand img{

    width:37px;
    height:37px;

    object-fit:contain;
}

.brand b{

    display:block;

    font-size:15px;

    letter-spacing:.3px;
}

.brand small{

    display:block;

    color:#b8cee2;

    font-size:9px;

    margin-top:2px;
}

.nav{
    padding-top:10px;
}

.nav a{

    display:flex;

    align-items:center;

    gap:9px;

    padding:
        9px 10px;

    margin:3px 0;

    border-radius:9px;

    color:#dce8f3;

    font-size:11px;

    transition:.18s;
}

.nav a:hover,
.nav a.active{

    background:var(--blue);

    color:#fff;
}

.logout{

    background:
        rgba(255,255,255,.06);

    margin-top:9px!important;
}

.userBox{

    margin-top:16px;

    padding:9px;

    border-radius:9px;

    background:
        rgba(255,255,255,.07);

    color:#c8d9e8;

    font-size:9px;

    line-height:1.5;
}


/* =========================================================
   CONTENU
   ========================================================= */

.main{

    margin-left:205px;

    width:calc(100% - 205px);

    min-width:0;

    padding:
        20px
        22px
        28px;
}


/* =========================================================
   EN-TÊTE
   ========================================================= */

.top{

    display:flex;

    align-items:flex-start;

    justify-content:space-between;

    gap:12px;

    margin-bottom:15px;
}

.top h1{

    margin:0;

    color:var(--navy);

    font-size:20px;
}

.top p{

    margin:4px 0 0;

    color:var(--muted);

    font-size:10px;
}

.topUser{

    background:#fff;

    border:1px solid var(--line);

    padding:7px 9px;

    border-radius:8px;

    color:#5a6c7f;

    font-size:9px;
}


/* =========================================================
   GRANDES CARTES
   ========================================================= */

.kpis{

    display:grid;

    grid-template-columns:
        repeat(4,1fr);

    gap:9px;
}

.kpi{

    background:#fff;

    border:1px solid var(--line);

    border-radius:12px;

    padding:
        12px
        13px;

    min-height:86px;

    box-shadow:
        0 5px 18px
        rgba(17,48,75,.035);
}

.kpi .t{

    color:var(--muted);

    font-size:10px;
}

.kpi .v{

    margin-top:6px;

    color:#193650;

    font-size:16px;

    font-weight:800;
}

.kpi.blue{
    border-left:4px solid var(--blue);
}

.kpi.green{
    border-left:4px solid var(--green);
}

.kpi.orange{
    border-left:4px solid var(--orange);
}

.kpi.red{
    border-left:4px solid var(--red);
}


/* =========================================================
   INFORMATIONS RAPIDES
   ========================================================= */

.smallgrid{

    display:grid;

    grid-template-columns:
        repeat(4,1fr);

    gap:7px;

    margin-top:8px;
}

.pill{

    background:#fff;

    border:1px solid var(--line);

    border-radius:9px;

    padding:8px 9px;

    font-size:9px;

    color:#5d6d7e;
}

.pill b{

    color:#193650;

    font-size:11px;

    margin-left:4px;
}


/* =========================================================
   PANELS
   ========================================================= */

.panel{

    background:#fff;

    border:1px solid var(--line);

    border-radius:12px;

    padding:12px;

    box-shadow:
        0 5px 18px
        rgba(17,48,75,.035);
}

.panelHead{

    display:flex;

    align-items:center;

    justify-content:space-between;

    gap:8px;

    margin-bottom:8px;
}

.panel h2{

    margin:0;

    color:var(--navy);

    font-size:13px;
}

.linkBtn{

    background:var(--blueSoft);

    color:#31536f;

    border-radius:7px;

    padding:6px 8px;

    font-size:9px;

    font-weight:700;
}


/* =========================================================
   ACCÈS RAPIDE
   ========================================================= */

.quick{

    display:grid;

    grid-template-columns:
        repeat(4,1fr);

    gap:7px;
}

.quick a{

    background:
        linear-gradient(
            135deg,
            #f7fbff,
            #edf5fc
        );

    border:1px solid #dce8f2;

    border-radius:9px;

    padding:9px 5px;

    text-align:center;

    color:#234663;

    font-size:9px;

    font-weight:800;

    transition:.15s;
}

.quick a:hover{

    border-color:var(--blue);

    transform:translateY(-1px);
}

.quick span{

    display:block;

    font-size:16px;

    margin-bottom:3px;
}


/* =========================================================
   TABLEAUX
   ========================================================= */

.contentGrid{

    display:grid;

    grid-template-columns:
        1fr 1fr;

    gap:9px;

    margin-top:9px;
}

.tablewrap{

    overflow:auto;
}

.table{

    width:100%;

    border-collapse:collapse;

    font-size:9px;
}

.table th,
.table td{

    padding:
        7px 6px;

    border-bottom:
        1px solid
        #edf1f5;

    text-align:left;

    white-space:nowrap;
}

.table th{

    background:#f7fafc;

    color:#758494;

    font-size:8px;

    text-transform:uppercase;
}

.amount{

    text-align:right!important;

    font-weight:800;

    color:#1b3a55;
}

.empty{

    padding:13px!important;

    text-align:center!important;

    color:#8a98a5;
}


/* =========================================================
   TABLETTE
   ========================================================= */

@media(max-width:1050px){

    .side{
        width:180px;
    }

    .main{

        margin-left:180px;

        width:
            calc(100% - 180px);

        padding:17px;
    }

    .kpis{

        grid-template-columns:
            repeat(2,1fr);
    }

    .smallgrid{

        grid-template-columns:
            repeat(2,1fr);
    }

    .quick{

        grid-template-columns:
            repeat(2,1fr);
    }
}


/* =========================================================
   TÉLÉPHONE
   ========================================================= */

@media(max-width:700px){

    .side{

        width:54px;

        padding:
            9px 4px;
    }

    .brand{

        justify-content:center;

        padding:
            5px 2px
            12px;
    }

    .brand img{

        width:34px;
        height:34px;
    }

    .brand div{

        display:none;
    }

    .nav a{

        justify-content:center;

        padding:
            9px 3px;

        font-size:16px;
    }

    .nav a span,
    .userBox{

        display:none;
    }

    .main{

        margin-left:54px;

        width:
            calc(100% - 54px);

        padding:
            9px;
    }

    .top{

        margin-bottom:8px;
    }

    .top h1{

        font-size:16px;
    }

    .top p{

        font-size:8px;

        margin-top:3px;
    }

    .topUser{

        display:none;
    }

    .kpis{

        grid-template-columns:
            1fr 1fr;

        gap:5px;
    }

    .kpi{

        min-height:68px;

        padding:
            8px 8px;

        border-radius:9px;
    }

    .kpi .t{

        font-size:8px;
    }

    .kpi .v{

        font-size:11px;

        margin-top:5px;
    }

    .smallgrid{

        grid-template-columns:
            1fr 1fr;

        gap:5px;

        margin-top:5px;
    }

    .pill{

        padding:
            6px 6px;

        font-size:7.5px;

        border-radius:8px;
    }

    .pill b{

        font-size:9px;
    }

    .panel{

        padding:8px;

        border-radius:9px;
    }

    .panel h2{

        font-size:11px;
    }

    .linkBtn{

        font-size:8px;

        padding:
            5px 6px;
    }

    .quick{

        grid-template-columns:
            1fr 1fr;

        gap:5px;
    }

    .quick a{

        padding:
            7px 3px;

        font-size:8px;

        border-radius:8px;
    }

    .quick span{

        font-size:14px;

        margin-bottom:2px;
    }

    .contentGrid{

        grid-template-columns:
            1fr;

        gap:7px;

        margin-top:7px;
    }

    .table{

        font-size:8px;
    }

    .table th,
    .table td{

        padding:
            5px 4px;
    }

    .table th{

        font-size:7px;
    }
}


/* =========================================================
   TRÈS PETITS TÉLÉPHONES
   ========================================================= */

@media(max-width:380px){

    .side{

        width:49px;
    }

    .main{

        margin-left:49px;

        width:
            calc(100% - 49px);

        padding:7px;
    }

    .kpi .v{

        font-size:10px;
    }

    .pill{

        font-size:7px;
    }

    .pill b{

        font-size:8px;
    }

    .quick a{

        font-size:7.5px;
    }
}

</style>

</head>


<body>

<div class="app">


<!-- =====================================================
     MENU
     ===================================================== -->

<aside class="side">

    <div class="brand">

        <img
            src="/assets/logo.png"
            alt="LAMBEMAH"
            onerror="this.style.display='none'"
        >

        <div>

            <b>LAMBEMAH</b>

            <small>
                GESTION • PRESTATION
            </small>

        </div>

    </div>


    <nav class="nav">

        <a
            class="active"
            href="index.php"
        >
            🏠
            <span>
                Tableau de bord
            </span>
        </a>


        <a href="produits.php">

            📦

            <span>
                Achats / Produits
            </span>

        </a>


        <a href="ventes.php">

            💰

            <span>
                Ventes / Clients
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


        <a
            class="logout"
            href="?logout=1"
        >

            🚪

            <span>
                Déconnexion
            </span>

        </a>

    </nav>


    <div class="userBox">

        Connecté :

        <b>
            <?= h($userName) ?>
        </b>

        <br>

        <?= h($role) ?>

    </div>

</aside>


<!-- =====================================================
     CONTENU
     ===================================================== -->

<main class="main">


    <div class="top">

        <div>

            <h1>
                📊 Tableau de bord
            </h1>

            <p>
                Vue rapide de l’activité de LAMBEMAH GESTION.
            </p>

        </div>


        <div class="topUser">

            👤
            <?= h($userName) ?>

            ·

            <?= h($role) ?>

        </div>

    </div>


    <!-- =================================================
         INDICATEURS
         ================================================= -->

    <div class="kpis">


        <div class="kpi blue">

            <div class="t">
                Chiffre d’affaires total
            </div>

            <div class="v">
                <?= money($caTotal) ?>
            </div>

        </div>


        <div class="kpi green">

            <div class="t">
                Ventes
            </div>

            <div class="v">
                <?= money($caVentes) ?>
            </div>

        </div>


        <div class="kpi orange">

            <div class="t">
                Prestations DTF
            </div>

            <div class="v">
                <?= money($caPrestations) ?>
            </div>

        </div>


        <div class="kpi red">

            <div class="t">
                Dépenses
            </div>

            <div class="v">
                <?= money($depenses) ?>
            </div>

        </div>

    </div>


    <!-- =================================================
         INFORMATIONS
         ================================================= -->

    <div class="smallgrid">


        <div class="pill">

            💼 Bénéfice estimé

            <b>
                <?= money($benefice) ?>
            </b>

        </div>


        <div class="pill">

            📦 Stock

            <b>
                <?= number_format(
                    $stockQte,
                    0,
                    ',',
                    ' '
                ) ?>
            </b>

        </div>


        <div class="pill">

            💰 Valeur stock

            <b>
                <?= money($stockValeur) ?>
            </b>

        </div>


        <div class="pill">

            ⚠️ Faible

            <b>
                <?= $faible ?>
            </b>

            ·

            ⛔ Ruptures

            <b>
                <?= $rupture ?>
            </b>

        </div>

    </div>


    <!-- =================================================
         ACCÈS RAPIDE
         ================================================= -->

    <section
        class="panel"
        style="margin-top:9px"
    >

        <div class="panelHead">

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


            <a href="produits.php?nouvel_achat=1">

                <span>
                    📦
                </span>

                Nouvel achat

            </a>


            <a href="statistiques.php">

                <span>
                    📊
                </span>

                Statistiques

            </a>


        </div>

    </section>


    <!-- =================================================
         DERNIÈRES OPÉRATIONS
         ================================================= -->

    <div class="contentGrid">


        <!-- VENTES -->

        <section class="panel">

            <div class="panelHead">

                <h2>
                    🧾 Dernières ventes
                </h2>

                <a
                    class="linkBtn"
                    href="ventes.php"
                >
                    Tout voir
                </a>

            </div>


            <div class="tablewrap">

                <table class="table">

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


                    <?php if($recentSales): ?>

                        <?php foreach(
                            $recentSales
                            as $r
                        ): ?>

                            <tr>

                                <td>
                                    <?= h(
                                        $r['nom']
                                        ?? 'Article'
                                    ) ?>
                                </td>

                                <td>
                                    <?= h(
                                        $r['quantite']
                                    ) ?>
                                </td>

                                <td class="amount">

                                    <?= money(
                                        $r['montant']
                                    ) ?>

                                </td>

                                <td>

                                    <?= h(
                                        substr(
                                            (string)$r['date_vente'],
                                            0,
                                            10
                                        )
                                    ) ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>

                            <td
                                class="empty"
                                colspan="4"
                            >
                                Aucune vente.
                            </td>

                        </tr>

                    <?php endif; ?>


                    </tbody>

                </table>

            </div>

        </section>


        <!-- PRESTATIONS -->

        <section class="panel">

            <div class="panelHead">

                <h2>
                    🖨️ Dernières prestations
                </h2>

                <a
                    class="linkBtn"
                    href="prestations.php"
                >
                    Tout voir
                </a>

            </div>


            <div class="tablewrap">

                <table class="table">

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


                    <?php if($recentPrestations): ?>

                        <?php foreach(
                            $recentPrestations
                            as $r
                        ): ?>

                            <?php

                            $client =
                                preg_replace(
                                    '/^Prestation DTF\s*-\s*/i',
                                    '',
                                    $r['libelle']
                                );

                            ?>

                            <tr>

                                <td>
                                    <?= h($client) ?>
                                </td>

                                <td class="amount">

                                    <?= money(
                                        $r['montant']
                                    ) ?>

                                </td>

                                <td>

                                    <?= h(
                                        substr(
                                            (string)$r['date_recette'],
                                            0,
                                            10
                                        )
                                    ) ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>

                            <td
                                class="empty"
                                colspan="3"
                            >
                                Aucune prestation.
                            </td>

                        </tr>

                    <?php endif; ?>


                    </tbody>

                </table>

            </div>

        </section>


    </div>


</main>

</div>

</body>

</html>
