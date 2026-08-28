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
    header("Location: index.php");
    exit;
}

$nom = $_SESSION["nom"] ?? "Utilisateur";
$role = $_SESSION["role"] ?? "lecture";

$message = "";
$type_message = "";


/*
|--------------------------------------------------------------------------
| TRAITEMENT D'UNE PRESTATION
|--------------------------------------------------------------------------
|
| Le client peut :
| - apporter son propre T-shirt
| - demander seulement une impression
| - demander DTF + impression
|
| Le coût fournisseur actuel du DTF A4 = 5 000 FG
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $client = trim($_POST["client"] ?? "");
    $format = trim($_POST["format"] ?? "A4");
    $quantite = (int)($_POST["quantite"] ?? 1);

    $cout_dtf = (float)($_POST["cout_dtf"] ?? 0);
    $prix_impression = (float)($_POST["prix_impression"] ?? 0);

    $description = trim($_POST["description"] ?? "");

    if ($quantite < 1) {
        $message = "La quantité doit être au moins égale à 1.";
        $type_message = "error";

    } elseif ($prix_impression <= 0) {

        $message = "Veuillez indiquer le prix facturé au client.";
        $type_message = "error";

    } else {

        /*
        | Coût DTF total
        */

        $cout_total_dtf = $cout_dtf * $quantite;


        /*
        | Prix total facturé
        */

        $total_facture = $prix_impression * $quantite;


        /*
        | Bénéfice brut
        */

        $benefice = $total_facture - $cout_total_dtf;


        /*
        | Libellé automatique
        */

        if ($client !== "") {

            $libelle =
                "Prestation DTF - " .
                $client .
                " - " .
                $format;

        } else {

            $libelle =
                "Prestation DTF - " .
                $format;
        }


        /*
        | Description
        */

        $description_finale =
            "Client : " . ($client ?: "Non renseigné") .
            " | Format : " . $format .
            " | Quantité : " . $quantite .
            " | Coût DTF : " . number_format($cout_dtf, 0, ",", " ") . " FG/unité" .
            " | Prix impression : " . number_format($prix_impression, 0, ",", " ") . " FG/unité" .
            " | Coût DTF total : " . number_format($cout_total_dtf, 0, ",", " ") . " FG" .
            " | Bénéfice : " . number_format($benefice, 0, ",", " ") . " FG";

        if ($description !== "") {
            $description_finale .= " | Note : " . $description;
        }


        /*
        | Enregistrer comme recette
        */

        $stmt = $conn->prepare(
            "INSERT INTO recettes
            (libelle, montant, description)
            VALUES (?, ?, ?)"
        );

        if ($stmt) {

            $stmt->bind_param(
                "sds",
                $libelle,
                $total_facture,
                $description_finale
            );

            if ($stmt->execute()) {

                /*
                | Enregistrer le coût DTF comme dépense
                | seulement si le coût fournisseur est supérieur à 0.
                */

                if ($cout_total_dtf > 0) {

                    $libelle_depense =
                        "DTF fournisseur - " .
                        ($client ?: "Prestation") .
                        " - " .
                        $format;

                    $stmt_depense = $conn->prepare(
                        "INSERT INTO depenses
                        (libelle, montant, description)
                        VALUES (?, ?, ?)"
                    );

                    if ($stmt_depense) {

                        $description_depense =
                            "Coût fournisseur DTF pour " .
                            $quantite .
                            " impression(s), format " .
                            $format .
                            ". Client : " .
                            ($client ?: "Non renseigné");

                        $stmt_depense->bind_param(
                            "sds",
                            $libelle_depense,
                            $cout_total_dtf,
                            $description_depense
                        );

                        $stmt_depense->execute();

                        $stmt_depense->close();
                    }
                }


                $message =
                    "Prestation enregistrée avec succès.";

                $type_message = "success";

            } else {

                $message =
                    "Erreur lors de l'enregistrement.";

                $type_message = "error";
            }

            $stmt->close();

        } else {

            $message =
                "Impossible de préparer l'enregistrement.";

            $type_message = "error";
        }
    }
}


/*
|--------------------------------------------------------------------------
| CALCULS POUR LA PAGE
|--------------------------------------------------------------------------
*/

$total_prestations = 0;
$nombre_prestations = 0;
$total_couts_dtf = 0;


/*
| On récupère les recettes contenant "Prestation DTF".
*/

