<?php

// Purpose: Export visitors to CSV files grouped by visit reason
// Author: Jim Schrempp
// Copyright: 2026 Maker Nexus
// Creative Commons: Attribution/Share Alike/Non Commercial (cc) 2026 Maker Nexus
//
// Date: 2026-03-08
//

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'OVLcommonfunctions.php';
$AUTH_BASE_PATH = '../';  

// Include auth if it exists
if (file_exists($AUTH_BASE_PATH . 'auth_check.php')) {
    include $AUTH_BASE_PATH . 'auth_check.php';
    requireRole(['admin']);  // Require admin role only
} else {
    // Auth system not available - for development/testing only
    echo "<!-- Warning: Auth system not loaded - development mode -->\n";
}

$OVLdebug = false; // set to true to see debug messages

// Set default date range - last 3 weeks
$today = new DateTime();
$today->setTimeZone(new DateTimeZone("America/Los_Angeles"));
$endDate = $today->format("Y-m-d");

$startDateObj = clone $today;
$startDateObj->sub(new DateInterval('P21D')); // 3 weeks = 21 days
$startDate = $startDateObj->format("Y-m-d");

// Check if form was submitted
if (isset($_POST['generateCSV'])) {
    $startDate = $_POST['startDate'];
    $endDate = $_POST['endDate'];
    
    // Generate CSV files
    $csvFiles = generateCSVFiles($startDate, $endDate);
    
    // Create ZIP file
    $zipFileName = createZipFile($csvFiles, $startDate, $endDate);
}

// Get the HTML skeleton
if (file_exists($AUTH_BASE_PATH . 'auth_header.php')) {
    ob_start();
    include $AUTH_BASE_PATH . 'auth_header.php';
    echo ob_get_clean();
}

$html = file_get_contents("OVLexportvisitors.html");
if (!$html){
    die("unable to open file");
}

// Replace date placeholders
$html = str_replace("<<STARTDATE>>", $startDate, $html);
$html = str_replace("<<ENDDATE>>", $endDate, $html);

// Add CSV download links if files were generated
$csvLinksHTML = "";
if (isset($zipFileName) && !empty($zipFileName)) {
    $csvLinksHTML = '<div class="csv-links">';
    $csvLinksHTML .= '<h4>Export Ready:</h4>';
    
    // Count total records
    $totalRecords = 0;
    foreach ($csvFiles as $file) {
        $totalRecords += $file['count'];
    }
    
    $csvLinksHTML .= '<a href="' . $zipFileName . '" download class="zip-download">' . 
                     'Download All Visitors (' . count($csvFiles) . ' files, ' . $totalRecords . ' total records)</a>';
    
    $csvLinksHTML .= '<div class="file-details">';
    $csvLinksHTML .= '<p><strong>Files included in ZIP:</strong></p>';
    $csvLinksHTML .= '<ul>';
    foreach ($csvFiles as $file) {
        $csvLinksHTML .= '<li>' . $file['category'] . ': ' . $file['count'] . ' records</li>';
    }
    $csvLinksHTML .= '</ul>';
    $csvLinksHTML .= '</div>';
    
    $csvLinksHTML .= '</div>';
}

$html = str_replace("<<CSVLINKS>>", $csvLinksHTML, $html);

echo $html;

// -------------------------------------
// Functions

/**
 * Generate CSV files for each visit reason category
 */
