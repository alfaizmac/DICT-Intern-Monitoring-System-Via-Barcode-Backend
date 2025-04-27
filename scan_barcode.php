<?php
// Include database connection
include 'db_connectTest.php';

// Set the correct timezone for your location
date_default_timezone_set('Asia/Manila');

// Get the scanned barcode and client time from POST request
$scannedBarcode = $_POST['barcode'] ?? '';
$clientTime = $_POST['client_time'] ?? '';

if (empty($scannedBarcode)) {
    die(json_encode(['success' => false, 'message' => 'No barcode scanned']));
}

try {
    $currentDateTime = new DateTime($clientTime, new DateTimeZone('Asia/Manila'));
} catch (Exception $e) {
    die(json_encode(['success' => false, 'message' => 'Invalid time format: ' . $e->getMessage()]));
}

$currentDate = $currentDateTime->format('Y-m-d');
$currentTime = $currentDateTime->format('H:i:s');
$currentHour = $currentDateTime->format('H');
$currentMinute = $currentDateTime->format('i');
$currentDateTimeFull = $currentDateTime->format('Y-m-d H:i:s');

$am_pm = ($currentHour < 12) ? 'AM' : 'PM';

// Function to check if current time is within lunch break (12:00 PM - 12:29 PM)
function isLunchBreak($time)
{
    $timeObj = DateTime::createFromFormat('H:i:s', $time);
    $startLunch = DateTime::createFromFormat('H:i:s', '12:00:00');
    $endLunch = DateTime::createFromFormat('H:i:s', '12:29:59');

    return ($timeObj >= $startLunch && $timeObj <= $endLunch);
}

// Function to check if time is in PM shift start window (12:30 PM - 12:59 PM)
function isPMStartWindow($time)
{
    $timeObj = DateTime::createFromFormat('H:i:s', $time);
    $startPM = DateTime::createFromFormat('H:i:s', '12:30:00');
    $endPM = DateTime::createFromFormat('H:i:s', '12:59:59');

    return ($timeObj >= $startPM && $timeObj <= $endPM);
}

// Function to check if time is after PM shift end (after 5:00 PM)
function isAfterPMShiftEnd($time)
{
    $timeObj = DateTime::createFromFormat('H:i:s', $time);
    $pmShiftEnd = DateTime::createFromFormat('H:i:s', '17:00:00');

    return ($timeObj > $pmShiftEnd);
}

// Function to check if time is in valid AM shift period (8:00 AM - 12:59 PM)
function isValidAMShift($time)
{
    $timeObj = DateTime::createFromFormat('H:i:s', $time);
    $startAM = DateTime::createFromFormat('H:i:s', '08:00:00');
    $endAM = DateTime::createFromFormat('H:i:s', '12:59:59');

    return ($timeObj >= $startAM && $timeObj <= $endAM);
}

// Function to check if time is in late PM period (1:00 PM - 11:59 PM)
function isLatePM($time)
{
    $timeObj = DateTime::createFromFormat('H:i:s', $time);
    $startLatePM = DateTime::createFromFormat('H:i:s', '13:00:00');
    $endLatePM = DateTime::createFromFormat('H:i:s', '23:59:59');

    return ($timeObj >= $startLatePM && $timeObj <= $endLatePM);
}

function shouldUpdateOJTStatus($time)
{
    $timeObj = DateTime::createFromFormat('H:i:s', $time);
    $startMorning = DateTime::createFromFormat('H:i:s', '00:00:00');
    $endMorning = DateTime::createFromFormat('H:i:s', '08:30:00');
    $startAfternoon = DateTime::createFromFormat('H:i:s', '12:30:00');
    $endAfternoon = DateTime::createFromFormat('H:i:s', '13:15:00');

    return ($timeObj >= $startMorning && $timeObj <= $endMorning) ||
        ($timeObj >= $startAfternoon && $timeObj <= $endAfternoon);
}

function adjustTimeIn($time, $am_pm)
{
    $timeObj = DateTime::createFromFormat('H:i:s', $time);
    $eightAM = DateTime::createFromFormat('H:i:s', '08:00:00');
    $onePM = DateTime::createFromFormat('H:i:s', '13:00:00');

    // AM shift adjustment
    if ($am_pm === 'AM' && $timeObj < $eightAM) {
        return '08:00:00';
    }
    // PM shift adjustment (12:30 PM - 12:59 PM becomes 1:00 PM)
    elseif ($am_pm === 'PM' && isPMStartWindow($time)) {
        return '13:00:00';
    }
    return $time;
}

