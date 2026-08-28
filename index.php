<?php
session_start();
require_once "config.php";

$message = "";

/* =========================
   DÉCONNEXION
========================= */
if (isset($_GET["logout"])) {
    session_unset();
    session_destroy();
    header("Location: index.php");
    exit;
}

/* =========================
   CONNEXION
========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["connexion"])) {

    $username = trim($_POST["username"] ?? "");
    $password = trim($_POST["password"] ?? "");

    if ($username === "" || $password === "") {
        $message = "Veuillez remplir tous les champs.";
    } else {

        $stmt = $conn->prepare(
            "SELECT id, nom, username, mot_de_passe, role
             FROM utilisateurs
             WHERE username = ?
             LIMIT 1"
        );

        if ($stmt) {

            $stmt->bind_param("s", $username);
            $stmt->execute();

            $result = $stmt->get_result();

            if ($result && $result->num_rows === 1) {

                $user = $result->fetch_assoc();

                if ($password === $user["mot_de_passe"]) {

                    $_SESSION["id"] = $user["id"];
                    $_SESSION["nom"] = $user["nom"];
                    $_SESSION["username"] = $user["username"];
                    $_SESSION["role"] = $user["role"];

                    header("Location: index.php");
                    exit;

                } else {
                    $message = "Nom d'utilisateur ou mot de passe incorrect.";
                }

            } else {
                $message = "Nom d'utilisateur ou mot de passe incorrect.";
            }

            $stmt->close();

        } else {
            $message = "Impossible de vérifier la connexion.";
        }
    }
}

/* =========================
   STATISTIQUES DU TABLEAU DE BORD
========================= */

$total_produits = 0;
$total_stock = 0;
$total_ventes = 0;
$total_recettes = 0;
$total_depenses = 0;

/* PRODUITS */
$result = $conn->query(
    "SELECT COUNT(*) AS nombre, COALESCE(SUM(stock),0) AS stock
     FROM produits"
);

if ($result) {
    $data = $result->fetch_assoc();
    $total_produits = (int)$data["nombre"];
    $total_stock = (int)$data["stock"];
}

/* VENTES */
$result = $conn->query(
    "SELECT COALESCE(SUM(total),0) AS total
     FROM ventes"
);

if ($result) {
    $total_ventes = (float)$result->fetch_assoc()["total"];
}

/* RECETTES */
$result = $conn->query(
    "SELECT COALESCE(SUM(montant),0) AS total
     FROM recettes"
);

if ($result) {
    $total_recettes = (float)$result->fetch_assoc()["total"];
}

/* DÉPENSES */
$result = $conn->query(
    "SELECT COALESCE(SUM(montant),0) AS total
     FROM depenses"
);

if ($result) {
    $total_depenses = (float)$result->fetch_assoc()["total"];
}

$total_entrees = $total_ventes + $total_recettes;
$benefice = $total_entrees - $total_depenses;


/* =========================
   DONNÉES DU JOUR
========================= */

$ventes_jour = 0;
$recettes_jour = 0;
$depenses_jour = 0;

$result = $conn->query(
    "SELECT COALESCE(SUM(total),0) AS total
     FROM ventes
     WHERE DATE(date_vente) = CURDATE()"
);

if ($result) {
    $ventes_jour = (float)$result->fetch_assoc()["total"];
}

$result = $conn->query(
    "SELECT COALESCE(SUM(montant),0) AS total
     FROM recettes
     WHERE DATE(date_recette) = CURDATE()"
);

if ($result) {
    $recettes_jour = (float)$result->fetch_assoc()["total"];
}

$result = $conn->query(
    "SELECT COALESCE(SUM(montant),0) AS total
     FROM depenses
     WHERE DATE(date_depense) = CURDATE()"
);

if ($result) {
    $depenses_jour = (float)$result->fetch_assoc()["total"];
}

$entrees_jour = $ventes_jour + $recettes_jour;
$resultat_jour = $entrees_jour - $depenses_jour;


/* =========================
   FORMATAGE
========================= */

function fg($montant) {
    return number_format((float)$montant, 0, ",", " ") . " FG";
}

?>

<!DOCTYPE html>
<html lang="fr">

