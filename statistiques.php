<?php
session_start();
require_once "config.php";

/* =========================================================
   PROTECTION
========================================================= */

if (!isset($_SESSION["id"])) {
    header("Location: index.php");
    exit;
}

$nom  = $_SESSION["nom"] ?? "Utilisateur";
$role = $_SESSION["role"] ?? "lecture";


/* =========================================================
   FORMAT ARGENT
========================================================= */

function argent($montant)
{
    return number_format(
        (float)$montant,
        0,
        ",",
        " "
    ) . " FG";
}


/* =========================================================
   CHIFFRE D'AFFAIRES DES VENTES
========================================================= */

$total_ventes = 0;

$result = $conn->query("
    SELECT COALESCE(SUM(montant), 0) AS total
    FROM ventes
");

if ($result) {
    $data = $result->fetch_assoc();
    $total_ventes = (float)$data["total"];
}


/* =========================================================
   CHIFFRE D'AFFAIRES DES PRESTATIONS
========================================================= */

$total_prestations = 0;

$result = $conn->query("
    SELECT COALESCE(SUM(montant), 0) AS total
    FROM recettes
    WHERE libelle LIKE '%Prestation DTF%'
");

if ($result) {
    $data = $result->fetch_assoc();
    $total_prestations = (float)$data["total"];
}


/* =========================================================
   RECETTES TOTALES
========================================================= */

$total_recettes = 0;

$result = $conn->query("
    SELECT COALESCE(SUM(montant), 0) AS total
    FROM recettes
");

if ($result) {
    $data = $result->fetch_assoc();
    $total_recettes = (float)$data["total"];
}


/* =========================================================
   DEPENSES TOTALES
========================================================= */

$total_depenses = 0;

$result = $conn->query("
    SELECT COALESCE(SUM(montant), 0) AS total
    FROM depenses
");

if ($result) {
    $data = $result->fetch_assoc();
    $total_depenses = (float)$data["total"];
}


/* =========================================================
   BENEFICE NET
========================================================= */

$benefice_net =
    $total_recettes - $total_depenses;


/* =========================================================
   NOMBRE DE VENTES
========================================================= */

$nombre_ventes = 0;

$result = $conn->query("
    SELECT COUNT(*) AS total
    FROM ventes
");

if ($result) {
    $data = $result->fetch_assoc();
    $nombre_ventes = (int)$data["total"];
}


/* =========================================================
   NOMBRE DE PRESTATIONS
========================================================= */

$nombre_prestations = 0;

$result = $conn->query("
    SELECT COUNT(*) AS total
    FROM recettes
    WHERE libelle LIKE '%Prestation DTF%'
");

if ($result) {
    $data = $result->fetch_assoc();
    $nombre_prestations = (int)$data["total"];
}


/* =========================================================
   PRODUITS
========================================================= */

$total_produits = 0;
$total_articles = 0;
$valeur_stock = 0;

$result = $conn->query("
    SELECT
        COUNT(*) AS produits,
        COALESCE(SUM(stock), 0) AS articles,
        COALESCE(SUM(stock * prix_achat), 0) AS valeur
    FROM produits
");

if ($result) {
    $data = $result->fetch_assoc();

    $total_produits = (int)$data["produits"];
    $total_articles = (int)$data["articles"];
    $valeur_stock = (float)$data["valeur"];
}


/* =========================================================
   DERNIERES VENTES
========================================================= */

$dernieres_ventes = $conn->query("
    SELECT
        v.montant,
        v.quantite,
        v.date_vente,
        p.nom AS produit_nom
    FROM ventes v
    LEFT JOIN produits p
        ON p.id = v.produit_id
    ORDER BY v.id DESC
    LIMIT 5
");


/* =========================================================
   DERNIERES DEPENSES
========================================================= */

$dernieres_depenses = $conn->query("
    SELECT
        libelle,
        montant,
        date_depense
    FROM depenses
    ORDER BY id DESC
    LIMIT 5
");

?>

<!DOCTYPE html>

<html lang="fr">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>Statistiques - LAMBEMAH GESTION</title>


<style>

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {

    font-family: Arial, sans-serif;

    background:
        linear-gradient(
            135deg,
            #eef7ff,
            #f8fbff
        );

    color: #172536;

    min-height: 100vh;
}


/* =========================================================
   SIDEBAR
========================================================= */

.sidebar {

    position: fixed;

    left: 0;
    top: 0;
    bottom: 0;

    width: 250px;

    background:
        linear-gradient(
            180deg,
            #06182c,
            #092b49,
            #073f66
        );

    color: white;

    padding: 24px 15px;

    z-index: 1000;
}

.brand {

    display: flex;

    align-items: center;

    gap: 11px;

    padding: 5px 10px 25px;
}

.brand-icon {

    width: 45px;
    height: 45px;

    border-radius: 14px;

    display: flex;

    align-items: center;
    justify-content: center;

    background:
        linear-gradient(
            135deg,
            #20b8ff,
            #1264c7
        );

    font-size: 22px;
}

.brand h2 {
    font-size: 18px;
}

.brand span {

    font-size: 9px;

    color: #86a9c0;
}

.nav {
    list-style: none;
}

.nav li {
    margin: 4px 0;
}

.nav a {

    display: flex;

    align-items: center;

    gap: 12px;

    padding: 12px 14px;

    border-radius: 12px;

    color: #c5d5e1;

    text-decoration: none;

    font-size: 13px;

    transition: .2s;
}

.nav a:hover,
.nav a.active {

    background:
        rgba(32,184,255,.16);

    color: white;
}

.nav a.active {

    border-left:
        3px solid #20b8ff;
}

.sidebar-bottom {

    position: absolute;

    left: 15px;
    right: 15px;
    bottom: 20px;
}

.profile {

    padding: 12px;

    border-radius: 12px;

    background:
        rgba(255,255,255,.06);

    margin-bottom: 10px;
}

.profile strong {

    display: block;

    font-size: 12px;
}

.profile span {

    color: #8ca8bb;

    font-size: 10px;
}

.logout {

    display: block;

    text-align: center;

    text-decoration: none;

    color: #ffbaba;

    background:
        rgba(255,70,70,.08);

    padding: 10px;

    border-radius: 10px;

    font-size: 11px;
}


/* =========================================================
   MAIN
========================================================= */

.main {

    margin-left: 250px;

    padding: 28px;

    max-width: 1500px;
}


/* =========================================================
   HEADER
========================================================= */

.header {

    display: flex;

    justify-content: space-between;

    align-items: center;

    margin-bottom: 22px;
}

.header h1 {
    font-size: 25px;
}

.header p {

    color: #8998a5;

    font-size: 12px;

    margin-top: 5px;
}

.avatar {

    width: 42px;
    height: 42px;

    border-radius: 13px;

    display: flex;

    align-items: center;
    justify-content: center;

    background:
        linear-gradient(
            135deg,
            #20b8ff,
            #1264c7
        );

    color: white;

    font-weight: bold;
}


/* =========================================================
   STATISTIQUES PRINCIPALES
========================================================= */

.stats {

    display: grid;

    grid-template-columns:
        repeat(4, 1fr);

    gap: 15px;

    margin-bottom: 20px;
}

.stat {

    background: white;

    border-radius: 17px;

    padding: 18px;

    box-shadow:
        0 6px 25px
        rgba(25,55,80,.06);
}

.stat-icon {

    font-size: 20px;

    margin-bottom: 10px;
}

.stat small {

    color: #8c9aa6;

    font-size: 9px;
}

.stat h2 {

    margin-top: 8px;

    font-size: 20px;
}

.blue {
    color: #0878b7;
}

.green {
    color: #159765;
}

.red {
    color: #d65b5b;
}


/* =========================================================
   DEUXIEME LIGNE
========================================================= */

.small-stats {

    display: grid;

    grid-template-columns:
        repeat(3, 1fr);

    gap: 15px;

    margin-bottom: 20px;
}

.small-card {

    background: white;

    border-radius: 17px;

    padding: 18px;

    box-shadow:
        0 6px 25px
        rgba(25,55,80,.06);
}

.small-card small {

    color: #8c9aa6;

    font-size: 9px;
}

.small-card strong {

    display: block;

    margin-top: 8px;

    font-size: 18px;

    color: #20394c;
}


/* =========================================================
   CONTENU
========================================================= */

.content {

    display: grid;

    grid-template-columns:
        1fr 1fr;

    gap: 20px;
}

.card {

    background: white;

    border-radius: 20px;

    padding: 22px;

    box-shadow:
        0 6px 25px
        rgba(25,55,80,.06);
}

.card h2 {

    font-size: 17px;

    margin-bottom: 5px;
}

.card p {

    color: #8998a5;

    font-size: 10px;

    margin-bottom: 18px;
}


/* =========================================================
   LIGNES
========================================================= */

.row {

    display: flex;

    justify-content: space-between;

    align-items: center;

    gap: 15px;

    padding: 12px 0;

    border-bottom:
        1px solid #edf1f4;
}

.row:last-child {
    border-bottom: 0;
}

.row-left strong {

    display: block;

    color: #20394c;

    font-size: 11px;
}

.row-left span {

    display: block;

    color: #9aa6ae;

    font-size: 9px;

    margin-top: 4px;
}

.row-right {

    color: #0878b7;

    font-size: 11px;

    font-weight: bold;

    white-space: nowrap;
}


/* =========================================================
   MOBILE
========================================================= */

@media(max-width:900px) {

    .stats {

        grid-template-columns:
            repeat(2, 1fr);
    }

    .small-stats {

        grid-template-columns:
            1fr 1fr;
    }

    .content {

        grid-template-columns:
            1fr;
    }

}


@media(max-width:700px) {

    .sidebar {

        position: relative;

        width: 100%;

        height: auto;

        padding: 10px;
    }

    .brand {

        padding:
            4px
            7px
            10px;
    }

    .brand-icon {

        width: 39px;
        height: 39px;
    }

    .brand h2 {
        font-size: 15px;
    }

    .nav {

        display: grid;

        grid-template-columns:
            repeat(4, 1fr);

        gap: 4px;
    }

    .nav a {

        flex-direction: column;

        gap: 4px;

        padding: 8px 3px;

        text-align: center;

        font-size: 8px;
    }

    .nav a.active {

        border-left: 0;

        border-bottom:
            2px solid #20b8ff;
    }

    .sidebar-bottom {

        position: static;

        margin-top: 8px;
    }

    .profile {
        display: none;
    }

    .main {

        margin-left: 0;

        padding: 14px;
    }

    .header h1 {
        font-size: 20px;
    }

    .stats {

        grid-template-columns:
            1fr 1fr;

        gap: 9px;
    }

    .stat {

        padding: 14px;
    }

    .stat h2 {
        font-size: 16px;
    }

    .small-stats {

        grid-template-columns:
            1fr 1fr;

        gap: 9px;
    }

    .small-card {

        padding: 14px;
    }

    .small-card strong {
        font-size: 15px;
    }

    .card {

        padding: 15px;

        border-radius: 16px;
    }

}


@media(max-width:390px) {

    .nav a {
        font-size: 7px;
    }

    .stats {

        grid-template-columns:
            1fr 1fr;
    }

}

</style>

</head>


<body>


<!-- =========================================================
     MENU
========================================================= -->

<aside class="sidebar">

    <div class="brand">

        <div class="brand-icon">
            💼
        </div>

        <div>

            <h2>LAMBEMAH</h2>

            <span>
                GESTION • PRESTATION
            </span>

        </div>

    </div>


    <ul class="nav">

        <li>
            <a href="index.php">
                🏠 <span>Accueil</span>
            </a>
        </li>

        <li>
            <a href="produits.php">
                📦 <span>Produits</span>
            </a>
        </li>

        <li>
            <a href="ventes.php">
                💰 <span>Ventes</span>
            </a>
        </li>

        <li>
            <a href="prestations.php">
                🖨️ <span>Prestations</span>
            </a>
        </li>

        <li>
            <a href="recettes.php">
                💵 <span>Recettes</span>
            </a>
        </li>

        <li>
            <a href="depenses.php">
                💸 <span>Dépenses</span>
            </a>
        </li>

        <li>
            <a
                href="statistiques.php"
                class="active"
            >
                📊 <span>Statistiques</span>
            </a>
        </li>

        <?php if ($role === "admin"): ?>

        <li>
            <a href="utilisateurs.php">
                👥 <span>Équipe</span>
            </a>
        </li>

        <?php endif; ?>

    </ul>


    <div class="sidebar-bottom">

        <div class="profile">

            <strong>
                <?= htmlspecialchars($nom) ?>
            </strong>

            <span>
                <?= htmlspecialchars($role) ?>
            </span>

        </div>

        <a
            class="logout"
            href="index.php?logout=1"
        >
            🚪 Déconnexion
        </a>

    </div>

</aside>


<!-- =========================================================
     CONTENU
========================================================= -->

<main class="main">


    <div class="header">

        <div>

            <h1>
                📊 Statistiques
            </h1>

            <p>
                Une vue simple de l'activité de LAMBEMAH GESTION.
            </p>

        </div>


        <div class="avatar">

            <?= strtoupper(
                substr($nom, 0, 1)
            ) ?>

        </div>

    </div>


    <!-- =====================================================
         GRANDES STATISTIQUES
    ====================================================== -->

    <div class="stats">


        <div class="stat">

            <div class="stat-icon">
                💰
            </div>

            <small>
                CHIFFRE D'AFFAIRES
            </small>

            <h2 class="blue">
                <?= argent($total_recettes) ?>
            </h2>

        </div>


        <div class="stat">

            <div class="stat-icon">
                📈
            </div>

            <small>
                BÉNÉFICE NET
            </small>

            <h2 class="green">
                <?= argent($benefice_net) ?>
            </h2>

        </div>


        <div class="stat">

            <div class="stat-icon">
                💸
            </div>

            <small>
                DÉPENSES
            </small>

            <h2 class="red">
                <?= argent($total_depenses) ?>
            </h2>

        </div>


        <div class="stat">

            <div class="stat-icon">
                🖨️
            </div>

            <small>
                PRESTATIONS DTF
            </small>

            <h2 class="blue">
                <?= argent($total_prestations) ?>
            </h2>

        </div>

    </div>


    <!-- =====================================================
         PETITES STATISTIQUES
    ====================================================== -->

    <div class="small-stats">


        <div class="small-card">

            <small>
                VENTES
            </small>

            <strong>
                <?= $nombre_ventes ?>
            </strong>

        </div>


        <div class="small-card">

            <small>
                PRESTATIONS
            </small>

            <strong>
                <?= $nombre_prestations ?>
            </strong>

        </div>


        <div class="small-card">

            <small>
                ARTICLES EN STOCK
            </small>

            <strong>
                <?= $total_articles ?>
            </strong>

        </div>

    </div>


    <!-- =====================================================
         DERNIERES ACTIVITES
    ====================================================== -->

    <div class="content">


        <!-- VENTES -->

        <div class="card">

            <h2>
                💰 Dernières ventes
            </h2>

            <p>
                Les 5 dernières ventes enregistrées.
            </p>


            <?php if (
                $dernieres_ventes &&
                $dernieres_ventes->num_rows > 0
            ): ?>


                <?php while (
                    $v =
                    $dernieres_ventes->fetch_assoc()
                ): ?>

                    <div class="row">

                        <div class="row-left">

                            <strong>

                                <?= htmlspecialchars(
                                    $v["produit_nom"]
                                    ?? "Produit supprimé"
                                ) ?>

                            </strong>

                            <span>

                                Quantité :
                                <?= (int)$v["quantite"] ?>

                                —
                                <?= date(
                                    "d/m/Y",
                                    strtotime(
                                        $v["date_vente"]
                                    )
                                ) ?>

                            </span>

                        </div>


                        <div class="row-right">

                            <?= argent(
                                $v["montant"]
                            ) ?>

                        </div>

                    </div>

                <?php endwhile; ?>


            <?php else: ?>

                <div class="row">

                    <div class="row-left">

                        <strong>
                            Aucune vente
                        </strong>

                        <span>
                            Rien à afficher pour le moment.
                        </span>

                    </div>

                </div>

            <?php endif; ?>

        </div>


        <!-- DEPENSES -->

        <div class="card">

            <h2>
                💸 Dernières dépenses
            </h2>

            <p>
                Les 5 dernières dépenses enregistrées.
            </p>


            <?php if (
                $dernieres_depenses &&
                $dernieres_depenses->num_rows > 0
            ): ?>


                <?php while (
                    $d =
                    $dernieres_depenses->fetch_assoc()
                ): ?>

                    <div class="row">

                        <div class="row-left">

                            <strong>

                                <?= htmlspecialchars(
                                    $d["libelle"]
                                ) ?>

                            </strong>

                            <span>

                                <?= date(
                                    "d/m/Y",
                                    strtotime(
                                        $d["date_depense"]
                                    )
                                ) ?>

                            </span>

                        </div>


                        <div
                            class="row-right"
                            style="color:#d65b5b;"
                        >

                            - <?= argent(
                                $d["montant"]
                            ) ?>

                        </div>

                    </div>

                <?php endwhile; ?>


            <?php else: ?>

                <div class="row">

                    <div class="row-left">

                        <strong>
                            Aucune dépense
                        </strong>

                        <span>
                            Rien à afficher pour le moment.
                        </span>

                    </div>

                </div>

            <?php endif; ?>

        </div>

    </div>


</main>

</body>

</html>
