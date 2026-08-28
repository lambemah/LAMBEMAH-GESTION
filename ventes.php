<?php
session_start();
require_once "config.php";

if (!isset($_SESSION["id"])) {
    header("Location: index.php");
    exit;
}

$message = "";
$type_message = "";

/* =========================
   AJOUT D'UNE VENTE
========================= */

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["ajouter_vente"])) {

    $produit_id = (int)($_POST["produit_id"] ?? 0);
    $quantite = (int)($_POST["quantite"] ?? 0);
    $prix_unitaire = (float)($_POST["prix_unitaire"] ?? 0);
    $description = trim($_POST["description"] ?? "");

    if ($produit_id <= 0 || $quantite <= 0 || $prix_unitaire <= 0) {

        $message = "Veuillez remplir correctement les informations.";
        $type_message = "error";

    } else {

        /* Vérifier le stock */
        $stmt = $conn->prepare("
            SELECT nom, stock
            FROM produits
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->bind_param("i", $produit_id);
        $stmt->execute();

        $result = $stmt->get_result();

        if ($result && $result->num_rows === 1) {

            $produit = $result->fetch_assoc();

            if ((int)$produit["stock"] < $quantite) {

                $message = "Stock insuffisant pour : " . $produit["nom"];
                $type_message = "error";

            } else {

                $montant = $quantite * $prix_unitaire;

                /* Ajouter la vente */
                $stmt_vente = $conn->prepare("
                    INSERT INTO ventes
                    (produit_id, quantite, prix_unitaire, montant, description)
                    VALUES (?, ?, ?, ?, ?)
                ");

                $stmt_vente->bind_param(
                    "iidds",
                    $produit_id,
                    $quantite,
                    $prix_unitaire,
                    $montant,
                    $description
                );

                if ($stmt_vente->execute()) {

                    /* Diminuer le stock */
                    $stmt_stock = $conn->prepare("
                        UPDATE produits
                        SET stock = stock - ?
                        WHERE id = ?
                    ");

                    $stmt_stock->bind_param(
                        "ii",
                        $quantite,
                        $produit_id
                    );

                    $stmt_stock->execute();
                    $stmt_stock->close();

                    $message = "Vente enregistrée avec succès.";
                    $type_message = "success";

                } else {

                    $message = "Impossible d'enregistrer la vente.";
                    $type_message = "error";
                }

                $stmt_vente->close();
            }

        } else {

            $message = "Produit introuvable.";
            $type_message = "error";
        }

        $stmt->close();
    }
}


/* =========================
   PRODUITS
========================= */

$produits = $conn->query("
    SELECT id, nom, categorie, prix_vente, stock
    FROM produits
    ORDER BY nom ASC
");


/* =========================
   HISTORIQUE DES VENTES
========================= */

$ventes = $conn->query("
    SELECT
        v.id,
        v.produit_id,
        v.quantite,
        v.prix_unitaire,
        v.montant,
        v.description,
        v.date_vente,
        p.nom AS produit_nom
    FROM ventes v
    LEFT JOIN produits p ON p.id = v.produit_id
    ORDER BY v.date_vente DESC
");


/* =========================
   TOTAL DES VENTES
========================= */

$total_ventes = 0;

$result_total = $conn->query("
    SELECT COALESCE(SUM(montant), 0) AS total
    FROM ventes
");

if ($result_total) {
    $data_total = $result_total->fetch_assoc();
    $total_ventes = (float)$data_total["total"];
}


function montant($nombre)
{
    return number_format((float)$nombre, 0, ',', ' ') . " FG";
}

?>

<!DOCTYPE html>

<html lang="fr">

<head>

<meta charset="UTF-8">

<meta name="viewport"
content="width=device-width, initial-scale=1.0, maximum-scale=1.0">

<title>Ventes - LAMBEMAH GESTION</title>

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
    color: #10233f;
    min-height: 100vh;
}

button,
input,
select,
textarea {
    font-family: inherit;
}


/* =========================
   HEADER
========================= */

.header {
    background: linear-gradient(135deg, #061a35, #0869d7);
    color: white;
    padding: 16px;
    position: sticky;
    top: 0;
    z-index: 1000;
    box-shadow: 0 5px 20px rgba(6,26,53,.20);
}

.header-inner {
    max-width: 1150px;
    margin: auto;
    display: flex;
    align-items: center;
    gap: 13px;
}

.menu-button {
    width: 44px;
    height: 44px;
    border: none;
    border-radius: 13px;
    background: rgba(255,255,255,.14);
    color: white;
    font-size: 23px;
    cursor: pointer;
    flex-shrink: 0;
}

.brand {
    flex: 1;
}

.brand strong {
    display: block;
    font-size: 18px;
}

.brand span {
    display: block;
    font-size: 11px;
    opacity: .78;
    margin-top: 3px;
}


/* =========================
   MENU
========================= */

.sidebar {
    position: fixed;
    top: 0;
    left: -290px;
    width: 275px;
    height: 100vh;
    background: #061a35;
    z-index: 2000;
    padding: 22px 15px;
    transition: .25s ease;
    box-shadow: 8px 0 30px rgba(0,0,0,.18);
    overflow-y: auto;
}

.sidebar.active {
    left: 0;
}

.sidebar-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    color: white;
    margin-bottom: 25px;
}

.sidebar-title {
    font-size: 19px;
    font-weight: bold;
}

.close-menu {
    border: none;
    background: rgba(255,255,255,.10);
    color: white;
    width: 38px;
    height: 38px;
    border-radius: 11px;
    font-size: 20px;
}

.nav-link {
    display: flex;
    align-items: center;
    gap: 12px;
    color: white;
    padding: 14px 13px;
    border-radius: 13px;
    text-decoration: none;
    margin-bottom: 7px;
    font-size: 14px;
}

.nav-link:hover,
.nav-link.active {
    background: #0877e8;
}

.nav-icon {
    width: 25px;
    text-align: center;
    font-size: 19px;
}

.overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.45);
    z-index: 1500;
}

.overlay.active {
    display: block;
}


/* =========================
   CONTENU
========================= */

.container {
    width: 100%;
    max-width: 1150px;
    margin: auto;
    padding: 22px 15px 70px;
}


/* =========================
   TITRE
========================= */

.page-title {
    margin-bottom: 18px;
}

.page-title h1 {
    color: #061a35;
    font-size: 25px;
}

.page-title p {
    color: #718096;
    font-size: 13px;
    margin-top: 5px;
}


/* =========================
   CARTE TOTAL
========================= */

.total-card {
    background: linear-gradient(135deg, #061a35, #0877e8);
    color: white;
    border-radius: 21px;
    padding: 22px;
    margin-bottom: 20px;
    box-shadow: 0 8px 25px rgba(6,26,53,.17);
}

.total-card small {
    opacity: .8;
    font-size: 12px;
}

.total-card strong {
    display: block;
    font-size: 28px;
    margin-top: 7px;
}


/* =========================
   FORMULAIRE
========================= */

.form-card {
    background: white;
    border-radius: 21px;
    padding: 21px;
    box-shadow: 0 6px 22px rgba(15,40,75,.07);
    border: 1px solid #e6edf5;
    margin-bottom: 22px;
}

.form-card h2 {
    color: #061a35;
    font-size: 18px;
    margin-bottom: 18px;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
}

.form-group.full {
    grid-column: 1 / -1;
}

label {
    display: block;
    color: #34445c;
    font-size: 13px;
    font-weight: bold;
    margin-bottom: 7px;
}

input,
select,
textarea {
    width: 100%;
    border: 1px solid #d9e2ec;
    border-radius: 12px;
    padding: 13px;
    outline: none;
    background: #fbfdff;
    color: #172b4d;
    font-size: 14px;
}

textarea {
    min-height: 80px;
    resize: vertical;
}

input:focus,
select:focus,
textarea:focus {
    border-color: #0877e8;
    box-shadow: 0 0 0 3px rgba(8,119,232,.09);
}

.montant-preview {
    background: #edf6ff;
    color: #075dcc;
    padding: 14px;
    border-radius: 12px;
    font-weight: bold;
    margin-top: 10px;
}

.submit-button {
    border: none;
    background: linear-gradient(135deg, #0877e8, #0755c9);
    color: white;
    padding: 14px 20px;
    border-radius: 12px;
    font-weight: bold;
    cursor: pointer;
    width: 100%;
    margin-top: 15px;
}


/* =========================
   MESSAGES
========================= */

.message {
    padding: 13px;
    border-radius: 12px;
    margin-bottom: 17px;
    font-size: 13px;
    font-weight: bold;
}

.success {
    background: #e8f8ef;
    color: #147a43;
}

.error {
    background: #fff0f0;
    color: #c62828;
}


/* =========================
   HISTORIQUE
========================= */

.history {
    background: white;
    border-radius: 21px;
    padding: 21px;
    box-shadow: 0 6px 22px rgba(15,40,75,.07);
    border: 1px solid #e6edf5;
}

.history h2 {
    font-size: 18px;
    color: #061a35;
    margin-bottom: 17px;
}

.sale {
    border: 1px solid #e8eef5;
    border-radius: 15px;
    padding: 15px;
    margin-bottom: 11px;
}

.sale-top {
    display: flex;
    justify-content: space-between;
    gap: 10px;
}

.sale-product {
    font-weight: bold;
    color: #061a35;
}

.sale-amount {
    color: #0877e8;
    font-weight: bold;
    white-space: nowrap;
}

.sale-info {
    margin-top: 8px;
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
}

.badge {
    background: #edf4fb;
    color: #52647c;
    padding: 6px 8px;
    border-radius: 8px;
    font-size: 11px;
}

.sale-date {
    color: #8a98aa;
    font-size: 11px;
    margin-top: 9px;
}


/* =========================
   DESKTOP TABLE
========================= */

.table-container {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
}

th,
td {
    padding: 13px 10px;
    text-align: left;
    border-bottom: 1px solid #edf1f5;
    font-size: 13px;
}

th {
    color: #52647c;
    background: #f7faff;
}


/* =========================
   MOBILE
========================= */

@media (max-width: 650px) {

    .header {
        padding: 13px;
    }

    .brand strong {
        font-size: 16px;
    }

    .brand span {
        font-size: 10px;
    }

    .container {
        padding: 18px 12px 70px;
    }

    .page-title h1 {
        font-size: 22px;
    }

    .total-card {
        padding: 19px;
    }

    .total-card strong {
        font-size: 25px;
    }

    .form-card,
    .history {
        padding: 17px;
        border-radius: 18px;
    }

    .form-grid {
        grid-template-columns: 1fr;
        gap: 13px;
    }

    .form-group.full {
        grid-column: auto;
    }

    .table-container {
        display: none;
    }

}

</style>

</head>

<body>

<!-- =========================
     SIDEBAR
========================= -->

<div class="sidebar" id="sidebar">

```
<div class="sidebar-top">

    <div class="sidebar-title">
        💼 LAMBEMAH
    </div>

    <button class="close-menu" onclick="fermerMenu()">
        ×
    </button>

</div>


<a href="index.php" class="nav-link">

    <span class="nav-icon">🏠</span>
    Tableau de bord

</a>


<a href="produits.php" class="nav-link">

    <span class="nav-icon">👕</span>
    Produits

</a>


<a href="ventes.php" class="nav-link active">

    <span class="nav-icon">🛒</span>
    Ventes

</a>


<a href="prestations.php" class="nav-link">

    <span class="nav-icon">🖨️</span>
    Prestations

</a>


<a href="recettes.php" class="nav-link">

    <span class="nav-icon">💰</span>
    Recettes

</a>


<a href="depenses.php" class="nav-link">

    <span class="nav-icon">💸</span>
    Dépenses

</a>


<a href="statistiques.php" class="nav-link">

    <span class="nav-icon">📊</span>
    Statistiques

</a>


<a href="utilisateurs.php" class="nav-link">

    <span class="nav-icon">👥</span>
    Utilisateurs

</a>


<a href="index.php?logout=1" class="nav-link">

    <span class="nav-icon">🚪</span>
    Déconnexion

</a>
```

</div>

<div class="overlay" id="overlay" onclick="fermerMenu()"></div>

<!-- =========================
     HEADER
========================= -->

<header class="header">

```
<div class="header-inner">

    <button
        class="menu-button"
        onclick="ouvrirMenu()"
    >
        ☰
    </button>

    <div class="brand">

        <strong>
            LAMBEMAH GESTION
        </strong>

        <span>
            Gestion des ventes
        </span>

    </div>

</div>
```

</header>

<!-- =========================
     CONTENU
========================= -->

<main class="container">

```
<div class="page-title">

    <h1>
        🛒 Ventes
    </h1>

    <p>
        Enregistrez et suivez les ventes de vos produits.
    </p>

</div>


<?php if ($message !== ""): ?>

    <div class="message <?= $type_message ?>">
        <?= htmlspecialchars($message) ?>
    </div>

<?php endif; ?>


<!-- TOTAL -->

<div class="total-card">

    <small>
        💰 Total des ventes
    </small>

    <strong>
        <?= montant($total_ventes) ?>
    </strong>

</div>


<!-- FORMULAIRE -->

<div class="form-card">

    <h2>
        ➕ Nouvelle vente
    </h2>


    <form method="POST">

        <input
            type="hidden"
            name="ajouter_vente"
            value="1"
        >


        <div class="form-grid">


            <div class="form-group">

                <label>
                    Produit
                </label>

                <select
                    name="produit_id"
                    id="produit"
                    onchange="calculerMontant()"
                    required
                >

                    <option value="">
                        Sélectionner un produit
                    </option>

                    <?php if ($produits): ?>

                        <?php while ($p = $produits->fetch_assoc()): ?>

                            <option
                                value="<?= (int)$p["id"] ?>"
                                data-prix="<?= htmlspecialchars($p["prix_vente"]) ?>"
                                data-stock="<?= (int)$p["stock"] ?>"
                            >

                                <?= htmlspecialchars($p["nom"]) ?>

                                —
                                Stock :
                                <?= (int)$p["stock"] ?>

                            </option>

                        <?php endwhile; ?>

                    <?php endif; ?>

                </select>

            </div>


            <div class="form-group">

                <label>
                    Quantité
                </label>

                <input
                    type="number"
                    name="quantite"
                    id="quantite"
                    min="1"
                    value="1"
                    oninput="calculerMontant()"
                    required
                >

            </div>


            <div class="form-group">

                <label>
                    Prix unitaire
                </label>

                <input
                    type="number"
                    name="prix_unitaire"
                    id="prix_unitaire"
                    min="1"
                    step="0.01"
                    oninput="calculerMontant()"
                    required
                >

            </div>


            <div class="form-group">

                <label>
                    Montant
                </label>

                <div
                    class="montant-preview"
                    id="montantPreview"
                >
                    0 FG
                </div>

            </div>


            <div class="form-group full">

                <label>
                    Description
                </label>

                <textarea
                    name="description"
                    placeholder="Ex : T-shirt personnalisé, client..."
                ></textarea>

            </div>

        </div>


        <button
            type="submit"
            class="submit-button"
        >
            💾 Enregistrer la vente
        </button>

    </form>

</div>


<!-- HISTORIQUE -->

<div class="history">

    <h2>
        📋 Historique des ventes
    </h2>


    <?php if ($ventes && $ventes->num_rows > 0): ?>

        <?php while ($v = $ventes->fetch_assoc()): ?>

            <div class="sale">

                <div class="sale-top">

                    <div class="sale-product">

                        👕
                        <?= htmlspecialchars(
                            $v["produit_nom"] ?? "Produit supprimé"
                        ) ?>

                    </div>

                    <div class="sale-amount">

                        <?= montant($v["montant"]) ?>

                    </div>

                </div>


                <div class="sale-info">

                    <span class="badge">
                        Quantité :
                        <?= (int)$v["quantite"] ?>
                    </span>

                    <span class="badge">
                        Prix :
                        <?= montant($v["prix_unitaire"]) ?>
                    </span>

                </div>


                <?php if (!empty($v["description"])): ?>

                    <div class="sale-date">

                        <?= htmlspecialchars($v["description"]) ?>

                    </div>

                <?php endif; ?>


                <div class="sale-date">

                    📅
                    <?= htmlspecialchars($v["date_vente"]) ?>

                </div>

            </div>

        <?php endwhile; ?>

    <?php else: ?>

        <p style="color:#718096;font-size:13px;text-align:center;padding:20px;">
            Aucune vente enregistrée pour le moment.
        </p>

    <?php endif; ?>

</div>
```

</main>

<script>

function ouvrirMenu() {

    document.getElementById("sidebar").classList.add("active");

    document.getElementById("overlay").classList.add("active");

}

function fermerMenu() {

    document.getElementById("sidebar").classList.remove("active");

    document.getElementById("overlay").classList.remove("active");

}


function calculerMontant() {

    const produit = document.getElementById("produit");

    const quantite = document.getElementById("quantite");

    const prix = document.getElementById("prix_unitaire");

    const preview = document.getElementById("montantPreview");

    if (
        produit.value &&
        produit.options[produit.selectedIndex]
    ) {

        const prixProduit =
            produit.options[
                produit.selectedIndex
            ].getAttribute("data-prix");

        if (
            prixProduit &&
            (
                prix.value === "" ||
                prix.value === "0"
            )
        ) {

            prix.value = prixProduit;

        }

    }

    const q = parseFloat(quantite.value) || 0;

    const p = parseFloat(prix.value) || 0;

    const total = q * p;

    preview.textContent =
        new Intl.NumberFormat("fr-FR").format(total)
        + " FG";

}

</script>

</body>
</html>