<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width, initial-scale=1.0">

<title>LAMBEMAH GESTION</title>

<style>

*{
    box-sizing:border-box;
    margin:0;
    padding:0;
}

:root{
    --blue-dark:#071a35;
    --blue:#0d6efd;
    --blue-light:#eaf5ff;
    --blue-soft:#f4f9ff;
    --white:#ffffff;
    --text:#172033;
    --muted:#748096;
    --border:#e5edf5;
    --green:#0a9f63;
    --red:#e34d59;
}

body{
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        Roboto,
        Arial,
        sans-serif;

    background:#f4f8fc;
    color:var(--text);
    min-height:100vh;
}


/* =========================
   PAGE DE CONNEXION
========================= */

.login-page{

    min-height:100vh;

    display:flex;
    align-items:center;
    justify-content:center;

    padding:20px;

    background:
        radial-gradient(
            circle at top left,
            #b9e5ff 0,
            transparent 35%
        ),
        linear-gradient(
            135deg,
            #071a35,
            #0b5796
        );
}

.login-box{

    width:100%;
    max-width:430px;

    background:rgba(255,255,255,.98);

    border-radius:28px;

    padding:35px 28px;

    box-shadow:
        0 25px 70px rgba(0,0,0,.25);
}

.brand{

    text-align:center;
    margin-bottom:30px;
}

.brand-logo{

    width:75px;
    height:75px;

    margin:auto;
    margin-bottom:15px;

    border-radius:23px;

    display:flex;
    align-items:center;
    justify-content:center;

    background:
        linear-gradient(
            135deg,
            #0d6efd,
            #071a35
        );

    color:white;

    font-size:31px;

    box-shadow:
        0 12px 30px rgba(13,110,253,.3);
}

.brand h1{

    font-size:27px;
    color:var(--blue-dark);

    margin-bottom:7px;
}

.brand p{

    color:var(--muted);
    font-size:14px;
}

.login-box h2{

    font-size:22px;
    margin-bottom:20px;
    color:var(--blue-dark);
}

.form-group{
    margin-bottom:17px;
}

.form-group label{

    display:block;

    font-size:14px;
    font-weight:700;

    margin-bottom:7px;
}

.form-group input{

    width:100%;

    padding:15px;

    border:1px solid #dce6f0;

    border-radius:13px;

    outline:none;

    font-size:15px;

    transition:.2s;
}

.form-group input:focus{

    border-color:var(--blue);

    box-shadow:
        0 0 0 4px rgba(13,110,253,.1);
}

.login-button{

    width:100%;

    border:0;

    padding:15px;

    border-radius:13px;

    background:
        linear-gradient(
            135deg,
            #0d6efd,
            #0755a0
        );

    color:white;

    font-size:16px;
    font-weight:700;

    cursor:pointer;

    box-shadow:
        0 10px 25px rgba(13,110,253,.25);
}

.login-button:hover{
    transform:translateY(-1px);
}

.error{

    background:#fff0f1;
    color:#c52f3b;

    padding:13px;

    border-radius:12px;

    margin-bottom:18px;

    font-size:14px;

    text-align:center;
}


/* =========================
   APPLICATION
========================= */

.app{

    min-height:100vh;

    display:flex;
}


/* SIDEBAR */

.sidebar{

    width:260px;

    position:fixed;
    left:0;
    top:0;
    bottom:0;

    padding:22px 15px;

    background:
        linear-gradient(
            180deg,
            #06172f 0%,
            #092f58 55%,
            #0b5b99 100%
        );

    color:white;

    z-index:100;

    overflow-y:auto;
}

.sidebar-brand{

    padding:8px 12px 25px;

    border-bottom:
        1px solid rgba(255,255,255,.1);

    margin-bottom:18px;
}

.sidebar-brand h1{

    font-size:21px;
    margin-bottom:5px;
}

.sidebar-brand p{

    font-size:12px;
    opacity:.7;
}

.user-card{

    display:flex;
    align-items:center;
    gap:11px;

    background:rgba(255,255,255,.08);

    padding:12px;

    border-radius:15px;

    margin-bottom:20px;
}