$result = $conn->query(
    "SELECT
        COUNT(*) AS nombre,
        COALESCE(SUM(montant),0) AS total
     FROM recettes
     WHERE libelle LIKE '%Prestation DTF%'"
);

if ($result) {

    $data = $result->fetch_assoc();

    $nombre_prestations =
        (int)$data["nombre"];

    $total_prestations =
        (float)$data["total"];
}


/*
| Coûts DTF enregistrés dans les dépenses.
*/

$result = $conn->query(
    "SELECT
        COALESCE(SUM(montant),0) AS total
     FROM depenses
     WHERE libelle LIKE 'DTF fournisseur%'"
);

if ($result) {

    $data = $result->fetch_assoc();

    $total_couts_dtf =
        (float)$data["total"];
}


$total_benefice =
    $total_prestations - $total_couts_dtf;


/*
|--------------------------------------------------------------------------
| DERNIÈRES PRESTATIONS
|--------------------------------------------------------------------------
*/

$prestations = $conn->query(
    "SELECT
        id,
        libelle,
        montant,
        description,
        date_recette
     FROM recettes
     WHERE libelle LIKE '%Prestation DTF%'
     ORDER BY id DESC
     LIMIT 10"
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

<title>Prestations - LAMBEMAH GESTION</title>


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
            #071b2e,
            #0b2944,
            #07395b
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

    justify-content: center;
    align-items: center;

    background:
        linear-gradient(
            135deg,
            #21b8ff,
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

    gap: 12px;

    align-items: center;

    padding: 12px 14px;

    border-radius: 12px;

    color: #c5d5e1;

    text-decoration: none;

    font-size: 13px;

}

.nav a:hover {

    background: rgba(255,255,255,.08);

    color: white;

}

.nav a.active {

    background: rgba(32,184,255,.15);

    color: white;

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


/*
|--------------------------------------------------------------------------
| MAIN
|--------------------------------------------------------------------------
*/

.main {

    margin-left: 250px;

    padding: 28px;

    max-width: 1500px;

}


/*
|--------------------------------------------------------------------------
| HEADER
|--------------------------------------------------------------------------
*/

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

.user {

    width: 42px;
    height: 42px;

    border-radius: 13px;

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


/*
|--------------------------------------------------------------------------
| CARDS
|--------------------------------------------------------------------------
*/

.cards {

    display: grid;

    grid-template-columns:
        repeat(3, 1fr);

    gap: 15px;

    margin-bottom: 20px;

}

.card-stat {

    background: white;

    border-radius: 17px;

    padding: 18px;

    box-shadow:
        0 6px 25px rgba(25,55,80,.06);

}

.card-stat small {

    color: #8c9aa6;

    font-size: 10px;

}

.card-stat h2 {

    margin-top: 8px;

    font-size: 21px;

}

.card-stat .icon {

    font-size: 20px;

    margin-bottom: 10px;

}


/*
|--------------------------------------------------------------------------
| LAYOUT
|--------------------------------------------------------------------------
*/

.grid {

    display: grid;

    grid-template-columns:
        400px 1fr;

    gap: 20px;

}


/*
|--------------------------------------------------------------------------
| FORM CARD
|--------------------------------------------------------------------------
*/

.form-card {

    background: white;

    border-radius: 20px;

    padding: 22px;

    box-shadow:
        0 6px 25px rgba(25,55,80,.06);

}

.form-card h2 {

    font-size: 17px;

    margin-bottom: 5px;

}

.form-card > p {

    color: #8b99a5;

    font-size: 11px;

    margin-bottom: 20px;

}

.group {

    margin-bottom: 14px;

}

label {

    display: block;

    margin-bottom: 6px;

    font-size: 11px;

    font-weight: bold;

    color: #536675;

}

input,
select,
textarea {

    width: 100%;

    border: 1px solid #e0e8ee;

    border-radius: 10px;

    padding: 11px;

    font-size: 12px;

    outline: none;

    background: #fbfdff;

}

input:focus,
select:focus,
textarea:focus {

    border-color: #21aee9;

}

textarea {

    resize: vertical;

    min-height: 70px;

}

.price-info {

    background: #eff9ff;

    border: 1px solid #d5effb;

    color: #24779e;

    border-radius: 10px;

    padding: 10px;

    margin-bottom: 15px;

    font-size: 10px;

}

button {

    width: 100%;

    border: none;

    border-radius: 11px;

    padding: 13px;

    background:
        linear-gradient(
            135deg,
            #20b8ff,
            #1264c7
        );

    color: white;

    font-weight: bold;

    cursor: pointer;

    font-size: 12px;

}

button:hover {

    opacity: .92;

}


/*
|--------------------------------------------------------------------------
| MESSAGES
|--------------------------------------------------------------------------
*/

.message {

    padding: 12px;

    border-radius: 11px;

    margin-bottom: 15px;

    font-size: 11px;

}

.success {

    background: #eafaf2;

    color: #198754;

}

.error {

    background: #fff0f0;

    color: #d33;

}


/*
|--------------------------------------------------------------------------
| TABLE CARD
|--------------------------------------------------------------------------
*/

.list-card {

    background: white;

    border-radius: 20px;

    padding: 22px;

    box-shadow:
        0 6px 25px rgba(25,55,80,.06);

}

.list-header {

    display: flex;

    justify-content: space-between;

    margin-bottom: 18px;

}

.list-header h2 {

    font-size: 17px;

}

.list-header span {

    color: #8c99a5;

    font-size: 10px;

}

.table-wrap {

    overflow-x: auto;

}

table {

    width: 100%;

    border-collapse: collapse;

}

th {

    color: #95a1aa;

    font-size: 9px;

    text-align: left;

    padding: 9px;

    border-bottom: 1px solid #edf1f4;

}

td {

    padding: 12px 9px;

    border-bottom: 1px solid #f0f3f5;

    font-size: 11px;

}

td strong {

    color: #20394c;

}

.amount {

    color: #1377b1;

    font-weight: bold;

}

.profit {

    color: #159765;

    font-weight: bold;

}

.date {

    color: #9ba5ad;

    font-size: 9px;

}


/*
|--------------------------------------------------------------------------
| MOBILE
|--------------------------------------------------------------------------
*/

@media (max-width: 900px) {

    .grid {

        grid-template-columns: 1fr;

    }

    .cards {

        grid-template-columns:
            repeat(3, 1fr);

    }

}


@media (max-width: 700px) {

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

        border-left: none;

        border-bottom: 2px solid #20b8ff;

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

    .cards {

        grid-template-columns:
            1fr 1fr;

        gap: 9px;

    }

    .card-stat {

        padding: 14px;

    }

    .card-stat:last-child {

        grid-column:
            1 / -1;

    }

    .grid {

        gap: 13px;

    }

    .form-card,
    .list-card {

        padding: 15px;

        border-radius: 16px;

    }

}


/*
|--------------------------------------------------------------------------
| PETIT TELEPHONE
|--------------------------------------------------------------------------
*/

@media (max-width: 390px) {

    .nav a {

        font-size: 7px;

    }

    .cards {

        grid-template-columns:
            1fr 1fr;

    }

    .card-stat h2 {

        font-size: 16px;

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
            <a href="index.php">
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
            <a
                href="prestations.php"
                class="active"
            >
                🖨️
                <span>Prestations</span>
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
                <span>Stats</span>
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


<!-- ==========================================================
     MAIN
=========================================================== -->

<main class="main">


    <div class="header">

        <div>

            <h1>
                🖨️ Prestations
            </h1>

            <p>
                Gérez les impressions DTF et les T-shirts apportés par les clients.
            </p>

        </div>

        <div class="user">

            <?= strtoupper(
                substr($nom, 0, 1)
            ) ?>

        </div>

    </div>


    <!-- ======================================================
         STATISTIQUES
    ======================================================= -->

    <div class="cards">


        <div class="card-stat">

            <div class="icon">
                💵
            </div>

            <small>
                CA PRESTATIONS
            </small>

            <h2>
                <?= argent($total_prestations) ?>
            </h2>

        </div>


        <div class="card-stat">

            <div class="icon">
                🧾
            </div>

            <small>
                COÛT DTF
            </small>

            <h2>
                <?= argent($total_couts_dtf) ?>
            </h2>

        </div>


        <div class="card-stat">

            <div class="icon">
                📈
            </div>

            <small>
                BÉNÉFICE DTF
            </small>

            <h2 class="profit">
                <?= argent($total_benefice) ?>
            </h2>

        </div>

    </div>


    <!-- ======================================================
         CONTENU
    ======================================================= -->

    <div class="grid">


        <!-- FORMULAIRE -->

        <div class="form-card">

            <h2>
                ➕ Nouvelle prestation
            </h2>

            <p>
                Enregistrer une impression pour un client.
            </p>


            <?php if ($message !== ""): ?>

                <div class="message <?= $type_message ?>">

                    <?= htmlspecialchars($message) ?>

                </div>

            <?php endif; ?>


            <div class="price-info">

                💡 <strong>Prix fournisseur DTF A4 :</strong>
                5 000 FG

                <br><br>

                Le client peut apporter son propre T-shirt.
                Tu factures alors directement ta prestation d'impression.

            </div>


            <form method="POST">


                <div class="group">

                    <label>
                        CLIENT
                    </label>

                    <input
                        type="text"
                        name="client"
                        placeholder="Nom du client"
                    >

                </div>


                <div class="group">

                    <label>
                        FORMAT DTF
                    </label>

                    <select name="format">

                        <option value="A4">
                            A4 — 5 000 FG fournisseur
                        </option>

                        <option value="A3">
                            A3
                        </option>

                        <option value="Autre">
                            Autre format
                        </option>

                    </select>

                </div>


                <div class="group">

                    <label>
                        QUANTITÉ
                    </label>

                    <input
                        type="number"
                        name="quantite"
                        value="1"
                        min="1"
                        required
                    >

                </div>


                <div class="group">

                    <label>
                        COÛT DTF FOURNISSEUR / UNITÉ
                    </label>

                    <input
                        type="number"
                        name="cout_dtf"
                        value="5000"
                        min="0"
                        step="500"
                        required
                    >

                </div>


                <div class="group">

                    <label>
                        PRIX FACTURÉ AU CLIENT / UNITÉ
                    </label>

                    <input
                        type="number"
                        name="prix_impression"
                        placeholder="Exemple : 15000"
                        min="1"
                        step="500"
                        required
                    >

                </div>


                <div class="group">

                    <label>
                        NOTE / DÉTAIL
                    </label>

                    <textarea
                        name="description"
                        placeholder="Ex : T-shirt blanc apporté par le client..."
                    ></textarea>

                </div>


                <button type="submit">

                    🖨️ ENREGISTRER LA PRESTATION

                </button>

            </form>

        </div>


        <!-- HISTORIQUE -->

        <div class="list-card">

            <div class="list-header">

                <h2>
                    📋 Prestations récentes
                </h2>

                <span>
                    <?= $nombre_prestations ?> prestation(s)
                </span>

            </div>


            <div class="table-wrap">

                <table>

                    <thead>

                    <tr>

                        <th>
                            CLIENT / PRESTATION
                        </th>

                        <th>
                            MONTANT
                        </th>

                        <th>
                            BÉNÉFICE
                        </th>

                        <th>
                            DATE
                        </th>

                    </tr>

                    </thead>


                    <tbody>

                    <?php if (
                        $prestations &&
                        $prestations->num_rows > 0
                    ): ?>


                        <?php while (
                            $p =
                            $prestations->fetch_assoc()
                        ): ?>


                            <?php

                            /*
                            | Récupération du bénéfice
                            | depuis la description.
                            */

                            $benefice_ligne = 0;

                            if (
                                preg_match(
                                    '/Bénéfice : ([0-9 ]+)/',
                                    $p["description"],
                                    $matches
                                )
                            ) {

                                $benefice_ligne =
                                    (float)str_replace(
                                        " ",
                                        "",
                                        $matches[1]
                                    );
                            }

                            ?>


                            <tr>

                                <td>

                                    <strong>

                                        <?= htmlspecialchars(
                                            $p["libelle"]
                                        ) ?>

                                    </strong>

                                </td>


                                <td class="amount">

                                    <?= argent(
                                        $p["montant"]
                                    ) ?>

                                </td>


                                <td class="profit">

                                    <?= argent(
                                        $benefice_ligne
                                    ) ?>

                                </td>


                                <td class="date">

                                    <?= date(
                                        "d/m/Y",
                                        strtotime(
                                            $p["date_recette"]
                                        )
                                    ) ?>

                                </td>

                            </tr>


                        <?php endwhile; ?>


                    <?php else: ?>

                        <tr>

                            <td colspan="4">

                                Aucune prestation enregistrée.

                            </td>

                        </tr>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

</main>

</body>

</html>
```
