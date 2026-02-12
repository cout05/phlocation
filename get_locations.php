<?php
if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
}
require_once 'db_connect.php';

$action = $_GET['action'] ?? '';

try {
    if ($action === 'get_provinces') {
        $result = $mysqli->query("SELECT id, provDesc as name FROM refprovince ORDER BY provDesc ASC");
        echo json_encode($result->fetch_all(MYSQLI_ASSOC));
    } 
    elseif ($action === 'get_cities') {
        $province_id = $_GET['province_id'] ?? null;
        if ($province_id) {
            // Join refprovince to match by ID, then link via provCode
            $stmt = $mysqli->prepare("
                SELECT c.id, c.citymunDesc as name, c.zipcode 
                FROM refcitymun c 
                JOIN refprovince p ON c.provCode = p.provCode 
                WHERE p.id = ? 
                ORDER BY c.citymunDesc ASC
            ");
            $stmt->bind_param("i", $province_id);
            $stmt->execute();
            $result = $stmt->get_result();
            echo json_encode($result->fetch_all(MYSQLI_ASSOC));
        } else {
            // Return all cities
            $result = $mysqli->query("SELECT id, citymunDesc as name, zipcode FROM refcitymun ORDER BY citymunDesc ASC");
            echo json_encode($result->fetch_all(MYSQLI_ASSOC));
        }
    } 
    elseif ($action === 'get_barangays') {
        $city_id = $_GET['city_id'] ?? null;
        if ($city_id) {
            // Join refcitymun to match by ID, then link via citymunCode
            $stmt = $mysqli->prepare("
                SELECT b.id, b.brgyDesc as name, b.zipcode 
                FROM refbrgy b 
                JOIN refcitymun c ON b.citymunCode = c.citymunCode 
                WHERE c.id = ? 
                ORDER BY b.brgyDesc ASC
            ");
            $stmt->bind_param("i", $city_id);
            $stmt->execute();
            $result = $stmt->get_result();
            echo json_encode($result->fetch_all(MYSQLI_ASSOC));
        } else {
            echo json_encode([]);
        }
    }
    elseif ($action === 'get_zip') {
        $city_id = $_GET['city_id'] ?? null;
        $barangay_id = $_GET['barangay_id'] ?? null;
        
        $zip = '';
        
        if ($barangay_id) {
            $stmt = $mysqli->prepare("SELECT zipcode FROM refbrgy WHERE id = ?");
            $stmt->bind_param("i", $barangay_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            if ($row && $row['zipcode']) {
                $zip = $row['zipcode'];
            }
        }
        
        // If no barangay zip found (or not selected), fallback to city zip
        if (empty($zip) && $city_id) {
            $stmt = $mysqli->prepare("SELECT zipcode FROM refcitymun WHERE id = ?");
            $stmt->bind_param("i", $city_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            if ($row && $row['zipcode']) {
                $zip = $row['zipcode'];
            }
        }
        
        echo json_encode(['zipcode' => $zip]);
    }
    else {
        echo json_encode(['error' => 'Invalid action']);
    }
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>
