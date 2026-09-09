<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

session_start();
require_once __DIR__ . '/config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Connexion à la base de données impossible.");
}

$conn->set_charset("utf8mb4");

/* =========================================================
   OUTILS
========================================================= */

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function money($v) {
    return number_format((float)$v, 0, ',', ' ') . ' FG';
}

function cleanText($v) {
    return trim(preg_replace('/[|\r\n]+/', ' ', (string)$v));
}

function nextId(mysqli $conn, string $table): int {

    $tables = ['produits', 'ventes', 'mouvements'];

    if (!in_array($table, $tables, true)) {
        return 1;
    }

    $sql = "SELECT COALESCE(MAX(id),0)+1 AS prochain FROM `$table`";
    $res = $conn->query($sql);

    if (!$res) {
        throw new Exception("Impossible de générer un nouvel ID pour $table.");
    }

    $row = $res->fetch_assoc();

    return (int)($row['prochain'] ?? 1);
}

function parseMeta(string $description): array {

    $data = [];

    foreach (explode('|', $description) as $part) {

        if (strpos($part, '=') !== false) {

            [$key, $value] = explode('=', $part, 2);

            $data[trim($key)] = trim($value);
        }
    }

    return $data;
}

function flash($type, $message) {

    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

function getInvoiceRows(mysqli $conn, string $ref): array {

    $rows = [];

    $safe = $conn->real_escape_string($ref);

    $sql = "
        SELECT
            v.id,
            v.produit_id,
            v.quantite,
            v.prix_unitaire,
            v.montant,
            v.description,
            v.date_vente,
            p.nom,
            p.categorie,
            p.stock
        FROM ventes v
        LEFT JOIN produits p
            ON p.id = v.produit_id
        WHERE v.description LIKE '%FACTURE=$safe|%'
        ORDER BY v.id ASC
    ";

    $result = $conn->query($sql);

    if ($result) {

        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function invoiceTotal(array $rows): float {

    $total = 0;

    foreach ($rows as $row) {

        $total +=
            (float)$row['quantite'] *
            (float)$row['prix_unitaire'];
    }

    return $total;
}

function invoicePaid(array $rows): float {

    if (!$rows) {
        return 0;
    }

    $meta = parseMeta($rows[0]['description']);

    return (float)($meta['PAYE'] ?? 0);
}

function invoiceClient(array $rows): string {

    if (!$rows) {
        return 'Client comptant';
    }

    $meta = parseMeta($rows[0]['description']);

    return $meta['CLIENT'] ?? 'Client comptant';
}

function invoiceStatus(float $total, float $paid): string {

    if ($paid <= 0.01) {
        return 'Non payée';
    }

    if ($paid >= $total - 0.01) {
        return 'Payée';
    }

    return 'Partiellement payée';
}

/* =========================================================
   IMPRESSION FACTURE
========================================================= */

if (isset($_GET['imprimer'])) {

    $ref = cleanText($_GET['imprimer']);

    $rows = getInvoiceRows($conn, $ref);

    if (!$rows) {
        die("Facture introuvable.");
    }

    $total = invoiceTotal($rows);
    $paid = invoicePaid($rows);
    $rest = max(0, $total - $paid);
    $client = invoiceClient($rows);
    $status = invoiceStatus($total, $paid);

    ?>

<!DOCTYPE html>
<html lang="fr">

<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width, initial-scale=1.0">

<title><?= h($ref) ?></title>

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: #eef6ff;
    color: #172033;
    font-family: Arial, sans-serif;
}

.facture {
    width: 850px;
    max-width: 95%;
    margin: 30px auto;
    background: white;
    padding: 35px;
    border-radius: 18px;
    box-shadow: 0 10px 35px rgba(20, 60, 100, .12);
}

.entete {
    display: flex;
    justify-content: space-between;
    gap: 20px;
    padding-bottom: 20px;
    border-bottom: 3px solid #1677ff;
}

.logo {
    font-size: 28px;
    font-weight: 900;
    color: #1261c9;
}

.sous-logo {
    color: #607089;
    margin-top: 4px;
}

.titre {
    text-align: right;
    font-size: 20px;
    font-weight: 800;
    color: #162a49;
}

.titre small {
    display: block;
    margin-top: 7px;
    color: #1677ff;
    font-size: 13px;
}

.infos {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 15px;
    margin: 25px 0;
}

.info {
    border: 1px solid #dbe9f8;
    border-radius: 12px;
    padding: 14px;
    background: #f8fbff;
}

table {
    width: 100%;
    border-collapse: collapse;
}

th {
    background: #edf6ff;
    color: #174a82;
    padding: 12px 8px;
    text-align: left;
    font-size: 13px;
}

td {
    padding: 11px 8px;
    border-bottom: 1px solid #e6edf5;
}

.num {
    text-align: right;
}

.total {
    width: 350px;
    max-width: 100%;
    margin-left: auto;
    margin-top: 25px;
}

.ligne-total {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
}

.grand-total {
    border-top: 2px solid #1677ff;
    margin-top: 7px;
    padding-top: 12px;
    font-size: 18px;
    font-weight: 900;
}

.statut {
    display: inline-block;
    padding: 6px 12px;
    border-radius: 30px;
    background: #eaf4ff;
    color: #1261c9;
    font-weight: 700;
}

.imprimer {
    margin-top: 30px;
    text-align: center;
}

.imprimer button {
    border: 0;
    background: #1677ff;
    color: white;
    padding: 13px 22px;
    border-radius: 10px;
    font-weight: 800;
    cursor: pointer;
}

@media print {

    body {
        background: white;
    }

    .facture {
        width: 100%;
        max-width: none;
        margin: 0;
        box-shadow: none;
    }

    .imprimer {
        display: none;
    }
}

@media(max-width:600px) {

    .facture {
        padding: 18px;
    }

    .entete {
        flex-direction: column;
    }

    .titre {
        text-align: left;
    }

    .infos {
        grid-template-columns: 1fr;
    }

    table {
        font-size: 12px;
    }

    th,
    td {
        padding: 8px 5px;
    }
}

</style>

</head>

<body>

<div class="facture">

    <div class="entete">

        <div>
            <div class="logo">
                LAMBEMAH GESTION
            </div>

            <div class="sous-logo">
                Gestion des ventes
            </div>
        </div>

        <div class="titre">
            FACTURE DE VENTE

            <small>
                <?= h($ref) ?>
            </small>
        </div>

    </div>

    <div class="infos">

        <div class="info">

            <strong>Client</strong>

            <br>

            <?= h($client) ?>

        </div>

        <div class="info">

            <strong>Date</strong>

            <br>

            <?= h(
                date(
                    'd/m/Y H:i',
                    strtotime($rows[0]['date_vente'] ?? 'now')
                )
            ) ?>

        </div>

    </div>

    <table>

        <thead>

            <tr>

                <th>Article</th>

                <th>Catégorie</th>

                <th class="num">Qté</th>

                <th class="num">Prix</th>

                <th class="num">Montant</th>

            </tr>

        </thead>

        <tbody>

        <?php foreach ($rows as $row): ?>

            <?php
            $montant =
                (float)$row['quantite'] *
                (float)$row['prix_unitaire'];
            ?>

            <tr>

                <td>
                    <?= h($row['nom'] ?? 'Article') ?>
                </td>

                <td>
                    <?= h($row['categorie'] ?? '') ?>
                </td>

                <td class="num">
                    <?= h($row['quantite']) ?>
                </td>

                <td class="num">
                    <?= money($row['prix_unitaire']) ?>
                </td>

                <td class="num">
                    <?= money($montant) ?>
                </td>

            </tr>

        <?php endforeach; ?>

        </tbody>

    </table>

    <div class="total">

        <div class="ligne-total">
            <span>Total</span>
            <strong><?= money($total) ?></strong>
        </div>

        <div class="ligne-total">
            <span>Déjà payé</span>
            <strong><?= money($paid) ?></strong>
        </div>

        <div class="ligne-total">
            <span>Reste</span>
            <strong><?= money($rest) ?></strong>
        </div>

        <div class="ligne-total grand-total">
            <span>Statut</span>

            <span class="statut">
                <?= h($status) ?>
            </span>
        </div>

    </div>

    <div class="imprimer">

        <button onclick="window.print()">
            🖨️ Imprimer / Enregistrer en PDF
        </button>

    </div>

</div>

</body>

</html>

<?php
exit;
}

/* =========================================================
   TRAITEMENTS POST
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        /* =====================================================
           NOUVELLE FACTURE / MODIFICATION
        ===================================================== */

        if (isset($_POST['save_vente'])) {

            $client = cleanText(
                $_POST['client'] ?? 'Client comptant'
            );

            if ($client === '') {
                $client = 'Client comptant';
            }

            $editRef = cleanText(
                $_POST['edit_ref'] ?? ''
            );

            $ids = $_POST['produit_id'] ?? [];
            $qtys = $_POST['quantite'] ?? [];
            $prices = $_POST['prix_unitaire'] ?? [];

            $lines = [];

            for ($i = 0; $i < count($ids); $i++) {

                $pid = (int)($ids[$i] ?? 0);
                $qty = (int)($qtys[$i] ?? 0);
                $price = (float)($prices[$i] ?? 0);

                if (
                    $pid > 0 &&
                    $qty > 0 &&
                    $price > 0
                ) {

                    $lines[] = [
                        'pid' => $pid,
                        'qty' => $qty,
                        'price' => $price
                    ];
                }
            }

            if (!$lines) {
                throw new Exception(
                    "Ajoutez au moins un article."
                );
            }

            $newTotal = 0;

            foreach ($lines as $line) {

                $newTotal +=
                    $line['qty'] *
                    $line['price'];
            }

            /* =================================================
               MODIFICATION
            ================================================= */

            if ($editRef !== '') {

                $oldRows = getInvoiceRows(
                    $conn,
                    $editRef
                );

                if (!$oldRows) {
                    throw new Exception(
                        "Facture introuvable."
                    );
                }

                $oldTotal = invoiceTotal(
                    $oldRows
                );

                $oldPaid = invoicePaid(
                    $oldRows
                );

                /*
                 * Facture totalement payée =
                 * verrouillée.
                 */

                if ($oldPaid >= $oldTotal - 0.01) {

                    throw new Exception(
                        "Cette facture est totalement payée et verrouillée."
                    );
                }

                /*
                 * On ne peut pas mettre le total
                 * sous le montant déjà payé.
                 */

                if ($newTotal < $oldPaid - 0.01) {

                    throw new Exception(
                        "Le nouveau total ne peut pas être inférieur au montant déjà payé."
                    );
                }

                $conn->begin_transaction();

                try {

                    /*
                     * 1. Restaurer ancien stock
                     */

                    foreach ($oldRows as $old) {

                        $pid = (int)$old['produit_id'];
                        $qty = (int)$old['quantite'];

                        $stmt = $conn->prepare(
                            "UPDATE produits
                             SET stock = stock + ?
                             WHERE id = ?"
                        );

                        if (!$stmt) {
                            throw new Exception(
                                "Erreur restauration stock."
                            );
                        }

                        $stmt->bind_param(
                            "ii",
                            $qty,
                            $pid
                        );

                        $stmt->execute();
                        $stmt->close();
                    }

                    /*
                     * 2. Supprimer anciennes lignes
                     */

                    $safeRef =
                        $conn->real_escape_string(
                            $editRef
                        );

                    $conn->query(
                        "DELETE FROM ventes
                         WHERE description LIKE
                         '%FACTURE=$safeRef|%'"
                    );

                    $conn->query(
                        "DELETE FROM mouvements
                         WHERE type='SORTIE'
                         AND description LIKE
                         '%FACTURE=$safeRef|%'"
                    );

                    /*
                     * 3. Recréer les nouvelles lignes
                     */

                    $date = date(
                        'Y-m-d H:i:s'
                    );

                    $newRest =
                        max(
                            0,
                            $newTotal - $oldPaid
                        );

                    foreach ($lines as $line) {

                        $pid =
                            (int)$line['pid'];

                        $qty =
                            (int)$line['qty'];

                        $price =
                            (float)$line['price'];

                        /*
                         * Vérifier produit
                         */

                        $stmt = $conn->prepare(
                            "SELECT id, nom, stock
                             FROM produits
                             WHERE id = ?"
                        );

                        if (!$stmt) {
                            throw new Exception(
                                "Erreur vérification produit."
                            );
                        }

                        $stmt->bind_param(
                            "i",
                            $pid
                        );

                        $stmt->execute();

                        $result =
                            $stmt->get_result();

                        if (
                            !$result ||
                            !$result->num_rows
                        ) {

                            $stmt->close();

                            throw new Exception(
                                "Produit introuvable."
                            );
                        }

                        $product =
                            $result->fetch_assoc();

                        $stmt->close();

                        if (
                            (int)$product['stock']
                            < $qty
                        ) {

                            throw new Exception(
                                "Stock insuffisant pour "
                                .$product['nom']
                                ." (stock disponible : "
                                .$product['stock']
                                .")."
                            );
                        }

                        $vid =
                            nextId(
                                $conn,
                                'ventes'
                            );

                        $montant =
                            $qty * $price;

                        $description =
                            "FACTURE="
                            .$editRef
                            ."|CLIENT="
                            .$client
                            ."|PAYE="
                            .$oldPaid
                            ."|RESTE="
                            .$newRest
                            ."|VENTE";

                        /*
                         * Vente
                         */

                        $stmt = $conn->prepare(
                            "INSERT INTO ventes
                            (
                                id,
                                produit_id,
                                quantite,
                                prix_unitaire,
                                montant,
                                description,
                                date_vente
                            )
                            VALUES
                            (?,?,?,?,?,?,?)"
                        );

                        if (!$stmt) {
                            throw new Exception(
                                "Erreur insertion vente."
                            );
                        }

                        $stmt->bind_param(
                            "iiiddss",
                            $vid,
                            $pid,
                            $qty,
                            $price,
                            $montant,
                            $description,
                            $date
                        );

                        $stmt->execute();
                        $stmt->close();

                        /*
                         * Mouvement stock
                         */

                        $mid =
                            nextId(
                                $conn,
                                'mouvements'
                            );

                        $movementDescription =
                            "FACTURE="
                            .$editRef
                            ."|CLIENT="
                            .$client
                            ."|SORTIE VENTE";

                        $stmt = $conn->prepare(
                            "INSERT INTO mouvements
                            (
                                id,
                                produit_id,
                                type,
                                quantite,
                                prix,
                                description,
                                date_mouvement
                            )
                            VALUES
                            (?,?,'SORTIE',?,?,?,?)"
                        );

                        if (!$stmt) {
                            throw new Exception(
                                "Erreur mouvement stock."
                            );
                        }

                        $stmt->bind_param(
                            "iiidss",
                            $mid,
                            $pid,
                            $qty,
                            $price,
                            $movementDescription,
                            $date
                        );

                        $stmt->execute();
                        $stmt->close();

                        /*
                         * Nouveau stock
                         */

                        $stmt = $conn->prepare(
                            "UPDATE produits
                             SET stock = stock - ?
                             WHERE id = ?"
                        );

                        if (!$stmt) {
                            throw new Exception(
                                "Erreur mise à jour stock."
                            );
                        }

                        $stmt->bind_param(
                            "ii",
                            $qty,
                            $pid
                        );

                        $stmt->execute();
                        $stmt->close();
                    }

                    $conn->commit();

                    flash(
                        'success',
                        "Facture $editRef modifiée avec succès."
                    );

                    header(
                        "Location: ventes.php?facture="
                        .urlencode($editRef)
                    );

                    exit;

                } catch (Throwable $e) {

                    $conn->rollback();

                    throw $e;
                }
            }

            /* =================================================
               NOUVELLE FACTURE
            ================================================= */

            $ref =
                "VEN-"
                .date('Ymd-His')
                ."-"
                .nextId(
                    $conn,
                    'ventes'
                );

            $conn->begin_transaction();

            try {

                $date =
                    date('Y-m-d H:i:s');

                foreach ($lines as $line) {

                    $pid =
                        (int)$line['pid'];

                    $qty =
                        (int)$line['qty'];

                    $price =
                        (float)$line['price'];

                    /*
                     * Vérification produit
                     */

                    $stmt = $conn->prepare(
                        "SELECT id, nom, stock
                         FROM produits
                         WHERE id = ?"
                    );

                    if (!$stmt) {
                        throw new Exception(
                            "Erreur vérification produit."
                        );
                    }

                    $stmt->bind_param(
                        "i",
                        $pid
                    );

                    $stmt->execute();

                    $result =
                        $stmt->get_result();

                    if (
                        !$result ||
                        !$result->num_rows
                    ) {

                        $stmt->close();

                        throw new Exception(
                            "Produit introuvable."
                        );
                    }

                    $product =
                        $result->fetch_assoc();

                    $stmt->close();

                    /*
                     * Vérification stock
                     */

                    if (
                        (int)$product['stock']
                        < $qty
                    ) {

                        throw new Exception(
                            "Stock insuffisant pour "
                            .$product['nom']
                            ." (stock : "
                            .$product['stock']
                            ")."
                        );
                    }

                    $vid =
                        nextId(
                            $conn,
                            'ventes'
                        );

                    $montant =
                        $qty * $price;

                    $description =
                        "FACTURE="
                        .$ref
                        ."|CLIENT="
                        .$client
                        ."|PAYE=0"
                        ."|RESTE="
                        .$newTotal
                        ."|VENTE";

                    /*
                     * Vente
                     */

                    $stmt = $conn->prepare(
                        "INSERT INTO ventes
                        (
                            id,
                            produit_id,
                            quantite,
                            prix_unitaire,
                            montant,
                            description,
                            date_vente
                        )
                        VALUES
                        (?,?,?,?,?,?,?)"
                    );

                    if (!$stmt) {
                        throw new Exception(
                            "Erreur insertion vente."
                        );
                    }

                    $stmt->bind_param(
                        "iiiddss",
                        $vid,
                        $pid,
                        $qty,
                        $price,
                        $montant,
                        $description,
                        $date
                    );

                    $stmt->execute();
                    $stmt->close();

                    /*
                     * Mouvement
                     */

                    $mid =
                        nextId(
                            $conn,
                            'mouvements'
                        );

                    $movementDescription =
                        "FACTURE="
                        .$ref
                        ."|CLIENT="
                        .$client
                        ."|SORTIE VENTE";

                    $stmt = $conn->prepare(
                        "INSERT INTO mouvements
                        (
                            id,
                            produit_id,
                            type,
                            quantite,
                            prix,
                            description,
                            date_mouvement
                        )
                        VALUES
                        (?,?,'SORTIE',?,?,?,?)"
                    );

                    if (!$stmt) {
                        throw new Exception(
                            "Erreur mouvement."
                        );
                    }

                    $stmt->bind_param(
                        "iiidss",
                        $mid,
                        $pid,
                        $qty,
                        $price,
                        $movementDescription,
                        $date
                    );

                    $stmt->execute();
                    $stmt->close();

                    /*
                     * Déduire stock
                     */

                    $stmt = $conn->prepare(
                        "UPDATE produits
                         SET stock = stock - ?
                         WHERE id = ?"
                    );

                    if (!$stmt) {
                        throw new Exception(
                            "Erreur mise à jour stock."
                        );
                    }

                    $stmt->bind_param(
                        "ii",
                        $qty,
                        $pid
                    );

                    $stmt->execute();
                    $stmt->close();
                }

                $conn->commit();

                flash(
                    'success',
                    "Facture $ref enregistrée avec succès."
                );

                header(
                    "Location: ventes.php?facture="
                    .urlencode($ref)
                );

                exit;

            } catch (Throwable $e) {

                $conn->rollback();

                throw $e;
            }
        }

        /* =====================================================
           ENCAISSEMENT
        ===================================================== */

        if (isset($_POST['encaisser_facture'])) {

            $ref =
                cleanText(
                    $_POST['ref'] ?? ''
                );

            $montant =
                (float)(
                    $_POST['montant'] ?? 0
                );

            if ($montant <= 0) {

                throw new Exception(
                    "Entrez un montant valide."
                );
            }

            $rows =
                getInvoiceRows(
                    $conn,
                    $ref
                );

            if (!$rows) {

                throw new Exception(
                    "Facture introuvable."
                );
            }

            $total =
                invoiceTotal($rows);

            $oldPaid =
                invoicePaid($rows);

            $reste =
                max(
                    0,
                    $total - $oldPaid
                );

            if ($reste <= 0.01) {

                throw new Exception(
                    "Cette facture est déjà totalement payée."
                );
            }

            if ($montant > $reste + 0.01) {

                throw new Exception(
                    "Le paiement dépasse le reste de la facture."
                );
            }

            $newPaid =
                $oldPaid + $montant;

            $newRest =
                max(
                    0,
                    $total - $newPaid
                );

            $client =
                invoiceClient($rows);

            $safeRef =
                $conn->real_escape_string(
                    $ref
                );

            $description =
                "FACTURE="
                .$ref
                ."|CLIENT="
                .$client
                ."|PAYE="
                .$newPaid
                ."|RESTE="
                .$newRest
                ."|VENTE";

            $stmt = $conn->prepare(
                "UPDATE ventes
                 SET description = ?
                 WHERE description LIKE ?"
            );

            if (!$stmt) {

                throw new Exception(
                    "Erreur paiement."
                );
            }

            $like =
                "%FACTURE="
                .$safeRef
                ."|%";

            $stmt->bind_param(
                "ss",
                $description,
                $like
            );

            $stmt->execute();
            $stmt->close();

            flash(
                'success',
                "Paiement enregistré : "
                .money($montant)
            );

            header(
                "Location: ventes.php?facture="
                .urlencode($ref)
            );

            exit;
        }

        /* =====================================================
           SUPPRESSION FACTURE
        ===================================================== */

        if (isset($_POST['supprimer_facture'])) {

            $ref =
                cleanText(
                    $_POST['ref'] ?? ''
                );

            $rows =
                getInvoiceRows(
                    $conn,
                    $ref
                );

            if (!$rows) {

                throw new Exception(
                    "Facture introuvable."
                );
            }

            $paid =
                invoicePaid($rows);

            if ($paid > 0.01) {

                throw new Exception(
                    "Impossible de supprimer une facture ayant déjà reçu un paiement."
                );
            }

            $conn->begin_transaction();

            try {

                /*
                 * Restaurer stock
                 */

                foreach ($rows as $row) {

                    $pid =
                        (int)$row['produit_id'];

                    $qty =
                        (int)$row['quantite'];

                    $stmt =
                        $conn->prepare(
                            "UPDATE produits
                             SET stock = stock + ?
                             WHERE id = ?"
                        );

                    $stmt->bind_param(
                        "ii",
                        $qty,
                        $pid
                    );

                    $stmt->execute();
                    $stmt->close();
                }

                $safeRef =
                    $conn->real_escape_string(
                        $ref
                    );

                $conn->query(
                    "DELETE FROM ventes
                     WHERE description LIKE
                     '%FACTURE=$safeRef|%'"
                );

                $conn->query(
                    "DELETE FROM mouvements
                     WHERE type='SORTIE'
                     AND description LIKE
                     '%FACTURE=$safeRef|%'"
                );

                $conn->commit();

                flash(
                    'success',
                    "Facture supprimée."
                );

                header(
                    "Location: ventes.php"
                );

                exit;

            } catch (Throwable $e) {

                $conn->rollback();

                throw $e;
            }
        }

    } catch (Throwable $e) {

        flash(
            'danger',
            $e->getMessage()
        );

        header(
            "Location: ventes.php"
        );

        exit;
    }
}

