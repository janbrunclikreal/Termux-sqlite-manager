<?php
// Zakázání zobrazování chyb v produkci
// ini_set('display_errors', 0);
// error_reporting(E_ALL);

session_start();

// --- KONFIGURACE ---
define('DB_DIR', __DIR__ . '/databases');
define('DB_EXTENSION', '.db');
define('PER_PAGE', 50);

// --- ZAJIŠTĚNÍ EXISTENCE ADRESÁŘE ---
if (!is_dir(DB_DIR)) {
    mkdir(DB_DIR, 0775, true);
}

// --- POMOCNÉ FUNKCE ---

function getDatabases(): array
{
    return glob(DB_DIR . '/*' . DB_EXTENSION);
}

function connectToDb(string $dbName): PDO
{
    $path = DB_DIR . '/' . $dbName;
    if (!file_exists($path)) {
        throw new Exception("Databáze '$dbName' neexistuje.");
    }
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON;');
    return $pdo;
}

function handleAjax()
{
    header('Content-Type: application/json');
    try {
        $action = $_POST['action'] ?? '';
        $db = $_SESSION['current_db'] ?? null;
        if (!$db) {
            throw new Exception("Není vybrána žádná databáze.");
        }
        $pdo = connectToDb($db);

        switch ($action) {
            case 'delete_row':
                $table = $_POST['table']; $rowid = $_POST['rowid'];
                $stmt = $pdo->prepare("DELETE FROM `$table` WHERE rowid = ?"); $stmt->execute([$rowid]);
                echo json_encode(['success' => true, 'message' => 'Záznam smazán.']); break;
            case 'drop_table':
                $table = $_POST['table']; $stmt = $pdo->prepare("DROP TABLE `$table`"); $stmt->execute();
                echo json_encode(['success' => true, 'message' => "Tabulka '$table' smazána.", 'redirect' => '?action=list_tables']); break;
            case 'empty_table':
                $table = $_POST['table']; $stmt = $pdo->prepare("DELETE FROM `$table`"); $stmt->execute();
                echo json_encode(['success' => true, 'message' => "Tabulka '$table' vyprázdněna."]); break;
            case 'rename_table':
                $oldName = $_POST['old_name']; $newName = $_POST['new_name'];
                $stmt = $pdo->prepare("ALTER TABLE `$oldName` RENAME TO `$newName`"); $stmt->execute();
                echo json_encode(['success' => true, 'message' => "Tabulka přejmenována na '$newName'.", 'redirect' => '?action=list_tables']); break;
            default: throw new Exception("Neznámá akce.");
        }
    } catch (Exception $e) { http_response_code(500); echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
    exit;
}

function generateCreateTableSql(PDO $pdo, string $tableName, array $columns): string
{
    $sql = "CREATE TABLE `$tableName` (\n"; $columnDefs = []; $primaryKeys = [];
    foreach ($columns as $col) {
        $name = $col['name']; $type = $col['type']; $constraints = [];
        if ($col['notnull']) $constraints[] = 'NOT NULL'; if ($col['unique']) $constraints[] = 'UNIQUE'; if ($col['pk']) $primaryKeys[] = $name;
        if ($col['dflt_value'] !== '') $constraints[] = 'DEFAULT ' . $pdo->quote($col['dflt_value']);
        $columnDefs[] = "    `$name` $type" . (empty($constraints) ? '' : ' ' . implode(' ', $constraints));
    }
    if (!empty($primaryKeys)) $columnDefs[] = "    PRIMARY KEY (`" . implode('`, `', $primaryKeys) . "`)";
    $sql .= implode(",\n", $columnDefs); $sql .= "\n)"; return $sql;
}

function rebuildTable(PDO $pdo, string $tableName, array $newColumns, array $columnsToDelete)
{
    $tempTable = $tableName . '_temp_' . time();
    $oldCols = $pdo->query("PRAGMA table_info(`$tableName`)")->fetchAll(PDO::FETCH_ASSOC);
    $oldColumnDefs = []; foreach ($oldCols as $col) $oldColumnDefs[$col['name']] = $col;
    $newColumnDefs = []; foreach ($newColumns as $col) $newColumnDefs[$col['name']] = $col;
    try {
        $pdo->beginTransaction();
        $createSql = generateCreateTableSql($pdo, $tempTable, $newColumns); $pdo->exec($createSql);
        $columnsToKeep = array_intersect(array_keys($oldColumnDefs), array_keys($newColumnDefs));
        if (!empty($columnsToKeep)) {
            $selectColumns = []; $insertColumns = [];
            foreach ($columnsToKeep as $colName) {
                $insertColumns[] = "`$colName`"; $newType = $newColumnDefs[$colName]['type']; $selectColumns[] = "CAST(`$colName` AS {$newType})";
            }
            $selectList = implode(', ', $selectColumns); $insertList = implode(', ', $insertColumns);
            $copySql = "INSERT INTO `$tempTable` ($insertList) SELECT $selectList FROM `$tableName`"; $pdo->exec($copySql);
        }
        $pdo->exec("DROP TABLE `$tableName`"); $pdo->exec("ALTER TABLE `$tempTable` RENAME TO `$tableName`"); $pdo->commit(); return true;
    } catch (Exception $e) { $pdo->rollBack(); throw $e; }
}

// --- ZPRACOVÁNÍ POST POŽADAVKŮ ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    try {
        if (isset($_POST['create_db'])) { $dbName = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['db_name']); if (empty($dbName)) throw new Exception("Neplatný název databáze."); $dbPath = DB_DIR . '/' . $dbName . DB_EXTENSION; if (file_exists($dbPath)) throw new Exception("Databáze již existuje."); touch($dbPath); header('Location: ?'); exit; }
        if (isset($_POST['delete_db'])) { $dbName = basename($_POST['db_name']); $dbPath = DB_DIR . '/' . $dbName; if (file_exists($dbPath)) { unlink($dbPath); if (($_SESSION['current_db'] ?? '') === $dbName) unset($_SESSION['current_db']); } header('Location: ?'); exit; }
        if (isset($_POST['select_db'])) { $_SESSION['current_db'] = basename($_POST['db_name']); header('Location: ?action=list_tables'); exit; }
        if (isset($_POST['run_query'])) { $query = trim($_POST['sql_query']); if (empty($query)) { header('Location: ?action=sql'); exit; } $_SESSION['last_query'] = $query; $_SESSION['query_result'] = null; $_SESSION['query_error'] = null; $db = $_SESSION['current_db'] ?? null; if (!$db) throw new Exception("Nejprve vyberte databázi."); $pdo = connectToDb($db); $stmt = $pdo->query($query); if ($stmt->columnCount() > 0) { $_SESSION['query_result'] = $stmt->fetchAll(); } else { $_SESSION['query_result'] = "Dotaz úspěšně proveden. Změněno řádků: " . $stmt->rowCount(); } header('Location: ?action=sql'); exit; }
        if (isset($_POST['create_table_interactive'])) { $db = $_SESSION['current_db'] ?? null; if (!$db) throw new Exception("Nejprve vyberte databázi."); $pdo = connectToDb($db); $tableName = $_POST['table_name']; $columns = $_POST['columns']; $createSql = generateCreateTableSql($pdo, $tableName, $columns); $pdo->exec($createSql); header('Location: ?action=list_tables'); exit; }
        if (isset($_POST['save_structure'])) { $db = $_SESSION['current_db'] ?? null; $tableName = $_POST['table_name']; if (!$db) throw new Exception("Nejprve vyberte databázi."); $pdo = connectToDb($db); $newColumns = []; $columnsToDelete = $_POST['delete_column'] ?? []; foreach ($_POST['columns'] as $col) { if (!in_array($col['original_name'] ?? '', $columnsToDelete)) $newColumns[] = $col; } rebuildTable($pdo, $tableName, $newColumns, $columnsToDelete); header("Location: ?action=structure&table=" . urlencode($tableName)); exit; }
        if (isset($_POST['save_row'])) { $db = $_SESSION['current_db'] ?? null; $table = $_POST['table']; $rowid = $_POST['rowid']; if (!$db) throw new Exception("Nejprve vyberte databázi."); $pdo = connectToDb($db); $setParts = []; $values = []; foreach ($_POST['data'] as $column => $value) { $setParts[] = "`$column` = ?"; $values[] = $value; } $values[] = $rowid; $sql = "UPDATE `$table` SET " . implode(', ', $setParts) . " WHERE rowid = ?"; $stmt = $pdo->prepare($sql); $stmt->execute($values); header("Location: ?action=view_table&table=$table"); exit; }
        if (isset($_POST['insert_row'])) { $db = $_SESSION['current_db'] ?? null; $table = $_POST['table']; if (!$db) throw new Exception("Nejprve vyberte databázi."); $pdo = connectToDb($db); $columns = array_keys($_POST['data']); $placeholders = array_fill(0, count($columns), '?'); $sql = "INSERT INTO `$table` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $placeholders) . ")"; $stmt = $pdo->prepare($sql); $stmt->execute(array_values($_POST['data'])); header("Location: ?action=view_table&table=$table"); exit; }
    } catch (Exception $e) { $error_message = $e->getMessage(); }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) { handleAjax(); }