.avatar{

    width:42px;
    height:42px;

    flex-shrink:0;

    border-radius:13px;

    display:flex;
    align-items:center;
    justify-content:center;

    background:#168cff;

    color:white;

    font-weight:bold;
}

.user-info strong{

    display:block;

    font-size:13px;
}

.user-info span{

    display:block;

    font-size:11px;

    opacity:.65;

    margin-top:3px;
}

.menu-title{

    color:rgba(255,255,255,.45);

    font-size:10px;

    font-weight:bold;

    text-transform:uppercase;

    letter-spacing:1px;

    padding:0 12px 8px;
}

.menu a{

    display:flex;

    align-items:center;

    gap:12px;

    padding:12px 13px;

    border-radius:12px;

    color:rgba(255,255,255,.82);

    text-decoration:none;

    font-size:14px;

    font-weight:600;

    margin-bottom:4px;

    transition:.2s;
}

.menu a:hover,
.menu a.active{

    background:rgba(255,255,255,.14);

    color:white;
}

.menu-icon{

    width:28px;
    height:28px;

    display:flex;
    align-items:center;
    justify-content:center;

    background:rgba(255,255,255,.08);

    border-radius:8px;

    font-size:14px;
}

.logout-link{

    margin-top:20px !important;

    color:#ffb5bb !important;
}


/* CONTENU */

.main{

    margin-left:260px;

    width:calc(100% - 260px);

    min-height:100vh;

    padding:25px;
}

.topbar{

    display:flex;

    justify-content:space-between;

    align-items:center;

    margin-bottom:25px;
}

.topbar h2{

    font-size:25px;

    color:var(--blue-dark);
}

.topbar p{

    margin-top:4px;

    color:var(--muted);

    font-size:13px;
}

.mobile-menu{

    display:none;
}


/* HERO */

.hero{

    position:relative;

    overflow:hidden;

    background:
        linear-gradient(
            120deg,
            #071a35,
            #0b5796
        );

    color:white;

    padding:28px;

    border-radius:23px;

    margin-bottom:20px;

    box-shadow:
        0 15px 35px rgba(7,26,53,.16);
}

.hero:after{

    content:"";

    position:absolute;

    width:180px;
    height:180px;

    right:-50px;
    top:-70px;

    border-radius:50%;

    background:rgba(255,255,255,.08);
}

.hero h1{

    font-size:24px;
    margin-bottom:7px;

    position:relative;
    z-index:2;
}

.hero p{

    opacity:.8;

    font-size:14px;

    position:relative;
    z-index:2;
}

.hero-date{

    margin-top:18px;

    display:inline-block;

    padding:8px 12px;

    background:rgba(255,255,255,.1);

    border-radius:10px;

    font-size:12px;
}


/* CARTES */

.cards{

    display:grid;

    grid-template-columns:
        repeat(4,1fr);

    gap:15px;

    margin-bottom:20px;
}

.card{

    background:white;

    border-radius:18px;

    padding:18px;

    border:1px solid var(--border);

    box-shadow:
        0 7px 25px rgba(0,0,0,.04);
}

.card-top{

    display:flex;

    justify-content:space-between;

    align-items:center;

    margin-bottom:15px;
}

.card-icon{

    width:43px;
    height:43px;

    display:flex;
    align-items:center;
    justify-content:center;

    border-radius:13px;

    background:var(--blue-light);

    font-size:20px;
}

.card small{

    color:var(--muted);

    font-weight:600;
}

.card h3{

    margin-top:5px;

    font-size:21px;

    color:var(--blue-dark);
}

.card.green .card-icon{
    background:#e9f9f2;
}

.card.red .card-icon{
    background:#fff0f1;
}

.card.orange .card-icon{
    background:#fff7e8;
}


/* DEUX COLONNES */

.dashboard-grid{

    display:grid;

    grid-template-columns:
        1.4fr .8fr;

    gap:18px;
}

.panel{

    background:white;

    border:1px solid var(--border);

    border-radius:19px;

    padding:20px;

    box-shadow:
        0 7px 25px rgba(0,0,0,.04);
}

.panel-header{

    display:flex;

    align-items:center;

    justify-content:space-between;

    margin-bottom:18px;
}

