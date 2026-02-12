<?php
// Configuration
$host = 'localhost';
$username = 'root';
$password = '';
// Database name to create/use
$dbname = 'phlocation';

// Report MySQLi errors
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    // 1. Connect to MySQL server (no database selected yet)
    $mysqli = new mysqli($host, $username, $password);
    
    // 2. Create Database if not exists
    $mysqli->query("CREATE DATABASE IF NOT EXISTS `$dbname`");
    echo "Database '$dbname' created or already exists.<br>";
    
    // 3. Select the database
    $mysqli->select_db($dbname);

    // 4. Import Reference Tables
    $sqlFiles = [
        'phlocation/refProvince.sql',
        'phlocation/refCitymun.sql',
        'phlocation/refBrgy.sql'
    ];

    foreach ($sqlFiles as $file) {
        if (file_exists($file)) {
            echo "Importing $file...<br>";
            $handle = fopen($file, "r");
            if ($handle) {
                // Read line-by-line to avoid max_allowed_packet errors
                $query = '';
                while (($line = fgets($handle)) !== false) {
                    $trimLine = trim($line);
                    if (empty($trimLine) || strpos($trimLine, '--') === 0 || strpos($trimLine, '/*') === 0) {
                        continue;
                    }
                    $query .= $line;
                    if (substr(trim($line), -1) == ';') {
                        $mysqli->query($query);
                        $query = '';
                    }
                }
                fclose($handle);
            }
            echo "Imported $file.<br>";
        } else {
            echo "Warning: File $file not found.<br>";
        }
    }

    // 5. Add Zip Code Column to Ref Tables
    
    // Check/Add zipcode to refcitymun
    $result = $mysqli->query("SHOW COLUMNS FROM refcitymun LIKE 'zipcode'");
    if ($result->num_rows === 0) {
        $mysqli->query("ALTER TABLE refcitymun ADD COLUMN zipcode VARCHAR(10) DEFAULT NULL");
        echo "Added zipcode column to refcitymun.<br>";
    }

    // Check/Add zipcode to refbrgy
    $result = $mysqli->query("SHOW COLUMNS FROM refbrgy LIKE 'zipcode'");
    if ($result->num_rows === 0) {
        $mysqli->query("ALTER TABLE refbrgy ADD COLUMN zipcode VARCHAR(10) DEFAULT NULL");
        echo "Added zipcode column to refbrgy.<br>";
    }

    // 6. Import Zip Codes from JSON
    $jsonFile = 'zips.json';
    if (file_exists($jsonFile)) {
        $jsonData = json_decode(file_get_contents($jsonFile), true);
        
        $updatedCities = 0;
        $updatedBrgys = 0;

        // Prepare Statements
        $updateCityZip = $mysqli->prepare("UPDATE refcitymun SET zipcode = ? WHERE id = ?");
        $updateBrgyZip = $mysqli->prepare("UPDATE refbrgy SET zipcode = ? WHERE id = ?");

        // Cache Cities for matching
        // Map: UPPER(citymunDesc) -> Array
        $cityMap = [];
        $result = $mysqli->query("SELECT id, UPPER(citymunDesc) as name, provCode, citymunCode FROM refcitymun");
        while ($row = $result->fetch_assoc()) {
            $cityMap[$row['name']] = $row;
            
            // Normalize: "CITY OF MANILA" -> "MANILA"
            if (strpos($row['name'], 'CITY OF ') === 0) {
                $cityMap[substr($row['name'], 8)] = $row;
            }
            // Normalize: "BANGUED (CAPITAL)" -> "BANGUED"
            if (strpos($row['name'], ' (CAPITAL)') !== false) {
                 $baseName = str_replace(' (CAPITAL)', '', $row['name']);
                 $cityMap[$baseName] = $row;
                 if (strpos($baseName, 'CITY OF ') === 0) {
                     $cityMap[substr($baseName, 8)] = $row;
                 }
            }
        }
        
        // Prepare Brgy Lookup Statement
        $findBrgy = $mysqli->prepare("SELECT id FROM refbrgy WHERE citymunCode = ? AND UPPER(brgyDesc) = ? LIMIT 1");

        // --- Process Cities ---
        if (!empty($jsonData['cities'])) {
            foreach ($jsonData['cities'] as $cityName => $zip) {
                $target = null;
                $upperName = strtoupper($cityName);
                
                if (isset($cityMap[$upperName])) {
                    $target = $cityMap[$upperName];
                } elseif (substr($upperName, -5) === ' CITY') {
                    $baseName = substr($upperName, 0, -5);
                    if (isset($cityMap[$baseName])) {
                        $target = $cityMap[$baseName];
                    }
                }
                
                if ($target) {
                    $updateCityZip->bind_param('si', $zip, $target['id']);
                    $updateCityZip->execute();
                    if ($updateCityZip->affected_rows > 0) $updatedCities++;
                }
            }
        }

        // --- Process Barangays ---
        if (!empty($jsonData['barangays'])) {
            foreach ($jsonData['barangays'] as $key => $zip) {
                $parts = explode('|', $key);
                if (count($parts) === 2) {
                    $jsonCity = strtoupper($parts[0]);
                    $jsonBrgy = strtoupper($parts[1]);
                    
                    // Case A: Manila Districts
                    if ($jsonCity === 'MANILA' && isset($cityMap[$jsonBrgy])) {
                        $cid = $cityMap[$jsonBrgy]['id'];
                        $updateCityZip->bind_param('si', $zip, $cid);
                        $updateCityZip->execute();
                        if ($updateCityZip->affected_rows > 0) $updatedCities++;
                        continue;
                    }

                    // Case B: Standard Barangay Match
                    $targetCity = null;
                    if (isset($cityMap[$jsonCity])) {
                         $targetCity = $cityMap[$jsonCity];
                    } elseif (substr($jsonCity, -5) === ' CITY') {
                        $baseName = substr($jsonCity, 0, -5);
                        if (isset($cityMap[$baseName])) $targetCity = $cityMap[$baseName];
                    }
                    
                    if ($targetCity) {
                        $findBrgy->bind_param('ss', $targetCity['citymunCode'], $jsonBrgy);
                        $findBrgy->execute();
                        $res = $findBrgy->get_result();
                        $row = $res->fetch_assoc();
                        
                        if ($row) {
                            $bid = $row['id'];
                            $updateBrgyZip->bind_param('si', $zip, $bid);
                            $updateBrgyZip->execute();
                            if ($updateBrgyZip->affected_rows > 0) $updatedBrgys++;
                        }
                    }
                }
            }
        }
        
        echo "Updated zip codes for $updatedCities cities and $updatedBrgys barangays.<br>";

    } else {
        echo "Warning: zips.json not found. Zip codes not updated.<br>";
    }
    
    echo "Setup Completed Successfully.";
    $mysqli->close();

} catch (mysqli_sql_exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
