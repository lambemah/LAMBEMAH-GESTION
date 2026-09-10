
<style>
.login-logo img{object-fit:contain!important;visibility:visible!important;opacity:1!important;}
</style>

<?php
session_start();
require_once __DIR__ . '/config.php';

/* =========================================================
   DECONNEXION
   ========================================================= */

if (isset($_GET['logout'])) {

    $_SESSION = [];

    if (ini_get("session.use_cookies")) {

        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    session_destroy();

    header("Location: index.php");
    exit;
}


/* =========================================================
   FONCTIONS
   ========================================================= */

function h($value)
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}


function money($value)
{
    return number_format(
        (float)$value,
        0,
        ',',
        ' '
    ) . ' FG';
}


/* =========================================================
   VERIFICATION DE CONNEXION
   ========================================================= */

$connecte =
    isset($_SESSION['user_id']) ||
    isset($_SESSION['utilisateur_id']) ||
    isset($_SESSION['id_utilisateur']);


/* =========================================================
   TRAITEMENT DE LA CONNEXION
   ========================================================= */

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim(
        $_POST['username'] ?? ''
    );

    $password = (string)(
        $_POST['password'] ?? ''
    );


    if ($username === '' || $password === '') {

        $error =
            "Veuillez remplir tous les champs.";

    } else {

        $stmt = $conn->prepare("
            SELECT
                id,
                nom,
                username,
                mot_de_passe,
                role
            FROM utilisateurs
            WHERE username = ?
            LIMIT 1
        ");


        if ($stmt) {

            $stmt->bind_param(
                "s",
                $username
            );

            $stmt->execute();

            $result =
                $stmt->get_result();

            $user =
                $result
                ? $result->fetch_assoc()
                : null;

            $stmt->close();


            if ($user) {

                $motDePasseStocke =
                    (string)$user['mot_de_passe'];


                /*
                 * Compatible avec :
                 * - mot de passe hashé
                 * - ancien mot de passe en clair
                 */

                $valide =
                    password_verify(
                        $password,
                        $motDePasseStocke
                    );


                if (!$valide) {

                    $valide =
                        hash_equals(
                            $motDePasseStocke,
                            $password
                        );
                }


                if ($valide) {

                    session_regenerate_id(true);


                    $_SESSION['user_id'] =
                        (int)$user['id'];

                    $_SESSION['utilisateur_id'] =
                        (int)$user['id'];

                    $_SESSION['id_utilisateur'] =
                        (int)$user['id'];

                    $_SESSION['nom'] =
                        $user['nom'];

                    $_SESSION['username'] =
                        $user['username'];

                    $_SESSION['role'] =
                        $user['role'];


                    header(
                        "Location: index.php"
                    );

                    exit;
                }
            }


            $error =
                "Nom d'utilisateur ou mot de passe incorrect.";

        } else {

            $error =
                "Impossible de se connecter à la base de données.";
        }
    }
}


/* =========================================================
   SI PAS CONNECTE : PAGE DE CONNEXION
   ========================================================= */

$connecte =
    isset($_SESSION['user_id']) ||
    isset($_SESSION['utilisateur_id']) ||
    isset($_SESSION['id_utilisateur']);