/* =========================================================
   FLASH
========================================================= */

$flash =
    $_SESSION['flash']
    ?? null;

unset(
    $_SESSION['flash']
);

/* =========================================================
   PRODUITS
========================================================= */

$produits = [];

$result =
    $conn->query(
        "SELECT id, nom, categorie,
                prix_vente, stock
         FROM produits
         ORDER BY nom ASC"
    );

if ($result) {

    while ($row = $result->fetch_assoc()) {

        $produits[] = $row;
    }
}

/* =========================================================
   FACTURE À AFFICHER
========================================================= */

$currentRef =
    cleanText(
        $_GET['facture'] ?? ''
    );

$currentRows = [];

if ($currentRef !== '') {

    $currentRows =
        getInvoiceRows(
            $conn,
            $currentRef
        );
}

/* =========================================================
   FACTURE À MODIFIER
========================================================= */

$editRef =
    cleanText(
        $_GET['modifier'] ?? ''
    );

$editRows = [];

if ($editRef !== '') {

    $editRows =
        getInvoiceRows(
            $conn,
            $editRef
        );

    if ($editRows) {

        $editTotal =
            invoiceTotal(
                $editRows
            );

        $editPaid =
            invoicePaid(
                $editRows
            );

        /*
         * Une facture totalement payée
         * ne peut pas être modifiée.
         */

        if (
            $editPaid >=
            $editTotal - 0.01
        ) {

            $editRows = [];

            flash(
                'danger',
                "Cette facture est totalement payée et verrouillée."
            );

            header(
                "Location: ventes.php?facture="
                .urlencode($editRef)
            );

            exit;
        }
    }
}

