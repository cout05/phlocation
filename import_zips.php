<?php
require 'db_connect.php';

$jsonFile = 'zips.json';
if (file_exists($jsonFile)) {
    $jsonData = json_decode(file_get_contents($jsonFile), true);
    
    $updatedCities = 0;
    $updatedBrgys = 0;

    echo "Starting Zip Code Import...<br>\n";

    // Prepare Statements
    $updateCityZip = $mysqli->prepare("UPDATE refcitymun SET zipcode = ? WHERE id = ?");
    $updateBrgyZip = $mysqli->prepare("UPDATE refbrgy SET zipcode = ? WHERE id = ?");
    
    // Bind parameters for updates (Variables must be passed by reference)
    $zipVal = '';
    $idVal = 0;
    $updateCityZip->bind_param("si", $zipVal, $idVal);
    $updateBrgyZip->bind_param("si", $zipVal, $idVal);

    // Cache all Cities for flexible matching
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
             
             // Also handle "CITY OF ... (CAPITAL)" -> "..."
             if (strpos($baseName, 'CITY OF ') === 0) {
                 $cityMap[substr($baseName, 8)] = $row;
             }
        }
    }
    echo "Loaded " . count($cityMap) . " cities for matching.<br>\n";
    
    // Prepare Brgy Lookup
    $findBrgy = $mysqli->prepare("SELECT id FROM refbrgy WHERE citymunCode = ? AND UPPER(brgyDesc) = ? LIMIT 1");
    $codeVal = '';
    $bnameVal = '';
    $findBrgy->bind_param("ss", $codeVal, $bnameVal);

    // --- Process Cities ---
    if (!empty($jsonData['cities'])) {
        foreach ($jsonData['cities'] as $cityName => $zip) {
            $target = null;
            $upperName = strtoupper($cityName);
            
            // 1. Direct Match in Cities
            if (isset($cityMap[$upperName])) {
                $target = $cityMap[$upperName];
            }
            // 2. Try removing " CITY" suffix
            elseif (substr($upperName, -5) === ' CITY') {
                $baseName = substr($upperName, 0, -5); 
                if (isset($cityMap[$baseName])) {
                    $target = $cityMap[$baseName];
                }
            }
            
            if ($target) {
                $zipVal = $zip;
                $idVal = $target['id'];
                $updateCityZip->execute();
                if ($updateCityZip->affected_rows > 0) $updatedCities++;
            }
        }
    }

    // --- Process Barangays ---
    if (!empty($jsonData['barangays'])) {
        foreach ($jsonData['barangays'] as $key => $zip) {
            // Key format: "CITY|BARANGAY"
            $parts = explode('|', $key);
            if (count($parts) === 2) {
                $jsonCity = strtoupper($parts[0]);
                $jsonBrgy = strtoupper($parts[1]);
                
                // Case A: Manila Districts (JSON City -> DB Province, JSON Brgy -> DB City)
                if ($jsonCity === 'MANILA' && isset($cityMap[$jsonBrgy])) {
                    $cid = $cityMap[$jsonBrgy]['id'];
                    $zipVal = $zip;
                    $idVal = $cid;
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
                    $codeVal = $targetCity['citymunCode'];
                    $bnameVal = $jsonBrgy;
                    $findBrgy->execute();
                    $res = $findBrgy->get_result();
                    $row = $res->fetch_assoc();
                    
                    if ($row) {
                        $bid = $row['id'];
                        $zipVal = $zip;
                        $idVal = $bid;
                        $updateBrgyZip->execute();
                        if ($updateBrgyZip->affected_rows > 0) $updatedBrgys++;
                    }
                }
            }
        }
    }
    
    echo "Updated zip codes for $updatedCities cities and $updatedBrgys barangays.<br>\n";

} else {
    echo "Warning: zips.json not found. Zip codes not updated.<br>\n";
}
?>
