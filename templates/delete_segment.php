<?php
//session_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
session_write_close();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../app_config.php';
header('Content-Type: application/json');

$pdo = getDatabaseConnection();
$table = $prefix . "customer_segment";
$table2 = $prefix . "segment_customers_info";
$id = isset($_POST['id']) ? $_POST['id'] : null;
$shop = isset($_POST['shop']) ? $_POST['shop'] : '';

if (!$id || !$shop) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit;
}

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("
        SELECT segment_id 
        FROM $table
        WHERE id = ? AND shop = ?
        LIMIT 1
    ");
    $stmt->execute([$id, $shop]);
    $segment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$segment) {
        throw new Exception("Segment not found");
    }

    $segment_id = $segment['segment_id'];
    $stmt2 = $pdo->prepare("
        DELETE FROM $table 
        WHERE id = ? AND shop = ?
    ");
    $stmt2->execute([$id, $shop]);
    $checkStmt = $pdo->prepare("
        SELECT 1 
        FROM $table 
        WHERE segment_id = ? AND shop = ?
        LIMIT 1
    ");
    $checkStmt->execute([$segment_id, $shop]);

    $stillExists = $checkStmt->fetchColumn();
    if (!$stillExists) {            
        $deleteStmt = $pdo->prepare("
            DELETE FROM $table2 
            WHERE segment_id = ?
        ");
        $deleteStmt->execute([$segment_id]);
    }
    $pdo->commit();
    echo json_encode([
        'success' => true,
        'customers_deleted' => !$stillExists
    ]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}