/* =========================================================
   LISTE DES FACTURES
========================================================= */

$factures = [];

$sqlFactures = "
    SELECT
        v.description,
        MIN(v.date_vente) AS date_vente
    FROM ventes v
    WHERE v.description LIKE '%FACTURE=%'
    GROUP BY
        SUBSTRING_INDEX(
            SUBSTRING_INDEX(
                v.description,
                'FACTURE=',
                -1
            ),
            '|',
            1
        )
    ORDER BY MIN(v.date_vente) DESC
";

$result =
    $conn->query(
        $sqlFactures
    );

if ($result) {

    while ($row =
        $result->fetch_assoc()
    ) {

        $meta =
            parseMeta(
                $row['description']
            );

        $ref =
            $meta['FACTURE']
            ?? '';

        if ($ref === '') {
            continue;
        }

        $rows =
            getInvoiceRows(
                $conn,
                $ref
            );

        if (!$rows) {
            continue;
        }

        $total =
            invoiceTotal(
                $rows
            );

        $paid =
            invoicePaid(
                $rows
            );

        $rest =
            max(
                0,
                $total - $paid
            );

        $factures[] = [
            'ref' => $ref,
            'client' =>
                invoiceClient($rows),
            'date' =>
                $row['date_vente'],
            'total' => $total,
            'paid' => $paid,
            'rest' => $rest,
            'status' =>
                invoiceStatus(
                    $total,
                    $paid
                ),
            'count' =>
                count($rows)
        ];
    }
}

