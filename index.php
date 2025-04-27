<?php
// Include the database connection
include 'db_connectTest.php';

// Verify connection is valid before using it
if ($conn === false) {
    die("Database connection failed: " . print_r(sqlsrv_errors(), true));
}

// Only include check_attendance.php if this is a POST request (from scanner)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    include 'check_attendance.php';
}

// Function to safely format time for display
function formatForDisplay($value)
{
    if ($value instanceof DateTime) {
        return $value->format('H:i:s');
    } elseif (is_string($value)) {
        $date = DateTime::createFromFormat('H:i:s', $value);
        if ($date) {
            return $date->format('H:i:s');
        }
    }
    return 'N/A';
}

// Function to format duration
function formatForDisplayDuration($value)
{
    if ($value instanceof DateTime) {
        return $value->format('H:i:s');
    } elseif (is_string($value)) {
        return $value; // Already in HH:MM format
    }
    return 'N/A';
}

// Function to format date
function formatForDisplayDate($value)
{
    if ($value instanceof DateTime) {
        return $value->format('m-d-Y');
    } elseif (is_string($value)) {
        $date = DateTime::createFromFormat('Y-m-d', $value);
        if ($date) {
            return $date->format('m-d-Y');
        }
    }
    return 'N/A';
}

// Get OJT list with their latest duration
$sql = "SELECT o.Name, o.Barcode, o.Barcode_Image_Path, 
               (SELECT TOP 1 a.Total_Duration 
                FROM Attendance a 
                WHERE a.OJT_ID = o.OJT_ID 
                ORDER BY a.Time_In DESC) as Total_Duration
        FROM OJT_List o";
if ($conn === false) {
    die("Database connection is not valid");
}
$result = sqlsrv_query($conn, $sql);

if ($result === false) {
    die(print_r(sqlsrv_errors(), true));
}


?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Intern Attendance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    <link rel="stylesheet" href="styles.css">
    <style>
        .Layout {
            display: block;
            margin: 50px;
            width: 100%;
            height: auto;
        }

        .TableDisplay {
            display: flex;
            flex-direction: row;
            align-items: start;
            width: 100%;
            height: auto;
            gap: 50px;
        }

        .Interns {
            display: flex;
            flex-direction: column;
            justify-content: start;
        }

        /* For the barcode images */
        .table img {
            max-width: 150px;
            height: auto;
            display: block;
            margin: 0 auto;
        }

        /* For the table cells */
        .table td {
            vertical-align: middle;
        }

        .status-indicator {
            display: inline-block;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            margin: 0 auto;
        }

        .scanning-active {
            animation: pulse 1.5s infinite;
        }

        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.7);
            }

            70% {
                box-shadow: 0 0 0 10px rgba(220, 53, 69, 0);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(220, 53, 69, 0);
            }
        }

        .Colors {
            display: flex;
            flex-direction: row;
            justify-content: center;
            align-items: center;
            gap: 10px;
            width: 100%;
            height: auto;
            margin: 0 auto;
        }

        .Validation {
            display: flex;
            flex-direction: row;
            justify-content: center;
            align-items: center;
            gap: 120px;
            width: 100%;
            height: auto;
            margin: 0 auto;
        }

        .Green,
        .Yellow,
        .Orange,
        .Red,
        .Blue,
        .Maroon {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            color: white;
            font-weight: bold;
            font-size: 1.2em;
        }

        .Green {
            background-color: #31cd54;
        }

        .Yellow {
            background-color: #ffc107;
        }

        .Orange {
            background-color: #fd7e14;
        }

        .Red {
            background-color: #dc3545;
        }

        .Blue {
            background-color: #0d6efd;
        }

        .Maroon {
            background-color: #800;
        }

        .divider {
            height: 2px;
            width: 100%;
            background: #b1b1b1;
        }

        .ComponentDisplay {
            text-align: center;
            color: #222;
        }
    </style>
</head>

