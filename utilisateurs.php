```php
<?php
session_start();
require_once "config.php";

if (!isset($_SESSION["id"])) {
    header("Location: index.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| DROITS
|--------------------------------------------------------------------------
| admin       = accès complet
| gestion     = produits, ventes, recettes, dépenses, statistiques
| vendeur     = produits et ventes
| comptable   = recettes, dépenses et statistiques
| lecture     = consultation uniquement
|--------------------------------------------------------------------------
*/

$role = $_SESSION["role"] ?? "lecture";

if ($role !== "admin") {
    die("⛔ Accès refusé. Seul l'administrateur peut gérer les utilisateurs.");
}

$message = "";
$type_message = "";

/*
|--------------------------------------------------------------------------
| AJOUTER UN UTILISATEUR
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["action"])) {

    $action = $_POST["action"];

    /*
    |--------------------------------------------------------------------------
    | AJOUT
    |--------------------------------------------------------------------------
    */

    if ($action === "ajouter") {

        $nom = trim($_POST["nom"] ?? "");
        $username = trim($_POST["username"] ?? "");
        $mot_de_passe = trim($_POST["mot_de_passe"] ?? "");
        $role_nouveau = trim($_POST["role"] ?? "lecture");

        $roles_autorises = [
            "admin",
            "gestion",
            "vendeur",
            "comptable",
            "lecture"
        ];

        if (
            $nom === "" ||
            $username === "" ||
            $mot_de_passe === ""
        ) {

            $message = "Veuillez remplir tous les champs.";
            $type_message = "error";

        } elseif (!in_array($role_nouveau, $roles_autorises)) {

            $message = "Rôle invalide.";
            $type_message = "error";

        } else {

            $verification = $conn->prepare(
                "SELECT id FROM utilisateurs WHERE username = ? LIMIT 1"
            );

            $verification->bind_param("s", $username);
            $verification->execute();

            $resultat = $verification->get_result();

            if ($resultat->num_rows > 0) {

                $message = "Ce nom d'utilisateur existe déjà.";
                $type_message = "error";

            } else {

                $stmt = $conn->prepare(
                    "INSERT INTO utilisateurs
                    (nom, username, mot_de_passe, role)
                    VALUES (?, ?, ?, ?)"
                );

                $stmt->bind_param(
                    "ssss",
                    $nom,
                    $username,
                    $mot_de_passe,
                    $role_nouveau
                );

                if ($stmt->execute()) {

                    $message = "Utilisateur ajouté avec succès.";
                    $type_message = "success";

                } else {

                    $message = "Erreur lors de l'ajout.";
                    $type_message = "error";
                }

                $stmt->close();
            }

            $verification->close();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | MODIFICATION
    |--------------------------------------------------------------------------
    */

    if ($action === "modifier") {

        $id = (int)($_POST["id"] ?? 0);
        $nom = trim($_POST["nom"] ?? "");
        $username = trim($_POST["username"] ?? "");
        $mot_de_passe = trim($_POST["mot_de_passe"] ?? "");
        $role_nouveau = trim($_POST["role"] ?? "lecture");

        $roles_autorises = [
            "admin",
            "gestion",
            "vendeur",
            "comptable",
            "lecture"
        ];

        if ($id <= 0 || $nom === "" || $username === "") {

            $message = "Informations invalides.";
            $type_message = "error";

        } elseif (!in_array($role_nouveau, $roles_autorises)) {

            $message = "Rôle invalide.";
            $type_message = "error";

        } else {

            /*
            | Si le mot de passe est vide,
            | on conserve l'ancien.
            */

            if ($mot_de_passe === "") {

                $stmt = $conn->prepare(
                    "UPDATE utilisateurs
                     SET nom = ?, username = ?, role = ?
                     WHERE id = ?"
                );

                $stmt->bind_param(
                    "sssi",
                    $nom,
                    $username,
                    $role_nouveau,
                    $id
                );

            } else {

                $stmt = $conn->prepare(
                    "UPDATE utilisateurs
                     SET nom = ?, username = ?, mot_de_passe = ?, role = ?
                     WHERE id = ?"
                );

                $stmt->bind_param(
                    "ssssi",
                    $nom,
                    $username,
                    $mot_de_passe,
                    $role_nouveau,
                    $id
                );
            }

            if ($stmt->execute()) {

                $message = "Utilisateur modifié avec succès.";
                $type_message = "success";

            } else {

                $message = "Erreur lors de la modification.";
                $type_message = "error";
            }

            $stmt->close();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | SUPPRESSION
    |--------------------------------------------------------------------------
    */

    if ($action === "supprimer") {

        $id = (int)($_POST["id"] ?? 0);

        /*
        | Empêche de supprimer son propre compte.
        */

        if ($id === (int)$_SESSION["id"]) {

            $message = "Tu ne peux pas supprimer ton propre compte.";
            $type_message = "error";

        } elseif ($id > 0) {

            $stmt = $conn->prepare(
                "DELETE FROM utilisateurs WHERE id = ?"
            );

            $stmt->bind_param("i", $id);

            if ($stmt->execute()) {

                $message = "Utilisateur supprimé.";
                $type_message = "success";

            } else {

                $message = "Erreur lors de la suppression.";
                $type_message = "error";
            }

            $stmt->close();
        }
    }
}

/*
|--------------------------------------------------------------------------
| UTILISATEUR À MODIFIER
|--------------------------------------------------------------------------
*/

$modifier = null;

if (isset($_GET["modifier"])) {

    $id = (int)$_GET["modifier"];

    $stmt = $conn->prepare(
        "SELECT id, nom, username, role
         FROM utilisateurs
         WHERE id = ?
         LIMIT 1"
    );

    $stmt->bind_param("i", $id);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $modifier = $result->fetch_assoc();
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| LISTE DES UTILISATEURS
|--------------------------------------------------------------------------
*/

$utilisateurs = $conn->query(
    "SELECT id, nom, username, role, date_creation
     FROM utilisateurs
     ORDER BY id DESC"
);

/*
|--------------------------------------------------------------------------
| LABELS DES RÔLES
|--------------------------------------------------------------------------
*/

function nomRole($role)
{
    $roles = [
        "admin" => "👑 Administrateur",
        "gestion" => "💼 Gestionnaire",
        "vendeur" => "💰 Vendeur",
        "comptable" => "🧾 Comptable",
        "lecture" => "👁️ Lecture seule"
    ];

    return $roles[$role] ?? $role;
}

?>

<!DOCTYPE html>

<html lang="fr">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Utilisateurs - LAMBEMAH GESTION</title>

<style>

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family: Arial, sans-serif;
    background: #f4faff;
    color: #263746;
}

/* SIDEBAR */

.sidebar {
    position: fixed;
    left: 0;
    top: 0;
    bottom: 0;
    width: 245px;
    background: linear-gradient(180deg, #55c7ed, #168dcc);
    padding: 25px 15px;
    color: white;
}

.brand {
    padding: 5px 12px 28px;
}

.brand-icon {
    font-size: 30px;
}

.brand h2 {
    font-size: 21px;
    margin-top: 5px;
}

.brand span {
    font-size: 11px;
    opacity: .85;
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
    color: white;
    text-decoration: none;
    border-radius: 12px;
    font-size: 14px;
}

.nav a:hover,
.nav a.active {
    background: rgba(255,255,255,.22);
}

.sidebar-bottom {
    position: absolute;
    bottom: 20px;
    left: 15px;
    right: 15px;
}

.logout {
    display: block;
    color: white;
    text-decoration: none;
    padding: 13px;
    border-radius: 12px;
    background: rgba(255,255,255,.12);
}

/* MAIN */

.main {
    margin-left: 245px;
    padding: 30px;
}

.header {
    margin-bottom: 25px;
}

.header h1 {
    font-size: 28px;
}

.header p {
    margin-top: 7px;
    color: #81919a;
}

/* CARDS */

.grid {
    display: grid;
    grid-template-columns: 350px 1fr;
    gap: 20px;
}

.card {
    background: white;
    border-radius: 18px;
    padding: 23px;
    box-shadow: 0 5px 20px #dfeef4;
}

.card h2 {
    font-size: 18px;
    margin-bottom: 20px;
}

/* FORM */

.group {
    margin-bottom: 15px;
}

.group label {
    display: block;
    font-size: 12px;
    font-weight: bold;
    margin-bottom: 7px;
}

.group input,
.group select {
    width: 100%;
    padding: 12px;
    border: 1px solid #dce8ed;
    border-radius: 10px;
    font-size: 14px;
    background: white;
}

button {
    border: none;
    border-radius: 10px;
    padding: 11px 15px;
    cursor: pointer;
    font-weight: bold;
}

.btn-primary {
    width: 100%;
    background: #168dcc;
    color: white;
}

.btn-primary:hover {
    background: #0d78ad;
}

.btn-edit {
    background: #eaf8ff;
    color: #168dcc;
}

.btn-delete {
    background: #fff0f0;
    color: #d33;
}

/* MESSAGES */

.message {
    padding: 13px;
    border-radius: 10px;
    margin-bottom: 18px;
}

.success {
    background: #eafaf1;
    color: #16834d;
}

.error {
    background: #fff0f0;
    color: #c62828;
}

/* ROLE INFO */

.roles {
    background: #f3faff;
    padding: 15px;
    border-radius: 12px;
    margin-top: 18px;
    font-size: 12px;
    line-height: 1.8;
}

.roles strong {
    color: #168dcc;
}

/* TABLE */

.table-container {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 650px;
}

th,
td {
    padding: 14px;
    text-align: left;
    border-bottom: 1px solid #edf3f6;
    font-size: 13px;
}

th {
    color: #89969d;
    font-size: 11px;
}

.badge {
    display: inline-block;
    padding: 6px 9px;
    border-radius: 20px;
    background: #eaf8ff;
    color: #168dcc;
    font-size: 11px;
    font-weight: bold;
}

.actions {
    display: flex;
    gap: 7px;
}

.date {
    color: #89969d;
    font-size: 12px;
}

@media(max-width: 900px) {

    .grid {
        grid-template-columns: 1fr;
    }

}

@media(max-width: 700px) {

    .sidebar {
        position: relative;
        width: 100%;
        padding: 15px;
    }

    .nav {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
    }

    .nav a {
        flex-direction: column;
        justify-content: center;
        font-size: 10px;
        gap: 5px;
    }

    .sidebar-bottom {
        position: static;
        margin-top: 10px;
    }

    .main {
        margin-left: 0;
        padding: 18px;
    }

}

</style>

</head>

<body>

<aside class="sidebar">

<div class="brand">

<div class="brand-icon">💼</div>

<h2>LAMBEMAH</h2>

<span>GESTION • PRESTATION</span>

</div>

<ul class="nav">

<li>
<a href="index.php">
🏠 Accueil
</a>
</li>

<li>
<a href="produits.php">
📦 Produits
</a>
</li>

<li>
<a href="ventes.php">
💰 Ventes
</a>
</li>

<li>
<a href="prestations.php">
🖨️ Prestations
</a>
</li>

<li>
<a href="depenses.php">
💸 Dépenses
</a>
</li>

<li>
<a href="statistiques.php">
📊 Statistiques
</a>
</li>

<li>
<a href="utilisateurs.php" class="active">
👥 Utilisateurs
</a>
</li>

</ul>

<div class="sidebar-bottom">

<a class="logout" href="index.php?logout=1">
🚪 Déconnexion
</a>

</div>

</aside>


<main class="main">

<div class="header">

<h1>Utilisateurs 👥</h1>

<p>
Gère les personnes qui peuvent accéder à LAMBEMAH GESTION.
</p>

</div>


<?php if ($message !== ""): ?>

<div class="message <?= $type_message ?>">

<?= htmlspecialchars($message) ?>

</div>

<?php endif; ?>


<div class="grid">


<!-- FORMULAIRE -->

<div class="card">

<?php if ($modifier): ?>

<h2>✏️ Modifier l'utilisateur</h2>

<form method="POST">

<input
type="hidden"
name="action"
value="modifier"
>

<input
type="hidden"
name="id"
value="<?= (int)$modifier["id"] ?>"
>

<div class="group">

<label>Nom complet</label>

<input
type="text"
name="nom"
value="<?= htmlspecialchars($modifier["nom"]) ?>"
required
>

</div>


<div class="group">

<label>Nom d'utilisateur</label>

<input
type="text"
name="username"
value="<?= htmlspecialchars($modifier["username"]) ?>"
required
>

</div>


<div class="group">

<label>Nouveau mot de passe</label>

<input
type="password"
name="mot_de_passe"
placeholder="Laisser vide pour conserver l'ancien"
>

</div>


<div class="group">

<label>Droits</label>

<select name="role">

<option value="admin"
<?= $modifier["role"] === "admin" ? "selected" : "" ?>>
👑 Administrateur
</option>

<option value="gestion"
<?= $modifier["role"] === "gestion" ? "selected" : "" ?>>
💼 Gestionnaire
</option>

<option value="vendeur"
<?= $modifier["role"] === "vendeur" ? "selected" : "" ?>>
💰 Vendeur
</option>

<option value="comptable"
<?= $modifier["role"] === "comptable" ? "selected" : "" ?>>
🧾 Comptable
</option>

<option value="lecture"
<?= $modifier["role"] === "lecture" ? "selected" : "" ?>>
👁️ Lecture seule
</option>

</select>

</div>


<button class="btn-primary" type="submit">
💾 Enregistrer les modifications
</button>

</form>

<br>

<a href="utilisateurs.php"
style="color:#168dcc;text-decoration:none;font-size:13px;">
← Annuler
</a>

<?php else: ?>

<h2>➕ Ajouter une personne</h2>

<form method="POST">

<input
type="hidden"
name="action"
value="ajouter"
>

<div class="group">

<label>Nom complet</label>

<input
type="text"
name="nom"
placeholder="Ex : Ibrahima Konaté"
required
>

</div>


<div class="group">

<label>Nom d'utilisateur</label>

<input
type="text"
name="username"
placeholder="Ex : ibrahima"
required
>

</div>


<div class="group">

<label>Mot de passe</label>

<input
type="password"
name="mot_de_passe"
placeholder="Mot de passe"
required
>

</div>


<div class="group">

<label>Droits accordés</label>

<select name="role">

<option value="lecture">
👁️ Lecture seule
</option>

<option value="vendeur">
💰 Vendeur
</option>

<option value="comptable">
🧾 Comptable
</option>

<option value="gestion">
💼 Gestionnaire
</option>

<option value="admin">
👑 Administrateur
</option>

</select>

</div>


<button class="btn-primary" type="submit">
➕ Créer l'utilisateur
</button>

</form>


<div class="roles">

<strong>👑 Administrateur</strong> : accès complet.<br>

<strong>💼 Gestionnaire</strong> : gestion de l'activité.<br>

<strong>💰 Vendeur</strong> : produits et ventes.<br>

<strong>🧾 Comptable</strong> : recettes, dépenses et statistiques.<br>

<strong>👁️ Lecture seule</strong> : consultation uniquement.

</div>

<?php endif; ?>

</div>


<!-- LISTE -->

<div class="card">

<h2>👥 Équipe LAMBEMAH GESTION</h2>

<div class="table-container">

<table>

<thead>

<tr>

<th>NOM</th>

<th>IDENTIFIANT</th>

<th>DROITS</th>

<th>CRÉÉ LE</th>

<th>ACTIONS</th>

</tr>

</thead>

<tbody>

<?php if ($utilisateurs && $utilisateurs->num_rows > 0): ?>

<?php while ($u = $utilisateurs->fetch_assoc()): ?>

<tr>

<td>

<strong>
<?= htmlspecialchars($u["nom"]) ?>
</strong>

</td>

<td>
<?= htmlspecialchars($u["username"]) ?>
</td>

<td>

<span class="badge">

<?= htmlspecialchars(nomRole($u["role"])) ?>

</span>

</td>

<td class="date">

<?= date(
"d/m/Y",
strtotime($u["date_creation"])
) ?>

</td>

<td>

<div class="actions">

<a
href="utilisateurs.php?modifier=<?= (int)$u["id"] ?>"
style="text-decoration:none;"
>

<button
type="button"
class="btn-edit"
>
✏️
</button>

</a>


<?php if ((int)$u["id"] !== (int)$_SESSION["id"]): ?>

<form
method="POST"
onsubmit="return confirm('Supprimer cet utilisateur ?');"
>

<input
type="hidden"
name="action"
value="supprimer"
>

<input
type="hidden"
name="id"
value="<?= (int)$u["id"] ?>"
>

<button
type="submit"
class="btn-delete"
>
🗑️
</button>

</form>

<?php endif; ?>

</div>

</td>

</tr>

<?php endwhile; ?>

<?php else: ?>

<tr>

<td colspan="5">
Aucun utilisateur.
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