?>

<!DOCTYPE html>
<html lang="fr">

<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width, initial-scale=1.0">

<title>LAMBEMAH GESTION - Ventes</title>

<style>

/* =========================================================
   DESIGN
========================================================= */

:root {

    --primary: #1677ff;
    --primary-dark: #0d4ea6;
    --primary-light: #eaf4ff;

    --bg: #f3f8ff;
    --white: #ffffff;

    --text: #172033;
    --muted: #6d7b91;

    --border: #dce8f5;

    --success: #159447;
    --success-bg: #e9f9ef;

    --danger: #dc3545;
    --danger-bg: #fff0f1;

    --warning: #d58b00;
    --warning-bg: #fff8df;
}

* {
    box-sizing: border-box;
}

body {

    margin: 0;

    background:
        linear-gradient(
            135deg,
            #edf7ff,
            #f8fbff
        );

    color: var(--text);

    font-family:
        Inter,
        Arial,
        Helvetica,
        sans-serif;
}

a {
    text-decoration: none;
}

.container {

    width: 100%;
    max-width: 1250px;

    margin: auto;

    padding: 22px;
}

/* =========================================================
   HEADER
========================================================= */

.header {

    display: flex;

    justify-content: space-between;

    align-items: center;

    gap: 15px;

    background: white;

    border: 1px solid var(--border);

    border-radius: 20px;

    padding: 18px 22px;

    box-shadow:
        0 8px 30px
        rgba(32, 92, 150, .08);

    margin-bottom: 20px;
}