// --- SPOUŠTĚNÍ A ROUTING ---
 $action = $_GET['action'] ?? 'list_dbs'; $db = $_SESSION['current_db'] ?? null; $table = $_GET['table'] ?? null; $page = max(1, (int)($_GET['page'] ?? 1));
 $databases = getDatabases(); $databaseList = array_map(fn($path) => basename($path), $databases);
 $pdo = null; $tables = []; $tableStructure = []; $tableData = []; $totalRows = 0;
if ($db && in_array($db, $databaseList)) { try { $pdo = connectToDb($db); $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $e) { $error_message = "Chyba připojení k DB '$db': " . $e->getMessage(); unset($_SESSION['current_db']); } }
if ($pdo && $table && in_array($table, $tables)) { if ($action === 'structure' || $action === 'edit_structure') $tableStructure = $pdo->query("PRAGMA table_info(`$table`)")->fetchAll(); if ($action === 'view_table' || $action === 'edit_row') { $offset = ($page - 1) * PER_PAGE; $countStmt = $pdo->query("SELECT COUNT(*) FROM `$table`"); $totalRows = $countStmt->fetchColumn(); $dataStmt = $pdo->query("SELECT *, rowid FROM `$table` ORDER BY rowid LIMIT " . PER_PAGE . " OFFSET $offset"); $tableData = $dataStmt->fetchAll(); } }
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Termux SQLite Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        /* --- NOVÝ RESPOZIVNÍ DESIGN --- */
        body { overflow-x: hidden; }
        .main-content { padding: 1rem; }
        .table-responsive { max-height: 70vh; overflow-y: auto; }
        .sql-textarea { font-family: 'Courier New', Courier, monospace; }
        .action-buttons a, .action-buttons button { margin-right: 5px; }
        .navbar-brand { font-weight: bold; }
        .list-group-item.active { background-color: #0d6efd; border-color: #0d6efd; }
        .column-row { border: 1px solid #ced4da; padding: 10px; margin-bottom: 10px; border-radius: 5px; background-color: #fff; }
        .column-row .form-control, .column-row .form-select { font-size: 0.9em; }
        .column-row-marked-delete { background-color: #f8d7da; border-color: #f5c6cb; }
        @media (max-width: 991.98px) { .column-row .row > div { margin-bottom: 0.5rem; } .column-row .form-check { display: flex; align-items: center; } }

        /* STYLY PRO HORNÍ LIŠTU (výchozí stav) */
        .sidebar {
            background-color: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            padding: 0.5rem 1rem;
            overflow-x: auto;
            white-space: nowrap;
        }
        .sidebar .nav-link {
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
        }
        .sidebar .nav-pills .nav-link.active, .sidebar .nav-pills .show > .nav-link {
            background-color: #0d6efd;
        }
        .sidebar .nav-link i { margin-right: 0.5rem; }
        .sidebar .nav-section-title {
            font-size: 0.8rem;
            font-weight: bold;
            color: #6c757d;
            text-transform: uppercase;
            padding: 0.5rem 0 0.25rem 0;
            margin-right: 1rem;
        }

        /* STYLY PRO LEVÝ PANEL (na velkých obrazovkách) */
        @media (min-width: 992px) {
            .flex-container { display: flex; }
            .sidebar {
                position: sticky;
                top: 56px; /* Výška navbaru */
                height: calc(100vh - 56px);
                width: 250px;
                flex-shrink: 0;
                border-right: 1px solid #dee2e6;
                border-bottom: none;
                padding: 1rem;
                overflow-y: auto;
                white-space: normal;
            }
            .sidebar .nav { flex-direction: column; }
            .sidebar .nav-section-title { padding: 1rem 0 0.5rem 0; }
            .main-content {
                flex-grow: 1;
                min-width: 0; /* Zabrání přetečení flex položky */
            }
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-primary sticky-top">
    <div class="container-fluid">
        <a class="navbar-brand" href="?"><i class="bi bi-database"></i> SQLite Manager</a>
        <span class="navbar-text text-white d-none d-md-inline">
            <?php if ($db): ?>
                <i class="bi bi-hdd"></i> DB: <strong><?= htmlspecialchars($db) ?></strong>
                <a href="?action=list_dbs" class="text-warning ms-2"><i class="bi bi-arrow-left-circle"></i> Změnit</a>
            <?php else: ?>
                <i class="bi bi-hdd"></i> Žádná databáze nevybrána
            <?php endif; ?>
        </span>
        <?php if ($db): ?>
            <span class="navbar-text text-white d-md-none">
                <i class="bi bi-hdd"></i> <strong><?= htmlspecialchars($db) ?></strong>
            </span>
        <?php endif; ?>
    </div>
</nav>

<!-- ZMĚNA: Postranní panel je nyní samostatný prvek, nikoliv součást řádku -->
<?php if ($db): ?>
<nav class="sidebar">
    <div class="nav">
        <div class="nav-section-title">Tabulky</div>
        <a href="#" data-bs-toggle="modal" data-bs-target="#createTableModal" class="nav-link link-secondary" title="Vytvořit tabulku">
            <i class="bi bi-plus-circle"></i> Vytvořit
        </a>
        <?php foreach ($tables as $t): ?>
            <a class="nav-link <?= ($table === $t) ? 'active' : '' ?>" href="?action=view_table&table=<?= urlencode($t) ?>">
                <i class="bi bi-table"></i> <?= htmlspecialchars($t) ?>
            </a>
        <?php endforeach; ?>
        <div class="nav-section-title">Nástroje</div>
        <a class="nav-link" href="?action=sql"><i class="bi bi-terminal"></i> Spustit SQL</a>
    </div>
</nav>
<?php endif; ?>

<!-- ZMĚNA: Hlavní obsah je v novém wrapperu pro flexbox -->
<div class="flex-container">
    <main class="main-content">
        <?php if (isset($error_message)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error_message) ?></div>
        <?php endif; ?>

        <?php if ($action === 'list_dbs'): ?>
            <h2>Správa databází</h2>
            <form method="post" class="row g-3 mb-4"><div class="col-auto flex-grow-1"><input type="text" name="db_name" class="form-control" placeholder="Název nové DB" required></div><div class="col-auto"><button type="submit" name="create_db" class="btn btn-success"><i class="bi bi-plus-circle"></i> Vytvořit</button></div></form>
            <div class="list-group">
                <?php if (empty($databaseList)): ?><p class="text-muted">Nebyly nalezeny žádné databáze.</p>
                <?php else: foreach ($databaseList as $dbName): ?>
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        <div><i class="bi bi-file-earmark-binary"></i> <?= htmlspecialchars($dbName) ?></div>
                        <div>
                            <form method="post" style="display:inline;"><input type="hidden" name="db_name" value="<?= htmlspecialchars($dbName) ?>"><button type="submit" name="select_db" class="btn btn-sm btn-primary">Vybrat</button></form>
                            <form method="post" style="display:inline;" onsubmit="return confirm('Opravdu smazat databázi <?= htmlspecialchars($dbName) ?>?')"><input type="hidden" name="db_name" value="<?= htmlspecialchars($dbName) ?>"><button type="submit" name="delete_db" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        <?php elseif ($action === 'list_tables' && $db): ?>
            <h2>Tabulky v databázi: <?= htmlspecialchars($db) ?></h2>
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead><tr><th>Název tabulky</th><th>Akce</th></tr></thead>
                    <tbody>
                        <?php foreach ($tables as $t): ?>
                            <tr>
                                <td><i class="bi bi-table"></i> <?= htmlspecialchars($t) ?></td>
                                <td class="action-buttons">
                                    <a href="?action=view_table&table=<?= urlencode($t) ?>" class="btn btn-sm btn-info" title="Zobrazit data"><i class="bi bi-eye"></i></a>
                                    <a href="?action=structure&table=<?= urlencode($t) ?>" class="btn btn-sm btn-secondary" title="Struktura"><i class="bi bi-list-ul"></i></a>
                                    <a href="?action=edit_structure&table=<?= urlencode($t) ?>" class="btn btn-sm btn-warning" title="Upravit strukturu"><i class="bi bi-gear"></i></a>
                                    <button class="btn btn-sm btn-warning" onclick="showRenameTableModal('<?= htmlspecialchars($t) ?>')" title="Přejmenovat"><i class="bi bi-pencil-square"></i></button>
                                    <button class="btn btn-sm btn-outline-success" onclick="confirmEmptyTable('<?= htmlspecialchars($t) ?>')" title="Vyprázdnit"><i class="bi bi-trash2"></i></button>
                                    <button class="btn btn-sm btn-outline-danger" onclick="confirmDropTable('<?= htmlspecialchars($t) ?>')" title="Smazat"><i class="bi bi-x-circle"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php elseif ($action === 'view_table' && $db && $table): /* ... (zbytek kódu pro view_table, edit_row, insert_row, sql atd. zůstává stejný) ... */ ?>
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h2>Data tabulky: <?= htmlspecialchars($table) ?></h2>
                <div class="btn-toolbar mb-2 mb-md-0"><div class="btn-group me-2"><a href="?action=insert_row&table=<?= urlencode($table) ?>" class="btn btn-sm btn-success"><i class="bi bi-plus-lg"></i> Vložit záznam</a></div></div>
            </div>
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead class="table-dark"><tr><th># (rowid)</th><?php if (!empty($tableData)): foreach (array_keys($tableData[0]) as $column): if ($column !== 'rowid'): ?><th><?= htmlspecialchars($column) ?></th><?php endif; endforeach; endif; ?><th>Akce</th></tr></thead>
                    <tbody><?php foreach ($tableData as $row): ?><tr><td><?= $row['rowid'] ?></td><?php foreach ($row as $column => $value): if ($column !== 'rowid'): ?><td><?= htmlspecialchars($value ?? 'NULL') ?></td><?php endif; endforeach; ?><td class="action-buttons"><a href="?action=edit_row&table=<?= urlencode($table) ?>&rowid=<?= $row['rowid'] ?>" class="btn btn-sm btn-warning"><i class="bi bi-pencil"></i></a><button class="btn btn-sm btn-danger" onclick="deleteRow('<?= urlencode($table) ?>', <?= $row['rowid'] ?>)"><i class="bi bi-trash"></i></button></td></tr><?php endforeach; ?></tbody>
                </table>
            </div>
            <?php if ($totalRows > PER_PAGE): ?><nav><ul class="pagination"><?php for ($i = 1; $i <= ceil($totalRows / PER_PAGE); $i++): ?><li class="page-item <?= ($page == $i) ? 'active' : '' ?>"><a class="page-link" href="?action=view_table&table=<?= urlencode($table) ?>&page=<?= $i ?>"><?= $i ?></a></li><?php endfor; ?></ul></nav><?php endif; ?>
        <?php elseif ($action === 'structure' && $db && $table): ?>
            <h2>Struktura tabulky: <?= htmlspecialchars($table) ?></h2>
            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead class="table-dark"><tr><th>Název sloupce</th><th>Typ</th><th>Not Null</th><th>Výchozí hodnota</th><th>Primární klíč</th></tr></thead>
                    <tbody><?php foreach ($tableStructure as $col): ?><tr><td><?= htmlspecialchars($col['name']) ?></td><td><?= htmlspecialchars($col['type']) ?></td><td><?= $col['notnull'] ? 'Ano' : 'Ne' ?></td><td><?= htmlspecialchars($col['dflt_value'] ?? '') ?></td><td><?= $col['pk'] ? 'Ano' : 'Ne' ?></td></tr><?php endforeach; ?></tbody>
                </table>
            </div>
        <?php elseif ($action === 'edit_structure' && $db && $table): ?>
            <h2>Upravit strukturu tabulky: <?= htmlspecialchars($table) ?></h2>
            <p class="text-warning"><i class="bi bi-exclamation-triangle"></i> <strong>Upozornění:</strong> Úprava struktury (smazání nebo změna sloupce) je v SQLite náročná operace. Aplikace bezpečně přegeneruje tabulku. Doporučuje se záloha.</p>
            <form method="post" id="editStructureForm"><input type="hidden" name="table_name" value="<?= htmlspecialchars($table) ?>"><div id="columns-container"><?php foreach ($tableStructure as $index => $col): ?><div class="column-row" id="column-row-<?= $index ?>"><div class="row g-2 align-items-center"><div class="col-sm-6 col-md-3"><input type="text" class="form-control" name="columns[<?= $index ?>][name]" value="<?= htmlspecialchars($col['name']) ?>" required></div><div class="col-sm-6 col-md-2"><select class="form-select" name="columns[<?= $index ?>][type]"><option value="TEXT" <?= $col['type'] === 'TEXT' ? 'selected' : '' ?>>TEXT</option><option value="INTEGER" <?= $col['type'] === 'INTEGER' ? 'selected' : '' ?>>INTEGER</option><option value="REAL" <?= $col['type'] === 'REAL' ? 'selected' : '' ?>>REAL</option><option value="BLOB" <?= $col['type'] === 'BLOB' ? 'selected' : '' ?>>BLOB</option><option value="NUMERIC" <?= $col['type'] === 'NUMERIC' ? 'selected' : '' ?>>NUMERIC</option></select></div><div class="col-6 col-md-1 form-check"><input class="form-check-input" type="checkbox" name="columns[<?= $index ?>][notnull]" value="1" <?= $col['notnull'] ? 'checked' : '' ?>><label class="form-check-label">Not Null</label></div><div class="col-6 col-md-1 form-check"><input class="form-check-input" type="checkbox" name="columns[<?= $index ?>][pk]" value="1" <?= $col['pk'] ? 'checked' : '' ?>><label class="form-check-label">PK</label></div><div class="col-sm-6 col-md-2"><input type="text" class="form-control" name="columns[<?= $index ?>][dflt_value]" value="<?= htmlspecialchars($col['dflt_value'] ?? '') ?>" placeholder="Výchozí hodnota"></div><div class="col-sm-6 col-md-2 form-check"><input class="form-check-input" type="checkbox" name="delete_column[]" value="<?= htmlspecialchars($col['name']) ?>" id="delete_<?= $index ?>"><label class="form-check-label text-danger" for="delete_<?= $index ?>">Smazat sloupec</label></div><input type="hidden" name="columns[<?= $index ?>][original_name]" value="<?= htmlspecialchars($col['name']) ?>"></div></div><?php endforeach; ?></div><button type="button" class="btn btn-secondary mt-2" onclick="addColumnEdit()"><i class="bi bi-plus-circle"></i> Přidat nový sloupec</button><hr><button type="submit" name="save_structure" class="btn btn-primary"><i class="bi bi-check-lg"></i> Uložit změny</button><a href="?action=structure&table=<?= urlencode($table) ?>" class="btn btn-secondary">Zrušit</a></form>
        <?php elseif ($action === 'edit_row' && $db && $table): ?>
            <?php $rowToEdit = null; foreach($tableData as $row) if($row['rowid'] == $_GET['rowid']) $rowToEdit = $row; if(!$rowToEdit) { echo "<div class='alert alert-danger'>Záznam nebyl nalezen.</div>"; exit; } ?>
            <h2>Upravit záznam v tabulce: <?= htmlspecialchars($table) ?></h2>
            <form method="post"><input type="hidden" name="table" value="<?= htmlspecialchars($table) ?>"><input type="hidden" name="rowid" value="<?= $rowToEdit['rowid'] ?>"><?php foreach ($rowToEdit as $column => $value): ?><?php if ($column !== 'rowid'): ?><div class="mb-3"><label for="col_<?= $column ?>" class="form-label"><?= htmlspecialchars($column) ?></label><input type="text" class="form-control" id="col_<?= $column ?>" name="data[<?= htmlspecialchars($column) ?>]" value="<?= htmlspecialchars($value ?? '') ?>"></div><?php endif; ?><?php endforeach; ?><button type="submit" name="save_row" class="btn btn-primary"><i class="bi bi-check-lg"></i> Uložit změny</button><a href="?action=view_table&table=<?= urlencode($table) ?>" class="btn btn-secondary">Zrušit</a></form>
        <?php elseif ($action === 'insert_row' && $db && $table): ?>
            <?php $struct = $pdo->query("PRAGMA table_info(`$table`)")->fetchAll(); ?>
            <h2>Vložit nový záznam do tabulky: <?= htmlspecialchars($table) ?></h2>
            <form method="post"><input type="hidden" name="table" value="<?= htmlspecialchars($table) ?>"><?php foreach ($struct as $col): ?><div class="mb-3"><label for="col_<?= $col['name'] ?>" class="form-label"><?= htmlspecialchars($col['name']) ?></label><input type="text" class="form-control" id="col_<?= $col['name'] ?>" name="data[<?= htmlspecialchars($col['name']) ?>]" value=""></div><?php endforeach; ?><button type="submit" name="insert_row" class="btn btn-success"><i class="bi bi-plus-lg"></i> Vložit</button><a href="?action=view_table&table=<?= urlencode($table) ?>" class="btn btn-secondary">Zrušit</a></form>
        <?php elseif ($action === 'sql' && $db): ?>
            <h2>Spustit SQL dotaz</h2>
            <form method="post"><div class="mb-3"><textarea name="sql_query" class="form-control sql-textarea" rows="10" placeholder="SELECT * FROM table_name WHERE condition;"><?= htmlspecialchars($_SESSION['last_query'] ?? '') ?></textarea></div><button type="submit" name="run_query" class="btn btn-primary"><i class="bi bi-play-fill"></i> Spustit</button></form>
            <?php if (isset($_SESSION['query_result'])): ?>
                <hr><h4>Výsledek</h4>
                <?php if (is_array($_SESSION['query_result'])): ?>
                    <div class="table-responsive"><table class="table table-striped table-bordered"><thead><tr><?php foreach (array_keys($_SESSION['query_result'][0]) as $col): ?><th><?= htmlspecialchars($col) ?></th><?php endforeach; ?></tr></thead><tbody><?php foreach ($_SESSION['query_result'] as $row): ?><tr><?php foreach ($row as $value): ?><td><?= htmlspecialchars($value ?? 'NULL') ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div>
                <?php else: ?>
                    <div class="alert alert-success"><?= htmlspecialchars($_SESSION['query_result']) ?></div>
                <?php endif; ?>
                <?php unset($_SESSION['query_result'], $_SESSION['last_query']); ?>
            <?php endif; ?>
            <?php if (isset($_SESSION['query_error'])): ?>
                <hr><h4>Chyba</h4><div class="alert alert-danger"><?= htmlspecialchars($_SESSION['query_error']) ?></div><?php unset($_SESSION['query_error']); ?>
            <?php endif; ?>
        <?php else: ?>
            <div class="text-center mt-5"><h1>Vítejte v SQLite Manageru</h1><p class="lead">Pro začátek prosím vyberte nebo vytvořte databázi.</p><a href="?action=list_dbs" class="btn btn-primary btn-lg">Spravovat databáze</a></div>
        <?php endif; ?>
    </main>
</div>

<!-- Modální okna (zůstávají stejná) -->
<div class="modal fade" id="createTableModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-fullscreen-sm-down">
        <div class="modal-content">
            <form method="post" id="createTableForm">
                <div class="modal-header"><h5 class="modal-title">Vytvořit novou tabulku</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3"><label for="table_name" class="form-label">Název tabulky</label><input type="text" class="form-control" name="table_name" id="table_name" required></div>
                    <h5>Sloupce</h5>
                    <div id="columns-container-create">
                        <div class="column-row">
                            <div class="row g-2 align-items-center">
                                <div class="col-sm-6 col-md-3"><input type="text" class="form-control" name="columns[0][name]" placeholder="Název" required></div>
                                <div class="col-sm-6 col-md-2"><select class="form-select" name="columns[0][type]"><option value="TEXT">TEXT</option><option value="INTEGER">INTEGER</option><option value="REAL">REAL</option><option value="BLOB">BLOB</option><option value="NUMERIC">NUMERIC</option></select></div>
                                <div class="col-6 col-md-1 form-check"><input class="form-check-input" type="checkbox" name="columns[0][notnull]" value="1"><label class="form-check-label">Not Null</label></div>
                                <div class="col-6 col-md-1 form-check"><input class="form-check-input" type="checkbox" name="columns[0][pk]" value="1"><label class="form-check-label">PK</label></div>
                                <div class="col-sm-6 col-md-3"><input type="text" class="form-control" name="columns[0][dflt_value]" placeholder="Výchozí hodnota"></div>
                                <div class="col-sm-6 col-md-2"><button type="button" class="btn btn-outline-danger btn-sm w-100" onclick="this.closest('.column-row').remove()"><i class="bi bi-trash"></i></button></div>
                            </div>
                        </div>
                    </div>
                    <button type="button" class="btn btn-secondary mt-2" onclick="addColumnCreate()"><i class="bi bi-plus-circle"></i> Přidat sloupec</button>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Zrušit</button><button type="submit" name="create_table_interactive" class="btn btn-success">Vytvořit</button></div>
            </form>
        </div>
    </div>
</div>
<div class="modal fade" id="renameTableModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen-sm-down">
        <div class="modal-content">
            <form method="post" id="renameTableForm">
                <div class="modal-header"><h5 class="modal-title">Přejmenovat tabulku</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="rename_table"><input type="hidden" name="old_name" id="renameOldName">
                    <div class="mb-3"><label for="new_name" class="form-label">Nový název</label><input type="text" class="form-control" name="new_name" id="renameNewName" required></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Zrušit</button><button type="submit" class="btn btn-warning">Přejmenovat</button></div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
let columnCreateIndex = 1; let columnEditIndex = <?= count($tableStructure) ?? 0 ?>;
function addColumnCreate() { const container = document.getElementById('columns-container-create'); const newRow = document.createElement('div'); newRow.className = 'column-row'; newRow.innerHTML = `<div class="row g-2 align-items-center"><div class="col-sm-6 col-md-3"><input type="text" class="form-control" name="columns[${columnCreateIndex}][name]" placeholder="Název" required></div><div class="col-sm-6 col-md-2"><select class="form-select" name="columns[${columnCreateIndex}][type]"><option value="TEXT">TEXT</option><option value="INTEGER">INTEGER</option><option value="REAL">REAL</option><option value="BLOB">BLOB</option><option value="NUMERIC">NUMERIC</option></select></div><div class="col-6 col-md-1 form-check"><input class="form-check-input" type="checkbox" name="columns[${columnCreateIndex}][notnull]" value="1"><label class="form-check-label">Not Null</label></div><div class="col-6 col-md-1 form-check"><input class="form-check-input" type="checkbox" name="columns[${columnCreateIndex}][pk]" value="1"><label class="form-check-label">PK</label></div><div class="col-sm-6 col-md-3"><input type="text" class="form-control" name="columns[${columnCreateIndex}][dflt_value]" placeholder="Výchozí hodnota"></div><div class="col-sm-6 col-md-2"><button type="button" class="btn btn-outline-danger btn-sm w-100" onclick="this.closest('.column-row').remove()"><i class="bi bi-trash"></i></button></div></div>`; container.appendChild(newRow); columnCreateIndex++; }
function addColumnEdit() { const container = document.getElementById('columns-container'); const newRow = document.createElement('div'); newRow.className = 'column-row'; newRow.id = `column-row-${columnEditIndex}`; newRow.innerHTML = `<div class="row g-2 align-items-center"><div class="col-sm-6 col-md-3"><input type="text" class="form-control" name="columns[${columnEditIndex}][name]" placeholder="Název" required></div><div class="col-sm-6 col-md-2"><select class="form-select" name="columns[${columnEditIndex}][type]"><option value="TEXT">TEXT</option><option value="INTEGER">INTEGER</option><option value="REAL">REAL</option><option value="BLOB">BLOB</option><option value="NUMERIC">NUMERIC</option></select></div><div class="col-6 col-md-1 form-check"><input class="form-check-input" type="checkbox" name="columns[${columnEditIndex}][notnull]" value="1"><label class="form-check-label">Not Null</label></div><div class="col-6 col-md-1 form-check"><input class="form-check-input" type="checkbox" name="columns[${columnEditIndex}][pk]" value="1"><label class="form-check-label">PK</label></div><div class="col-sm-6 col-md-2"><input type="text" class="form-control" name="columns[${columnEditIndex}][dflt_value]" placeholder="Výchozí hodnota"></div><div class="col-sm-6 col-md-2"><small class="text-muted">Nový sloupec</small></div><input type="hidden" name="columns[${columnEditIndex}][original_name]" value=""></div>`; container.appendChild(newRow); columnEditIndex++; }
document.addEventListener('change', function(e) { if (e.target && e.target.matches('input[name^="delete_column"]')) { const row = e.target.closest('.column-row'); if (e.target.checked) { row.classList.add('column-row-marked-delete'); } else { row.classList.remove('column-row-marked-delete'); } } });
function confirmDropTable(tableName) { if (confirm(`Opravdu chcete SMazAT tabulku '${tableName}'? Tuto akci nelze vrátit.`)) { fetch('', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: `action=drop_table&table=${encodeURIComponent(tableName)}` }).then(res => res.json()).then(data => { if (data.success) { alert(data.message); window.location.href = data.redirect; } else { alert('Chyba: ' + data.message); } }).catch(error => alert('Chyba komunikace: ' + error)); } }
function confirmEmptyTable(tableName) { if (confirm(`Opravdu chcete VYPRÁZDNIT tabulku '${tableName}'? Všechna data budou ztracena.`)) { fetch('', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: `action=empty_table&table=${encodeURIComponent(tableName)}` }).then(res => res.json()).then(data => { alert(data.message); if(data.success) location.reload(); }).catch(error => alert('Chyba komunikace: ' + error)); } }
function deleteRow(tableName, rowid) { if (confirm(`Opravdu smazat tento záznam?`)) { fetch('', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: `action=delete_row&table=${encodeURIComponent(tableName)}&rowid=${rowid}` }).then(res => res.json()).then(data => { if (data.success) { alert(data.message); location.reload(); } else { alert('Chyba: ' + data.message); } }).catch(error => alert('Chyba komunikace: ' + error)); } }
function showRenameTableModal(tableName) { document.getElementById('renameOldName').value = tableName; document.getElementById('renameNewName').value = tableName; new bootstrap.Modal(document.getElementById('renameTableModal')).show(); }
document.getElementById('renameTableForm').addEventListener('submit', function(e) { e.preventDefault(); const formData = new FormData(this); fetch('', { method: 'POST', body: formData }).then(res => res.json()).then(data => { if (data.success) { alert(data.message); window.location.href = data.redirect; } else { alert('Chyba: ' + data.message); } }).catch(error => alert('Chyba komunikace: ' + error)); });
</script>
</body>
</html>