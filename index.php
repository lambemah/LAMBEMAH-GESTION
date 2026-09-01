<?php
session_start();
require_once "config.php";

/* =========================================================
   DÉCONNEXION
========================================================= */

if (isset($_GET["logout"])) {

    $_SESSION = [];

    if (ini_get("session.use_cookies")) {

        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            "",
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    session_destroy();

    header("Location: connexion.php");
    exit;
}


/* =========================================================
   PROTECTION
========================================================= */

if (!isset($_SESSION["id"])) {
    header("Location: connexion.php");
    exit;
}

$nom  = $_SESSION["nom"] ?? "Utilisateur";
$role = $_SESSION["role"] ?? "lecture";


/* =========================================================
   FONCTION ARGENT
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
   TOTAL VENTES
========================================================= */

$total_ventes = 0;

$result = $conn->query(
    "SELECT COALESCE(SUM(montant),0) AS total
     FROM ventes"
);

if ($result) {

    $data = $result->fetch_assoc();

    $total_ventes = (float)$data["total"];
}


/* =========================================================
   TOTAL RECETTES
========================================================= */

$total_recettes = 0;

$result = $conn->query(
    "SELECT COALESCE(SUM(montant),0) AS total
     FROM recettes"
);

if ($result) {

    $data = $result->fetch_assoc();

    $total_recettes = (float)$data["total"];
}


/* =========================================================
   TOTAL DÉPENSES
========================================================= */

$total_depenses = 0;

$result = $conn->query(
    "SELECT COALESCE(SUM(montant),0) AS total
     FROM depenses"
);

if ($result) {

    $data = $result->fetch_assoc();

    $total_depenses = (float)$data["total"];
}


/* =========================================================
   BÉNÉFICE
========================================================= */

$benefice =
    $total_ventes
    + $total_recettes
    - $total_depenses;


/* =========================================================
   PRODUITS
========================================================= */

$nombre_produits = 0;

$result = $conn->query(
    "SELECT COUNT(*) AS total
     FROM produits"
);

if ($result) {

    $data = $result->fetch_assoc();

    $nombre_produits = (int)$data["total"];
}


/* =========================================================
   STOCK TOTAL
========================================================= */

$stock_total = 0;

$result = $conn->query(
    "SELECT COALESCE(SUM(stock),0) AS total
     FROM produits"
);

if ($result) {

    $data = $result->fetch_assoc();

    $stock_total = (int)$data["total"];
}


/* =========================================================
   NOMBRE DE VENTES
========================================================= */

$nombre_ventes = 0;

$result = $conn->query(
    "SELECT COUNT(*) AS total
     FROM ventes"
);

if ($result) {

    $data = $result->fetch_assoc();

    $nombre_ventes = (int)$data["total"];
}


/* =========================================================
   DERNIÈRES VENTES
========================================================= */

$dernieres_ventes = $conn->query(
    "SELECT
        v.montant,
        v.quantite,
        v.date_vente,
        p.nom AS produit_nom
     FROM ventes v
     LEFT JOIN produits p
        ON p.id = v.produit_id
     ORDER BY v.id DESC
     LIMIT 5"
);


/* =========================================================
   STOCK FAIBLE
========================================================= */

$stock_faible = $conn->query(
    "SELECT
        nom,
        stock
     FROM produits
     WHERE stock <= 5
     ORDER BY stock ASC
     LIMIT 5"
);

?>

<!DOCTYPE html>

<html lang="fr">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>Accueil - LAMBEMAH GESTION</title>

<style>

/* =========================================================
   GLOBAL
========================================================= */

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {

    font-family: Arial, sans-serif;

    background: #f3f7fb;

    color: #172536;
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
            #061a2d,
            #092d4b,
            #07527c
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

    background: rgba(32,184,255,.16);

    color: white;
}

.nav a.active {

    border-left: 3px solid #20b8ff;
}


/* =========================================================
   BAS SIDEBAR
========================================================= */

.sidebar-bottom {

    position: absolute;

    left: 15px;
    right: 15px;

    bottom: 20px;
}

.profile {

    padding: 12px;

    border-radius: 12px;

    background: rgba(255,255,255,.06);

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

    background: rgba(255,70,70,.08);

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

    width: 44px;
    height: 44px;

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
   BIENVENUE
========================================================= */

.welcome {

    background: white;

    border-radius: 18px;

    padding: 20px;

    margin-bottom: 20px;

    box-shadow:
        0 6px 25px
        rgba(25,55,80,.06);
}

.welcome h2 {

    font-size: 18px;

    margin-bottom: 5px;
}

.welcome p {

    color: #8998a5;

    font-size: 11px;
}


/* =========================================================
   CARDS
========================================================= */

.cards {

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

    color: #0878b7;
}

.stat.profit h2 {

    color: #159765;
}

.stat.expense h2 {

    color: #d25b5b;
}


/* =========================================================
   CONTENT
========================================================= */

.content {

    display: grid;

    grid-template-columns:
        1.5fr 1fr;

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

    margin-bottom: 6px;
}

.card > p {

    color: #8998a5;

    font-size: 11px;

    margin-bottom: 18px;
}


/* =========================================================
   VENTES
========================================================= */

.sale {

    display: flex;

    justify-content: space-between;

    align-items: center;

    padding: 13px 0;

    border-bottom:
        1px solid #edf1f4;
}

.sale:last-child {

    border-bottom: none;
}

.sale-name {

    font-size: 12px;

    font-weight: bold;

    color: #20394c;
}

.sale-info {

    color: #9aa6ae;

    font-size: 9px;

    margin-top: 4px;
}

.sale-amount {

    color: #0878b7;

    font-weight: bold;

    font-size: 12px;

    white-space: nowrap;
}


/* =========================================================
   STOCK
========================================================= */

.stock-item {

    display: flex;

    justify-content: space-between;

    align-items: center;

    padding: 13px 0;

    border-bottom:
        1px solid #edf1f4;
}

.stock-item:last-child {

    border-bottom: none;
}

.stock-name {

    font-size: 12px;

    font-weight: bold;
}

.stock-number {

    padding: 5px 9px;

    border-radius: 8px;

    background: #fff1f1;

    color: #d45454;

    font-size: 10px;

    font-weight: bold;
}


/* =========================================================
   RACCOURCIS
========================================================= */

.quick {

    display: grid;

    grid-template-columns:
        repeat(3, 1fr);

    gap: 10px;

    margin-top: 20px;
}

.quick a {

    text-decoration: none;

    background: #f5faff;

    border: 1px solid #e2edf4;

    border-radius: 12px;

    padding: 14px 10px;

    text-align: center;

    color: #26769d;

    font-size: 10px;
}

.quick a:hover {

    background: #eef8ff;
}


/* =========================================================
   MOBILE
========================================================= */

@media(max-width:900px) {

    .cards {

        grid-template-columns:
            repeat(2, 1fr);
    }

    .content {

        grid-template-columns: 1fr;
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

        padding: 4px 7px 10px;
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

    .header p {

        font-size: 10px;
    }

    .welcome {

        padding: 15px;
    }

    .welcome h2 {

        font-size: 16px;
    }

    .cards {

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

    .card {

        padding: 15px;

        border-radius: 16px;
    }

}


@media(max-width:390px) {

    .nav a {

        font-size: 7px;
    }

    .stat h2 {

        font-size: 14px;
    }

}

</style>

</head>


<body>


<!-- =========================================================
     SIDEBAR
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
            <a
                href="index.php"
                class="active"
            >
                🏠
                <span>Accueil</span>
            </a>
        </li>


        <li>
            <a href="produits.php">
                📦
                <span>Produits</span>
            </a>
        </li>


        <li>
            <a href="ventes.php">
                💰
                <span>Ventes</span>
            </a>
        </li>


        <li>
            <a href="prestations.php">
                🖨️
                <span>Prestations</span>
            </a>
        </li>


        <li>
            <a href="recettes.php">
                💵
                <span>Recettes</span>
            </a>
        </li>


        <li>
            <a href="depenses.php">
                💸
                <span>Dépenses</span>
            </a>
        </li>


        <li>
            <a href="statistiques.php">
                📊
                <span>Statistiques</span>
            </a>
        </li>


        <?php if ($role === "admin"): ?>

        <li>
            <a href="utilisateurs.php">
                👥
                <span>Équipe</span>
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
     MAIN
========================================================= -->

<main class="main">


    <div class="header">

        <div>

            <h1>
                🏠 Accueil
            </h1>

            <p>
                Vue générale de ton activité.
            </p>

        </div>


        <div class="avatar">

            <?= strtoupper(
                substr($nom, 0, 1)
            ) ?>

        </div>

    </div>


    <div class="welcome">

        <h2>

            Bienvenue,
            <?= htmlspecialchars($nom) ?>
            👋

        </h2>

        <p>
            Voici un aperçu de ton activité LAMBEMAH GESTION.
        </p>

    </div>


    <!-- =====================================================
         STATISTIQUES
    ====================================================== -->

    <div class="cards">


        <div class="stat">

            <div class="stat-icon">
                💰
            </div>

            <small>
                TOTAL VENTES
            </small>

            <h2>
                <?= argent($total_ventes) ?>
            </h2>

        </div>


        <div class="stat">

            <div class="stat-icon">
                💵
            </div>

            <small>
                RECETTES
            </small>

            <h2>
                <?= argent($total_recettes) ?>
            </h2>

        </div>


        <div class="stat expense">

            <div class="stat-icon">
                💸
            </div>

            <small>
                DÉPENSES
            </small>

            <h2>
                <?= argent($total_depenses) ?>
            </h2>

        </div>


        <div class="stat profit">

            <div class="stat-icon">
                📈
            </div>

            <small>
                BÉNÉFICE
            </small>

            <h2>
                <?= argent($benefice) ?>
            </h2>

        </div>

    </div>


    <!-- =====================================================
         CONTENU
    ====================================================== -->

    <div class="content">


        <!-- DERNIÈRES VENTES -->

        <div class="card">

            <h2>
                💰 Dernières ventes
            </h2>

            <p>
                Les dernières opérations enregistrées.
            </p>


            <?php if (
                $dernieres_ventes &&
                $dernieres_ventes->num_rows > 0
            ): ?>


                <?php while (
                    $vente =
                    $dernieres_ventes->fetch_assoc()
                ): ?>


                    <div class="sale">

                        <div>

                            <div class="sale-name">

                                👕
                                <?= htmlspecialchars(
                                    $vente["produit_nom"]
                                    ?? "Produit supprimé"
                                ) ?>

                            </div>


                            <div class="sale-info">

                                Quantité :
                                <?= (int)$vente["quantite"] ?>

                                •

                                <?= date(
                                    "d/m/Y",
                                    strtotime(
                                        $vente["date_vente"]
                                    )
                                ) ?>

                            </div>

                        </div>


                        <div class="sale-amount">

                            <?= argent(
                                $vente["montant"]
                            ) ?>

                        </div>

                    </div>


                <?php endwhile; ?>


            <?php else: ?>


                <div
                    style="
                    text-align:center;
                    padding:30px;
                    color:#8998a5;
                    font-size:11px;
                    "
                >

                    🛒

                    <br><br>

                    Aucune vente enregistrée.

                </div>


            <?php endif; ?>

        </div>


        <!-- STOCK -->

        <div class="card">

            <h2>
                📦 Stock
            </h2>

            <p>

                <?= $nombre_produits ?>
                produit(s)

                •

                <?= $stock_total ?>
                article(s) en stock.

            </p>


            <?php if (
                $stock_faible &&
                $stock_faible->num_rows > 0
            ): ?>


                <?php while (
                    $stock =
                    $stock_faible->fetch_assoc()
                ): ?>


                    <div class="stock-item">

                        <div class="stock-name">

                            📦
                            <?= htmlspecialchars(
                                $stock["nom"]
                            ) ?>

                        </div>


                        <div class="stock-number">

                            <?= (int)$stock["stock"] ?>

                        </div>

                    </div>


                <?php endwhile; ?>


            <?php else: ?>


                <div
                    style="
                    text-align:center;
                    padding:30px;
                    color:#168653;
                    font-size:11px;
                    "
                >

                    ✅

                    <br><br>

                    Aucun stock faible.

                </div>


            <?php endif; ?>


            <div class="quick">

                <a href="produits.php">

                    📦
                    <br>

                    Produits

                </a>


                <a href="ventes.php">

                    💰
                    <br>

                    Nouvelle vente

                </a>


                <a href="prestations.php">

                    🖨️
                    <br>

                    Prestation

                </a>

            </div>

        </div>

    </div>

</main>


</body>

</html>