.brand {

    display: flex;

    align-items: center;

    gap: 12px;
}

.brand-icon {

    width: 48px;
    height: 48px;

    display: flex;

    align-items: center;
    justify-content: center;

    background:
        linear-gradient(
            135deg,
            #1677ff,
            #0d4ea6
        );

    color: white;

    border-radius: 14px;

    font-size: 22px;
}

.brand h1 {

    margin: 0;

    font-size: 21px;

    color: #123c70;
}

.brand p {

    margin: 3px 0 0;

    color: var(--muted);

    font-size: 13px;
}

/* =========================================================
   BUTTONS
========================================================= */

.btn {

    border: 0;

    border-radius: 10px;

    padding: 10px 15px;

    cursor: pointer;

    font-weight: 800;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    gap: 7px;
}

.btn-primary {

    color: white;

    background:
        linear-gradient(
            135deg,
            #1677ff,
            #0d62d1
        );
}

.btn-primary:hover {

    background:
        linear-gradient(
            135deg,
            #0d62d1,
            #094caa
        );
}

.btn-light {

    background: #edf6ff;

    color: #1261c9;
}

.btn-success {

    background: var(--success);

    color: white;
}

.btn-danger {

    background: var(--danger);

    color: white;
}

.btn-warning {

    background: #f5b400;

    color: white;
}

.btn-small {

    padding: 8px 11px;

    font-size: 12px;
}

/* =========================================================
   FLASH
========================================================= */

.alert {

    padding: 14px 17px;

    border-radius: 12px;

    margin-bottom: 18px;

    font-weight: 700;
}

.alert-success {

    background: var(--success-bg);

    color: #11753a;

    border: 1px solid #bfe8cc;
}

.alert-danger {

    background: var(--danger-bg);

    color: #a91d2b;

    border: 1px solid #f2bdc3;
}

/* =========================================================
   GRID
========================================================= */

.grid {

    display: grid;

    grid-template-columns:
        minmax(0, 1.4fr)
        minmax(330px, .8fr);

    gap: 20px;
}

.card {

    background: white;

    border: 1px solid var(--border);

    border-radius: 18px;

    padding: 20px;

    box-shadow:
        0 8px 30px
        rgba(32, 92, 150, .07);
}

.card-title {

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 10px;

    margin-bottom: 18px;
}

.card-title h2 {

    margin: 0;

    color: #123c70;

    font-size: 18px;
}

/* =========================================================
   FORM
========================================================= */

.form-group {

    margin-bottom: 15px;
}

label {

    display: block;

    margin-bottom: 7px;

    color: #43546d;

    font-size: 13px;

    font-weight: 800;
}

input,
select {

    width: 100%;

    border: 1px solid #d6e3f1;

    background: #fbfdff;

    color: #172033;

    padding: 11px 12px;

    border-radius: 10px;

    outline: none;

    font-size: 14px;
}

input:focus,
select:focus {

    border-color: var(--primary);

    box-shadow:
        0 0 0 3px
        rgba(22,119,255,.10);

    background: white;
}

.form-grid {

    display: grid;

    grid-template-columns:
        1fr 1fr;

    gap: 15px;
}

/* =========================================================
   LIGNES FACTURE
========================================================= */

.line-head {

    display: grid;

    grid-template-columns:
        2fr
        .7fr
        1fr
        auto;

    gap: 8px;

    color: var(--muted);

    font-size: 12px;

    font-weight: 800;

    margin-bottom: 7px;
}

.article-line {

    display: grid;

    grid-template-columns:
        2fr
        .7fr
        1fr
        auto;

    gap: 8px;

    align-items: center;

    margin-bottom: 9px;

    padding: 9px;

    border-radius: 12px;

    background: #f7fbff;

    border: 1px solid #e1edf8;
}

.line-total {

    font-size: 13px;

    font-weight: 800;

    color: #175da8;

    text-align: right;
}

.remove-line {

    width: 36px;
    height: 36px;

    border: 0;

    border-radius: 9px;

    background: #fff0f1;

    color: #d52c3c;

    cursor: pointer;

    font-weight: 900;
}

/* =========================================================
   TOTAL
========================================================= */

.total-box {

    background:
        linear-gradient(
            135deg,
            #edf7ff,
            #f7fbff
        );

    border: 1px solid #cfe5fb;

    border-radius: 14px;

    padding: 15px;

    margin-top: 15px;
}