function adjustTimeOut($timeIn, $timeOut, $am_pm)
{
    $timeInObj = new DateTime($timeIn);
    $timeOutObj = new DateTime($timeOut);
    $eightAM = (new DateTime($timeIn))->setTime(8, 0, 0);
    $twelvePM = (new DateTime($timeIn))->setTime(12, 0, 0);
    $onePM = (new DateTime($timeIn))->setTime(13, 0, 0);
    $fivePM = (new DateTime($timeIn))->setTime(17, 0, 0);

    // AM shift adjustments
    if ($am_pm === 'AM') {
        if ($timeOutObj > $twelvePM) {
            return $twelvePM->format('H:i:s');
        } elseif ($timeInObj->format('H:i:s') === '08:00:00' && $timeOutObj < $eightAM) {
            return $eightAM->format('H:i:s');
        }
    }
    // PM shift adjustments
    elseif ($am_pm === 'PM') {
        // If time-out is during 12:30 PM - 12:59 PM, adjust to 1:00 PM
        if (isPMStartWindow($timeOut)) {
            return $onePM->format('H:i:s');
        }
        // If time-out is after 5:00 PM, adjust to 5:00 PM
        elseif (isAfterPMShiftEnd($timeOut)) {
            return $fivePM->format('H:i:s');
        }
    }

    return $timeOutObj->format('H:i:s');
}

function calculateDuration($datetimeIn, $datetimeOut)
{
    $start = new DateTime($datetimeIn);
    $end = new DateTime($datetimeOut);

    if (!$start || !$end) {
        return '00:00';
    }

    $diff = $start->diff($end);
    $hours = $diff->h + ($diff->days * 24);
    return sprintf('%02d:%02d', $hours, $diff->i);
}

// Check for existing attendance record (now checking for records without Time_Out)
$checkSql = "SELECT * FROM Attendance 
             WHERE Barcode = ? AND CONVERT(date, Time_In) = ? AND Time_Out IS NULL";
$checkParams = array($scannedBarcode, $currentDate);
$checkStmt = sqlsrv_query($conn, $checkSql, $checkParams);

if ($checkStmt === false) {
    die(json_encode(['success' => false, 'message' => 'Check query failed: ' . print_r(sqlsrv_errors(), true)]));
}