<body>

    <div class="Layout">

        <div class="TableDisplay">
            <div class="LogBook">
                <h3>Attendance Records</h3>
                <div class="table-responsive">
                    <?php
                    // Get attendance records
                    $attendance_sql = "SELECT a.Attendance_ID, o.Name, a.Barcode, o.Barcode_Image_Path,
                               a.Real_Time_In, a.Real_Time_Out, 
                               a.Total_Duration, a.Attendance_Validation
                        FROM Attendance a
                        JOIN OJT_List o ON a.OJT_ID = o.OJT_ID
                        ORDER BY a.Real_Time_In DESC";
                    $attendance_result = sqlsrv_query($conn, $attendance_sql);
                    ?>
                    <table class="table table-striped table-bordered">
                        <thead class="table-dark">
                            <tr>
                                <th>Name</th>
                                <th>Date</th>
                                <th>Time In</th>
                                <th>Time Out</th>
                                <th>Total Duration</th>
                                <th>Validation</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($att_row = sqlsrv_fetch_array($attendance_result, SQLSRV_FETCH_ASSOC)):
                                // Determine validation color based on Attendance_Validation
                                $validationColor = '#cccccc'; // Default gray color
                                $validationTooltip = '';

                                if ($att_row['Attendance_Validation'] === 'Valid') {
                                    $validationColor = '#0d6efd'; // Blue
                                    $validationTooltip = 'Valid';
                                } elseif ($att_row['Attendance_Validation'] === 'Invalid') {
                                    $validationColor = '#880000'; // Maroon
                                    $validationTooltip = 'Invalid';
                                }
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($att_row['Name'] ?? 'N/A'); ?></td>
                                    <td><?php echo $att_row['Real_Time_In'] ? formatForDisplayDate($att_row['Real_Time_In']) : 'N/A'; ?>
                                    </td>
                                    <td><?php echo $att_row['Real_Time_In'] ? formatForDisplay($att_row['Real_Time_In']) : 'N/A'; ?>
                                    </td>
                                    <td><?php echo $att_row['Real_Time_Out'] ? formatForDisplay($att_row['Real_Time_Out']) : 'N/A'; ?>
                                    </td>
                                    <td><?php echo formatForDisplayDuration($att_row['Total_Duration'] ?? 'N/A'); ?></td>
                                    <td style="text-align: center;">
                                        <div style="display: inline-block; width: 20px; height: 20px; border-radius: 50%; background-color: <?php echo $validationColor; ?>;"
                                            title="<?php echo htmlspecialchars($validationTooltip); ?>">
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="Interns">
                <h3>Interns</h3>
                <div class="table-responsive">
                    <table class="table table-striped table-bordered">
                        <thead class="table-dark">
                            <tr>
                                <th>OJT_ID</th>
                                <th>Name</th>
                                <th>Time Complete</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $sql = "SELECT o.OJT_ID, o.Name, 
                       o.Time_Complete, o.OJT_Status, o.OJT_Connect,
                       (SELECT TOP 1 a.Total_Duration 
                        FROM Attendance a 
                        WHERE a.OJT_ID = o.OJT_ID 
                        ORDER BY a.Time_In DESC) as Total_Duration
                FROM OJT_List o";
                            $result = sqlsrv_query($conn, $sql);

                            if ($result === false) {
                                die(print_r(sqlsrv_errors(), true));
                            }

                            while ($row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC)):
                                // Determine status color based on OJT_Status and OJT_Connect
                                $statusColor = '#cccccc'; // Default gray color
                                $statusTooltip = '';

                                if ($row['OJT_Status'] === 'Present' && $row['OJT_Connect'] === 'Online') {
                                    $statusColor = '#31cd54';
                                    $statusTooltip = 'Present & Online';
                                } elseif ($row['OJT_Status'] === 'Present' && $row['OJT_Connect'] === 'Offline') {
                                    $statusColor = '#ffc107';
                                    $statusTooltip = 'Present & Offline';
                                } elseif ($row['OJT_Status'] === 'Absent' && $row['OJT_Connect'] === 'Online') {
                                    $statusColor = '#fd7e14';
                                    $statusTooltip = 'Absent but Present';
                                } elseif ($row['OJT_Status'] === 'Absent' && $row['OJT_Connect'] === 'Offline') {
                                    $statusColor = '#dc3545';
                                    $statusTooltip = 'Absent & Offline';
                                }
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['OJT_ID'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($row['Name'] ?? 'N/A'); ?></td>
                                    <td>
                                        <?php
                                        if (!empty($row['Time_Complete'])) {
                                            if ($row['Time_Complete'] instanceof DateTime) {
                                                echo $row['Time_Complete']->format('H:i:s');
                                            } else {
                                                echo date('H:i:s', strtotime($row['Time_Complete']));
                                            }
                                        } else {
                                            echo 'N/A';
                                        }
                                        ?>
                                    </td>
                                    <td style="text-align: center;">
                                        <div style="display: inline-block; width: 20px; height: 20px; border-radius: 50%; background-color: <?php echo $statusColor; ?>;"
                                            title="<?php echo htmlspecialchars($statusTooltip); ?>">
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>

                </div>
            </div>

            <div class="Legends">

                <div class="ComponentDisplay">
                    <h1>Shift Schedules</h1>
                    <h4>AM Shift</h4>
                    <p>Please time in before 8:30 AM, After it will consider late.
                        <br> Time out before 12:30 PM, or your time will consider Invalid
                    </p>
                    <h4>PM Shift</h4>
                    <p>Please time in Between 12:30 PM - 1:15 PM, After it will consider late.
                        <br> Time out before 8:00 PM, or your time will consider Invalid
                    </p>
                    <div class="divider"></div>
                    <h2>Status legend</h2>
                    <div class="Colors">
                        <div class="Green"><span>Present</span></div>
                        <div class="Yellow"><span>Break</span></div>
                        <div class="Orange"><span>Late</span></div>
                        <div class="Red"><span>Absent</span></div>
                    </div>
                    <br>
                    <h2>Validation legend</h2>
                    <div class="Validation">
                        <div class="Blue"><span>Valid</span></div>
                        <div class="Maroon"><span>Invalid</span></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const barcodeInput = document.createElement('input');
            barcodeInput.type = 'text';
            barcodeInput.id = 'barcode-scanner-input';
            barcodeInput.style.position = 'fixed';
            barcodeInput.style.opacity = '0';
            barcodeInput.style.pointerEvents = 'none';
            document.body.appendChild(barcodeInput);

            let barcodeString = '';
            let lastScanTime = 0;

            // Automatically focus on the barcode input when page loads
            barcodeInput.focus();

            barcodeInput.addEventListener('keypress', function (e) {
                const currentTime = new Date().getTime();
                const timeSinceLastChar = currentTime - lastScanTime;
                lastScanTime = currentTime;

                if (timeSinceLastChar > 100) {
                    barcodeString = '';
                }

                if (e.key === 'Enter') {
                    const now = new Date();
                    const localTime = formatLocalDateTime(now);

                    if (barcodeString.length >= 3) {
                        processScannedBarcode(barcodeString, localTime);
                    }
                    barcodeString = '';
                    // Keep focus on input after scan
                    barcodeInput.focus();
                } else {
                    barcodeString += e.key;
                }
            });

            // Handle click anywhere to focus on barcode input
            document.addEventListener('click', function () {
                barcodeInput.focus();
            });

            function formatLocalDateTime(date) {
                const year = date.getFullYear();
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const day = String(date.getDate()).padStart(2, '0');
                const hours = String(date.getHours()).padStart(2, '0');
                const minutes = String(date.getMinutes()).padStart(2, '0');
                const seconds = String(date.getSeconds()).padStart(2, '0');

                return `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
            }

            function processScannedBarcode(barcode, clientTime) {
                fetch('check_attendance.php')
                    .then(() => {
                        return fetch('scan_barcode.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: `barcode=${encodeURIComponent(barcode)}&client_time=${encodeURIComponent(clientTime)}`
                        });
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            if (data.alert_message) {
                                alert(data.alert_message);
                            }
                            setTimeout(() => location.reload(), 500);
                        } else {
                            alert(data.message || 'Error processing barcode');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('Error processing barcode. Please try again.');
                    });
            }
        });
    </script>
</body>

</html>