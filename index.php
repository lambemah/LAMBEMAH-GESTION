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
            $message = "Erreur lors de la connexion.";
        }
    }
}

/* =========================
   VALEURS PAR DÉFAUT
========================= */

$total_produits = 0;
$total_stock = 0;
$total_ventes = 0;
$total_recettes = 0;
$total_depenses = 0;

$ventes_jour = 0;
$recettes_jour = 0;
$depenses_jour = 0;


/* =========================
   PRODUITS
========================= */

$result = $conn->query(
    "SELECT COUNT(*) AS nombre,
            COALESCE(SUM(stock),0) AS stock
     FROM produits"
);

if ($result) {
    $data = $result->fetch_assoc();

    $total_produits = (int)$data["nombre"];
    $total_stock = (int)$data["stock"];
}


/* =========================
   VENTES
   IMPORTANT : ta colonne est
   'montant' et non 'total'
========================= */

$result = $conn->query(
    "SELECT COALESCE(SUM(montant),0) AS total
     FROM ventes"
);

if ($result) {
    $total_ventes = (float)$result->fetch_assoc()["total"];
}


/* =========================
   RECETTES
========================= */

$result = $conn->query(
    "SELECT COALESCE(SUM(montant),0) AS total
     FROM recettes"
);

if ($result) {
    $total_recettes = (float)$result->fetch_assoc()["total"];
}


/* =========================
   DEPENSES
========================= */

$result = $conn->query(
    "SELECT COALESCE(SUM(montant),0) AS total
     FROM depenses"
);

if ($result) {
    $total_depenses = (float)$result->fetch_assoc()["total"];
}


/* =========================
   TOTALS
========================= */

$total_entrees = $total_ventes + $total_recettes;

$benefice = $total_entrees - $total_depenses;


/* =========================
   ACTIVITÉ DU JOUR
========================= */

