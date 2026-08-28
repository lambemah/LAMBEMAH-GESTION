<?php
session_start();
require_once "config.php";

if (!isset($_SESSION["id"])) {
    header("Location: index.php");
    exit;
}

$nom  = $_SESSION["nom"] ?? "Utilisateur";
$role = $_SESSION["role"] ?? "lecture";

$message = "";
$type = "";

/* =========================================================
   AJOUT D'UNE VENTE
   - vérifie le produit
   - vérifie le stock
   - enregistre la vente
   - diminue le stock
   - ajoute automatiquement la vente aux recettes
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["ajouter_vente"])) {

    $produit_id = (int)($_POST["produit_id"] ?? 0);
    $quantite = (int)($_POST["quantite"] ?? 0);
    $prix_unitaire = (float)($_POST["prix_unitaire"] ?? 0);
    $description = trim($_POST["description"] ?? "");

    if ($produit_id <= 0) {

        $message = "Veuillez sélectionner un produit.";
        $type = "error";

    } elseif ($quantite <= 0) {

        $message = "La quantité doit être supérieure à zéro.";
        $type = "error";

    } elseif ($prix_unitaire <= 0) {

        $message = "Le prix unitaire doit être supérieur à zéro.";
        $type = "error";

    } else {

        /* Récupération du produit */
        $stmt = $conn->prepare(
            "SELECT id, nom, prix_vente, stock
             FROM produits
             WHERE id = ?
             LIMIT 1"
        );

        $stmt->bind_param("i", $produit_id);
        $stmt->execute();

        $result = $stmt->get_result();
        $produit = $result->fetch_assoc();

        $stmt->close();

        if (!$produit) {

            $message = "Produit introuvable.";
            $type = "error";

        } elseif ((int)$produit["stock"] < $quantite) {

            $message =
                "Stock insuffisant. Stock disponible : "
                . (int)$produit["stock"]
                . ".";

            $type = "error";

        } else {

            $montant = $quantite * $prix_unitaire;

            /*
             * Transaction :
             * 1. vente
             * 2. diminution stock
             * 3. recette
             */

            $conn->begin_transaction();

            try {

                /* -----------------------------------------
                   1. ENREGISTRER LA VENTE
                ----------------------------------------- */

                $stmt = $conn->prepare(
                    "INSERT INTO ventes
                    (produit_id, quantite, prix_unitaire, montant, description)
                    VALUES (?, ?, ?, ?, ?)"
                );

                if (!$stmt) {
                    throw new Exception("Erreur préparation vente.");
                }

                $stmt->bind_param(
                    "iidds",
                    $produit_id,
                    $quantite,
                    $prix_unitaire,
                    $montant,
                    $description
                );

                if (!$stmt->execute()) {
                    throw new Exception("Impossible d'enregistrer la vente.");
                }

                $stmt->close();


                /* -----------------------------------------
                   2. DIMINUER LE STOCK
                ----------------------------------------- */

                $stmt = $conn->prepare(
                    "UPDATE produits
                     SET stock = stock - ?
                     WHERE id = ?
                     AND stock >= ?"
                );

                if (!$stmt) {
                    throw new Exception("Erreur préparation stock.");
                }

                $stmt->bind_param(
                    "iii",
                    $quantite,
                    $produit_id,
                    $quantite
                );

                if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                    throw new Exception("Impossible de mettre à jour le stock.");
                }

                $stmt->close();


                /* -----------------------------------------
                   3. AJOUTER AUTOMATIQUEMENT LA RECETTE
                ----------------------------------------- */

                $libelle_recette =
                    "Vente - " . $produit["nom"];

                $description_recette =
                    "Vente de "
                    . $quantite
                    . " unité(s) de "
                    . $produit["nom"]
                    . "."
                    . ($description !== ""
                        ? " Note : " . $description
                        : "");

                $stmt = $conn->prepare(
                    "INSERT INTO recettes
                    (libelle, montant, description)
                    VALUES (?, ?, ?)"
                );

                if (!$stmt) {
                    throw new Exception("Erreur préparation recette.");
                }

                $stmt->bind_param(
                    "sds",
                    $libelle_recette,
                    $montant,
                    $description_recette
                );

                if (!$stmt->execute()) {
                    throw new Exception("Impossible d'enregistrer la recette.");
                }

                $stmt->close();


                /* -----------------------------------------
                   VALIDATION
                ----------------------------------------- */

                $conn->commit();

                $message =
                    "✅ Vente enregistrée : "
                    . $produit["nom"]
                    . " × "
                    . $quantite
                    . " — "
                    . argent($montant);

                $type = "success";

            } catch (Exception $e) {

                $conn->rollback();

                $message =
                    "❌ La vente n'a pas pu être enregistrée.";

                $type = "error";
            }
        }
    }
}


