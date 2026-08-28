```php
<?php
session_start();
require_once "config.php";

$message = "";

/* =========================================================
   DÉCONNEXION
========================================================= */
if (isset($_GET["logout"])) {
    session_unset();
    session_destroy();

    header("Location: index.php");
    exit;
}

/* =========================================================
   CONNEXION
========================================================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && !isset($_SESSION["id"])) {

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

/* =========================================================
   SI PAS CONNECTÉ → PAGE DE CONNEXION
========================================================= */
if (!isset($_SESSION["id"])) {
?>

<!DOCTYPE html>
<html lang="fr">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>LAMBEMAH GESTION - Connexion</title>

<style>

*{
    box-sizing:border-box;
    margin:0;
    padding:0;
}

body{
    min-height:100vh;
    font-family:Arial,Helvetica,sans-serif;
    background:linear-gradient(135deg,#e9f8ff,#ffffff);
    display:flex;
    justify-content:center;
    align-items:center;
    padding:20px;
}

.login-container{
    width:100%;
    max-width:420px;
}

.logo{
    text-align:center;
    margin-bottom:25px;
}

.logo-icon{
    width:70px;
    height:70px;
    margin:auto;
    border-radius:20px;
    background:linear-gradient(135deg,#55c7ee,#178dcc);
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:32px;
    box-shadow:0 10px 25px rgba(24,141,204,.20);
}

.logo h1{
    margin-top:15px;
    color:#168dcc;
    font-size:29px;
}

.logo p{
    margin-top:7px;
    color:#71808a;
}

.login-card{
    background:#fff;
    padding:32px;
    border-radius:24px;
    box-shadow:0 15px 45px rgba(24,141,204,.12);
}

.login-card h2{
    text-align:center;
    margin-bottom:25px;
    color:#263746;
}

.form-group{
    margin-bottom:18px;
}

.form-group label{
    display:block;
    margin-bottom:8px;
    color:#46545e;
    font-weight:bold;
}

.form-group input{
    width:100%;
    padding:14px 15px;
    border:1px solid #dbe7ed;
    border-radius:12px;
    outline:none;
    font-size:15px;
    transition:.2s;
}

.form-group input:focus{
    border-color:#2caee0;
    box-shadow:0 0 0 3px rgba(44,174,224,.10);
}

.login-btn{
    width:100%;
    border:0;
    padding:15px;
    border-radius:12px;
    background:linear-gradient(135deg,#55c7ee,#168dcc);
    color:white;
    font-size:16px;
    font-weight:bold;
    cursor:pointer;
    box-shadow:0 8px 20px rgba(24,141,204,.20);
}

.login-btn:hover{
    transform:translateY(-1px);
}

.error{
    background:#fff0f0;
    color:#c62828;
    border:1px solid #ffd4d4;
    padding:12px;
    border-radius:10px;
    text-align:center;
    margin-bottom:18px;
}

.footer{
    text-align:center;
    margin-top:18px;
    color:#9aa7ad;
    font-size:12px;
}

</style>

</head>

<body>

<div class="login-container">

    <div class="logo">

        <div class="logo-icon">
            💼
        </div>

        <h1>LAMBEMAH GESTION</h1>

        <p>La gestion intelligente de votre activité</p>

    </div>

    <div class="login-card">

        <h2>Bienvenue 👋</h2>

        <?php if ($message !== ""): ?>

            <div class="error">
                <?= htmlspecialchars($message) ?>
            </div>

        <?php endif; ?>

        <form method="POST">

            <div class="form-group">

                <label>Nom d'utilisateur</label>

                <input
                    type="text"
                    name="username"
                    placeholder="Votre nom d'utilisateur"
                    required
                >

            </div>

            <div class="form-group">

                <label>Mot de passe</label>

                <input
                    type="password"
                    name="password"
                    placeholder="Votre mot de passe"
                    required
                >

            </div>

            <button class="login-btn" type="submit">
                Se connecter →
            </button>

        </form>

    </div>

    <div class="footer">
        LAMBEMAH GESTION © <?= date("Y") ?>
    </div>

</div>

</body>
</html>

<?php
exit;
}


/* =========================================================
   TABLEAU DE BORD
========================================================= */

/* Chiffre d'affaires */
$ca = 0;

$result = $conn->query(
    "SELECT COALESCE(SUM(montant),0) AS total
     FROM ventes"
);

if ($result) {
    $row = $result->fetch_assoc();
    $ca = (float)$row["total"];
}


/* Recettes */
$recettes = 0;

$result = $conn->query(
    "SELECT COALESCE(SUM(montant),0) AS total
     FROM recettes"
);

if ($result) {
    $row = $result->fetch_assoc();
    $recettes = (float)$row["total"];
}


/* Dépenses */
$depenses = 0;

$result = $conn->query(
    "SELECT COALESCE(SUM(montant),0) AS total
     FROM depenses"
);

if ($result) {
    $row = $result->fetch_assoc();
    $depenses = (float)$row["total"];
}


/* Nombre de produits */
$nombre_produits = 0;

$result = $conn->query(
    "SELECT COUNT(*) AS total
     FROM produits"
);

if ($result) {
    $row = $result->fetch_assoc();
    $nombre_produits = (int)$row["total"];
}


/* Stock total */
$stock_total = 0;

$result = $conn->query(
    "SELECT COALESCE(SUM(stock),0) AS total
     FROM produits"
);

if ($result) {
    $row = $result->fetch_assoc();
    $stock_total = (int)$row["total"];
}


/* Nombre de ventes */
$nombre_ventes = 0;

$result = $conn->query(
    "SELECT COUNT(*) AS total
     FROM ventes"
);

if ($result) {
    $row = $result->fetch_assoc();
    $nombre_ventes = (int)$row["total"];
}


/* Solde */
$solde = ($ca + $recettes) - $depenses;


/* Bénéfice estimé */
$benefice = $solde;


/* Ventes récentes */
$ventes_recentes = [];

$result = $conn->query(
    "SELECT
        v.id,
        v.quantite,
        v.prix_unitaire,
        v.montant,
        v.description,
        v.date_vente,
        p.nom AS produit
     FROM ventes v
     LEFT JOIN produits p ON p.id = v.produit_id
     ORDER BY v.date_vente DESC
     LIMIT 6"
);

if ($result) {

    while ($row = $result->fetch_assoc()) {
        $ventes_recentes[] = $row;
    }

}


/* Produits stock faible */
$stocks_faibles = [];

$result = $conn->query(
    "SELECT id, nom, categorie, stock
     FROM produits
     WHERE stock <= 5
     ORDER BY stock ASC
     LIMIT 6"
);

if ($result) {

    while ($row = $result->fetch_assoc()) {
        $stocks_faibles[] = $row;
    }

}


/* Format GNF */
function gnf($montant)
{
    return number_format(
        (float)$montant,
        0,
        ',',
        ' '
    ) . " GNF";
}

?>

<!DOCTYPE html>
<html lang="fr">

<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width, initial-scale=1.0">

<title>LAMBEMAH GESTION - Tableau de bord</title>

<style>

*{
    box-sizing:border-box;
    margin:0;
    padding:0;
}

body{
    font-family:Arial,Helvetica,sans-serif;
    background:#f5faff;
    color:#263746;
}


/* =========================
   SIDEBAR
========================= */

.sidebar{
    position:fixed;
    left:0;
    top:0;
    bottom:0;
    width:245px;
    background:linear-gradient(180deg,#53c5eb,#168dcc);
    color:white;
    padding:25px 15px;
    z-index:100;
}

.brand{
    padding:5px 12px 28px;
}

.brand-icon{
    width:48px;
    height:48px;
    background:rgba(255,255,255,.20);
    border-radius:14px;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:23px;
    margin-bottom:12px;
}

.brand h2{
    font-size:21px;
}

.brand span{
    font-size:11px;
    opacity:.8;
}

.nav{
    list-style:none;
}

.nav li{
    margin:5px 0;
}

.nav a{
    display:flex;
    align-items:center;
    gap:12px;
    padding:13px 14px;
    border-radius:12px;
    color:white;
    text-decoration:none;
    font-size:14px;
    transition:.2s;
}

.nav a:hover,
.nav a.active{
    background:rgba(255,255,255,.20);
}

.nav-icon{
    width:25px;
    text-align:center;
    font-size:17px;
}

.sidebar-bottom{
    position:absolute;
    left:15px;
    right:15px;
    bottom:20px;
}

.logout{
    display:flex;
    align-items:center;
    gap:12px;
    padding:13px 14px;
    border-radius:12px;
    background:rgba(255,255,255,.12);
    color:white;
    text-decoration:none;
    font-size:14px;
}


/* =========================
   MAIN
========================= */

.main{
    margin-left:245px;
    padding:25px 30px 40px;
}


/* HEADER */

.header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:28px;
}

.header-left h1{
    font-size:25px;
    margin-bottom:5px;
}

.header-left p{
    color:#7d8c95;
    font-size:13px;
}

.profile{
    display:flex;
    align-items:center;
    gap:12px;
    background:white;
    padding:8px 14px 8px 8px;
    border-radius:30px;
    box-shadow:0 4px 15px rgba(0,0,0,.05);
}

.avatar{
    width:38px;
    height:38px;
    border-radius:50%;
    background:#dff5ff;
    display:flex;
    justify-content:center;
    align-items:center;
    color:#168dcc;
    font-weight:bold;
}

.profile strong{
    display:block;
    font-size:13px;
}

.profile small{
    color:#89969d;
}


/* WELCOME */

.welcome{
    background:linear-gradient(135deg,#55c7ee,#168dcc);
    border-radius:20px;
    padding:25px 28px;
    color:white;
    margin-bottom:25px;
    box-shadow:0 12px 30px rgba(24,141,204,.18);
}

.welcome h2{
    font-size:22px;
    margin-bottom:8px;
}

.welcome p{
    opacity:.9;
    font-size:14px;
}


/* =========================
   STATS
========================= */

.stats{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:17px;
    margin-bottom:25px;
}

.stat-card{
    background:white;
    border-radius:17px;
    padding:20px;
    box-shadow:0 5px 20px rgba(32,88,112,.06);
    position:relative;
    overflow:hidden;
}

.stat-top{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:15px;
}

.stat-icon{
    width:42px;
    height:42px;
    border-radius:12px;
    background:#e8f8ff;
    display:flex;
    justify-content:center;
    align-items:center;
    font-size:20px;
}

.stat-label{
    color:#89969d;
    font-size:12px;
}

.stat-value{
    font-size:21px;
    font-weight:bold;
    color:#263746;
}


/* =========================
   CONTENT GRID
========================= */

.grid{
    display:grid;
    grid-template-columns:2fr 1fr;
    gap:20px;
    margin-bottom:20px;
}

.panel{
    background:white;
    border-radius:18px;
    padding:22px;
    box-shadow:0 5px 20px rgba(32,88,112,.06);
}

.panel-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:20px;
}

.panel-header h2{
    font-size:17px;
}

.panel-link{
    text-decoration:none;
    color:#168dcc;
    font-size:12px;
    font-weight:bold;
}


/* RECENT SALES */

.sale{
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:13px 0;
    border-bottom:1px solid #edf3f6;
}

.sale:last-child{
    border-bottom:0;
}

.sale-left{
    display:flex;
    align-items:center;
    gap:12px;
}

.sale-icon{
    width:38px;
    height:38px;
    border-radius:10px;
    background:#e9f8ff;
    display:flex;
    justify-content:center;
    align-items:center;
}

.sale-name{
    font-weight:bold;
    font-size:13px;
}

.sale-date{
    color:#9aa6ad;
    font-size:11px;
    margin-top:4px;
}

.sale-price{
    color:#16a26a;
    font-weight:bold;
    font-size:13px;
}


/* STOCK */

.stock-item{
    padding:13px 0;
    border-bottom:1px solid #edf3f6;
}

.stock-item:last-child{
    border-bottom:0;
}

.stock-row{
    display:flex;
    justify-content:space-between;
    margin-bottom:8px;
}

.stock-name{
    font-weight:bold;
    font-size:13px;
}

.stock-number{
    font-size:12px;
    color:#e58b21;
    font-weight:bold;
}

.stock-bar{
    height:6px;
    background:#edf3f6;
    border-radius:10px;
    overflow:hidden;
}

.stock-progress{
    height:100%;
    background:#f2b45a;
    border-radius:10px;
}


/* =========================
   QUICK ACTIONS
========================= */

.quick-actions{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:15px;
}

.action{
    background:white;
    border-radius:16px;
    padding:18px;
    text-decoration:none;
    color:#263746;
    box-shadow:0 5px 20px rgba(32,88,112,.06);
    transition:.2s;
}

.action:hover{
    transform:translateY(-3px);
    box-shadow:0 10px 25px rgba(32,88,112,.10);
}

.action-icon{
    font-size:24px;
    margin-bottom:12px;
}

.action strong{
    display:block;
    font-size:13px;
}

.action span{
    display:block;
    color:#89969d;
    font-size:11px;
    margin-top:4px;
}


/* EMPTY */

.empty{
    text-align:center;
    padding:30px 10px;
    color:#9aa6ad;
    font-size:13px;
}


/* =========================
   MOBILE
========================= */

@media(max-width:1100px){

    .stats{
        grid-template-columns:repeat(2,1fr);
    }

    .grid{
        grid-template-columns:1fr;
    }

    .quick-actions{
        grid-template-columns:repeat(2,1fr);
    }

}

@media(max-width:750px){

    .sidebar{
        position:relative;
        width:100%;
        height:auto;
        padding:15px;
    }

    .brand{
        padding-bottom:15px;
    }

    .nav{
        display:grid;
        grid-template-columns:repeat(3,1fr);
        gap:5px;
    }

    .nav li{
        margin:0;
    }

    .nav a{
        justify-content:center;
        flex-direction:column;
        gap:4px;
        padding:9px 5px;
        font-size:10px;
    }

    .nav-icon{
        font-size:16px;
    }

    .sidebar-bottom{
        position:static;
        margin-top:8px;
    }

    .main{
        margin-left:0;
        padding:18px;
    }

    .header{
        align-items:flex-start;
    }

    .header-left h1{
        font-size:21px;
    }

    .profile{
        padding-right:8px;
    }

    .profile div:last-child{
        display:none;
    }

}

@media(max-width:520px){

    .stats{
        grid-template-columns:1fr 1fr;
        gap:10px;
    }

    .stat-card{
        padding:15px;
    }

    .stat-value{
        font-size:16px;
    }

    .stat-label{
        font-size:10px;
    }

    .welcome{
        padding:20px;
    }

    .welcome h2{
        font-size:18px;
    }

    .quick-actions{
        grid-template-columns:1fr 1fr;
        gap:10px;
    }

    .panel{
        padding:16px;
    }

}

</style>

</head>

<body>


<!-- =========================
     SIDEBAR
========================= -->

<aside class="sidebar">

    <div class="brand">

        <div class="brand-icon">
            💼
        </div>

        <h2>LAMBEMAH</h2>

        <span>GESTION • PRESTATION</span>

    </div>


    <ul class="nav">

        <li>
            <a href="index.php" class="active">
                <span class="nav-icon">🏠</span>
                <span>Accueil</span>
            </a>
        </li>

        <li>
            <a href="#">
                <span class="nav-icon">📦</span>
                <span>Produits</span>
            </a>
        </li>

        <li>
            <a href="#">
                <span class="nav-icon">💰</span>
                <span>Ventes</span>
            </a>
        </li>

        <li>
            <a href="#">
                <span class="nav-icon">🖨️</span>
                <span>Prestations</span>
            </a>
        </li>

        <li>
            <a href="#">
                <span class="nav-icon">💸</span>
                <span>Dépenses</span>
            </a>
        </li>

        <li>
            <a href="#">
                <span class="nav-icon">👥</span>
                <span>Clients</span>
            </a>
        </li>

        <li>
            <a href="#">
                <span class="nav-icon">📊</span>
                <span>Statistiques</span>
            </a>
        </li>

        <li>
            <a href="#">
                <span class="nav-icon">📋</span>
                <span>Historique</span>
            </a>
        </li>

    </ul>


    <div class="sidebar-bottom">

        <a class="logout" href="?logout=1">
            <span>🚪</span>
            Déconnexion
        </a>

    </div>

</aside>


<!-- =========================
     MAIN
========================= -->

<main class="main">


    <!-- HEADER -->

    <header class="header">

        <div class="header-left">

            <h1>Tableau de bord</h1>

            <p>
                Vue générale de votre activité
            </p>

        </div>


        <div class="profile">

            <div class="avatar">
                <?= strtoupper(substr($_SESSION["nom"], 0, 1)) ?>
            </div>

            <div>

                <strong>
                    <?= htmlspecialchars($_SESSION["nom"]) ?>
                </strong>

                <small>
                    Administrateur
                </small>

            </div>

        </div>

    </header>


    <!-- WELCOME -->

    <section class="welcome">

        <h2>
            Bonjour <?= htmlspecialchars($_SESSION["nom"]) ?> 👋
        </h2>

        <p>
            Voici l'état actuel de votre activité LAMBEMAH GESTION.
        </p>

    </section>


    <!-- STATISTIQUES -->

    <section class="stats">


        <div class="stat-card">

            <div class="stat-top">

                <div>
                    <div class="stat-label">
                        Chiffre d'affaires
                    </div>

                    <div class="stat-value">
                        <?= gnf($ca) ?>
                    </div>
                </div>

                <div class="stat-icon">
                    💰
                </div>

            </div>

        </div>


        <div class="stat-card">

            <div class="stat-top">

                <div>

                    <div class="stat-label">
                        Recettes
                    </div>

                    <div class="stat-value">
                        <?= gnf($recettes) ?>
                    </div>

                </div>

                <div class="stat-icon">
                    💵
                </div>

            </div>

        </div>


        <div class="stat-card">

            <div class="stat-top">

                <div>

                    <div class="stat-label">
                        Dépenses
                    </div>

                    <div class="stat-value">
                        <?= gnf($depenses) ?>
                    </div>

                </div>

                <div class="stat-icon">
                    💸
                </div>

            </div>

        </div>


        <div class="stat-card">

            <div class="stat-top">

                <div>

                    <div class="stat-label">
                        Solde / bénéfice
                    </div>

                    <div class="stat-value">
                        <?= gnf($benefice) ?>
                    </div>

                </div>

                <div class="stat-icon">
                    📈
                </div>

            </div>

        </div>


    </section>


    <!-- SECONDARY STATS -->

    <section class="stats">


        <div class="stat-card">

            <div class="stat-top">

                <div>

                    <div class="stat-label">
                        Produits
                    </div>

                    <div class="stat-value">
                        <?= $nombre_produits ?>
                    </div>

                </div>

                <div class="stat-icon">
                    📦
                </div>

            </div>

        </div>


        <div class="stat-card">

            <div class="stat-top">

                <div>

                    <div class="stat-label">
                        Stock total
                    </div>

                    <div class="stat-value">
                        <?= $stock_total ?>
                    </div>

                </div>

                <div class="stat-icon">
                    🧺
                </div>

            </div>

        </div>


        <div class="stat-card">

            <div class="stat-top">

                <div>

                    <div class="stat-label">
                        Nombre de ventes
                    </div>

                    <div class="stat-value">
                        <?= $nombre_ventes ?>
                    </div>

                </div>

                <div class="stat-icon">
                    🛒
                </div>

            </div>

        </div>


        <div class="stat-card">

            <div class="stat-top">

                <div>

                    <div class="stat-label">
                        Activité
                    </div>

                    <div class="stat-value">
                        Active
                    </div>

                </div>

                <div class="stat-icon">
                    ⚡
                </div>

            </div>

        </div>


    </section>


    <!-- VENTES + STOCK -->

    <section class="grid">


        <!-- VENTES -->

        <div class="panel">

            <div class="panel-header">

                <h2>
                    🛒 Ventes récentes
                </h2>

                <a href="#" class="panel-link">
                    Voir tout
                </a>

            </div>


            <?php if (count($ventes_recentes) > 0): ?>

                <?php foreach ($ventes_recentes as $vente): ?>

                    <div class="sale">

                        <div class="sale-left">

                            <div class="sale-icon">
                                👕
                            </div>

                            <div>

                                <div class="sale-name">

                                    <?= htmlspecialchars(
                                        $vente["produit"] ?: "Produit"
                                    ) ?>

                                    × <?= (int)$vente["quantite"] ?>

                                </div>

                                <div class="sale-date">

                                    <?= date(
                                        "d/m/Y à H:i",
                                        strtotime($vente["date_vente"])
                                    ) ?>

                                </div>

                            </div>

                        </div>


                        <div class="sale-price">

                            +<?= gnf($vente["montant"]) ?>

                        </div>

                    </div>

                <?php endforeach; ?>

            <?php else: ?>

                <div class="empty">

                    🛒<br><br>

                    Aucune vente enregistrée pour le moment.

                </div>

            <?php endif; ?>

        </div>


        <!-- STOCK FAIBLE -->

        <div class="panel">

            <div class="panel-header">

                <h2>
                    ⚠️ Stock faible
                </h2>

                <a href="#" class="panel-link">
                    Stock
                </a>

            </div>


            <?php if (count($stocks_faibles) > 0): ?>

                <?php foreach ($stocks_faibles as $stock): ?>

                    <div class="stock-item">

                        <div class="stock-row">

                            <span class="stock-name">

                                <?= htmlspecialchars(
                                    $stock["nom"]
                                ) ?>

                            </span>

                            <span class="stock-number">

                                <?= (int)$stock["stock"] ?>

                            </span>

                        </div>

                        <div class="stock-bar">

                            <div
                                class="stock-progress"
                                style="width:<?= min(
                                    100,
                                    max(5, $stock["stock"] * 20)
                                ) ?>%"
                            ></div>

                        </div>

                    </div>

                <?php endforeach; ?>

            <?php else: ?>

                <div class="empty">

                    📦<br><br>

                    Aucun stock critique.

                </div>

            <?php endif; ?>

        </div>

    </section>


    <!-- ACTIONS RAPIDES -->

    <section>

        <div class="panel-header">

            <h2>
                Actions rapides
            </h2>

        </div>


        <div class="quick-actions">

            <a href="#" class="action">

                <div class="action-icon">
                    📦
                </div>

                <strong>
                    Ajouter un produit
                </strong>

                <span>
                    Gérer votre stock
                </span>

            </a>


            <a href="#" class="action">

                <div class="action-icon">
                    💰
                </div>

                <strong>
                    Nouvelle vente
                </strong>

                <span>
                    Enregistrer une vente
                </span>

            </a>


            <a href="#" class="action">

                <div class="action-icon">
                    🖨️
                </div>

                <strong>
                    Nouvelle prestation
                </strong>

                <span>
                    DTF / impression
                </span>

            </a>


            <a href="#" class="action">

                <div class="action-icon">
                    💸
                </div>

                <strong>
                    Nouvelle dépense
                </strong>

                <span>
                    Enregistrer une dépense
                </span>

            </a>

        </div>

    </section>


</main>

</body>
</html>
```
