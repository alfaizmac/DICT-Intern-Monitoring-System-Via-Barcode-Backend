<?php
// Include database connection
include 'db_connectTest.php';

// Set the correct timezone
date_default_timezone_set('Asia/Manila');

// Get current time
$currentTime = date('H:i:s');
$currentDate = date('Y-m-d');

// Define reset time windows
$morningWindowStart = '00:00:00';
$morningWindowEnd = '08:15:00';
$afternoonWindowStart = '12:30:00';
$afternoonWindowEnd = '13:15:00';

// Check if we're in either reset window
$inMorningWindow = ($currentTime >= $morningWindowStart && $currentTime <= $morningWindowEnd);
$inAfternoonWindow = ($currentTime >= $afternoonWindowStart && $currentTime <= $afternoonWindowEnd);

// Only proceed if we're in one of the reset windows
if ($inMorningWindow || $inAfternoonWindow) {
    // Check if reset has already been done today for this window
    $resetCheckSql = "SELECT TOP 1 Reset_Time FROM Attendance_Resets 
                      WHERE Reset_Date = ? 
                      AND ((? = 1 AND Reset_Type = 'morning') OR (? = 1 AND Reset_Type = 'afternoon'))";

    $params = array(
        array($currentDate, SQLSRV_PARAM_IN),
        array($inMorningWindow ? 1 : 0, SQLSRV_PARAM_IN),
        array($inAfternoonWindow ? 1 : 0, SQLSRV_PARAM_IN)
    );

    $resetCheckResult = sqlsrv_query($conn, $resetCheckSql, $params);

    // If no reset record found for this window today, perform the reset
    if ($resetCheckResult && !sqlsrv_has_rows($resetCheckResult)) {
        // Check if any intern is still marked "Present"
        $checkPresent = "SELECT TOP 1 OJT_ID FROM OJT_List WHERE OJT_Status = 'Present'";
        $result = sqlsrv_query($conn, $checkPresent);

        if (sqlsrv_has_rows($result)) {
            // Perform the reset
            $updateSql = "UPDATE OJT_List SET OJT_Status = 'Absent'";
            if (sqlsrv_query($conn, $updateSql)) {
                // Record that we've done this reset
                $resetType = $inMorningWindow ? 'morning' : 'afternoon';
                $recordResetSql = "INSERT INTO Attendance_Resets (Reset_Date, Reset_Time, Reset_Type) 
                                   VALUES (?, ?, ?)";
                $params = array(
                    array($currentDate, SQLSRV_PARAM_IN),
                    array($currentTime, SQLSRV_PARAM_IN),
                    array($resetType, SQLSRV_PARAM_IN)
                );
                sqlsrv_query($conn, $recordResetSql, $params);
            }
        }
    }
}
?>