if (!$connecte) {
?>

<!DOCTYPE html>

<html lang="fr">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>
    Connexion - LAMBEMAH GESTION
</title>


<style>

* {
    box-sizing: border-box;
}


body {

    margin: 0;

    min-height: 100vh;

    display: flex;

    align-items: center;

    justify-content: center;

    background: #eef4fa;

    font-family:
        Arial,
        Helvetica,
        sans-serif;

    color: #172033;
}


.login-box {

    width: 390px;

    max-width: 92%;

    background: #ffffff;

    border-radius: 18px;

    padding: 32px;

    border: 1px solid #dfe7f0;

    box-shadow:
        0 15px 40px
        rgba(7, 26, 53, 0.12);
}


.logo {

    text-align: center;

    margin-bottom: 25px;
}


.logo h1 {

    margin: 0;

    color: #071a35;

    font-size: 25px;
}


.logo p {

    margin-top: 7px;

    color: #718096;

    font-size: 10px;
}


.error {

    background: #fff0f0;

    color: #c0392b;

    border: 1px solid #f2cccc;

    border-radius: 9px;

    padding: 11px;

    margin-bottom: 15px;

    font-size: 10px;
}


label {

    display: block;

    margin-bottom: 7px;

    font-size: 10px;

    font-weight: bold;
}


input {

    width: 100%;

    padding: 13px;

    margin-bottom: 16px;

    border: 1px solid #d5deea;

    border-radius: 9px;

    outline: none;

    font-size: 10px;
}


input:focus {

    border-color: #1479e8;
}


button {

    width: 100%;

    padding: 13px;

    border: none;

    border-radius: 9px;

    background: #1479e8;

    color: white;

    font-size: 10px;

    font-weight: bold;

    cursor: pointer;
}


button:hover {

    background: #0d68cf;
}

</style>

</head>


<body>


<div class="login-box">
<div class="login-logo" style="text-align:center;margin:0 auto 12px;">
    <img src="assets/logo.png" alt="LAMBEMAH GESTION"
         style="width:170px;max-width:70%;height:auto;display:block;margin:0 auto;"
         onerror="this.style.display='none';">
</div>



    <div class="logo">

        <h1>
            LAMBEMAH GESTION
        </h1>

        <p>
            Connexion à votre espace
        </p>

    </div>


    <?php if ($error !== ''): ?>

        <div class="error">

            <?= h($error) ?>

        </div>

    <?php endif; ?>


    <form
        method="post"
        autocomplete="off"
    >


        <label>
            Nom d'utilisateur
        </label>


        <input
            type="text"
            name="username"
            autocomplete="username"
            required
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


        <button type="submit">
            Se connecter
        </button>


    </form>


</div>


</body>

</html>

<?php
exit;
}


/* =========================================================
   UTILISATEUR CONNECTE
   ========================================================= */

$userName =
    $_SESSION['nom']
    ?? $_SESSION['username']
    ?? 'Utilisateur';


$role =
    $_SESSION['role']
    ?? 'Utilisateur';


/* =========================================================
   VENTES
   ========================================================= */

$caVentes = 0;

$nbVentes = 0;