.total-row {

    display: flex;

    justify-content: space-between;

    align-items: center;

    padding: 5px 0;
}

.total-final {

    font-size: 21px;

    color: #0d4ea6;

    font-weight: 900;

    border-top: 2px solid #b8d8f7;

    margin-top: 7px;

    padding-top: 11px;
}

/* =========================================================
   FACTURES
========================================================= */

.facture-list {

    display: flex;

    flex-direction: column;

    gap: 10px;
}

.facture-item {

    border: 1px solid var(--border);

    border-radius: 13px;

    padding: 13px;

    background: #fbfdff;
}

.facture-top {

    display: flex;

    justify-content: space-between;

    gap: 10px;
}

.facture-ref {

    color: #125db5;

    font-weight: 900;

    font-size: 14px;
}

.facture-client {

    color: #66758a;

    font-size: 12px;

    margin-top: 4px;
}

.facture-money {

    display: flex;

    justify-content: space-between;

    gap: 10px;

    margin-top: 10px;

    font-size: 13px;
}

.status {

    display: inline-block;

    padding: 5px 9px;

    border-radius: 30px;

    font-size: 11px;

    font-weight: 900;
}

.status-paid {

    background: #e8f8ee;

    color: #168442;
}

.status-partial {

    background: #fff5d9;

    color: #a46b00;
}

.status-unpaid {

    background: #edf5ff;

    color: #1762b5;
}

.facture-actions {

    display: flex;

    flex-wrap: wrap;

    gap: 7px;

    margin-top: 11px;
}

/* =========================================================
   DETAIL
========================================================= */

.detail-table {

    width: 100%;

    border-collapse: collapse;

    margin-top: 12px;
}

.detail-table th {

    background: #edf6ff;

    color: #19558e;

    padding: 10px;

    text-align: left;
}

.detail-table td {

    padding: 10px;

    border-bottom: 1px solid #e7eef5;
}

.detail-right {

    text-align: right;
}

.locked {

    margin-top: 15px;

    background: #edf8ff;

    border: 1px solid #cbe6fa;

    color: #145a91;

    padding: 13px;

    border-radius: 12px;

    font-weight: 700;
}

/* =========================================================
   PAIEMENT
========================================================= */

.payment-box {

    margin-top: 18px;

    padding: 15px;

    border-radius: 14px;

    background: #f7fbff;

    border: 1px solid var(--border);
}

.payment-form {

    display: grid;

    grid-template-columns:
        1fr auto;

    gap: 9px;

    margin-top: 10px;
}

/* =========================================================
   RESPONSIVE
========================================================= */

@media(max-width:900px) {

    .grid {

        grid-template-columns: 1fr;
    }
}

@media(max-width:650px) {

    .container {

        padding: 10px;
    }

    .header {

        padding: 14px;

        border-radius: 15px;

        align-items: flex-start;

        flex-direction: column;
    }

    .form-grid {

        grid-template-columns: 1fr;
    }

    .line-head {

        display: none;
    }

    .article-line {

        grid-template-columns: 1fr 80px 100px 36px;
    }

    .card {

        padding: 14px;

        border-radius: 15px;
    }

    .brand h1 {

        font-size: 18px;
    }

    .article-line select {

        min-width: 0;
    }

    .payment-form {

        grid-template-columns: 1fr;
    }

    .detail-table {

        font-size: 12px;
    }
}

</style>

</head>

<body>