/* =========================================================
   STATISTIQUES DES VENTES
========================================================= */

$total_ventes = 0;
$nombre_ventes = 0;

$result = $conn->query(
    "SELECT
        COALESCE(SUM(montant), 0) AS total,
        COUNT(*) AS nombre
     FROM ventes"
);

if ($result) {

    $data = $result->fetch_assoc();

    $total_ventes = (float)$data["total"];
    $nombre_ventes = (int)$data["nombre"];
}


/* =========================================================
   PRODUITS DISPONIBLES
========================================================= */

$produits = $conn->query(
    "SELECT
        id,
        nom,
        categorie,
        prix_vente,
        stock
     FROM produits
     WHERE stock > 0
     ORDER BY nom ASC"
);


/* =========================================================
   HISTORIQUE
========================================================= */

$ventes = $conn->query(
    "SELECT
        v.id,
        v.produit_id,
        v.quantite,
        v.prix_unitaire,
        v.montant,
        v.description,
        v.date_vente,
        p.nom AS produit_nom
     FROM ventes v
     LEFT JOIN produits p
        ON p.id = v.produit_id
     ORDER BY v.id DESC
     LIMIT 50"
);


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

?>

<!DOCTYPE html>

<html lang="fr">

<head>

<meta charset="UTF-8">

<meta
name="viewport"
content="width=device-width, initial-scale=1.0"

>

<title>Ventes - LAMBEMAH GESTION</title>

<style>

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
   STATISTIQUES
========================================================= */