function generateCSVFiles($startDate, $endDate) {
    global $OVLdebug;
    
    // Get database connection
    $ini_array = parse_ini_file("OVLconfig.ini", true);
    $dbUser = $ini_array["SQL_DB"]["readOnlyUser"];
    $dbPassword = $ini_array["SQL_DB"]["readOnlyPassword"];
    $dbName = $ini_array["SQL_DB"]["dataBaseName"];
    
    $con = mysqli_connect("localhost", $dbUser, $dbPassword, $dbName);
    
    // Check connection
    if (mysqli_connect_errno()) {
        echo "Failed to connect to MySQL: " . mysqli_connect_error();
        return array();
    }
    
    // Define the five categories
    $categories = array(
        array(
            'name' => 'Class or Workshop',
            'filename' => 'visitors_class_workshop.csv',
            'condition' => "visitReason LIKE '%classOrworkshop%'"
        ),
        array(
            'name' => 'Tour',
            'filename' => 'visitors_tour.csv',
            'condition' => "visitReason LIKE '%tour%'"
        ),
        array(
            'name' => 'Guest',
            'filename' => 'visitors_guest.csv',
            'condition' => "visitReason LIKE '%guest%'"
        ),
        array(
            'name' => 'Meetup',
            'filename' => 'visitors_meetup.csv',
            'condition' => "visitReason LIKE '%meetup%'"
        ),
        array(
            'name' => 'None Given',
            'filename' => 'visitors_none_given.csv',
            'condition' => "(visitReason LIKE '%none given%' OR visitReason LIKE '%other%' OR visitReason = '' OR visitReason IS NULL)"
        )
    );
    
    $csvFiles = array();
    
    foreach ($categories as $category) {
        // Build SQL query with base conditions
        $sql = "SELECT nameFirst, nameLast, email, visitReason FROM ovl_visits "
             . "WHERE dateCreatedLocal BETWEEN '" . mysqli_real_escape_string($con, $startDate) . "' "
             . "      AND '" . mysqli_real_escape_string($con, $endDate) . " 23:59:59' "
             . "  AND email != '' "
             . "  AND visitReason NOT LIKE '%forgotbadge%' "
             . "  AND " . $category['condition']
             . " ORDER BY nameLast ASC, nameFirst ASC";
        
        if ($OVLdebug) {
            echo "SQL for " . $category['name'] . ": " . $sql . "<br>";
        }
        
        $result = mysqli_query($con, $sql);
        
        if (!$result) {
            echo "Error: " . $sql . "<br>" . mysqli_error($con);
            continue;
        }
        
        $rowCount = mysqli_num_rows($result);
        
        if ($rowCount > 0) {
            // Create CSV file
            $filepath = $category['filename'];
            $fp = fopen($filepath, 'w');
            
            if ($fp === false) {
                echo "Error: Unable to create file " . $filepath . "<br>";
                continue;
            }
            
            // Write CSV header
            fputcsv($fp, array('First Name', 'Last Name', 'Email', 'Visit Reason'), ",", "\"", "\\");
            
            // Write data rows
            while ($row = mysqli_fetch_assoc($result)) {
                fputcsv($fp, array(
                    $row['nameFirst'],
                    $row['nameLast'],
                    $row['email'],
                    $row['visitReason']
                ), ",", "\"", "\\");
            }
            
            fclose($fp);
            
            $csvFiles[] = array(
                'path' => $filepath,
                'category' => $category['name'],
                'count' => $rowCount
            );
        }
    }
    
    mysqli_close($con);
    
    return $csvFiles;
}

/**
 * Create a ZIP file containing all CSV files
 */
function createZipFile($csvFiles, $startDate, $endDate) {
    global $OVLdebug;
    
    if (empty($csvFiles)) {
        return '';
    }
    
    // Create ZIP filename with date range
    $zipFileName = 'visitor_exports_' . $startDate . '_to_' . $endDate . '.zip';
    
    // Create new ZIP archive
    $zip = new ZipArchive();
    
    if ($zip->open($zipFileName, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
        echo "Error: Unable to create ZIP file<br>";
        return '';
    }
    
    // Add each CSV file to the ZIP
    foreach ($csvFiles as $file) {
        $filePath = $file['path'];
        $fileName = basename($filePath);
        
        if (file_exists($filePath)) {
            $zip->addFile($filePath, $fileName);
            if ($OVLdebug) {
                echo "Added to ZIP: " . $fileName . "<br>";
            }
        }
    }
    
    $zip->close();
    
    // Delete the individual CSV files after creating ZIP
    foreach ($csvFiles as $file) {
        $filePath = $file['path'];
        if (file_exists($filePath)) {
            unlink($filePath);
            if ($OVLdebug) {
                echo "Deleted: " . $filePath . "<br>";
            }
        }
    }
    
    return $zipFileName;
}

/**
 * Echo a string to the user for debugging
 */
function debugToUser($data) {
    global $OVLdebug;
    if ($OVLdebug) {
        echo "<br>" . $data . "<br>";
    }
}

?>