<div class="container">

    <!-- =====================================================
         HEADER
    ====================================================== -->

    <div class="header">

        <div class="brand">

            <div class="brand-icon">
                🛍️
            </div>

            <div>

                <h1>
                    LAMBEMAH GESTION
                </h1>

                <p>
                    Gestion des ventes et factures
                </p>

            </div>

        </div>

        <a
            href="index.php"
            class="btn btn-light"
        >
            🏠 Accueil
        </a>

    </div>


    <!-- =====================================================
         MESSAGE
    ====================================================== -->

    <?php if ($flash): ?>

        <div
            class="alert
            <?= $flash['type'] === 'success'
                ? 'alert-success'
                : 'alert-danger'
            ?>"
        >

            <?= h($flash['message']) ?>

        </div>

    <?php endif; ?>


    <!-- =====================================================
         DETAIL FACTURE
    ====================================================== -->

    <?php if ($currentRows): ?>

        <?php

        $detailTotal =
            invoiceTotal(
                $currentRows
            );

        $detailPaid =
            invoicePaid(
                $currentRows
            );

        $detailRest =
            max(
                0,
                $detailTotal - $detailPaid
            );

        $detailClient =
            invoiceClient(
                $currentRows
            );

        $detailStatus =
            invoiceStatus(
                $detailTotal,
                $detailPaid
            );

        ?>

        <div class="card">

            <div class="card-title">

                <div>

                    <h2>
                        📄 Facture
                        <?= h($currentRef) ?>
                    </h2>

                    <div
                        style="
                        margin-top:5px;
                        color:#6d7b91;
                        font-size:13px;
                        "
                    >
                        Client :
                        <strong>
                            <?= h($detailClient) ?>
                        </strong>
                    </div>

                </div>

                <span
                    class="status
                    <?=
                    $detailStatus === 'Payée'
                    ? 'status-paid'
                    : (
                        $detailStatus ===
                        'Partiellement payée'
                        ? 'status-partial'
                        : 'status-unpaid'
                    )
                    ?>"
                >
                    <?= h($detailStatus) ?>
                </span>

            </div>


            <table class="detail-table">

                <thead>

                    <tr>

                        <th>
                            Article
                        </th>

                        <th>
                            Qté
                        </th>

                        <th>
                            Prix
                        </th>

                        <th class="detail-right">
                            Montant
                        </th>

                    </tr>

                </thead>

                <tbody>

                <?php foreach (
                    $currentRows as $row
                ): ?>

                    <tr>

                        <td>
                            <?= h(
                                $row['nom']
                                ?? 'Article'
                            ) ?>
                        </td>

                        <td>
                            <?= h(
                                $row['quantite']
                            ) ?>
                        </td>

                        <td>
                            <?= money(
                                $row['prix_unitaire']
                            ) ?>
                        </td>

                        <td class="detail-right">

                            <?= money(
                                $row['quantite']
                                *
                                $row['prix_unitaire']
                            ) ?>

                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>


            <div class="total-box">

                <div class="total-row">

                    <span>
                        Total facture
                    </span>

                    <strong>
                        <?= money($detailTotal) ?>
                    </strong>

                </div>

                <div class="total-row">

                    <span>
                        Déjà payé
                    </span>

                    <strong>
                        <?= money($detailPaid) ?>
                    </strong>

                </div>

                <div class="total-row total-final">

                    <span>
                        Reste
                    </span>

                    <strong>
                        <?= money($detailRest) ?>
                    </strong>

                </div>

            </div>


            <div class="facture-actions">

                <a
                    href="ventes.php"
                    class="btn btn-light"
                >
                    ← Retour
                </a>

                <a
                    href="ventes.php?imprimer=<?= urlencode($currentRef) ?>"
                    target="_blank"
                    class="btn btn-primary"
                >
                    🖨️ Imprimer
                </a>

                <?php if ($detailRest > 0.01): ?>

                    <a
                        href="ventes.php?modifier=<?= urlencode($currentRef) ?>"
                        class="btn btn-warning"
                    >
                        ✏️ Modifier
                    </a>

                <?php endif; ?>

                <?php if ($detailPaid <= 0.01): ?>

                    <form
                        method="post"
                        onsubmit="
                            return confirm(
                                'Supprimer définitivement cette facture ?'
                            );
                        "
                    >

                        <input
                            type="hidden"
                            name="ref"
                            value="<?= h($currentRef) ?>"
                        >

                        <button
                            type="submit"
                            name="supprimer_facture"
                            class="btn btn-danger"
                        >
                            🗑️ Supprimer
                        </button>

                    </form>

                <?php endif; ?>

            </div>


            <?php if ($detailRest > 0.01): ?>

                <div class="payment-box">

                    <strong>
                        💰 Enregistrer un paiement
                    </strong>

                    <form
                        method="post"
                        class="payment-form"
                    >

                        <input
                            type="hidden"
                            name="ref"
                            value="<?= h($currentRef) ?>"
                        >

                        <input
                            type="number"
                            name="montant"
                            min="1"
                            step="1"
                            max="<?= h($detailRest) ?>"
                            placeholder="Montant payé en FG"
                            required
                        >

                        <button
                            type="submit"
                            name="encaisser_facture"
                            class="btn btn-success"
                        >
                            💵 Encaisser
                        </button>

                    </form>

                </div>

            <?php else: ?>

                <div class="locked">

                    🔒 Cette facture est totalement payée.
                    Elle est maintenant verrouillée.

                </div>

            <?php endif; ?>

        </div>

        <br>

    <?php endif; ?>


    <!-- =====================================================
         NOUVELLE / MODIFICATION
    ====================================================== -->

    <?php if ($editRows): ?>

        <?php
        $editClient =
            invoiceClient(
                $editRows
            );
        ?>

        <div class="card">

            <div class="card-title">

                <h2>
                    ✏️ Modifier la facture
                    <?= h($editRef) ?>
                </h2>

                <a
                    href="ventes.php"
                    class="btn btn-light btn-small"
                >
                    Annuler
                </a>

            </div>

    <?php else: ?>

        <div class="grid">

        <div class="card">

            <div class="card-title">

                <h2>
                    🧾 Nouvelle vente
                </h2>

            </div>

    <?php endif; ?>


            <form
                method="post"
                id="venteForm"
            >

                <?php if ($editRows): ?>

                    <input
                        type="hidden"
                        name="edit_ref"
                        value="<?= h($editRef) ?>"
                    >

                <?php endif; ?>


                <div class="form-group">

                    <label>
                        Nom du client
                    </label>

                    <input
                        type="text"
                        name="client"
                        value="<?=
                            h(
                                $editRows
                                ? $editClient
                                : ''
                            )
                        ?>"
                        placeholder="Client comptant"
                    >

                </div>


                <div class="line-head">

                    <span>
                        Article
                    </span>

                    <span>
                        Quantité
                    </span>

                    <span>
                        Prix unitaire
                    </span>

                    <span></span>

                </div>


                <div id="lines">

                    <?php if ($editRows): ?>

                        <?php foreach (
                            $editRows as $row
                        ): ?>

                            <div
                                class="article-line"
                            >

                                <select
                                    name="produit_id[]"
                                    class="product"
                                    required
                                >

                                    <option value="">
                                        Choisir...
                                    </option>

                                    <?php foreach (
                                        $produits
                                        as $p
                                    ): ?>

                                        <option
                                            value="<?= $p['id'] ?>"
                                            data-price="<?= h($p['prix_vente']) ?>"
                                            <?= (int)$p['id'] ===
                                                (int)$row['produit_id']
                                                ? 'selected'
                                                : ''
                                            ?>
                                        >
                                            <?= h($p['nom']) ?>
                                            —
                                            Stock :
                                            <?= h($p['stock']) ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>


                                <input
                                    type="number"
                                    name="quantite[]"
                                    class="qty"
                                    min="1"
                                    value="<?= h($row['quantite']) ?>"
                                    required
                                >


                                <input
                                    type="number"
                                    name="prix_unitaire[]"
                                    class="price"
                                    min="1"
                                    step="1"
                                    value="<?= h($row['prix_unitaire']) ?>"
                                    required
                                >


                                <button
                                    type="button"
                                    class="remove-line"
                                    onclick="removeLine(this)"
                                >
                                    ×
                                </button>

                            </div>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <div class="article-line">

                            <select
                                name="produit_id[]"
                                class="product"
                                required
                            >

                                <option value="">
                                    Choisir un article...
                                </option>

                                <?php foreach (
                                    $produits
                                    as $p
                                ): ?>

                                    <option
                                        value="<?= $p['id'] ?>"
                                        data-price="<?= h($p['prix_vente']) ?>"
                                    >
                                        <?= h($p['nom']) ?>
                                        —
                                        Stock :
                                        <?= h($p['stock']) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>


                            <input
                                type="number"
                                name="quantite[]"
                                class="qty"
                                min="1"
                                value="1"
                                required
                            >


                            <input
                                type="number"
                                name="prix_unitaire[]"
                                class="price"
                                min="1"
                                step="1"
                                placeholder="Prix"
                                required
                            >


                            <button
                                type="button"
                                class="remove-line"
                                onclick="removeLine(this)"
                            >
                                ×
                            </button>

                        </div>

                    <?php endif; ?>

                </div>


                <button
                    type="button"
                    class="btn btn-light"
                    onclick="addLine()"
                    style="margin-top:8px;"
                >
                    ➕ Ajouter un article
                </button>


                <div class="total-box">

                    <div class="total-row total-final">

                        <span>
                            TOTAL
                        </span>

                        <strong id="grandTotal">
                            0 FG
                        </strong>

                    </div>

                </div>


                <button
                    type="submit"
                    name="save_vente"
                    class="btn btn-primary"
                    style="
                    width:100%;
                    margin-top:15px;
                    padding:13px;
                    "
                >

                    <?=
                    $editRows
                    ? '💾 Enregistrer les modifications'
                    : '✅ Enregistrer la facture'
                    ?>

                </button>

            </form>

        </div>


        <?php if (!$editRows): ?>

        <!-- =================================================
             LISTE FACTURES
        ================================================== -->

        <div class="card">

            <div class="card-title">

                <h2>
                    📋 Factures récentes
                </h2>

            </div>

            <?php if (!$factures): ?>

                <div
                    style="
                    text-align:center;
                    color:#75849a;
                    padding:25px 10px;
                    "
                >
                    Aucune facture enregistrée.
                </div>

            <?php else: ?>

                <div class="facture-list">

                    <?php foreach (
                        array_slice(
                            $factures,
                            0,
                            30
                        )
                        as $f
                    ): ?>

                        <div
                            class="facture-item"
                        >

                            <div class="facture-top">

                                <div>

                                    <div class="facture-ref">

                                        <?= h(
                                            $f['ref']
                                        ) ?>

                                    </div>

                                    <div
                                        class="facture-client"
                                    >

                                        <?= h(
                                            $f['client']
                                        ) ?>

                                    </div>

                                </div>

                                <span
                                    class="status
                                    <?=
                                    $f['status']
                                    === 'Payée'
                                    ? 'status-paid'
                                    : (
                                        $f['status']
                                        ===
                                        'Partiellement payée'
                                        ? 'status-partial'
                                        : 'status-unpaid'
                                    )
                                    ?>"
                                >
                                    <?= h(
                                        $f['status']
                                    ) ?>
                                </span>

                            </div>


                            <div class="facture-money">

                                <span>
                                    Total :
                                    <strong>
                                        <?= money(
                                            $f['total']
                                        ) ?>
                                    </strong>
                                </span>

                                <span>
                                    Reste :
                                    <strong>
                                        <?= money(
                                            $f['rest']
                                        ) ?>
                                    </strong>
                                </span>

                            </div>


                            <div class="facture-actions">

                                <a
                                    href="ventes.php?facture=<?= urlencode($f['ref']) ?>"
                                    class="btn btn-light btn-small"
                                >
                                    👁️ Voir
                                </a>

                                <a
                                    href="ventes.php?imprimer=<?= urlencode($f['ref']) ?>"
                                    target="_blank"
                                    class="btn btn-light btn-small"
                                >
                                    🖨️ PDF
                                </a>

                                <?php if (
                                    $f['rest'] > 0.01
                                ): ?>

                                    <a
                                        href="ventes.php?modifier=<?= urlencode($f['ref']) ?>"
                                        class="btn btn-warning btn-small"
                                    >
                                        ✏️ Modifier
                                    </a>

                                <?php else: ?>

                                    <span
                                        class="btn btn-light btn-small"
                                        style="
                                        cursor:default;
                                        "
                                    >
                                        🔒 Verrouillée
                                    </span>

                                <?php endif; ?>

                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

        </div>

        <?php endif; ?>

        </div>

    <?php endif; ?>