.panel-header h3{

    font-size:17px;

    color:var(--blue-dark);
}

.panel-header a{

    color:var(--blue);

    font-size:12px;

    font-weight:bold;

    text-decoration:none;
}


/* JOUR */

.today{

    display:grid;

    grid-template-columns:
        repeat(3,1fr);

    gap:10px;
}

.today-item{

    padding:15px;

    border-radius:14px;

    background:var(--blue-soft);
}

.today-item small{

    display:block;

    color:var(--muted);

    margin-bottom:7px;
}

.today-item strong{

    font-size:17px;

    color:var(--blue-dark);
}


/* RESULTAT */

.result-box{

    background:
        linear-gradient(
            135deg,
            #0d6efd,
            #071a35
        );

    color:white;

    padding:20px;

    border-radius:17px;
}

.result-box small{

    opacity:.75;
}

.result-box strong{

    display:block;

    font-size:28px;

    margin-top:7px;
}


/* RACCOURCIS */

.shortcuts{

    display:grid;

    grid-template-columns:
        repeat(2,1fr);

    gap:10px;
}

.shortcut{

    text-decoration:none;

    color:var(--text);

    padding:15px;

    border-radius:14px;

    background:#f5f9fd;

    border:1px solid #e7eff7;

    transition:.2s;
}

.shortcut:hover{

    background:var(--blue-light);

    border-color:#cce6ff;
}

.shortcut-icon{

    font-size:22px;

    margin-bottom:8px;
}

.shortcut strong{

    display:block;

    font-size:13px;
}

.shortcut span{

    display:block;

    color:var(--muted);

    font-size:11px;

    margin-top:3px;
}


/* =========================
   MOBILE
========================= */

@media(max-width:900px){

    .sidebar{

        width:225px;
    }

    .main{

        margin-left:225px;

        width:calc(100% - 225px);
    }

    .cards{

        grid-template-columns:
            repeat(2,1fr);
    }

    .dashboard-grid{

        grid-template-columns:1fr;
    }
}


@media(max-width:700px){

    .sidebar{

        display:none;
    }

    .main{

        margin-left:0;

        width:100%;

        padding:12px;

        padding-bottom:80px;
    }

    .topbar{

        margin-bottom:15px;
    }

    .topbar h2{

        font-size:20px;
    }

    .mobile-menu{

        display:flex;

        width:42px;
        height:42px;

        align-items:center;
        justify-content:center;

        background:white;

        border:1px solid var(--border);

        border-radius:12px;

        color:var(--blue-dark);

        font-size:20px;
    }

    .hero{

        padding:22px;

        border-radius:20px;
    }

    .hero h1{

        font-size:20px;
    }

    .cards{

        grid-template-columns:
            repeat(2,1fr);

        gap:10px;
    }

    .card{

        padding:14px;

        border-radius:16px;
    }

    .card h3{

        font-size:17px;
    }

    .card-icon{

        width:38px;
        height:38px;
    }

    .today{

        grid-template-columns:1fr;
    }

    .shortcuts{

        grid-template-columns:
            repeat(2,1fr);
    }

}


/* BOTTOM NAVIGATION MOBILE */

.bottom-nav{

    display:none;
}

@media(max-width:700px){

    .bottom-nav{

        display:flex;

        position:fixed;

        left:10px;
        right:10px;
        bottom:10px;

        height:65px;

        background:
            rgba(7,26,53,.96);

        backdrop-filter:blur(15px);

        border-radius:19px;

        z-index:999;

        box-shadow:
            0 10px 35px rgba(0,0,0,.2);

        align-items:center;
        justify-content:space-around;
    }

    .bottom-nav a{

        color:rgba(255,255,255,.65);

        text-decoration:none;

        display:flex;

        flex-direction:column;

        align-items:center;

        gap:4px;

        font-size:10px;
    }

    .bottom-nav a:first-child{

        color:white;
    }

    .bottom-nav span{

        font-size:19px;
    }
}

</style>

</head>

<body>


<?php if (!isset($_SESSION["id"])): ?>

<!-- =========================
     CONNEXION
========================= -->

