```php
<?php
session_start();
require_once "config.php";

if (!isset($_SESSION["id"])) {
    header("Location: index.php");
    exit;
}

/* SUPPRESSION */
if (isset($_GET["supprimer"])) {
    $id = (int) $_GET["supprimer"];

    $stmt = $conn->prepare("DELETE FROM produits WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    header("Location: produits.php");
    exit;
}

/* AJOUT */
$message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["ajouter"])) {

    $nom = trim($_POST["nom"] ?? "");
    $categorie = trim($_POST["categorie"] ?? "");
    $prix_achat = (float) ($_POST["prix_achat"] ?? 0);
    $prix_vente = (float) ($_POST["prix_vente"] ?? 0);
    $stock = (int) ($_POST["stock"] ?? 0);

    if ($nom === "") {
        $message = "Le nom du produit est obligatoire.";
    } else {

        $stmt = $conn->prepare(
            "INSERT INTO produits
            (nom, categorie, prix_achat, prix_vente, stock)
            VALUES (?, ?, ?, ?, ?)"
        );

        $stmt->bind_param(
            "ssddi",
            $nom,
            $categorie,
            $prix_achat,
            $prix_vente,
            $stock
        );

        if ($stmt->execute()) {
            header("Location: produits.php");
            exit;
        } else {
            $message = "Erreur lors de l'ajout du produit.";
        }

        $stmt->close();
    }
}

/* MODIFICATION */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["modifier"])) {

    $id = (int) $_POST["id"];
    $nom = trim($_POST["nom"] ?? "");
    $categorie = trim($_POST["categorie"] ?? "");
    $prix_achat = (float) ($_POST["prix_achat"] ?? 0);
    $prix_vente = (float) ($_POST["prix_vente"] ?? 0);
    $stock = (int) ($_POST["stock"] ?? 0);

    $stmt = $conn->prepare(
        "UPDATE produits
         SET nom = ?, categorie = ?, prix_achat = ?,
             prix_vente = ?, stock = ?
         WHERE id = ?"
    );

    $stmt->bind_param(
        "ssddii",
        $nom,
        $categorie,
        $prix_achat,
        $prix_vente,
        $stock,
        $id
    );

    $stmt->execute();
    $stmt->close();

    header("Location: produits.php");
    exit;
}

/* RECHERCHE */
$recherche = trim($_GET["recherche"] ?? "");

if ($recherche !== "") {

    $motif = "%" . $recherche . "%";

    $stmt = $conn->prepare(
        "SELECT *
         FROM produits
         WHERE nom LIKE ?
         OR categorie LIKE ?
         ORDER BY id DESC"
    );

    $stmt->bind_param("ss", $motif, $motif);
    $stmt->execute();

    $produits = $stmt->get_result();

} else {

    $produits = $conn->query(
        "SELECT *
         FROM produits
         ORDER BY id DESC"
    );
}

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

<title>LAMBEMAH GESTION - Produits</title>

<style>

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family: Arial, Helvetica, sans-serif;
    background: #f5faff;
    color: #263746;
}


/* SIDEBAR */

.sidebar {
    position: fixed;
    left: 0;
    top: 0;
    bottom: 0;
    width: 245px;
    background: linear-gradient(180deg, #53c5eb, #168dcc);
    color: white;
    padding: 25px 15px;
}

.brand {
    padding: 5px 12px 28px;
}

.brand-icon {
    width: 48px;
    height: 48px;
    background: rgba(255,255,255,.20);
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 23px;
    margin-bottom: 12px;
}

.brand h2 {
    font-size: 21px;
}

.brand span {
    font-size: 11px;
    opacity: .8;
}

.nav {
    list-style: none;
}

.nav li {
    margin: 5px 0;
}

.nav a {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 13px 14px;
    border-radius: 12px;
    color: white;
    text-decoration: none;
    font-size: 14px;
}

.nav a:hover,
.nav a.active {
    background: rgba(255,255,255,.20);
}

.nav-icon {
    width: 25px;
    text-align: center;
    font-size: 17px;
}

.sidebar-bottom {
    position: absolute;
    left: 15px;
    right: 15px;
    bottom: 20px;
}

.logout {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 13px 14px;
    border-radius: 12px;
    background: rgba(255,255,255,.12);
    color: white;
    text-decoration: none;
    font-size: 14px;
}


/* MAIN */

.main {
    margin-left: 245px;
    padding: 28px 30px 50px;
}

.header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
}

.header h1 {
    font-size: 26px;
    margin-bottom: 5px;
}

.header p {
    color: #7d8c95;
    font-size: 13px;
}

.profile {
    display: flex;
    align-items: center;
    gap: 10px;
    background: white;
    padding: 8px 14px 8px 8px;
    border-radius: 30px;
    box-shadow: 0 4px 15px rgba(0,0,0,.05);
}

.avatar {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: #dff5ff;
    color: #168dcc;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
}


/* TOP */

.top-card {
    background: linear-gradient(135deg,#55c7ee,#168dcc);
    color: white;
    border-radius: 20px;
    padding: 24px;
    margin-bottom: 22px;
    box-shadow: 0 12px 30px rgba(24,141,204,.16);
}

.top-card h2 {
    font-size: 21px;
    margin-bottom: 7px;
}

.top-card p {
    opacity: .9;
    font-size: 13px;
}


/* FORM */

.content {
    display: grid;
    grid-template-columns: 330px 1fr;
    gap: 20px;
}

.card {
    background: white;
    border-radius: 18px;
    padding: 22px;
    box-shadow: 0 5px 20px rgba(32,88,112,.06);
}

.card h2 {
    font-size: 17px;
    margin-bottom: 20px;
}

.form-group {
    margin-bottom: 15px;
}

.form-group label {
    display: block;
    margin-bottom: 7px;
    font-size: 12px;
    font-weight: bold;
    color: #51616b;
}

.form-group input,
.form-group select {
    width: 100%;
    padding: 12px;
    border: 1px solid #dbe7ed;
    border-radius: 10px;
    outline: none;
    font-size: 13px;
}

.form-group input:focus,
.form-group select:focus {
    border-color: #35b3e4;
}

.btn {
    width: 100%;
    border: none;
    border-radius: 10px;
    padding: 13px;
    background: linear-gradient(135deg,#55c7ee,#168dcc);
    color: white;
    font-weight: bold;
    cursor: pointer;
}

.message {
    background: #fff0f0;
    color: #c62828;
    padding: 10px;
    border-radius: 9px;
    margin-bottom: 15px;
    font-size: 12px;
}


/* SEARCH */

.search {
    display: flex;
    gap: 10px;
    margin-bottom: 18px;
}

.search input {
    flex: 1;
    padding: 12px;
    border: 1px solid #dbe7ed;
    border-radius: 10px;
    outline: none;
}

.search button {
    border: none;
    background: #168dcc;
    color: white;
    border-radius: 10px;
    padding: 0 18px;
    font-weight: bold;
    cursor: pointer;
}


/* TABLE */

.table-wrapper {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 700px;
}

th {
    text-align: left;
    font-size: 11px;
    color: #89969d;
    padding: 12px 10px;
    border-bottom: 1px solid #edf3f6;
}

td {
    padding: 14px 10px;
    border-bottom: 1px solid #edf3f6;
    font-size: 13px;
}

.product-name {
    font-weight: bold;
}

.category {
    color: #7e8c94;
    font-size: 11px;
}

.stock {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 20px;
    background: #e8f8ff;
    color: #168dcc;
    font-weight: bold;
    font-size: 11px;
}

.stock.low {
    background: #fff3df;
    color: #df8b1d;
}

.stock.zero {
    background: #ffecec;
    color: #d93025;
}

.actions {
    display: flex;
    gap: 7px;
}

.actions a {
    text-decoration: none;
    padding: 7px 9px;
    border-radius: 8px;
    font-size: 11px;
}

.edit {
    background: #e8f8ff;
    color: #168dcc;
}

.delete {
    background: #fff0f0;
    color: #d93025;
}

.empty {
    text-align: center;
    padding: 45px 15px;
    color: #9aa6ad;
}


/* MOBILE */

@media(max-width:950px) {

    .content {
        grid-template-columns: 1fr;
    }

}

@media(max-width:750px) {

    .sidebar {
        position: relative;
        width: 100%;
        height: auto;
        padding: 15px;
    }

    .brand {
        padding-bottom: 15px;
    }

    .nav {
        display: grid;
        grid-template-columns: repeat(3,1fr);
        gap: 5px;
    }

    .nav li {
        margin: 0;
    }

    .nav a {
        justify-content: center;
        flex-direction: column;
        gap: 4px;
        padding: 9px 5px;
        font-size: 10px;
    }

    .sidebar-bottom {
        position: static;
        margin-top: 8px;
    }

    .main {
        margin-left: 0;
        padding: 18px;
    }

    .profile div {
        display: none;
    }

}

</style>

</head>

<body>


<!-- SIDEBAR -->

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
            <a href="index.php">
                <span class="nav-icon">🏠</span>
                Accueil
            </a>
        </li>

        <li>
            <a href="produits.php" class="active">
                <span class="nav-icon">📦</span>
                Produits
            </a>
        </li>

        <li>
            <a href="ventes.php">
                <span class="nav-icon">💰</span>
                Ventes
            </a>
        </li>

        <li>
            <a href="prestations.php">
                <span class="nav-icon">🖨️</span>
                Prestations
            </a>
        </li>

        <li>
            <a href="depenses.php">
                <span class="nav-icon">💸</span>
                Dépenses
            </a>
        </li>

        <li>
            <a href="statistiques.php">
                <span class="nav-icon">📊</span>
                Statistiques
            </a>
        </li>

        <li>
            <a href="utilisateurs.php">
                <span class="nav-icon">👥</span>
                Utilisateurs
            </a>
        </li>

    </ul>


    <div class="sidebar-bottom">

        <a class="logout" href="index.php?logout=1">
            🚪 Déconnexion
        </a>

    </div>

</aside>


<!-- MAIN -->

<main class="main">


    <div class="header">

        <div>

            <h1>Produits 📦</h1>

            <p>
                Gérez vos T-shirts, chemises, pulls, képis et autres articles.
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

    </div>


    <div class="top-card">

        <h2>
            Gestion de votre stock
        </h2>

        <p>
            Ajoutez vos articles, définissez vos prix et suivez votre stock en temps réel.
        </p>

    </div>


    <div class="content">


        <!-- AJOUT -->

        <div class="card">

            <h2>
                ➕ Nouveau produit
            </h2>


            <?php if ($message !== ""): ?>

                <div class="message">
                    <?= htmlspecialchars($message) ?>
                </div>

            <?php endif; ?>


            <form method="POST">

                <input
                    type="hidden"
                    name="ajouter"
                    value="1"
                >


                <div class="form-group">

                    <label>
                        Nom du produit
                    </label>

                    <input
                        type="text"
                        name="nom"
                        placeholder="Ex : T-shirt adulte"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>
                        Catégorie
                    </label>

                    <input
                        type="text"
                        name="categorie"
                        placeholder="Ex : T-shirts"
                    >

                </div>


                <div class="form-group">

                    <label>
                        Prix d'achat
                    </label>

                    <input
                        type="number"
                        name="prix_achat"
                        min="0"
                        placeholder="0"
                    >

                </div>


                <div class="form-group">

                    <label>
                        Prix de vente
                    </label>

                    <input
                        type="number"
                        name="prix_vente"
                        min="0"
                        placeholder="0"
                    >

                </div>


                <div class="form-group">

                    <label>
                        Stock initial
                    </label>

                    <input
                        type="number"
                        name="stock"
                        min="0"
                        value="0"
                    >

                </div>


                <button class="btn" type="submit">

                    Ajouter le produit

                </button>

            </form>

        </div>


        <!-- LISTE -->

        <div class="card">

            <h2>
                📋 Mes produits
            </h2>


            <form method="GET" class="search">

                <input
                    type="text"
                    name="recherche"
                    placeholder="Rechercher un produit..."
                    value="<?= htmlspecialchars($recherche) ?>"
                >

                <button type="submit">
                    🔎
                </button>

            </form>


            <div class="table-wrapper">

                <?php if ($produits && $produits->num_rows > 0): ?>

                    <table>

                        <thead>

                            <tr>

                                <th>PRODUIT</th>
                                <th>CATÉGORIE</th>
                                <th>ACHAT</th>
                                <th>VENTE</th>
                                <th>STOCK</th>
                                <th>ACTIONS</th>

                            </tr>

                        </thead>

                        <tbody>

                        <?php while ($produit = $produits->fetch_assoc()): ?>

                            <tr>

                                <td>

                                    <div class="product-name">

                                        <?= htmlspecialchars(
                                            $produit["nom"]
                                        ) ?>

                                    </div>

                                </td>


                                <td>

                                    <span class="category">

                                        <?= htmlspecialchars(
                                            $produit["categorie"] ?: "—"
                                        ) ?>

                                    </span>

                                </td>


                                <td>

                                    <?= gnf(
                                        $produit["prix_achat"]
                                    ) ?>

                                </td>


                                <td>

                                    <?= gnf(
                                        $produit["prix_vente"]
                                    ) ?>

                                </td>


                                <td>

                                    <?php

                                    $stock = (int)$produit["stock"];

                                    $classe = "";

                                    if ($stock == 0) {
                                        $classe = "zero";
                                    } elseif ($stock <= 5) {
                                        $classe = "low";
                                    }

                                    ?>

                                    <span class="stock <?= $classe ?>">

                                        <?= $stock ?>

                                    </span>

                                </td>


                                <td>

                                    <div class="actions">

                                        <a
                                            class="edit"
                                            href="produits.php?modifier=<?= $produit["id"] ?>"
                                        >
                                            ✏️
                                        </a>

                                        <a
                                            class="delete"
                                            href="produits.php?supprimer=<?= $produit["id"] ?>"
                                            onclick="return confirm('Voulez-vous vraiment supprimer ce produit ?')"
                                        >
                                            🗑️
                                        </a>

                                    </div>

                                </td>

                            </tr>

                        <?php endwhile; ?>

                        </tbody>

                    </table>

                <?php else: ?>

                    <div class="empty">

                        📦<br><br>

                        Aucun produit enregistré.

                    </div>

                <?php endif; ?>

            </div>

        </div>

    </div>

</main>

</body>

</html>
```