</div>


<script>

/* =========================================================
   PRODUITS
========================================================= */

const productOptions = `

<option value="">
    Choisir un article...
</option>

<?php foreach ($produits as $p): ?>

<option
    value="<?= $p['id'] ?>"
    data-price="<?= h($p['prix_vente']) ?>"
>
    <?= h($p['nom']) ?>
    — Stock : <?= h($p['stock']) ?>
</option>

<?php endforeach; ?>

`;


/* =========================================================
   AJOUTER UNE LIGNE
========================================================= */

function addLine() {

    const container =
        document.getElementById('lines');

    if (!container) {
        return;
    }

    const div =
        document.createElement('div');

    div.className =
        'article-line';

    div.innerHTML = `

        <select
            name="produit_id[]"
            class="product"
            required
        >
            ${productOptions}
        </select>

        <input
            type="number"
            name="quantite[]"
            class="qty"
            min="1"
            value="1"
            required
        >

        <input
            type="number"
            name="prix_unitaire[]"
            class="price"
            min="1"
            step="1"
            placeholder="Prix"
            required
        >

        <button
            type="button"
            class="remove-line"
            onclick="removeLine(this)"
        >
            ×
        </button>
    `;

    container.appendChild(div);

    attachProductEvents();

    calculateTotal();
}


/* =========================================================
   SUPPRIMER UNE LIGNE
========================================================= */

function removeLine(button) {

    const container =
        document.getElementById('lines');

    if (!container) {
        return;
    }

    const lines =
        container.querySelectorAll(
            '.article-line'
        );

    if (lines.length <= 1) {

        alert(
            "Il faut garder au moins un article."
        );

        return;
    }

    button
        .closest('.article-line')
        .remove();

    calculateTotal();
}


/* =========================================================
   PRIX AUTOMATIQUE
========================================================= */

function attachProductEvents() {

    document
        .querySelectorAll('.product')
        .forEach(select => {

            select.onchange = function() {

                const option =
                    this.options[
                        this.selectedIndex
                    ];

                const price =
                    option.dataset.price
                    || '';

                const row =
                    this.closest(
                        '.article-line'
                    );

                const input =
                    row.querySelector(
                        '.price'
                    );

                if (
                    input &&
                    (!input.value ||
                     input.dataset.auto === '1')
                ) {

                    input.value = price;

                    input.dataset.auto = '1';
                }

                calculateTotal();
            };
        });

    document
        .querySelectorAll('.qty, .price')
        .forEach(input => {

            input.oninput =
                calculateTotal;
        });
}


/* =========================================================
   CALCUL TOTAL
========================================================= */

function calculateTotal() {

    let total = 0;

    document
        .querySelectorAll(
            '.article-line'
        )
        .forEach(row => {

            const qty =
                parseFloat(
                    row.querySelector(
                        '.qty'
                    )?.value
                    || 0
                );

            const price =
                parseFloat(
                    row.querySelector(
                        '.price'
                    )?.value
                    || 0
                );

            total +=
                qty * price;
        });

    const target =
        document.getElementById(
            'grandTotal'
        );

    if (target) {

        target.textContent =
            new Intl.NumberFormat(
                'fr-FR'
            ).format(total)
            + ' FG';
    }
}


/* =========================================================
   INITIALISATION
========================================================= */

document.addEventListener(
    'DOMContentLoaded',
    function() {

        attachProductEvents();

        calculateTotal();
    }
);

</script>

</body>

</html>