<div class="login-page">

    <div class="login-box">

        <div class="brand">

            <div class="brand-logo">
                💼
            </div>

            <h1>LAMBEMAH GESTION</h1>

            <p>
                Votre activité, simplement maîtrisée.
            </p>

        </div>

        <h2>Bienvenue 👋</h2>

        <?php if ($message !== ""): ?>

            <div class="error">
                <?= htmlspecialchars($message) ?>
            </div>

        <?php endif; ?>

        <form method="POST">

            <div class="form-group">

                <label>
                    Nom d'utilisateur
                </label>

                <input
                    type="text"
                    name="username"
                    placeholder="Votre nom d'utilisateur"
                    autocomplete="username"
                    required
                >

            </div>

            <div class="form-group">

                <label>
                    Mot de passe
                </label>

                <input
                    type="password"
                    name="password"
                    placeholder="Votre mot de passe"
                    autocomplete="current-password"
                    required
                >

            </div>

            <button
                class="login-button"
                type="submit"
                name="connexion"
            >
                Se connecter →
            </button>

        </form>

    </div>

</div>


<?php else: ?>


<!-- =========================
     APPLICATION
========================= -->

<div class="app">


<!-- SIDEBAR -->

<aside class="sidebar">

    <div class="sidebar-brand">

        <h1>💼 LAMBEMAH</h1>

        <p>
            GESTION • PRESTATION • COMMERCE
        </p>

    </div>


    <div class="user-card">

        <div class="avatar">
            <?= strtoupper(substr($_SESSION["nom"],0,1)) ?>
        </div>

        <div class="user-info">

            <strong>
                <?= htmlspecialchars($_SESSION["nom"]) ?>
            </strong>

            <span>
                <?= htmlspecialchars($_SESSION["role"]) ?>
            </span>

        </div>

    </div>


    <div class="menu-title">
        Menu principal
    </div>


    <nav class="menu">

        <a href="index.php" class="active">

            <span class="menu-icon">⌂</span>

            Tableau de bord

        </a>


        <a href="produits.php">

            <span class="menu-icon">📦</span>

            Produits

        </a>


        <a href="ventes.php">

            <span class="menu-icon">🛒</span>

            Ventes

        </a>


        <a href="prestations.php">

            <span class="menu-icon">👕</span>

            Prestations DTF

        </a>


        <a href="recettes.php">

            <span class="menu-icon">💵</span>

            Recettes

        </a>


        <a href="depenses.php">

            <span class="menu-icon">💸</span>

            Dépenses

        </a>


        <a href="statistiques.php">

            <span class="menu-icon">📊</span>

            Statistiques

        </a>


        <a href="utilisateurs.php">

            <span class="menu-icon">👥</span>

            Utilisateurs

        </a>


        <a
            href="?logout=1"
            class="logout-link"
        >

            <span class="menu-icon">↪</span>

            Déconnexion

        </a>

    </nav>

</aside>


<!-- CONTENU -->

