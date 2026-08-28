<?php
session_start();
require_once "config.php";

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
$message = "";

if (!isset($_SESSION["id"]) && $_SERVER["REQUEST_METHOD"] === "POST") {

    $username = trim($_POST["username"] ?? "");
    $password = trim($_POST["password"] ?? "");

    if ($username === "" || $password === "") {
        $message = "Veuillez remplir tous les champs.";
    } else {

        $stmt = $conn->prepare("
            SELECT id, nom, username, mot_de_passe, role
            FROM utilisateurs
            WHERE username = ?
            LIMIT 1
        ");

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
   PAGE DE CONNEXION
========================= */

if (!isset($_SESSION["id"])) {
?>

<!DOCTYPE html>

<html lang="fr">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>LAMBEMAH GESTION</title>

<style>

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family: Arial, Helvetica, sans-serif;
    min-height: 100vh;
    background: linear-gradient(145deg, #061a35, #0b5ed7);
    display: flex;
    justify-content: center;
    align-items: center;
    padding: 20px;
}

.login-container {
    width: 100%;
    max-width: 430px;
}

.logo {
    text-align: center;
    color: white;
    margin-bottom: 25px;
}

.logo .logo-icon {
    width: 75px;
    height: 75px;
    margin: auto;
    border-radius: 22px;
    background: rgba(255,255,255,.15);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 38px;
    margin-bottom: 15px;
}

.logo h1 {
    font-size: 28px;
    margin-bottom: 7px;
}

.logo p {
    opacity: .85;
    font-size: 14px;
}

.login-card {
    background: white;
    border-radius: 25px;
    padding: 28px 22px;
    box-shadow: 0 20px 50px rgba(0,0,0,.22);
}

.login-card h2 {
    color: #061a35;
    text-align: center;
    margin-bottom: 23px;
}

.form-group {
    margin-bottom: 17px;
}

label {
    display: block;
    color: #334155;
    font-size: 14px;
    font-weight: bold;
    margin-bottom: 7px;
}

input {
    width: 100%;
    padding: 15px;
    border: 1px solid #dbe4ee;
    border-radius: 13px;
    outline: none;
    font-size: 16px;
}

input:focus {
    border-color: #0b7cff;
    box-shadow: 0 0 0 3px rgba(11,124,255,.10);
}

.login-button {
    width: 100%;
    border: none;
    padding: 15px;
    border-radius: 13px;
    background: linear-gradient(135deg, #0b7cff, #0755c9);
    color: white;
    font-size: 16px;
    font-weight: bold;
    cursor: pointer;
}

.message {
    background: #fff0f0;
    color: #c62828;
    padding: 12px;
    border-radius: 11px;
    margin-bottom: 17px;
    text-align: center;
    font-size: 14px;
}

.footer-login {
    text-align: center;
    margin-top: 18px;
    color: #64748b;
    font-size: 12px;
}

</style>

</head>

<body>

<div class="login-container">

```
<div class="logo">

    <div class="logo-icon">
        💼
    </div>

    <h1>LAMBEMAH GESTION</h1>

    <p>
        Gestion simple et intelligente de votre activité
    </p>

</div>

<div class="login-card">

    <h2>Bienvenue 👋</h2>

    <?php if ($message !== ""): ?>

        <div class="message">
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
                autocomplete="username"
                required
            >

        </div>

        <div class="form-group">

            <label>Mot de passe</label>

            <input
                type="password"
                name="password"
                placeholder="Votre mot de passe"
                autocomplete="current-password"
                required
            >

        </div>

        <button class="login-button" type="submit">
            Se connecter
        </button>

    </form>

    <div class="footer-login">
        LAMBEMAH GESTION • Votre activité, mieux organisée
    </div>

</div>
```

</div>

</body>
</html>

<?php
exit;
}


/* =========================
   TABLEAU DE BORD
========================= */

$nom = $_SESSION["nom"] ?? "Utilisateur";
$role = $_SESSION["role"] ?? "utilisateur";


/* PRODUITS */

$total_produits = 0;
$stock_total = 0;

$result = $conn->query("
    SELECT
        COUNT(*) AS total_produits,
        COALESCE(SUM(stock),0) AS stock_total
    FROM produits
");

if ($result) {

    $data = $result->fetch_assoc();

    $total_produits = (int)$data["total_produits"];
    $stock_total = (int)$data["stock_total"];
}


/* VENTES */

$montant_ventes = 0;
$total_ventes = 0;

$result = $conn->query("
    SELECT
        COUNT(*) AS total_ventes,
        COALESCE(SUM(montant),0) AS montant_ventes
    FROM ventes
");

if ($result) {

    $data = $result->fetch_assoc();

    $total_ventes = (int)$data["total_ventes"];
    $montant_ventes = (float)$data["montant_ventes"];
}


/* RECETTES */

$montant_recettes = 0;

$result = $conn->query("
    SELECT COALESCE(SUM(montant),0) AS montant_recettes
    FROM recettes
");

if ($result) {

    $data = $result->fetch_assoc();

    $montant_recettes = (float)$data["montant_recettes"];
}


/* DEPENSES */

$montant_depenses = 0;

$result = $conn->query("
    SELECT COALESCE(SUM(montant),0) AS montant_depenses
    FROM depenses
");

if ($result) {

    $data = $result->fetch_assoc();

    $montant_depenses = (float)$data["montant_depenses"];
}


/* RESULTAT */

$total_entrees = $montant_ventes + $montant_recettes;
$resultat = $total_entrees - $montant_depenses;


/* FORMATAGE */

function afficherMontant($montant)
{
    return number_format((float)$montant, 0, ',', ' ') . " FG";
}

?>

<!DOCTYPE html>

<html lang="fr">

<head>

<meta charset="UTF-8">

<meta name="viewport"
content="width=device-width, initial-scale=1.0, maximum-scale=1.0">

<title>LAMBEMAH GESTION</title>

<style>

/* =========================
   GLOBAL
========================= */

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family: Arial, Helvetica, sans-serif;
    background: #f3f7fc;
    color: #12233f;
    min-height: 100vh;
}

a {
    text-decoration: none;
}


/* =========================
   HEADER
========================= */

.header {
    background: linear-gradient(135deg, #061a35, #075dcc);
    color: white;
    padding: 22px 18px 28px;
    border-radius: 0 0 28px 28px;
    box-shadow: 0 8px 25px rgba(6,26,53,.18);
}

.header-content {
    max-width: 1150px;
    margin: auto;
}

.top-line {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
}

.brand {
    font-size: 21px;
    font-weight: bold;
}

.role {
    background: rgba(255,255,255,.14);
    padding: 7px 10px;
    border-radius: 20px;
    font-size: 11px;
}

.welcome {
    margin-top: 23px;
}

.welcome small {
    opacity: .8;
    font-size: 13px;
}

.welcome h1 {
    margin-top: 5px;
    font-size: 25px;
}


/* =========================
   CONTAINER
========================= */

.container {
    max-width: 1150px;
    margin: auto;
    padding: 20px 15px 100px;
}


/* =========================
   RESULTAT
========================= */

.main-result {
    background: white;
    border-radius: 22px;
    padding: 22px;
    margin-top: -5px;
    box-shadow: 0 8px 25px rgba(15,40,75,.08);
    border: 1px solid #e8eef6;
}

.result-title {
    color: #718096;
    font-size: 13px;
    margin-bottom: 7px;
}

.result-value {
    font-size: 30px;
    font-weight: bold;
    color: #061a35;
}

.result-description {
    margin-top: 8px;
    color: #64748b;
    font-size: 12px;
}


/* =========================
   STATISTIQUES RAPIDES
========================= */

.cards {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-top: 17px;
}

.card {
    background: white;
    border-radius: 18px;
    padding: 18px;
    border: 1px solid #e7edf5;
    box-shadow: 0 5px 18px rgba(15,40,75,.06);
}

.card-icon {
    font-size: 25px;
    margin-bottom: 10px;
}

.card-label {
    color: #718096;
    font-size: 12px;
}

.card-value {
    color: #061a35;
    font-size: 20px;
    font-weight: bold;
    margin-top: 5px;
}


/* =========================
   ACTIONS
========================= */

.section-title {
    margin: 25px 0 13px;
    font-size: 18px;
    color: #061a35;
}

.actions {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 13px;
}

.action {
    background: white;
    border: 1px solid #e7edf5;
    border-radius: 18px;
    padding: 18px;
    color: #12233f;
    box-shadow: 0 5px 18px rgba(15,40,75,.05);
    transition: .2s;
}

.action:hover {
    transform: translateY(-2px);
    box-shadow: 0 9px 25px rgba(15,40,75,.10);
}

.action-icon {
    font-size: 28px;
    margin-bottom: 10px;
}

.action-name {
    font-weight: bold;
    font-size: 14px;
}

.action-description {
    margin-top: 5px;
    font-size: 11px;
    color: #718096;
}


/* =========================
   MENU COMPLEMENTAIRE
========================= */

.menu-list {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
}

.menu-item {
    background: #eaf4ff;
    color: #075dcc;
    padding: 15px;
    border-radius: 15px;
    font-weight: bold;
    font-size: 13px;
}

.menu-item:hover {
    background: #dceeff;
}


/* =========================
   DECONNEXION
========================= */

.logout {
    display: block;
    text-align: center;
    margin-top: 25px;
    padding: 14px;
    border-radius: 14px;
    background: #fff0f0;
    color: #c62828;
    font-weight: bold;
    font-size: 14px;
}


/* =========================
   MOBILE
========================= */

@media (max-width: 800px) {

    .cards {
        grid-template-columns: repeat(2, 1fr);
    }

    .actions {
        grid-template-columns: repeat(2, 1fr);
    }

    .menu-list {
        grid-template-columns: 1fr 1fr;
    }

}

@media (max-width: 480px) {

    .header {
        padding: 19px 15px 24px;
        border-radius: 0 0 23px 23px;
    }

    .brand {
        font-size: 18px;
    }

    .welcome h1 {
        font-size: 21px;
    }

    .container {
        padding: 16px 12px 90px;
    }

    .main-result {
        padding: 19px;
        border-radius: 19px;
    }

    .result-value {
        font-size: 26px;
    }

    .cards {
        gap: 10px;
    }

    .card {
        padding: 15px 13px;
        border-radius: 16px;
    }

    .card-icon {
        font-size: 22px;
    }

    .card-value {
        font-size: 17px;
    }

    .actions {
        gap: 10px;
    }

    .action {
        padding: 16px 13px;
        border-radius: 16px;
    }

    .action-icon {
        font-size: 25px;
    }

    .action-name {
        font-size: 13px;
    }

    .menu-list {
        gap: 9px;
    }

    .menu-item {
        padding: 13px 11px;
        font-size: 12px;
    }

}

</style>

</head>

<body>

<header class="header">

```
<div class="header-content">

    <div class="top-line">

        <div class="brand">
            💼 LAMBEMAH GESTION
        </div>

        <div class="role">
            <?= htmlspecialchars($role) ?>
        </div>

    </div>

    <div class="welcome">

        <small>Bonjour 👋</small>

        <h1>
            <?= htmlspecialchars($nom) ?>
        </h1>

    </div>

</div>
```

</header>

<main class="container">

```
<!-- RESULTAT -->

<div class="main-result">

    <div class="result-title">
        💎 Résultat actuel
    </div>

    <div class="result-value">
        <?= afficherMontant($resultat) ?>
    </div>

    <div class="result-description">
        Entrées : <?= afficherMontant($total_entrees) ?>
        · Dépenses : <?= afficherMontant($montant_depenses) ?>
    </div>

</div>


<!-- CARTES -->

<div class="cards">

    <div class="card">

        <div class="card-icon">
            🛍️
        </div>

        <div class="card-label">
            Ventes
        </div>

        <div class="card-value">
            <?= afficherMontant($montant_ventes) ?>
        </div>

    </div>


    <div class="card">

        <div class="card-icon">
            💰
        </div>

        <div class="card-label">
            Recettes
        </div>

        <div class="card-value">
            <?= afficherMontant($montant_recettes) ?>
        </div>

    </div>


    <div class="card">

        <div class="card-icon">
            💸
        </div>

        <div class="card-label">
            Dépenses
        </div>

        <div class="card-value">
            <?= afficherMontant($montant_depenses) ?>
        </div>

    </div>


    <div class="card">

        <div class="card-icon">
            📦
        </div>

        <div class="card-label">
            Stock
        </div>

        <div class="card-value">
            <?= $stock_total ?>
        </div>

    </div>

</div>


<!-- ACTIONS -->

<h2 class="section-title">
    ⚡ Actions rapides
</h2>


<div class="actions">


    <a href="produits.php" class="action">

        <div class="action-icon">
            👕
        </div>

        <div class="action-name">
            Produits
        </div>

        <div class="action-description">
            Gérer les T-shirts et articles
        </div>

    </a>


    <a href="ventes.php" class="action">

        <div class="action-icon">
            🛒
        </div>

        <div class="action-name">
            Ventes
        </div>

        <div class="action-description">
            Enregistrer une vente
        </div>

    </a>


    <a href="prestations.php" class="action">

        <div class="action-icon">
            🖨️
        </div>

        <div class="action-name">
            Prestations
        </div>

        <div class="action-description">
            DTF, impression et presse
        </div>

    </a>


    <a href="recettes.php" class="action">

        <div class="action-icon">
            💰
        </div>

        <div class="action-name">
            Recettes
        </div>

        <div class="action-description">
            Enregistrer une entrée
        </div>

    </a>


    <a href="depenses.php" class="action">

        <div class="action-icon">
            💸
        </div>

        <div class="action-name">
            Dépenses
        </div>

        <div class="action-description">
            Suivre les sorties
        </div>

    </a>


    <a href="statistiques.php" class="action">

        <div class="action-icon">
            📊
        </div>

        <div class="action-name">
            Statistiques
        </div>

        <div class="action-description">
            Voir les performances
        </div>

    </a>


    <a href="utilisateurs.php" class="action">

        <div class="action-icon">
            👥
        </div>

        <div class="action-name">
            Utilisateurs
        </div>

        <div class="action-description">
            Gérer les accès
        </div>

    </a>

</div>


<!-- INFORMATIONS -->

<h2 class="section-title">
    📋 Gestion
</h2>

<div class="menu-list">

    <a href="produits.php" class="menu-item">
        📦 <?= $total_produits ?> produit(s)
    </a>

    <a href="ventes.php" class="menu-item">
        🛍️ <?= $total_ventes ?> vente(s)
    </a>

    <a href="statistiques.php" class="menu-item">
        📈 Voir les statistiques
    </a>

</div>


<!-- DECONNEXION -->

<a href="index.php?logout=1" class="logout">
    🚪 Se déconnecter
</a>
```

</main>

</body>
</html>