$q = $conn->query("
    SELECT
        COUNT(*) AS nombre,
        COALESCE(SUM(montant),0) AS total
    FROM ventes
");


if ($q) {

    $r = $q->fetch_assoc();

    $nbVentes =
        (int)$r['nombre'];

    $caVentes =
        (float)$r['total'];
}


/* =========================================================
   PRESTATIONS
   ========================================================= */

$caPrestations = 0;

$nbPrestations = 0;


$q = $conn->query("
    SELECT
        COUNT(*) AS nombre,
        COALESCE(SUM(montant),0) AS total
    FROM recettes
    WHERE libelle LIKE 'Prestation DTF%'
");


if ($q) {

    $r = $q->fetch_assoc();

    $nbPrestations =
        (int)$r['nombre'];

    $caPrestations =
        (float)$r['total'];
}


/* =========================================================
   RECETTES MANUELLES
   ========================================================= */

$recettesManuelles = 0;


$q = $conn->query("
    SELECT
        COALESCE(SUM(montant),0) AS total
    FROM recettes
    WHERE libelle NOT LIKE 'Prestation DTF%'
");


if ($q) {

    $recettesManuelles =
        (float)$q->fetch_assoc()['total'];
}


/* =========================================================
   DEPENSES
   ========================================================= */

$depenses = 0;


$q = $conn->query("
    SELECT
        COALESCE(SUM(montant),0) AS total
    FROM depenses
");


if ($q) {

    $depenses =
        (float)$q->fetch_assoc()['total'];
}


/* =========================================================
   CHIFFRE D'AFFAIRES TOTAL
   ========================================================= */

$caTotal =
    $caVentes
    +
    $caPrestations
    +
    $recettesManuelles;


/*
 * Pour le tableau de bord simple :
 * bénéfice = recettes - dépenses.
 *
 * Les statistiques détaillées pourront
 * calculer le coût des marchandises séparément.
 */

$benefice =
    $caTotal
    -
    $depenses;


/* =========================================================
   STOCK
   ========================================================= */

$stockQte = 0;

$stockValeur = 0;

$rupture = 0;

$faible = 0;

$produitsCount = 0;


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


if ($q) {

    while ($r = $q->fetch_assoc()) {

        $produitsCount++;


        $quantiteStock =
            (int)$r['stock'];


        $prixAchat =
            (float)$r['prix_achat'];


        $stockQte +=
            $quantiteStock;


        $stockValeur +=
            $quantiteStock
            *
            $prixAchat;


        if ($quantiteStock <= 0) {

            $rupture++;

        } elseif ($quantiteStock <= 5) {

            $faible++;
        }
    }
}


/* =========================================================
   DERNIERES VENTES
   ========================================================= */

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


if ($q) {

    while ($r = $q->fetch_assoc()) {

        $recentSales[] = $r;
    }
}


/* =========================================================
   DERNIERES PRESTATIONS
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


if ($q) {

    while ($r = $q->fetch_assoc()) {

        $recentPrestations[] = $r;
    }
}


/* =========================================================
   PRODUITS LES PLUS VENDUS
   ========================================================= */

$topProducts = [];


$q = $conn->query("
    SELECT
        p.nom,
        SUM(v.quantite) AS qte,
        SUM(v.montant) AS ca
    FROM ventes v
    LEFT JOIN produits p
        ON p.id = v.produit_id
    GROUP BY
        v.produit_id,
        p.nom
    ORDER BY qte DESC
    LIMIT 5
");


if ($q) {

    while ($r = $q->fetch_assoc()) {

        $topProducts[] = $r;
    }
}

?>

<!DOCTYPE html>

<html lang="fr">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>
    Tableau de bord - LAMBEMAH GESTION
</title>


<style>

/* =========================================================
   GENERAL
   ========================================================= */

* {
    box-sizing: border-box;
}


body {

    margin: 0;

    font-family:
        Arial,
        Helvetica,
        sans-serif;

    background: #f4f7fb;

    color: #172033;
}


a {

    text-decoration: none;

    color: inherit;
}


/* =========================================================
   STRUCTURE
   ========================================================= */

.layout {

    display: flex;

    min-height: 100vh;
}


/* =========================================================
   SIDEBAR
   ========================================================= */

.sidebar {

    position: fixed;

    top: 0;

    left: 0;

    bottom: 0;

    width: 245px;

    background: #071a35;

    color: white;

    padding: 20px 13px;

    overflow-y: auto;
}


.brand {

    padding:
        10px
        12px
        23px;

    border-bottom:
        1px solid
        rgba(255,255,255,.10);

    margin-bottom: 15px;
}


.brand strong {

    display: block;

    font-size: 10px;

    line-height: 1.2;
}


.brand small {

    display: block;

    color: #9eb8da;

    margin-top: 7px;

    font-size: 10px;
}


.menu a {

    display: flex;

    align-items: center;

    gap: 11px;

    padding: 12px 13px;

    margin: 5px 0;

    border-radius: 10px;

    color: #dbe7f8;

    font-size: 11px;

    transition: .2s;
}


.menu a:hover {

    background: #0e62b9;

    color: white;
}


.menu a.active {

    background: #1479e8;

    color: white;
}


/* =========================================================
   MAIN
   ========================================================= */

.main {

    margin-left: 245px;

    width:
        calc(100% - 245px);

    padding: 25px;
}


/* =========================================================
   HEADER
   ========================================================= */

.header {

    display: flex;

    justify-content: space-between;

    align-items: center;

    margin-bottom: 20px;
}


.header h1 {

    margin: 0;

    font-size: 10px;
}


.header p {

    margin:
        7px
        0
        0;

    color: #68758a;

    font-size: 11px;
}


.user {

    background: white;

    border:
        1px solid
        #dfe6ef;

    border-radius: 10px;

    padding: 10px 14px;

    font-size: 10px;
}


/* =========================================================
   CARTES PRINCIPALES
   ========================================================= */

.cards {

    display: grid;

    grid-template-columns:
        repeat(4, 1fr);

    gap: 15px;
}


.card {

    background: white;

    border:
        1px solid
        #e1e8f1;

    border-radius: 14px;

    padding: 18px;

    box-shadow:
        0 4px 15px
        rgba(20,45,80,.04);
}


.card small {

    display: block;

    color: #68758a;

    font-size: 10px;
}


.card strong {

    display: block;

    margin-top: 8px;

    font-size: 11px;
}


.blue {

    border-left:
        5px solid
        #1479e8;
}


.green {

    border-left:
        5px solid
        #17a673;
}


.orange {

    border-left:
        5px solid
        #e89b21;
}


.red {

    border-left:
        5px solid
        #dc4b4b;
}


/* =========================================================
   INFORMATIONS
   ========================================================= */

.infos {

    display: flex;

    flex-wrap: wrap;

    gap: 10px;

    margin:
        16px
        0;
}


.info {

    background: white;

    border:
        1px solid
        #e0e7ef;

    border-radius: 10px;

    padding:
        10px
        13px;

    font-size: 10px;
}


.info strong {

    margin-left: 4px;
}


/* =========================================================
   ACCES RAPIDE
   ========================================================= */

.quick {

    background: white;

    border:
        1px solid
        #e1e8f1;

    border-radius: 14px;

    padding: 18px;

    margin-bottom: 18px;
}


.quick h2 {

    margin:
        0
        0
        12px;

    font-size: 11px;
}


.quick-links {

    display: grid;

    grid-template-columns:
        repeat(4, 1fr);

    gap: 10px;
}


.quick-links a {

    display: block;

    background: #1479e8;

    color: white;

    padding: 14px;

    border-radius: 9px;

    text-align: center;

    font-size: 10px;

    font-weight: bold;
}


.quick-links a:hover {

    background: #0d68cf;
}


/* =========================================================
   SECTIONS
   ========================================================= */

.sections {

    display: grid;

    grid-template-columns:
        1.25fr 1fr;

    gap: 16px;

    margin-bottom: 16px;
}


.section-title {

    display: flex;

    justify-content: space-between;

    align-items: center;

    margin-bottom: 12px;
}


.section-title h2 {

    margin: 0;

    font-size: 11px;
}


.btn {

    display: inline-block;

    padding:
        8px
        11px;

    border-radius: 8px;

    font-size: 11px;

    background: #eef2f7;

    color: #34445b;
}


.btn:hover {

    background: #e2e8f0;
}


/* =========================================================
   TABLEAUX
   ========================================================= */

.table-wrap {

    width: 100%;

    overflow-x: auto;
}


table {

    width: 100%;

    border-collapse: collapse;

    font-size: 10px;
}


th {

    background: #f2f6fb;

    color: #4c5b70;

    font-weight: bold;

    text-align: left;
}


th,
td {

    padding:
        10px
        8px;

    border-bottom:
        1px solid
        #e8edf3;

    white-space: nowrap;
}


.money {

    text-align: right;

    font-weight: bold;
}


/* =========================================================
   NOTE
   ========================================================= */

.note {

    margin-top: 10px;

    color: #758196;

    font-size: 11px;

    line-height: 1.5;
}


/* =========================================================
   RESPONSIVE TABLETTE
   ========================================================= */

@media (max-width: 1000px) {

    .cards {

        grid-template-columns:
            repeat(2, 1fr);
    }


    .sections {

        grid-template-columns: 1fr;
    }


    .quick-links {

        grid-template-columns:
            repeat(2, 1fr);
    }
}


/* =========================================================
   RESPONSIVE TELEPHONE
   ========================================================= */

@media (max-width: 700px) {

    .sidebar {

        width: 68px;

        padding:
            14px
            7px;
    }


    .brand {

        text-align: center;

        padding:
            8px
            4px
            18px;
    }


    .brand strong {

        font-size: 0;
    }


    .brand strong::after {

        content: "LTK";

        font-size: 10px;
    }


    .brand small {

        display: none;
    }


    .menu a {

        justify-content: center;

        padding:
            12px
            5px;

        font-size: 10px;
    }


    .menu a span {

        display: none;
    }


    .main {

        margin-left: 68px;

        width:
            calc(100% - 68px);

        padding: 14px;
    }


    .header {

        align-items:
            flex-start;
    }


    .header h1 {

        font-size: 10px;
    }


    .header p {

        font-size: 10px;
    }


    .user {

        display: none;
    }


    .cards {

        grid-template-columns:
            repeat(2, 1fr);

        gap: 9px;
    }


    .card {

        padding: 13px;
    }


    .card strong {

        font-size: 10px;
    }


    .card small {

        font-size: 11px;
    }


    .quick {

        padding: 13px;
    }


    .quick-links {

        grid-template-columns:
            repeat(2, 1fr);

        gap: 8px;
    }


    .quick-links a {

        padding: 11px 5px;

        font-size: 11px;
    }


    .sections {

        grid-template-columns: 1fr;
    }


    table {

        font-size: 11px;
    }

}


/* =========================================================
   PETITS TELEPHONES
   ========================================================= */

@media (max-width: 420px) {

    .cards {

        grid-template-columns: 1fr;
    }


    .quick-links {

        grid-template-columns:
            repeat(2, 1fr);
    }

}

</style>

</head>


<body>


<div class="layout">


<!-- =====================================================
     MENU
     ===================================================== -->

<aside class="sidebar">


    <div class="brand">

        <strong>
            LAMBEMAH GESTION
        </strong>

        <small>
            Gestion simple & professionnelle
        </small>

    </div>


    <nav class="menu">


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


        <a href="index.php?logout=1">

            🚪

            <span>
                Déconnexion
            </span>

        </a>


    </nav>


</aside>


<!-- =====================================================
     CONTENU PRINCIPAL
     ===================================================== -->

<main class="main">


    <div class="header">


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

            <?= h($userName) ?>

            ·

            <?= h($role) ?>

        </div>


    </div>


    <!-- =================================================
         CARTES
         ================================================= -->

    <div class="cards">


        <div class="card blue">

            <small>
                Chiffre d'affaires total
            </small>

            <strong>
                <?= money($caTotal) ?>
            </strong>

        </div>


        <div class="card green">

            <small>
                Ventes
            </small>

            <strong>
                <?= money($caVentes) ?>
            </strong>

        </div>


        <div class="card orange">

            <small>
                Prestations DTF
            </small>

            <strong>
                <?= money($caPrestations) ?>
            </strong>

        </div>


        <div class="card red">

            <small>
                Dépenses
            </small>

            <strong>
                <?= money($depenses) ?>
            </strong>

        </div>


    </div>


    <!-- =================================================
         INFORMATIONS
         ================================================= -->

    <div class="infos">


        <div class="info">

            💼 Bénéfice

            <strong>
                <?= money($benefice) ?>
            </strong>

        </div>


        <div class="info">

            📦 Stock

            <strong>
                <?= number_format(
                    $stockQte,
                    0,
                    ',',
                    ' '
                ) ?>
            </strong>

        </div>


        <div class="info">

            💰 Valeur stock

            <strong>
                <?= money($stockValeur) ?>
            </strong>

        </div>


        <div class="info">

            ⚠️ Stock faible

            <strong>
                <?= $faible ?>
            </strong>

        </div>


        <div class="info">

            ⛔ Ruptures

            <strong>
                <?= $rupture ?>
            </strong>

        </div>


    </div>


    <!-- =================================================
         ACCES RAPIDE
         ================================================= -->

    <section class="quick">


        <h2>
            ⚡ Accès rapide
        </h2>


        <div class="quick-links">


            <a href="ventes.php">

                💰

                Nouvelle vente

            </a>


            <a href="prestations.php">

                🖨️

                Nouvelle prestation

            </a>


            <a href="produits.php">

                📦

                Produits / achats

            </a>


            <a href="statistiques.php">

                📊

                Statistiques

            </a>


        </div>


    </section>


    <!-- =================================================
         DERNIERES OPERATIONS
         ================================================= -->

    <div class="sections">


        <!-- ================= VENTES ================= -->

        <section class="card">


            <div class="section-title">


                <h2>
                    🧾 Dernières ventes
                </h2>


                <a
                    class="btn"
                    href="ventes.php"
                >
                    Tout voir
                </a>


            </div>


            <div class="table-wrap">


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


                    <?php if ($recentSales): ?>


                        <?php foreach (
                            $recentSales
                            as $vente
                        ): ?>


                            <tr>


                                <td>

                                    <?= h(
                                        $vente['nom']
                                        ?? 'Article'
                                    ) ?>

                                </td>


                                <td>

                                    <?= h(
                                        $vente['quantite']
                                    ) ?>

                                </td>


                                <td class="money">

                                    <?= money(
                                        $vente['montant']
                                    ) ?>

                                </td>


                                <td>

                                    <?= h(
                                        substr(
                                            (string)$vente['date_vente'],
                                            0,
                                            16
                                        )
                                    ) ?>

                                </td>


                            </tr>


                        <?php endforeach; ?>


                    <?php else: ?>


                        <tr>

                            <td colspan="4">

                                Aucune vente enregistrée.

                            </td>

                        </tr>


                    <?php endif; ?>


                    </tbody>


                </table>


            </div>


        </section>


        <!-- ================= PRESTATIONS ================= -->

        <section class="card">


            <div class="section-title">


                <h2>
                    🖨️ Dernières prestations
                </h2>


                <a
                    class="btn"
                    href="prestations.php"
                >
                    Tout voir
                </a>


            </div>


            <div class="table-wrap">


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


                    <?php if ($recentPrestations): ?>


                        <?php foreach (
                            $recentPrestations
                            as $prestation
                        ): ?>


                            <?php

                            $client =
                                preg_replace(
                                    '/^Prestation DTF\s*-\s*/i',
                                    '',
                                    $prestation['libelle']
                                );

                            ?>


                            <tr>


                                <td>

                                    <?= h(
                                        $client
                                    ) ?>

                                </td>


                                <td class="money">

                                    <?= money(
                                        $prestation['montant']
                                    ) ?>

                                </td>


                                <td>

                                    <?= h(
                                        substr(
                                            (string)$prestation['date_recette'],
                                            0,
                                            16
                                        )
                                    ) ?>

                                </td>


                            </tr>


                        <?php endforeach; ?>


                    <?php else: ?>


                        <tr>

                            <td colspan="3">

                                Aucune prestation enregistrée.

                            </td>

                        </tr>


                    <?php endif; ?>


                    </tbody>


                </table>


            </div>


        </section>


    </div>


    <!-- =================================================
         PRODUITS LES PLUS VENDUS
         ================================================= -->

    <section class="card">


        <div class="section-title">


            <h2>
                🏆 Produits les plus vendus
            </h2>


            <a
                class="btn"
                href="produits.php"
            >
                Gérer le stock
            </a>


        </div>


        <div class="table-wrap">


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


                <?php if ($topProducts): ?>


                    <?php foreach (
                        $topProducts
                        as $produit
                    ): ?>


                        <tr>


                            <td>

                                <?= h(
                                    $produit['nom']
                                    ?? 'Produit'
                                ) ?>

                            </td>


                            <td>

                                <?= number_format(
                                    (float)$produit['qte'],
                                    0,
                                    ',',
                                    ' '
                                ) ?>

                            </td>


                            <td class="money">

                                <?= money(
                                    $produit['ca']
                                ) ?>

                            </td>


                        </tr>


                    <?php endforeach; ?>


                <?php else: ?>


                    <tr>

                        <td colspan="3">

                            Pas encore de données.

                        </td>

                    </tr>


                <?php endif; ?>


                </tbody>


            </table>


        </div>


        <div class="note">

            Le tableau de bord présente les principaux indicateurs
            de l'activité. Les calculs détaillés du coût des marchandises
            et du bénéfice réel sont disponibles dans Statistiques.

        </div>


    </section>


</main>


</div>


</body>

</html>