<main class="main">


    <div class="topbar">

        <div>

            <h2>
                Tableau de bord
            </h2>

            <p>
                Vue générale de votre activité
            </p>

        </div>

        <button
            class="mobile-menu"
            onclick="alert('Utilisez le menu en bas de l’écran.')"
        >
            ☰
        </button>

    </div>


    <!-- HERO -->

    <section class="hero">

        <h1>
            Bonjour <?= htmlspecialchars($_SESSION["nom"]) ?> 👋
        </h1>

        <p>
            Voici l'état actuel de votre entreprise.
        </p>

        <div class="hero-date">

            📅
            <?= date("d/m/Y") ?>

        </div>

    </section>


    <!-- CARTES -->

    <section class="cards">


        <div class="card">

            <div class="card-top">

                <small>
                    Produits
                </small>

                <div class="card-icon">
                    📦
                </div>

            </div>

            <h3>
                <?= $total_produits ?>
            </h3>

        </div>


        <div class="card green">

            <div class="card-top">

                <small>
                    Entrées
                </small>

                <div class="card-icon">
                    💰
                </div>

            </div>

            <h3>
                <?= fg($total_entrees) ?>
            </h3>

        </div>


        <div class="card red">

            <div class="card-top">

                <small>
                    Dépenses
                </small>

                <div class="card-icon">
                    💸
                </div>

            </div>

            <h3>
                <?= fg($total_depenses) ?>
            </h3>

        </div>


        <div class="card orange">

            <div class="card-top">

                <small>
                    Stock total
                </small>

                <div class="card-icon">
                    📦
                </div>

            </div>

            <h3>
                <?= $total_stock ?>
            </h3>

        </div>


    </section>


    <!-- DASHBOARD GRID -->

    <section class="dashboard-grid">


        <div class="panel">

            <div class="panel-header">

                <h3>
                    📅 Activité du jour
                </h3>

                <a href="statistiques.php">
                    Voir tout →
                </a>

            </div>


            <div class="today">


                <div class="today-item">

                    <small>
                        Ventes
                    </small>

                    <strong>
                        <?= fg($ventes_jour) ?>
                    </strong>

                </div>


                <div class="today-item">

                    <small>
                        Recettes
                    </small>

                    <strong>
                        <?= fg($recettes_jour) ?>
                    </strong>

                </div>


                <div class="today-item">

                    <small>
                        Dépenses
                    </small>

                    <strong>
                        <?= fg($depenses_jour) ?>
                    </strong>

                </div>


            </div>


            <div style="margin-top:12px">

                <div class="result-box">

                    <small>
                        Résultat du jour
                    </small>

                    <strong>
                        <?= fg($resultat_jour) ?>
                    </strong>

                </div>

            </div>

        </div>


        <div class="panel">

            <div class="panel-header">

                <h3>
                    ⚡ Actions rapides
                </h3>

            </div>


            <div class="shortcuts">


                <a
                    href="produits.php"
                    class="shortcut"
                >

                    <div class="shortcut-icon">
                        📦
                    </div>

                    <strong>
                        Produit
                    </strong>

                    <span>
                        Gérer le stock
                    </span>

                </a>


                <a
                    href="ventes.php"
                    class="shortcut"
                >

                    <div class="shortcut-icon">
                        🛒
                    </div>

                    <strong>
                        Vente
                    </strong>

                    <span>
                        Enregistrer une vente
                    </span>

                </a>


                <a
                    href="prestations.php"
                    class="shortcut"
                >

                    <div class="shortcut-icon">
                        👕
                    </div>

                    <strong>
                        DTF
                    </strong>

                    <span>
                        Nouvelle prestation
                    </span>

                </a>


                <a
                    href="depenses.php"
                    class="shortcut"
                >

                    <div class="shortcut-icon">
                        💸
                    </div>

                    <strong>
                        Dépense
                    </strong>

                    <span>
                        Enregistrer une sortie
                    </span>

                </a>


            </div>

        </div>


    </section>


    <!-- SITUATION GÉNÉRALE -->

    <section
        class="panel"
        style="margin-top:18px"
    >

        <div class="panel-header">

            <h3>
                💎 Situation générale
            </h3>

            <a href="statistiques.php">
                Statistiques →
            </a>

        </div>


        <div class="today">


            <div class="today-item">

                <small>
                    Total ventes
                </small>

                <strong>
                    <?= fg($total_ventes) ?>
                </strong>

            </div>


            <div class="today-item">

                <small>
                    Total recettes
                </small>

                <strong>
                    <?= fg($total_recettes) ?>
                </strong>

            </div>


            <div class="today-item">

                <small>
                    Bénéfice estimé
                </small>

                <strong>
                    <?= fg($benefice) ?>
                </strong>

            </div>


        </div>

    </section>


</main>


<!-- NAVIGATION MOBILE -->

<nav class="bottom-nav">

    <a href="index.php">

        <span>⌂</span>

        Accueil

    </a>


    <a href="produits.php">

        <span>📦</span>

        Produits

    </a>


    <a href="ventes.php">

        <span>🛒</span>

        Ventes

    </a>


    <a href="prestations.php">

        <span>👕</span>

        DTF

    </a>


    <a href="statistiques.php">

        <span>📊</span>

        Stats

    </a>

</nav>


</div>

<?php endif; ?>


</body>

</html>