.cards {
    display: grid;

    grid-template-columns:
        repeat(2, 1fr);

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

.stat small {
    color: #8c9aa6;
    font-size: 10px;
}

.stat h2 {
    margin-top: 8px;

    font-size: 21px;

    color: #0c79b5;
}


/* =========================================================
   CONTENU
========================================================= */

.content {
    display: grid;

    grid-template-columns:
        360px 1fr;

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
    color: #8b99a5;

    font-size: 11px;

    margin-bottom: 18px;
}


/* =========================================================
   MESSAGE
========================================================= */

.message {
    padding: 12px;

    border-radius: 10px;

    margin-bottom: 15px;

    font-size: 11px;
}

.success {
    background: #eafaf2;
    color: #168653;
}

.error {
    background: #fff0f0;
    color: #d33;
}


/* =========================================================
   FORMULAIRE
========================================================= */

.group {
    margin-bottom: 14px;
}

label {
    display: block;

    font-size: 10px;

    font-weight: bold;

    color: #536675;

    margin-bottom: 6px;
}

input,
select,
textarea {
    width: 100%;

    padding: 12px;

    border:
        1px solid #dfe8ee;

    border-radius: 10px;

    background: #fbfdff;

    outline: none;

    font-family: Arial, sans-serif;
}

input:focus,
select:focus,
textarea:focus {
    border-color: #20b8ff;
}

textarea {
    min-height: 80px;
    resize: vertical;
}

.product-info {
    margin-top: 8px;

    padding: 9px;

    border-radius: 9px;

    background: #eef9ff;

    color: #1679ad;

    font-size: 10px;

    display: none;
}

.montant {
    padding: 14px;

    border-radius: 12px;

    background:
        linear-gradient(
            135deg,
            #eef9ff,
            #f7fcff
        );

    color: #0878b7;

    font-size: 20px;

    font-weight: bold;

    text-align: center;
}

.btn-primary {
    width: 100%;

    border: 0;

    border-radius: 11px;

    padding: 13px;

    cursor: pointer;

    color: white;

    background:
        linear-gradient(
            135deg,
            #20b8ff,
            #1264c7
        );

    font-weight: bold;

    font-size: 13px;
}

.btn-primary:hover {
    opacity: .9;
}


/* =========================================================
   HISTORIQUE
========================================================= */

.sale {
    padding: 15px 0;

    border-bottom:
        1px solid #edf1f4;
}

.sale:first-child {
    padding-top: 0;
}

.sale-top {
    display: flex;

    justify-content: space-between;

    gap: 15px;
}

.sale-product {
    font-weight: bold;

    font-size: 13px;

    color: #20394c;
}

.sale-amount {
    color: #0878b7;

    font-weight: bold;

    font-size: 13px;

    white-space: nowrap;
}

.sale-info {
    display: flex;

    flex-wrap: wrap;

    gap: 7px;

    margin-top: 8px;
}

.badge {
    background: #f0f7fb;

    color: #607482;

    padding: 5px 8px;

    border-radius: 7px;

    font-size: 9px;
}

.sale-description {
    margin-top: 8px;

    padding: 8px;

    border-radius: 8px;

    background: #f8fafc;

    color: #647481;

    font-size: 10px;
}

.sale-date {
    margin-top: 8px;

    color: #9aa6ae;

    font-size: 9px;
}


/* =========================================================
   MOBILE
========================================================= */

@media(max-width:900px) {

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

    .cards {
        grid-template-columns: 1fr 1fr;

        gap: 9px;
    }

    .stat {
        padding: 14px;
    }

    .stat h2 {
        font-size: 17px;
    }

    .card {
        padding: 15px;

        border-radius: 16px;
    }

    .sale-top {
        align-items: flex-start;
    }

    .sale-product {
        font-size: 12px;
    }

    .sale-amount {
        font-size: 12px;
    }

}

</style>

</head>

<body>

<aside class="sidebar">

```
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
        <a href="ventes.php" class="active">
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
        <a href="statistiques.php">
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
```

</aside>

<main class="main">

```
<div class="header">

    <div>

        <h1>
            💰 Ventes
        </h1>

        <p>
            Enregistre tes ventes et suis ton chiffre d'affaires.
        </p>

    </div>

    <div class="avatar">

        <?= strtoupper(
            substr($nom, 0, 1)
        ) ?>

    </div>

</div>


<?php if ($message !== ""): ?>

    <div class="message <?= htmlspecialchars($type) ?>">

        <?= htmlspecialchars($message) ?>

    </div>

<?php endif; ?>


<div class="cards">

    <div class="stat">

        <small>
            TOTAL DES VENTES
        </small>

        <h2>
            <?= argent($total_ventes) ?>
        </h2>

    </div>


    <div class="stat">

        <small>
            NOMBRE DE VENTES
        </small>

        <h2>
            <?= $nombre_ventes ?>
        </h2>

    </div>

</div>


<div class="content">


    <div class="card">

        <h2>
            ➕ Nouvelle vente
        </h2>

        <p>
            Choisis un produit disponible dans ton stock.
        </p>


        <form method="POST">

            <input
                type="hidden"
                name="ajouter_vente"
                value="1"
            >


            <div class="group">

                <label>
                    PRODUIT
                </label>

                <select
                    name="produit_id"
                    id="produit_id"
                    required
                    onchange="produitSelectionne()"
                >

                    <option value="">
                        -- Sélectionner un produit --
                    </option>


                    <?php if ($produits && $produits->num_rows > 0): ?>

                        <?php while ($p = $produits->fetch_assoc()): ?>

                            <option
                                value="<?= (int)$p["id"] ?>"
                                data-prix="<?= htmlspecialchars($p["prix_vente"]) ?>"
                                data-stock="<?= (int)$p["stock"] ?>"
                            >

                                <?= htmlspecialchars($p["nom"]) ?>

                                <?php if (!empty($p["categorie"])): ?>

                                    — <?= htmlspecialchars($p["categorie"]) ?>

                                <?php endif; ?>

                                — Stock :
                                <?= (int)$p["stock"] ?>

                            </option>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <option value="" disabled>
                            Aucun produit disponible
                        </option>

                    <?php endif; ?>

                </select>


                <div
                    class="product-info"
                    id="productInfo"
                ></div>

            </div>


            <div class="group">

                <label>
                    QUANTITÉ
                </label>

                <input
                    type="number"
                    name="quantite"
                    id="quantite"
                    value="1"
                    min="1"
                    required
                    oninput="calculer()"
                >

            </div>


            <div class="group">

                <label>
                    PRIX UNITAIRE
                </label>

                <input
                    type="number"
                    name="prix_unitaire"
                    id="prix_unitaire"
                    min="1"
                    step="500"
                    required
                    oninput="calculer()"
                >

            </div>


            <div class="group">

                <label>
                    MONTANT TOTAL
                </label>

                <div
                    class="montant"
                    id="montant"
                >
                    0 FG
                </div>

            </div>


            <div class="group">

                <label>
                    DESCRIPTION
                </label>

                <textarea
                    name="description"
                    placeholder="Ex : T-shirts vendus au client..."
                ></textarea>

            </div>


            <button
                type="submit"
                class="btn-primary"
            >
                💾 Enregistrer la vente
            </button>

        </form>

    </div>


    <div class="card">

        <h2>
            📋 Historique des ventes
        </h2>

        <p>
            Les 50 dernières ventes enregistrées.
        </p>


        <?php if ($ventes && $ventes->num_rows > 0): ?>

            <?php while ($v = $ventes->fetch_assoc()): ?>

                <div class="sale">

                    <div class="sale-top">

                        <div class="sale-product">

                            👕
                            <?= htmlspecialchars(
                                $v["produit_nom"]
                                ?? "Produit supprimé"
                            ) ?>

                        </div>

                        <div class="sale-amount">

                            <?= argent(
                                $v["montant"]
                            ) ?>

                        </div>

                    </div>


                    <div class="sale-info">

                        <span class="badge">

                            Quantité :
                            <?= (int)$v["quantite"] ?>

                        </span>


                        <span class="badge">

                            Prix unitaire :
                            <?= argent(
                                $v["prix_unitaire"]
                            ) ?>

                        </span>

                    </div>


                    <?php if (!empty($v["description"])): ?>

                        <div class="sale-description">

                            <?= htmlspecialchars(
                                $v["description"]
                            ) ?>

                        </div>

                    <?php endif; ?>


                    <div class="sale-date">

                        📅
                        <?= htmlspecialchars(
                            $v["date_vente"]
                        ) ?>

                    </div>

                </div>

            <?php endwhile; ?>

        <?php else: ?>

            <div
                style="
                text-align:center;
                padding:35px 10px;
                color:#8998a5;
                font-size:12px;
                "
            >

                🛒

                <br><br>

                Aucune vente enregistrée pour le moment.

            </div>

        <?php endif; ?>

    </div>

</div>
```

</main>

<script>

function produitSelectionne() {

    const select =
        document.getElementById("produit_id");

    const option =
        select.options[select.selectedIndex];

    const prix =
        option.getAttribute("data-prix");

    const stock =
        option.getAttribute("data-stock");

    const prixInput =
        document.getElementById("prix_unitaire");

    const info =
        document.getElementById("productInfo");

    if (prix) {

        prixInput.value =
            parseFloat(prix);

        info.style.display = "block";

        info.innerHTML =
            "📦 Stock disponible : <strong>"
            + stock
            + "</strong>";

        calculer();

    } else {

        prixInput.value = "";

        info.style.display = "none";

        document.getElementById("montant").innerText =
            "0 FG";
    }
}


function calculer() {

    const quantite =
        parseFloat(
            document.getElementById("quantite").value
        ) || 0;

    const prix =
        parseFloat(
            document.getElementById("prix_unitaire").value
        ) || 0;

    const total =
        quantite * prix;

    document.getElementById("montant").innerText =
        new Intl.NumberFormat("fr-FR").format(total)
        + " FG";
}

</script>

</body>
</html>