$result = $conn->query(
    "SELECT COALESCE(SUM(montant),0) AS total
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
   FORMAT MONNAIE
========================= */

function formatFG($montant)
{
    return number_format(
        (float)$montant,
        0,
        ",",
        " "
    ) . " FG";
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

/* =========================
   BASE
========================= */

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

:root {

    --bleu-nuit: #071a35;

    --bleu: #168cff;

    --bleu-clair: #eaf6ff;

    --bleu-tres-clair: #f5faff;

    --blanc: #ffffff;

    --texte: #172033;

    --gris: #738096;

    --bordure: #e3edf6;

    --vert: #0a9f63;

    --rouge: #e04d5b;

    --orange: #e99722;
}

body {

    font-family:
        Arial,
        Helvetica,
        sans-serif;

    background: #f3f7fb;

    color: var(--texte);

    min-height: 100vh;
}


/* =========================
   LOGIN
========================= */

.login-page {

    min-height: 100vh;

    display: flex;

    align-items: center;

    justify-content: center;

    padding: 20px;

    background:
        radial-gradient(
            circle at 15% 10%,
            #7bd1ff 0,
            transparent 28%
        ),
        linear-gradient(
            135deg,
            #06162f,
            #0b5998
        );
}

.login-box {

    width: 100%;

    max-width: 430px;

    padding: 34px 28px;

    background: rgba(255,255,255,.98);

    border-radius: 28px;

    box-shadow:
        0 25px 70px rgba(0,0,0,.25);
}

.brand {

    text-align: center;

    margin-bottom: 30px;
}

.brand-logo {

    width: 76px;

    height: 76px;

    margin: 0 auto 15px;

    display: flex;

    align-items: center;

    justify-content: center;

    border-radius: 24px;

    background:
        linear-gradient(
            135deg,
            #168cff,
            #071a35
        );

    color: white;

    font-size: 32px;

    box-shadow:
        0 12px 30px rgba(22,140,255,.3);
}

.brand h1 {

    font-size: 27px;

    color: var(--bleu-nuit);

    margin-bottom: 7px;
}

.brand p {

    color: var(--gris);

    font-size: 14px;
}

.login-box h2 {

    font-size: 21px;

    margin-bottom: 20px;

    color: var(--bleu-nuit);
}

.form-group {

    margin-bottom: 17px;
}

.form-group label {

    display: block;

    margin-bottom: 7px;

    font-size: 14px;

    font-weight: bold;
}

.form-group input {

    width: 100%;

    padding: 15px;

    border: 1px solid #d9e5ef;

    border-radius: 13px;

    outline: none;

    font-size: 15px;

    transition: .2s;
}

.form-group input:focus {

    border-color: var(--bleu);

    box-shadow:
        0 0 0 4px rgba(22,140,255,.1);
}

.login-button {

    width: 100%;

    border: none;

    padding: 15px;

    border-radius: 13px;

    background:
        linear-gradient(
            135deg,
            #168cff,
            #07569c
        );

    color: white;

    font-size: 16px;

    font-weight: bold;

    cursor: pointer;

    box-shadow:
        0 10px 25px rgba(22,140,255,.25);
}

.error {

    background: #fff0f1;

    color: #c52e3a;

    padding: 13px;

    border-radius: 12px;

    margin-bottom: 18px;

    text-align: center;

    font-size: 14px;
}


/* =========================
   APPLICATION
========================= */

.app {

    min-height: 100vh;

    display: flex;
}


/* =========================
   SIDEBAR
========================= */

.sidebar {

    position: fixed;

    left: 0;

    top: 0;

    bottom: 0;

    width: 260px;

    padding: 22px 15px;

    background:
        linear-gradient(
            180deg,
            #06172f,
            #092f58 55%,
            #0a609f
        );

    color: white;

    overflow-y: auto;

    z-index: 100;
}

.sidebar-brand {

    padding: 8px 12px 23px;

    margin-bottom: 18px;

    border-bottom:
        1px solid rgba(255,255,255,.1);
}

.sidebar-brand h1 {

    font-size: 21px;

    margin-bottom: 5px;
}

.sidebar-brand p {

    font-size: 11px;

    opacity: .65;
}

.user-card {

    display: flex;

    align-items: center;

    gap: 11px;

    padding: 12px;

    margin-bottom: 20px;

    border-radius: 15px;

    background:
        rgba(255,255,255,.08);
}

.avatar {

    width: 42px;

    height: 42px;

    flex-shrink: 0;

    display: flex;

    align-items: center;

    justify-content: center;

    border-radius: 13px;

    background: #168cff;

    font-weight: bold;
}

.user-info strong {

    display: block;

    font-size: 13px;
}

.user-info span {

    display: block;

    margin-top: 3px;

    font-size: 11px;

    opacity: .65;
}

.menu-title {

    padding: 0 12px 8px;

    color: rgba(255,255,255,.45);

    font-size: 10px;

    font-weight: bold;

    letter-spacing: 1px;

    text-transform: uppercase;
}

.menu a {

    display: flex;

    align-items: center;

    gap: 12px;

    padding: 12px 13px;

    margin-bottom: 4px;

    border-radius: 12px;

    color: rgba(255,255,255,.82);

    text-decoration: none;

    font-size: 14px;

    font-weight: 600;

    transition: .2s;
}

.menu a:hover,
.menu a.active {

    color: white;

    background:
        rgba(255,255,255,.14);
}

.menu-icon {

    width: 29px;

    height: 29px;

    display: flex;

    align-items: center;

    justify-content: center;

    border-radius: 8px;

    background:
        rgba(255,255,255,.08);
}

.logout-link {

    margin-top: 20px !important;

    color: #ffb8bf !important;
}


/* =========================
   CONTENU
========================= */

.main {

    width: calc(100% - 260px);

    min-height: 100vh;

    margin-left: 260px;

    padding: 25px;
}

.topbar {

    display: flex;

    align-items: center;

    justify-content: space-between;

    margin-bottom: 24px;
}

.topbar h2 {

    font-size: 25px;

    color: var(--bleu-nuit);
}

.topbar p {

    margin-top: 4px;

    color: var(--gris);

    font-size: 13px;
}

.mobile-menu {

    display: none;
}


/* =========================
   HERO
========================= */

.hero {

    position: relative;

    overflow: hidden;

    padding: 28px;

    margin-bottom: 20px;

    border-radius: 23px;

    color: white;

    background:
        linear-gradient(
            120deg,
            #06172f,
            #0b5998
        );

    box-shadow:
        0 15px 35px rgba(7,26,53,.16);
}

.hero h1 {

    position: relative;

    z-index: 2;

    font-size: 24px;

    margin-bottom: 7px;
}

.hero p {

    position: relative;

    z-index: 2;

    font-size: 14px;

    opacity: .8;
}

.hero-date {

    display: inline-block;

    margin-top: 18px;

    padding: 8px 12px;

    border-radius: 10px;

    background:
        rgba(255,255,255,.1);

    font-size: 12px;
}


/* =========================
   CARTES
========================= */

.cards {

    display: grid;

    grid-template-columns:
        repeat(4,1fr);

    gap: 15px;

    margin-bottom: 20px;
}

.card {

    padding: 18px;

    border-radius: 18px;

    background: white;

    border: 1px solid var(--bordure);

    box-shadow:
        0 7px 25px rgba(0,0,0,.04);
}

.card-top {

    display: flex;

    align-items: center;

    justify-content: space-between;

    margin-bottom: 15px;
}

.card small {

    color: var(--gris);

    font-weight: 600;
}

.card h3 {

    color: var(--bleu-nuit);

    font-size: 20px;
}

.card-icon {

    width: 43px;

    height: 43px;

    display: flex;

    align-items: center;

    justify-content: center;

    border-radius: 13px;

    background: var(--bleu-clair);

    font-size: 20px;
}

.card.green .card-icon {

    background: #e8f9f1;
}

.card.red .card-icon {

    background: #fff0f1;
}

.card.orange .card-icon {

    background: #fff7e7;
}


/* =========================
   PANELS
========================= */

.dashboard-grid {

    display: grid;

    grid-template-columns:
        1.4fr .8fr;

    gap: 18px;
}

.panel {

    padding: 20px;

    border-radius: 19px;

    background: white;

    border: 1px solid var(--bordure);

    box-shadow:
        0 7px 25px rgba(0,0,0,.04);
}

.panel-header {

    display: flex;

    align-items: center;

    justify-content: space-between;

    margin-bottom: 18px;
}

.panel-header h3 {

    color: var(--bleu-nuit);

    font-size: 17px;
}

.panel-header a {

    color: var(--bleu);

    text-decoration: none;

    font-size: 12px;

    font-weight: bold;
}


/* =========================
   ACTIVITÉ
========================= */

.today {

    display: grid;

    grid-template-columns:
        repeat(3,1fr);

    gap: 10px;
}

.today-item {

    padding: 15px;

    border-radius: 14px;

    background: var(--bleu-tres-clair);
}

.today-item small {

    display: block;

    color: var(--gris);

    margin-bottom: 7px;
}

.today-item strong {

    color: var(--bleu-nuit);

    font-size: 17px;
}


/* =========================
   RESULTAT
========================= */

.result-box {

    padding: 20px;

    border-radius: 17px;

    color: white;

    background:
        linear-gradient(
            135deg,
            #168cff,
            #06172f
        );
}

.result-box small {

    opacity: .75;
}

.result-box strong {

    display: block;

    margin-top: 7px;

    font-size: 27px;
}


/* =========================
   RACCOURCIS
========================= */

.shortcuts {

    display: grid;

    grid-template-columns:
        repeat(2,1fr);

    gap: 10px;
}

.shortcut {

    padding: 15px;

    border-radius: 14px;

    color: var(--texte);

    text-decoration: none;

    background: #f5f9fd;

    border: 1px solid #e7eff7;

    transition: .2s;
}

.shortcut:hover {

    background: var(--bleu-clair);

    border-color: #cce6ff;
}

.shortcut-icon {

    margin-bottom: 8px;

    font-size: 22px;
}

.shortcut strong {

    display: block;

    font-size: 13px;
}

.shortcut span {

    display: block;

    margin-top: 3px;

    color: var(--gris);

    font-size: 11px;
}


/* =========================
   RESPONSIVE
========================= */

@media(max-width:950px) {

    .sidebar {

        width: 225px;
    }

    .main {

        width: calc(100% - 225px);

        margin-left: 225px;
    }

    .cards {

        grid-template-columns:
            repeat(2,1fr);
    }

    .dashboard-grid {

        grid-template-columns: 1fr;
    }
}


@media(max-width:700px) {

    .sidebar {

        display: none;
    }

    .main {

        width: 100%;

        margin-left: 0;

        padding: 13px;

        padding-bottom: 90px;
    }

    .topbar {

        margin-bottom: 15px;
    }

    .topbar h2 {

        font-size: 20px;
    }

    .mobile-menu {

        display: flex;

        width: 42px;

        height: 42px;

        align-items: center;

        justify-content: center;

        border: 1px solid var(--bordure);

        border-radius: 12px;

        background: white;

        color: var(--bleu-nuit);

        font-size: 20px;
    }

    .hero {

        padding: 22px;

        border-radius: 20px;
    }

    .hero h1 {

        font-size: 20px;
    }

    .cards {

        grid-template-columns:
            repeat(2,1fr);

        gap: 10px;
    }

    .card {

        padding: 14px;

        border-radius: 16px;
    }

    .card h3 {

        font-size: 16px;
    }

    .card-icon {

        width: 38px;

        height: 38px;

        font-size: 17px;
    }

    .today {

        grid-template-columns: 1fr;
    }

    .shortcuts {

        grid-template-columns:
            repeat(2,1fr);
    }
}


/* =========================
   NAVIGATION MOBILE
========================= */

.bottom-nav {

    display: none;
}

@media(max-width:700px) {

    .bottom-nav {

        position: fixed;

        left: 10px;

        right: 10px;

        bottom: 10px;

        z-index: 999;

        height: 65px;

        display: flex;

        align-items: center;

        justify-content: space-around;

        border-radius: 19px;

        background:
            rgba(6,23,47,.97);

        box-shadow:
            0 10px 35px rgba(0,0,0,.2);
    }

    .bottom-nav a {

        display: flex;

        flex-direction: column;

        align-items: center;

        gap: 4px;

        color: rgba(255,255,255,.65);

        text-decoration: none;

        font-size: 10px;
    }

    .bottom-nav a.active {

        color: white;
    }

    .bottom-nav span {

        font-size: 19px;
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

            <h1>
                LAMBEMAH GESTION
            </h1>

            <p>
                Votre activité, simplement maîtrisée.
            </p>

        </div>


        <h2>
            Bienvenue 👋
        </h2>


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
                type="submit"
                name="connexion"
                class="login-button"
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


<!-- =========================
     SIDEBAR
========================= -->

<aside class="sidebar">


    <div class="sidebar-brand">

        <h1>
            💼 LAMBEMAH
        </h1>

        <p>
            GESTION • PRESTATION • COMMERCE
        </p>

    </div>


    <div class="user-card">

        <div class="avatar">

            <?= strtoupper(
                substr(
                    $_SESSION["nom"],
                    0,
                    1
                )
            ) ?>

        </div>


        <div class="user-info">

            <strong>

                <?= htmlspecialchars(
                    $_SESSION["nom"]
                ) ?>

            </strong>

            <span>

                <?= htmlspecialchars(
                    $_SESSION["role"]
                ) ?>

            </span>

        </div>

    </div>


    <div class="menu-title">
        Menu principal
    </div>


    <nav class="menu">


        <a
            href="index.php"
            class="active"
        >

            <span class="menu-icon">
                ⌂
            </span>

            Tableau de bord

        </a>


        <a href="produits.php">

            <span class="menu-icon">
                📦
            </span>

            Produits

        </a>


        <a href="ventes.php">

            <span class="menu-icon">
                🛒
            </span>

            Ventes

        </a>


        <a href="prestations.php">

            <span class="menu-icon">
                👕
            </span>

            Prestations DTF

        </a>


        <a href="recettes.php">

            <span class="menu-icon">
                💵
            </span>

            Recettes

        </a>


        <a href="depenses.php">

            <span class="menu-icon">
                💸
            </span>

            Dépenses

        </a>


        <a href="statistiques.php">

            <span class="menu-icon">
                📊
            </span>

            Statistiques

        </a>


        <a href="utilisateurs.php">

            <span class="menu-icon">
                👥
            </span>

            Utilisateurs

        </a>


        <a
            href="?logout=1"
            class="logout-link"
        >

            <span class="menu-icon">
                ↪
            </span>

            Déconnexion

        </a>


    </nav>

</aside>


<!-- =========================
     CONTENU
========================= -->

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
            onclick="alert('La navigation se trouve en bas de l’écran.')"
        >

            ☰

        </button>

    </div>


    <!-- HERO -->

    <section class="hero">

        <h1>

            Bonjour
            <?= htmlspecialchars(
                $_SESSION["nom"]
            ) ?>
            👋

        </h1>


        <p>
            Voici l'état actuel de votre entreprise.
        </p>


        <div class="hero-date">

            📅 <?= date("d/m/Y") ?>

        </div>

    </section>


    <!-- =========================
         CARTES PRINCIPALES
    ========================= -->

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
                <?= formatFG($total_entrees) ?>
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
                <?= formatFG($total_depenses) ?>
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


    <!-- =========================
         BLOCS
    ========================= -->

    <section class="dashboard-grid">


        <!-- ACTIVITÉ DU JOUR -->

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
                        <?= formatFG($ventes_jour) ?>
                    </strong>

                </div>


                <div class="today-item">

                    <small>
                        Recettes
                    </small>

                    <strong>
                        <?= formatFG($recettes_jour) ?>
                    </strong>

                </div>


                <div class="today-item">

                    <small>
                        Dépenses
                    </small>

                    <strong>
                        <?= formatFG($depenses_jour) ?>
                    </strong>

                </div>


            </div>


            <div style="margin-top:12px">

                <div class="result-box">

                    <small>
                        Résultat du jour
                    </small>

                    <strong>
                        <?= formatFG($resultat_jour) ?>
                    </strong>

                </div>

            </div>

        </div>


        <!-- ACTIONS -->

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


    <!-- =========================
         SITUATION GÉNÉRALE
    ========================= -->

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
                    <?= formatFG($total_ventes) ?>
                </strong>

            </div>


            <div class="today-item">

                <small>
                    Total recettes
                </small>

                <strong>
                    <?= formatFG($total_recettes) ?>
                </strong>

            </div>


            <div class="today-item">

                <small>
                    Résultat estimé
                </small>

                <strong>
                    <?= formatFG($benefice) ?>
                </strong>

            </div>


        </div>

    </section>


</main>


<!-- =========================
     NAVIGATION MOBILE
========================= -->

<nav class="bottom-nav">


    <a
        href="index.php"
        class="active"
    >

        <span>
            ⌂
        </span>

        Accueil

    </a>


    <a href="produits.php">

        <span>
            📦
        </span>

        Produits

    </a>


    <a href="ventes.php">

        <span>
            🛒
        </span>

        Ventes

    </a>


    <a href="prestations.php">

        <span>
            👕
        </span>

        DTF

    </a>


    <a href="statistiques.php">

        <span>
            📊
        </span>

        Stats

    </a>


</nav>


</div>


<?php endif; ?>


</body>

</html>