if (sqlsrv_has_rows($checkStmt)) {
    // Update Time_Out for existing record
    $row = sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC);

    // Get Time_In from database
    $dbTimeIn = $row['Time_In'] instanceof DateTime ?
        $row['Time_In']->format('Y-m-d H:i:s') :
        $row['Time_In'];

    // Get AM_PM from database
    $dbAmPm = $row['AM_PM'] ?? 'AM';

    // Extract just the time part for comparison
    $dbTimeInTime = date('H:i:s', strtotime($dbTimeIn));

    // Adjust Time_Out if needed
    $adjustedTimeOut = adjustTimeOut($dbTimeIn, $currentTime, $dbAmPm);
    $adjustedDateTimeOut = $currentDateTime->format('Y-m-d') . ' ' . $adjustedTimeOut;

    $timeAdjustmentNote = '';
    if ($adjustedTimeOut !== $currentTime) {
        $timeAdjustmentNote = ' (Adjusted from ' . $currentTime . ')';
    }

    // Calculate duration using adjusted times
    $duration = calculateDuration($dbTimeIn, $adjustedDateTimeOut);

    // Check attendance validation rules
    $attendanceValid = true;
    $message = 'Time-out recorded successfully';
    $alertMessage = '';

    // Get OJT_Status first
    $ojtStatusCheckSql = "SELECT OJT_Status FROM OJT_List WHERE Barcode = ?";
    $ojtStatusParams = array($scannedBarcode);
    $ojtStatusStmt = sqlsrv_query($conn, $ojtStatusCheckSql, $ojtStatusParams);

    if ($ojtStatusStmt && sqlsrv_has_rows($ojtStatusStmt)) {
        $ojtStatusRow = sqlsrv_fetch_array($ojtStatusStmt, SQLSRV_FETCH_ASSOC);
        $ojtStatus = $ojtStatusRow['OJT_Status'];

        // Base validation on OJT_Status
        $attendanceValid = ($ojtStatus == 'Present');

        // Additional time-based validation only if status is Present
        if ($attendanceValid) {
            $currentTimeObj = DateTime::createFromFormat('H:i:s', $currentTime);
            $pmStart = DateTime::createFromFormat('H:i:s', '12:30:00');
            $pmLate = DateTime::createFromFormat('H:i:s', '20:00:00'); // 8:00 PM

            if ($dbAmPm === 'AM' && $currentTimeObj >= $pmStart) {
                // AM shift timing out after 12:30 PM
                $attendanceValid = false;
                $message = 'AM shift completed with late time-out';
                $alertMessage = 'ALERT: Invalid AM shift - timed out after 12:30 PM';
            } elseif ($dbAmPm === 'PM' && $currentTimeObj >= $pmLate) {
                // PM shift timing out after 8:00 PM
                $attendanceValid = false;
                $message = 'PM shift completed with very late time-out';
                $alertMessage = 'ALERT: Invalid PM shift - timed out after 8:00 PM';
            }
        }
    } else {
        $attendanceValid = false;
        $message = 'Error checking OJT status';
        $alertMessage = 'Error verifying your attendance status';
    }

    // Update the attendance record
    $updateSql = "UPDATE Attendance 
             SET Time_Out = ?, Real_Time_Out = ?, Total_Duration = ?,
                 Attendance_Validation = ?
             WHERE Attendance_ID = ?";
    $updateParams = array(
        $adjustedDateTimeOut,  // Adjusted time out
        $currentDateTimeFull,  // Real actual time
        $duration,
        ($attendanceValid ? 'Valid' : 'Invalid'),
        $row['Attendance_ID']
    );
    $updateStmt = sqlsrv_query($conn, $updateSql, $updateParams);

    if ($updateStmt === false) {
        die(json_encode([
            'success' => false,
            'message' => 'Update failed: ' . print_r(sqlsrv_errors(), true),
            'query' => $updateSql,
            'params' => $updateParams
        ]));
    }

    // Update Time_Complete in OJT_List if attendance is valid
    if ($attendanceValid) {
        // Calculate total completed time in minutes
        $timeCompleteUpdateSql = "UPDATE OJT_List 
                            SET Time_Complete = 
                                CASE 
                                    WHEN Time_Complete IS NULL THEN CONVERT(time, ?)
                                    ELSE DATEADD(MINUTE, 
                                        DATEDIFF(MINUTE, '00:00', CONVERT(time, ?)),
                                        CONVERT(datetime, Time_Complete))
                                END
                            WHERE Barcode = ?";
        $timeCompleteParams = array($duration, $duration, $scannedBarcode);
        $timeCompleteStmt = sqlsrv_query($conn, $timeCompleteUpdateSql, $timeCompleteParams);

        if ($timeCompleteStmt === false) {
            error_log('Failed to update Time_Complete: ' . print_r(sqlsrv_errors(), true));
        }
    }

    // Update OJT_Connect status to "Offline" in OJT_List table
    $updateOJTConnectSql = "UPDATE OJT_List SET OJT_Connect = 'Offline' WHERE Barcode = ?";
    $updateOJTConnectParams = array($scannedBarcode);
    $updateOJTConnectStmt = sqlsrv_query($conn, $updateOJTConnectSql, $updateOJTConnectParams);

    if ($updateOJTConnectStmt === false) {
        error_log('Failed to update OJT_Connect status: ' . print_r(sqlsrv_errors(), true));
    }

    echo json_encode([
        'success' => true,
        'action' => 'time_out',
        'duration' => $duration,
        'time_in' => $dbTimeIn,
        'time_out' => $adjustedDateTimeOut,
        'time_adjustment_note' => $timeAdjustmentNote,
        'ojt_connect_status' => 'Offline',
        'message' => $message,
        'alert_message' => $alertMessage,
        'attendance_valid' => $attendanceValid
    ]);
} else {
    // Check if current time is during lunch break (12:00 PM - 12:29 PM)
    if (isLunchBreak($currentTime)) {
        die(json_encode([
            'success' => false,
            'message' => 'Time-in not allowed between 12:00 PM - 12:29 PM. Please try again after 12:30 PM.'
        ]));
    }

    // Create new record with adjusted Time_In
    $adjustedTime = adjustTimeIn($currentTime, $am_pm);

    // Combine date with adjusted time
    $adjustedDateTime = $currentDateTime->format('Y-m-d') . ' ' . $adjustedTime;

    $timeAdjustmentNote = '';
    if ($adjustedTime === '08:00:00' && $currentTime < '08:00:00') {
        $timeAdjustmentNote = ' (Adjusted from ' . $currentTime . ')';
    } elseif ($adjustedTime === '13:00:00' && isPMStartWindow($currentTime)) {
        $timeAdjustmentNote = ' (Adjusted from ' . $currentTime . ')';
    }

    // Determine AM/PM for the adjusted time
    $adjustedTimeObj = DateTime::createFromFormat('H:i:s', $adjustedTime);
    $adjustedHour = $adjustedTimeObj->format('H');
    $adjusted_am_pm = ($adjustedHour < 12) ? 'AM' : 'PM';

    // Modified INSERT statement to include Real_Time_In
    $insertSql = "INSERT INTO Attendance 
                 (OJT_ID, Barcode, Time_In, Real_Time_In, Time_Out, Total_Duration, AM_PM) 
                 VALUES ((SELECT OJT_ID FROM OJT_List WHERE Barcode = ?), ?, ?, ?, NULL, NULL, ?)";
    $insertParams = array(
        $scannedBarcode,
        $scannedBarcode,
        $adjustedDateTime,  // Adjusted time
        $currentDateTimeFull,  // Real actual time
        $adjusted_am_pm
    );
    $insertStmt = sqlsrv_query($conn, $insertSql, $insertParams);

    if ($insertStmt === false) {
        die(json_encode([
            'success' => false,
            'message' => 'Insert failed: ' . print_r(sqlsrv_errors(), true),
            'query' => $insertSql,
            'params' => $insertParams
        ]));
    }

    // Get the newly inserted Attendance_ID
    $attendanceId = '';
    $getIdSql = "SELECT SCOPE_IDENTITY() AS Attendance_ID";
    $getIdStmt = sqlsrv_query($conn, $getIdSql);
    if ($getIdStmt && sqlsrv_has_rows($getIdStmt)) {
        $idRow = sqlsrv_fetch_array($getIdStmt, SQLSRV_FETCH_ASSOC);
        $attendanceId = $idRow['Attendance_ID'];
    }

    // Update OJT_Connect status to "Online" in OJT_List table
    $updateOJTConnectSql = "UPDATE OJT_List SET OJT_Connect = 'Online' WHERE Barcode = ?";
    $updateOJTConnectParams = array($scannedBarcode);
    $updateOJTConnectStmt = sqlsrv_query($conn, $updateOJTConnectSql, $updateOJTConnectParams);

    if ($updateOJTConnectStmt === false) {
        error_log('Failed to update OJT_Connect status: ' . print_r(sqlsrv_errors(), true));
    }

    // Check if we should update OJT_Status
    $ojtStatusUpdated = false;
    if (shouldUpdateOJTStatus($currentTime)) {
        $updateOjtStatusSql = "UPDATE OJT_List SET OJT_Status = 'Present' WHERE Barcode = ?";
        $updateOjtStatusParams = array($scannedBarcode);
        $updateOjtStatusStmt = sqlsrv_query($conn, $updateOjtStatusSql, $updateOjtStatusParams);
        $ojtStatusUpdated = true;

        if ($updateOjtStatusStmt === false) {
            error_log('Failed to update OJT_Status: ' . print_r(sqlsrv_errors(), true));
            $ojtStatusUpdated = false;
        }
    }

    // Update Attendance_Validation based on OJT_Status
    if ($attendanceId) {
        $validationUpdateSql = "UPDATE A 
                          SET A.Attendance_Validation = 
                              CASE 
                                  WHEN O.OJT_Status = 'Present' THEN 'Valid'
                                  WHEN O.OJT_Status = 'Absent' THEN 'Invalid'
                                  ELSE 'Valid' 
                              END
                          FROM Attendance A
                          INNER JOIN OJT_List O ON A.OJT_ID = O.OJT_ID
                          WHERE A.Attendance_ID = ?";
        $validationUpdateParams = array($attendanceId);
        $validationUpdateStmt = sqlsrv_query($conn, $validationUpdateSql, $validationUpdateParams);

        if ($validationUpdateStmt === false) {
            error_log('Failed to update Attendance_Validation: ' . print_r(sqlsrv_errors(), true));
        }
    }

    echo json_encode([
        'success' => true,
        'action' => 'time_in',
        'recorded_time' => $adjustedDateTime,
        'actual_time' => $currentDateTimeFull,
        'time_adjustment_note' => $timeAdjustmentNote,
        'am_pm' => $adjusted_am_pm,
        'ojt_status_updated' => $ojtStatusUpdated,
        'ojt_connect_status' => 'Online'
    ]);
}

sqlsrv_close($conn);
?>