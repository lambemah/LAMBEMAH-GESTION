```php
<?php
session_start();
require_once "config.php";

/*
|--------------------------------------------------------------------------
| PROTECTION
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION["id"])) {

    /*
    | Si personne n'est connecté : afficher la connexion.
    */

    $message = "";

    if ($_SERVER["REQUEST_METHOD"] === "POST") {

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

            $stmt->bind_param("s", $username);
            $stmt->execute();

            $result = $stmt->get_result();

            if ($result->num_rows === 1) {

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

        <title>LAMBEMAH GESTION - Connexion</title>

        <style>

            * {
                box-sizing: border-box;
                margin: 0;
                padding: 0;
            }

            body {
                min-height: 100vh;
                font-family: Arial, sans-serif;
                background:
                    radial-gradient(
                        circle at top right,
                        #174a73,
                        transparent 40%
                    ),
                    linear-gradient(
                        135deg,
                        #061525,
                        #0a2035,
                        #07111f
                    );
                display: flex;
                justify-content: center;
                align-items: center;
                padding: 20px;
                color: white;
            }

            .login-box {
                width: 100%;
                max-width: 430px;
                background: rgba(255,255,255,.08);
                backdrop-filter: blur(18px);
                border: 1px solid rgba(255,255,255,.12);
                border-radius: 25px;
                padding: 32px;
                box-shadow:
                    0 25px 70px rgba(0,0,0,.35);
            }

            .logo {
                text-align: center;
                margin-bottom: 28px;
            }

            .logo-icon {
                width: 75px;
                height: 75px;
                margin: auto;
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: 22px;
                background: linear-gradient(
                    135deg,
                    #21b8ff,
                    #1264c7
                );
                font-size: 35px;
                box-shadow: 0 10px 30px rgba(20,150,230,.35);
            }

            .logo h1 {
                margin-top: 16px;
                font-size: 26px;
            }

            .logo p {
                margin-top: 7px;
                color: #aab9c8;
                font-size: 13px;
            }

            .login-box h2 {
                font-size: 20px;
                margin-bottom: 20px;
            }

            .group {
                margin-bottom: 17px;
            }

            label {
                display: block;
                margin-bottom: 8px;
                color: #c8d5e0;
                font-size: 13px;
                font-weight: bold;
            }

            input {
                width: 100%;
                padding: 14px;
                border: 1px solid rgba(255,255,255,.15);
                background: rgba(0,0,0,.18);
                color: white;
                border-radius: 12px;
                outline: none;
                font-size: 14px;
            }

            input:focus {
                border-color: #21b8ff;
            }

            button {
                width: 100%;
                padding: 14px;
                border: none;
                border-radius: 12px;
                background: linear-gradient(
                    135deg,
                    #20b8ff,
                    #1264c7
                );
                color: white;
                font-size: 15px;
                font-weight: bold;
                cursor: pointer;
            }

            .error {
                background: rgba(255,60,60,.12);
                border: 1px solid rgba(255,80,80,.2);
                color: #ff9a9a;
                padding: 12px;
                border-radius: 10px;
                margin-bottom: 18px;
                font-size: 13px;
                text-align: center;
            }

        </style>

    </head>

    <body>

        <div class="login-box">

            <div class="logo">

                <div class="logo-icon">
                    💼
                </div>

                <h1>LAMBEMAH GESTION</h1>

                <p>
                    Gestion intelligente de votre activité
                </p>

            </div>

            <h2>Connexion</h2>

            <?php if ($message !== ""): ?>

                <div class="error">
                    <?= htmlspecialchars($message) ?>
                </div>

            <?php endif; ?>

            <form method="POST">

                <div class="group">

                    <label>
                        Nom d'utilisateur
                    </label>

                    <input
                        type="text"
                        name="username"
                        placeholder="Votre identifiant"
                        autocomplete="username"
                        required
                    >

                </div>

                <div class="group">

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

                <button type="submit">
                    Se connecter →
                </button>

            </form>

        </div>

    </body>

    </html>

    <?php
    exit;
}


/*
|--------------------------------------------------------------------------
| DÉCONNEXION
|--------------------------------------------------------------------------
*/

if (isset($_GET["logout"])) {

    session_unset();
    session_destroy();

    header("Location: index.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| INFORMATIONS UTILISATEUR
|--------------------------------------------------------------------------
*/

$nom = $_SESSION["nom"] ?? "Utilisateur";
$role = $_SESSION["role"] ?? "lecture";


/*
|--------------------------------------------------------------------------
| STATISTIQUES
|--------------------------------------------------------------------------
*/

/* Produits */

$total_produits = 0;
$stock_total = 0;

$result = $conn->query(
    "SELECT
        COUNT(*) AS total,
        COALESCE(SUM(stock),0) AS stock
     FROM produits"
);

if ($result) {

    $data = $result->fetch_assoc();

    $total_produits = (int)$data["total"];
    $stock_total = (int)$data["stock"];
}


/* Ventes */

$total_ventes = 0;
$nombre_ventes = 0;

$result = $conn->query(
    "SELECT
        COUNT(*) AS nombre,
        COALESCE(SUM(total),0) AS montant
     FROM ventes"
);

if ($result) {

    $data = $result->fetch_assoc();

    $nombre_ventes = (int)$data["nombre"];
    $total_ventes = (float)$data["montant"];
}


/* Recettes */

$total_recettes = 0;

$result = $conn->query(
    "SELECT
        COALESCE(SUM(montant),0) AS total
     FROM recettes"
);

if ($result) {

    $data = $result->fetch_assoc();

    $total_recettes = (float)$data["total"];
}


/* Dépenses */

$total_depenses = 0;

$result = $conn->query(
    "SELECT
        COALESCE(SUM(montant),0) AS total
     FROM depenses"
);

if ($result) {

    $data = $result->fetch_assoc();

    $total_depenses = (float)$data["total"];
}


/*
|--------------------------------------------------------------------------
| SOLDE
|--------------------------------------------------------------------------
|
| Argent entrant :
| ventes + recettes
|
| Argent sortant :
| dépenses
|--------------------------------------------------------------------------
*/

$total_entrees = $total_ventes + $total_recettes;

$solde = $total_entrees - $total_depenses;


/*
|--------------------------------------------------------------------------
| DERNIÈRES VENTES
|--------------------------------------------------------------------------
*/

$dernieres_ventes = $conn->query(
    "SELECT
        v.id,
        v.quantite,
        v.prix_unitaire,
        v.total,
        v.date_vente,
        p.nom AS produit
     FROM ventes v
     LEFT JOIN produits p
        ON p.id = v.produit_id
     ORDER BY v.id DESC
     LIMIT 5"
);


/*
|--------------------------------------------------------------------------
| DERNIÈRES DÉPENSES
|--------------------------------------------------------------------------
*/

$dernieres_depenses = $conn->query(
    "SELECT
        id,
        libelle,
        montant,
        date_depense
     FROM depenses
     ORDER BY id DESC
     LIMIT 5"
);


/*
|--------------------------------------------------------------------------
| FORMAT ARGENT
|--------------------------------------------------------------------------
*/

function argent($montant)
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

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>LAMBEMAH GESTION</title>

<style>

/*
|--------------------------------------------------------------------------
| GLOBAL
|--------------------------------------------------------------------------
*/

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {

    font-family:
        Inter,
        Arial,
        sans-serif;

    background: #f3f7fb;

    color: #172536;

    min-height: 100vh;

}


/*
|--------------------------------------------------------------------------
| SIDEBAR
|--------------------------------------------------------------------------
*/

.sidebar {

    position: fixed;

    left: 0;
    top: 0;
    bottom: 0;

    width: 250px;

    background:
        linear-gradient(
            180deg,
            #071b2e 0%,
            #0b2944 55%,
            #07395b 100%
        );

    color: white;

    padding: 24px 15px;

    z-index: 1000;

    box-shadow:
        5px 0 25px rgba(5,30,50,.12);

}

.brand {

    padding: 5px 12px 25px;

}

.brand-icon {

    width: 48px;
    height: 48px;

    border-radius: 15px;

    display: flex;

    justify-content: center;
    align-items: center;

    background:
        linear-gradient(
            135deg,
            #22b8ff,
            #1164c6
        );

    font-size: 24px;

    margin-bottom: 12px;

}

.brand h2 {

    font-size: 20px;

    letter-spacing: .5px;

}

.brand span {

    color: #8eb0c8;

    font-size: 10px;

    letter-spacing: 1px;

}


/*
|--------------------------------------------------------------------------
| NAVIGATION
|--------------------------------------------------------------------------
*/

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

    color: #c5d5e1;

    text-decoration: none;

    border-radius: 12px;

    font-size: 13px;

    transition: .2s;

}

.nav a:hover {

    background: rgba(255,255,255,.08);

    color: white;

}

.nav a.active {

    background:
        linear-gradient(
            90deg,
            rgba(32,184,255,.25),
            rgba(32,184,255,.08)
        );

    color: white;

    border-left: 3px solid #20b8ff;

}


/*
|--------------------------------------------------------------------------
| SIDEBAR BOTTOM
|--------------------------------------------------------------------------
*/

.sidebar-bottom {

    position: absolute;

    bottom: 20px;

    left: 15px;
    right: 15px;

}

.profile-mini {

    background: rgba(255,255,255,.06);

    border-radius: 13px;

    padding: 12px;

    margin-bottom: 10px;

}

.profile-mini strong {

    display: block;

    font-size: 12px;

}

.profile-mini span {

    color: #7fa0b8;

    font-size: 10px;

}

.logout {

    display: block;

    text-decoration: none;

    color: #ffb6b6;

    padding: 11px;

    border-radius: 11px;

    background: rgba(255,80,80,.08);

    text-align: center;

    font-size: 12px;

}


/*
|--------------------------------------------------------------------------
| MAIN
|--------------------------------------------------------------------------
*/

.main {

    margin-left: 250px;

    padding: 28px;

    max-width: 1600px;

}


/*
|--------------------------------------------------------------------------
| TOPBAR
|--------------------------------------------------------------------------
*/

.topbar {

    display: flex;

    align-items: center;

    justify-content: space-between;

    margin-bottom: 25px;

}

.greeting h1 {

    font-size: 25px;

    color: #11273b;

}

.greeting p {

    color: #8292a0;

    font-size: 13px;

    margin-top: 5px;

}

.user-badge {

    display: flex;

    align-items: center;

    gap: 10px;

    background: white;

    padding: 9px 13px;

    border-radius: 14px;

    box-shadow:
        0 5px 20px rgba(20,50,80,.06);

}

.avatar {

    width: 38px;
    height: 38px;

    border-radius: 12px;

    display: flex;

    align-items: center;

    justify-content: center;

    background:
        linear-gradient(
            135deg,
            #21b8ff,
            #1264c7
        );

    color: white;

    font-weight: bold;

}

.user-badge strong {

    display: block;

    font-size: 12px;

}

.user-badge span {

    font-size: 10px;

    color: #8997a4;

}


/*
|--------------------------------------------------------------------------
| SOLDE PRINCIPAL
|--------------------------------------------------------------------------
*/

.hero {

    background:
        linear-gradient(
            120deg,
            #071b2e,
            #0b3a5d,
            #1267a0
        );

    color: white;

    border-radius: 22px;

    padding: 25px;

    margin-bottom: 22px;

    position: relative;

    overflow: hidden;

    box-shadow:
        0 15px 35px rgba(7,40,65,.18);

}

.hero:after {

    content: "";

    position: absolute;

    width: 220px;
    height: 220px;

    border-radius: 50%;

    background: rgba(34,184,255,.12);

    right: -60px;
    top: -80px;

}

.hero-content {

    position: relative;

    z-index: 2;

}

.hero-label {

    color: #9fc4db;

    font-size: 11px;

    text-transform: uppercase;

    letter-spacing: 1px;

}

.hero h2 {

    font-size: 32px;

    margin: 7px 0;

}

.hero p {

    color: #b5d0df;

    font-size: 12px;

}


/*
|--------------------------------------------------------------------------
| STAT CARDS
|--------------------------------------------------------------------------
*/

.stats {

    display: grid;

    grid-template-columns:
        repeat(4, 1fr);

    gap: 16px;

    margin-bottom: 22px;

}

.stat {

    background: white;

    border-radius: 18px;

    padding: 19px;

    box-shadow:
        0 6px 25px rgba(25,55,80,.06);

}

.stat-top {

    display: flex;

    align-items: center;

    justify-content: space-between;

    margin-bottom: 15px;

}

.stat-icon {

    width: 42px;
    height: 42px;

    border-radius: 13px;

    display: flex;

    justify-content: center;

    align-items: center;

    background: #eaf7ff;

    font-size: 19px;

}

.stat small {

    color: #8b99a5;

    font-size: 11px;

}

.stat h3 {

    font-size: 20px;

    margin-top: 4px;

}


/*
|--------------------------------------------------------------------------
| CONTENT GRID
|--------------------------------------------------------------------------
*/

.content-grid {

    display: grid;

    grid-template-columns:
        1.4fr 1fr;

    gap: 20px;

}

.card {

    background: white;

    border-radius: 18px;

    padding: 20px;

    box-shadow:
        0 6px 25px rgba(25,55,80,.06);

}

.card-header {

    display: flex;

    justify-content: space-between;

    align-items: center;

    margin-bottom: 18px;

}

.card-header h2 {

    font-size: 16px;

}

.card-header span {

    font-size: 11px;

    color: #8b99a5;

}


/*
|--------------------------------------------------------------------------
| TABLE
|--------------------------------------------------------------------------
*/

.table-container {

    overflow-x: auto;

}

table {

    width: 100%;

    border-collapse: collapse;

}

th {

    text-align: left;

    color: #94a0aa;

    font-size: 10px;

    padding: 9px;

    border-bottom: 1px solid #edf1f4;

}

td {

    padding: 12px 9px;

    border-bottom: 1px solid #f0f3f5;

    font-size: 12px;

}

td strong {

    color: #263a4c;

}

.amount {

    font-weight: bold;

    color: #1174ae;

}

.date {

    color: #98a3ac;

    font-size: 10px;

}


/*
|--------------------------------------------------------------------------
| DEPENSES
|--------------------------------------------------------------------------
*/

.depense {

    display: flex;

    align-items: center;

    justify-content: space-between;

    padding: 13px 0;

    border-bottom: 1px solid #edf1f4;

}

.depense:last-child {

    border-bottom: none;

}

.depense-info {

    display: flex;

    align-items: center;

    gap: 10px;

}

.depense-icon {

    width: 37px;
    height: 37px;

    border-radius: 11px;

    background: #fff1f1;

    display: flex;

    align-items: center;

    justify-content: center;

}

.depense-info strong {

    display: block;

    font-size: 12px;

}

.depense-info small {

    color: #9ba5ad;

    font-size: 9px;

}

.depense-money {

    color: #d84a4a;

    font-weight: bold;

    font-size: 12px;

}


/*
|--------------------------------------------------------------------------
| QUICK ACTIONS
|--------------------------------------------------------------------------
*/

.actions {

    display: grid;

    grid-template-columns:
        repeat(3, 1fr);

    gap: 10px;

    margin-top: 20px;

}

.action {

    text-decoration: none;

    color: #233b4f;

    background: #f5faff;

    border: 1px solid #e5f1f7;

    border-radius: 14px;

    padding: 15px 10px;

    text-align: center;

    font-size: 11px;

    transition: .2s;

}

.action:hover {

    background: #e8f7ff;

    border-color: #bde8fa;

    transform: translateY(-2px);

}

.action div {

    font-size: 21px;

    margin-bottom: 7px;

}


/*
|--------------------------------------------------------------------------
| RESPONSIVE TABLET
|--------------------------------------------------------------------------
*/

@media (max-width: 1100px) {

    .stats {

        grid-template-columns:
            repeat(2, 1fr);

    }

    .content-grid {

        grid-template-columns: 1fr;

    }

}


/*
|--------------------------------------------------------------------------
| RESPONSIVE TELEPHONE
|--------------------------------------------------------------------------
*/

@media (max-width: 700px) {

    body {

        background: #f3f7fb;

    }

    .sidebar {

        position: relative;

        width: 100%;

        height: auto;

        padding: 12px;

        box-shadow: none;

    }

    .brand {

        display: flex;

        align-items: center;

        gap: 10px;

        padding: 4px 7px 12px;

    }

    .brand-icon {

        width: 40px;
        height: 40px;

        margin: 0;

        font-size: 19px;

    }

    .brand h2 {

        font-size: 16px;

    }

    .brand span {

        display: none;

    }

    .nav {

        display: grid;

        grid-template-columns:
            repeat(4, 1fr);

        gap: 4px;

    }

    .nav li {

        margin: 0;

    }

    .nav a {

        flex-direction: column;

        justify-content: center;

        text-align: center;

        gap: 4px;

        padding: 9px 3px;

        font-size: 9px;

        border-left: none;

    }

    .nav a.active {

        border-left: none;

        border-bottom: 2px solid #20b8ff;

    }

    .sidebar-bottom {

        position: static;

        margin-top: 10px;

    }

    .profile-mini {

        display: none;

    }

    .logout {

        padding: 8px;

        font-size: 10px;

    }

    .main {

        margin-left: 0;

        padding: 15px;

    }

    .topbar {

        align-items: flex-start;

    }

    .greeting h1 {

        font-size: 20px;

    }

    .greeting p {

        font-size: 11px;

    }

    .user-badge {

        padding: 6px;

    }

    .user-badge div:not(.avatar) {

        display: none;

    }

    .avatar {

        width: 34px;
        height: 34px;

    }

    .hero {

        padding: 20px;

        border-radius: 18px;

    }

    .hero h2 {

        font-size: 26px;

    }

    .stats {

        grid-template-columns:
            repeat(2, 1fr);

        gap: 10px;

    }

    .stat {

        padding: 14px;

        border-radius: 15px;

    }

    .stat h3 {

        font-size: 16px;

    }

    .stat-icon {

        width: 35px;
        height: 35px;

        font-size: 16px;

    }

    .content-grid {

        gap: 12px;

    }

    .card {

        padding: 15px;

        border-radius: 16px;

    }

    .actions {

        grid-template-columns:
            repeat(2, 1fr);

    }

}


/*
|--------------------------------------------------------------------------
| PETIT TELEPHONE
|--------------------------------------------------------------------------
*/

@media (max-width: 390px) {

    .nav {

        grid-template-columns:
            repeat(4, 1fr);

    }

    .nav a {

        font-size: 8px;

    }

    .stats {

        gap: 7px;

    }

    .stat {

        padding: 11px;

    }

    .stat h3 {

        font-size: 14px;

    }

    .hero h2 {

        font-size: 23px;

    }

}

</style>

</head>


<body>


<!-- ==========================================================
     SIDEBAR
=========================================================== -->

<aside class="sidebar">

    <div class="brand">

        <div class="brand-icon">
            💼
        </div>

        <div>

            <h2>
                LAMBEMAH
            </h2>

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

                <span>
                    Produits
                </span>

            </a>

        </li>


        <li>

            <a href="ventes.php">

                💰

                <span>
                    Ventes
                </span>

            </a>

        </li>


        <li>

            <a href="prestations.php">

                🖨️

                <span>
                    Prestations
                </span>

            </a>

        </li>


        <li>

            <a href="depenses.php">

                💸

                <span>
                    Dépenses
                </span>

            </a>

        </li>


        <li>

            <a href="statistiques.php">

                📊

                <span>
                    Stats
                </span>

            </a>

        </li>


        <?php if ($role === "admin"): ?>

        <li>

            <a href="utilisateurs.php">

                👥

                <span>
                    Équipe
                </span>

            </a>

        </li>

        <?php endif; ?>

    </ul>


    <div class="sidebar-bottom">

        <div class="profile-mini">

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


<!-- ==========================================================
     MAIN
=========================================================== -->

<main class="main">


    <!-- TOPBAR -->

    <div class="topbar">

        <div class="greeting">

            <h1>
                Bonjour, <?= htmlspecialchars($nom) ?> 👋
            </h1>

            <p>
                Voici un aperçu de ton activité aujourd'hui.
            </p>

        </div>


        <div class="user-badge">

            <div class="avatar">

                <?= strtoupper(
                    substr($nom, 0, 1)
                ) ?>

            </div>

            <div>

                <strong>
                    <?= htmlspecialchars($nom) ?>
                </strong>

                <span>
                    <?= htmlspecialchars($role) ?>
                </span>

            </div>

        </div>

    </div>


    <!-- SOLDE -->

    <section class="hero">

        <div class="hero-content">

            <div class="hero-label">
                Solde de l'activité
            </div>

            <h2>
                <?= argent($solde) ?>
            </h2>

            <p>
                Entrées :
                <?= argent($total_entrees) ?>

                &nbsp; • &nbsp;

                Dépenses :
                <?= argent($total_depenses) ?>
            </p>

        </div>

    </section>


    <!-- STATISTIQUES -->

    <section class="stats">


        <div class="stat">

            <div class="stat-top">

                <div>

                    <small>
                        Ventes
                    </small>

                    <h3>
                        <?= argent($total_ventes) ?>
                    </h3>

                </div>

                <div class="stat-icon">
                    💰
                </div>

            </div>

            <small>
                <?= $nombre_ventes ?> vente(s)
            </small>

        </div>


        <div class="stat">

            <div class="stat-top">

                <div>

                    <small>
                        Recettes
                    </small>

                    <h3>
                        <?= argent($total_recettes) ?>
                    </h3>

                </div>

                <div class="stat-icon">
                    💵
                </div>

            </div>

            <small>
                Revenus enregistrés
            </small>

        </div>


        <div class="stat">

            <div class="stat-top">

                <div>

                    <small>
                        Dépenses
                    </small>

                    <h3>
                        <?= argent($total_depenses) ?>
                    </h3>

                </div>

                <div class="stat-icon">
                    💸
                </div>

            </div>

            <small>
                Argent sorti
            </small>

        </div>


        <div class="stat">

            <div class="stat-top">

                <div>

                    <small>
                        Stock
                    </small>

                    <h3>
                        <?= $stock_total ?>
                    </h3>

                </div>

                <div class="stat-icon">
                    📦
                </div>

            </div>

            <small>
                <?= $total_produits ?> produit(s)
            </small>

        </div>


    </section>


    <!-- CONTENU -->

    <section class="content-grid">


        <!-- VENTES -->

        <div class="card">

            <div class="card-header">

                <h2>
                    💰 Dernières ventes
                </h2>

                <span>
                    5 dernières
                </span>

            </div>


            <div class="table-container">

                <table>

                    <thead>

                    <tr>

                        <th>
                            PRODUIT
                        </th>

                        <th>
                            QTÉ
                        </th>

                        <th>
                            TOTAL
                        </th>

                        <th>
                            DATE
                        </th>

                    </tr>

                    </thead>


                    <tbody>

                    <?php if (
                        $dernieres_ventes &&
                        $dernieres_ventes->num_rows > 0
                    ): ?>

                        <?php while (
                            $vente =
                            $dernieres_ventes->fetch_assoc()
                        ): ?>

                        <tr>

                            <td>

                                <strong>

                                    <?= htmlspecialchars(
                                        $vente["produit"]
                                        ?? "Produit supprimé"
                                    ) ?>

                                </strong>

                            </td>

                            <td>
                                <?= (int)$vente["quantite"] ?>
                            </td>

                            <td class="amount">

                                <?= argent(
                                    $vente["total"]
                                ) ?>

                            </td>

                            <td class="date">

                                <?= date(
                                    "d/m/Y",
                                    strtotime(
                                        $vente["date_vente"]
                                    )
                                ) ?>

                            </td>

                        </tr>

                        <?php endwhile; ?>

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

        </div>


        <!-- DEPENSES -->

        <div class="card">

            <div class="card-header">

                <h2>
                    💸 Dernières dépenses
                </h2>

                <span>
                    Activité récente
                </span>

            </div>


            <?php if (
                $dernieres_depenses &&
                $dernieres_depenses->num_rows > 0
            ): ?>

                <?php while (
                    $depense =
                    $dernieres_depenses->fetch_assoc()
                ): ?>

                <div class="depense">

                    <div class="depense-info">

                        <div class="depense-icon">
                            💸
                        </div>

                        <div>

                            <strong>

                                <?= htmlspecialchars(
                                    $depense["libelle"]
                                ) ?>

                            </strong>

                            <small>

                                <?= date(
                                    "d/m/Y",
                                    strtotime(
                                        $depense["date_depense"]
                                    )
                                ) ?>

                            </small>

                        </div>

                    </div>


                    <div class="depense-money">

                        -
                        <?= argent(
                            $depense["montant"]
                        ) ?>

                    </div>

                </div>

                <?php endwhile; ?>

            <?php else: ?>

                <p style="
                    color:#8997a4;
                    font-size:12px;
                ">

                    Aucune dépense enregistrée.

                </p>

            <?php endif; ?>


            <!-- ACTIONS -->

            <div class="actions">

                <a
                    href="produits.php"
                    class="action"
                >

                    <div>
                        📦
                    </div>

                    Produits

                </a>


                <a
                    href="ventes.php"
                    class="action"
                >

                    <div>
                        💰
                    </div>

                    Nouvelle vente

                </a>


                <a
                    href="prestations.php"
                    class="action"
                >

                    <div>
                        🖨️
                    </div>

                    Prestation DTF

                </a>


                <a
                    href="depenses.php"
                    class="action"
                >

                    <div>
                        💸
                    </div>

                    Dépense

                </a>


                <a
                    href="statistiques.php"
                    class="action"
                >

                    <div>
                        📊
                    </div>

                    Statistiques

                </a>


                <?php if ($role === "admin"): ?>

                <a
                    href="utilisateurs.php"
                    class="action"
                >

                    <div>
                        👥
                    </div>

                    Équipe

                </a>

                <?php endif; ?>

            </div>

        </div>

    </section>


</main>

</body>

</html>
```
