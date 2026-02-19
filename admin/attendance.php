<?php
session_start();
include('db_connect.php');

// Check if user is logged in as admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$message = '';
$message_class = '';

// Handle attendance record deletion
if (isset($_GET['delete_id'])) {
    $delete_id = $_GET['delete_id'];
    
    $delete_sql = "DELETE FROM attendance WHERE attendance_id = ?";
    $delete_stmt = $conn->prepare($delete_sql);
    $delete_stmt->bind_param("i", $delete_id);
    
    if ($delete_stmt->execute()) {
        $message = "✅ Attendance record deleted successfully!";
        $message_class = "success";
    } else {
        $message = "❌ Error deleting attendance record: " . $delete_stmt->error;
        $message_class = "error";
    }
}

// Handle bulk attendance update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['bulk_update'])) {
    $attendance_ids = $_POST['attendance_ids'] ?? [];
    $status = $_POST['bulk_status'];
    
    if (empty($attendance_ids)) {
        $message = "❌ Please select attendance records to update!";
        $message_class = "error";
    } else {
        $placeholders = str_repeat('?,', count($attendance_ids) - 1) . '?';
        $update_sql = "UPDATE attendance SET status = ? WHERE attendance_id IN ($placeholders)";
        $update_stmt = $conn->prepare($update_sql);
        
        $params = array_merge([$status], $attendance_ids);
        $types = str_repeat('i', count($attendance_ids));
        $types = 's' . $types;
        
        $update_stmt->bind_param($types, ...$params);
        
        if ($update_stmt->execute()) {
            $message = "✅ " . count($attendance_ids) . " attendance records updated successfully!";
            $message_class = "success";
        } else {
            $message = "❌ Error updating attendance records: " . $update_stmt->error;
            $message_class = "error";
        }
        $update_stmt->close();
    }
}

// Handle manual attendance entry
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_attendance'])) {
    $student_id = $_POST['student_id'];
    $subject_id = $_POST['subject_id'];
    $attendance_date = $_POST['attendance_date'];
    $status = $_POST['status'];
    
    // Check if attendance record already exists
    $check_sql = "SELECT attendance_id FROM attendance WHERE student_id = ? AND subject_id = ? AND attendance_date = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("iis", $student_id, $subject_id, $attendance_date);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        $message = "❌ Attendance record already exists for this student, subject, and date!";
        $message_class = "error";
    } else {
        $insert_sql = "INSERT INTO attendance (student_id, subject_id, attendance_date, status) VALUES (?, ?, ?, ?)";
        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->bind_param("iiss", $student_id, $subject_id, $attendance_date, $status);
        
        if ($insert_stmt->execute()) {
            $message = "✅ Attendance record added successfully!";
            $message_class = "success";
        } else {
            $message = "❌ Error adding attendance record: " . $insert_stmt->error;
            $message_class = "error";
        }
        $insert_stmt->close();
    }
    $check_stmt->close();
}

// Get filter parameters
$search = $_GET['search'] ?? '';
$subject_filter = $_GET['subject'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$status_filter = $_GET['status'] ?? '';
$student_filter = $_GET['student'] ?? '';

// Build query for attendance records (FIXED: Corrected JOIN for lecturers)
$attendance_sql = "
    SELECT 
        a.attendance_id,
        a.attendance_date,
        a.status,
        s.student_id,
        s.name as student_name,
        sub.subject_id,
        sub.subject_name,
        l.lecturer_name
    FROM attendance a
    JOIN students s ON a.student_id = s.student_id
    JOIN subjects sub ON a.subject_id = sub.subject_id
    LEFT JOIN lecturer_subjects ls ON sub.subject_id = ls.subject_id
    LEFT JOIN lecturers l ON ls.lecturer_id = l.lecturer_id
    WHERE 1=1
";

$params = [];
$types = "";

// Add search filter
if (!empty($search)) {
    $attendance_sql .= " AND (s.name LIKE ? OR sub.subject_name LIKE ? OR l.lecturer_name LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= "sss";
}

// Add subject filter
if (!empty($subject_filter)) {
    $attendance_sql .= " AND a.subject_id = ?";
    $params[] = $subject_filter;
    $types .= "i";
}

// Add student filter
if (!empty($student_filter)) {
    $attendance_sql .= " AND a.student_id = ?";
    $params[] = $student_filter;
    $types .= "i";
}

// Add date filters
if (!empty($date_from)) {
    $attendance_sql .= " AND a.attendance_date >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if (!empty($date_to)) {
    $attendance_sql .= " AND a.attendance_date <= ?";
    $params[] = $date_to;
    $types .= "s";
}

// Add status filter
if (!empty($status_filter)) {
    $attendance_sql .= " AND a.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

$attendance_sql .= " ORDER BY a.attendance_date DESC, sub.subject_name, s.name";

// Execute query
$stmt = $conn->prepare($attendance_sql);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $attendance_result = $stmt->get_result();
} else {
    error_log("Attendance SQL Error: " . $conn->error);
    $attendance_result = null;
}

// Get all subjects for dropdown
$subjects_sql = "SELECT subject_id, subject_name FROM subjects ORDER BY subject_name";
$subjects_result = $conn->query($subjects_sql);

// Get all students for dropdown
$students_sql = "SELECT student_id, name FROM students ORDER BY name";
$students_result = $conn->query($students_sql);

// Get attendance statistics
$stats = [
    'total_records' => 0,
    'present_count' => 0,
    'absent_count' => 0,
    'today_records' => 0,
    'this_week_records' => 0
];

$stats_sql = "
    SELECT 
        COUNT(*) as total_records,
        COUNT(CASE WHEN status = 'present' THEN 1 END) as present_count,
        COUNT(CASE WHEN status = 'absent' THEN 1 END) as absent_count,
        COUNT(CASE WHEN DATE(attendance_date) = CURDATE() THEN 1 END) as today_records,
        COUNT(CASE WHEN YEARWEEK(attendance_date) = YEARWEEK(CURDATE()) THEN 1 END) as this_week_records
    FROM attendance
";

$stats_result = $conn->query($stats_sql);
if ($stats_result) {
    $stats = $stats_result->fetch_assoc();
} else {
    error_log("Stats SQL Error: " . $conn->error);
}

// Get subject-wise statistics
$subject_stats_sql = "
    SELECT 
        sub.subject_name,
        COUNT(*) as total_records,
        COUNT(CASE WHEN a.status = 'present' THEN 1 END) as present_count,
        ROUND((COUNT(CASE WHEN a.status = 'present' THEN 1 END) * 100.0 / COUNT(*)), 2) as attendance_rate
    FROM attendance a
    JOIN subjects sub ON a.subject_id = sub.subject_id
    GROUP BY sub.subject_id, sub.subject_name
    ORDER BY attendance_rate DESC
    LIMIT 5
";

$subject_stats_result = $conn->query($subject_stats_sql);

// Get recent at-risk students (below 80% attendance)
$at_risk_sql = "
    SELECT 
        s.student_id,
        s.name as student_name,
        COUNT(a.attendance_id) as total_classes,
        SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as attended_classes,
        ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(a.attendance_id)), 2) as attendance_percentage
    FROM students s
    JOIN attendance a ON s.student_id = a.student_id
    GROUP BY s.student_id, s.name
    HAVING attendance_percentage < 80 AND total_classes >= 5
    ORDER BY attendance_percentage ASC
    LIMIT 5
";

$at_risk_result = $conn->query($at_risk_sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Management - CSSAP Admin</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8f9fa;
            color: #333;
        }

        .admin-container {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar Styles */
        .sidebar {
            width: 280px;
            background: linear-gradient(135deg, #2c3e50, #34495e);
            color: white;
            padding: 30px 0;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        }

        .sidebar-header {
            padding: 0 25px 25px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            margin-bottom: 25px;
        }

        .sidebar-header h2 {
            font-size: 1.5rem;
            margin-bottom: 5px;
            color: #ecf0f1;
        }

        .sidebar-header p {
            font-size: 0.9rem;
            opacity: 0.7;
        }

        .nav-links {
            list-style: none;
            padding: 0 15px;
        }

        .nav-links li {
            margin-bottom: 8px;
        }

        .nav-links a {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            color: #bdc3c7;
            text-decoration: none;
            border-radius: 10px;
            transition: all 0.3s ease;
        }

        .nav-links a:hover {
            background: rgba(255,255,255,0.1);
            color: white;
        }

        .nav-links a.active {
            background: #3498db;
            color: white;
        }

        .nav-icon {
            margin-right: 10px;
            font-size: 1.1rem;
        }

        /* Main Content Styles */
        .main-content {
            flex: 1;
            padding: 30px;
            background: #f8f9fa;
            overflow-y: auto;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e9ecef;
        }

        .header h1 {
            color: #2c3e50;
            font-size: 2.2rem;
            font-weight: 700;
        }

        .user-welcome {
            text-align: right;
        }

        .user-welcome .name {
            font-weight: 600;
            color: #2c3e50;
            font-size: 1.1rem;
        }

        .user-welcome .role {
            color: #7f8c8d;
            font-size: 0.9rem;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            text-align: center;
            border-left: 5px solid #3498db;
        }

        .stat-card.total { border-left-color: #3498db; }
        .stat-card.present { border-left-color: #2ecc71; }
        .stat-card.absent { border-left-color: #e74c3c; }
        .stat-card.today { border-left-color: #f39c12; }
        .stat-card.week { border-left-color: #9b59b6; }

        .stat-icon {
            font-size: 2rem;
            margin-bottom: 10px;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 0.9rem;
            color: #7f8c8d;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* Content Section */
        .content-section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e9ecef;
        }

        .section-header h2 {
            color: #2c3e50;
            font-size: 1.4rem;
            font-weight: 600;
        }

        .btn {
            padding: 10px 20px;
            background: linear-gradient(135deg, #3498db, #2980b9);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s ease;
            font-size: 14px;
            font-weight: 600;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.3);
        }

        .btn-success {
            background: linear-gradient(135deg, #2ecc71, #27ae60);
        }

        .btn-warning {
            background: linear-gradient(135deg, #f39c12, #e67e22);
        }

        .btn-danger {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
        }

        .btn-info {
            background: linear-gradient(135deg, #9b59b6, #8e44ad);
        }

        /* Filter Form */
        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
            border: 1px solid #e9ecef;
        }

        .form-group {
            margin-bottom: 0;
        }

        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #495057;
            font-size: 14px;
        }

        input[type="text"], input[type="date"], input[type="number"], select {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }

        input:focus, select:focus {
            outline: none;
            border-color: #3498db;
        }

        .filter-actions {
            display: flex;
            gap: 10px;
            align-items: end;
        }

        /* Table Styles */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            font-size: 14px;
        }

        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }

        th {
            background-color: #f8f9fa;
            font-weight: 600;
            color: #495057;
            position: sticky;
            top: 0;
        }

        tr:hover {
            background-color: #f8f9fa;
        }

        .status-present {
            color: #27ae60;
            font-weight: bold;
        }

        .status-absent {
            color: #e74c3c;
            font-weight: bold;
        }

        .subject-badge {
            background: #3498db;
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .action-buttons {
            display: flex;
            gap: 5px;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }

        .message {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            text-align: center;
            font-weight: 500;
            line-height: 1.5;
        }

        .success {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            color: #155724;
            border: 1px solid #b1dfbb;
        }

        .error {
            background: linear-gradient(135deg, #f8d7da, #f5c6cb);
            color: #721c24;
            border: 1px solid #f1b0b7;
        }

        .no-data {
            text-align: center;
            color: #7f8c8d;
            padding: 40px;
            font-style: italic;
        }

        /* Bulk Actions */
        .bulk-actions {
            background: #fff3cd;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #ffeaa7;
            margin-bottom: 20px;
            display: none;
        }

        .bulk-form {
            display: flex;
            gap: 15px;
            align-items: center;
        }

        .selected-count {
            font-weight: 600;
            color: #856404;
        }

        /* Subject Stats */
        .subject-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        .subject-stat-card {
            background: white;
            padding: 15px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border-left: 4px solid #3498db;
        }

        .subject-stat-card h4 {
            color: #2c3e50;
            margin-bottom: 8px;
            font-size: 0.9rem;
        }

        .attendance-rate {
            font-size: 1.5rem;
            font-weight: bold;
            color: #2c3e50;
        }

        .rate-good { color: #27ae60; }
        .rate-warning { color: #f39c12; }
        .rate-critical { color: #e74c3c; }

        .stat-details {
            font-size: 0.8rem;
            color: #7f8c8d;
            margin-top: 5px;
        }

        /* Checkbox Styles */
        .select-checkbox {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        /* At-Risk Students */
        .at-risk-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        .at-risk-card {
            background: white;
            padding: 15px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border-left: 4px solid #e74c3c;
        }

        .at-risk-card h4 {
            color: #2c3e50;
            margin-bottom: 8px;
            font-size: 0.9rem;
        }

        .attendance-percentage {
            font-size: 1.5rem;
            font-weight: bold;
            color: #e74c3c;
        }

        /* Manual Entry Form */
        .manual-entry-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
            border: 1px solid #e9ecef;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .filter-form, .manual-entry-form {
                grid-template-columns: 1fr;
            }
            
            .bulk-form {
                flex-direction: column;
                align-items: stretch;
            }
            
            table {
                font-size: 12px;
            }
            
            th, td {
                padding: 8px 10px;
            }
        }

        /* Export Button */
        .export-btn {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
        }

        .tabs {
            display: flex;
            margin-bottom: 20px;
            border-bottom: 2px solid #e9ecef;
        }

        .tab {
            padding: 12px 24px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            color: #7f8c8d;
            border-bottom: 3px solid transparent;
            transition: all 0.3s ease;
        }

        .tab.active {
            color: #3498db;
            border-bottom-color: #3498db;
        }

        .tab:hover {
            color: #3498db;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <h2>CSSAP</h2>
                <p>Admin Portal</p>
            </div>
            <ul class="nav-links">
                <li><a href="index.php">📊 Dashboard</a></li>
                <li><a href="users.php">👥 User Management</a></li>
                <li><a href="lecturers.php">👨‍🏫 Lecturer Management</a></li>
                <li><a href="students.php">🎓 Student Management</a></li>
                <li><a href="subjects.php">📚 Subject Management</a></li>
                <li><a href="attendance.php" class="active">📝 Attendance Management</a></li>
                <li><a href="interventions.php">🔄 Interventions</a></li>
                <li><a href="reports.php">📈 System Reports</a></li>
                <li><a href="profile.php">⚙️ My Profile</a></li>
                <li><a href="logout.php">🚪 Logout</a></li>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <div class="header">
                <h1>Attendance Management</h1>
                <div class="user-welcome">
                    <div class="name">Welcome, <?php echo htmlspecialchars($_SESSION['username'] ?? 'Admin'); ?></div>
                    <div class="role">Administrator</div>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="message <?php echo $message_class; ?>">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card total">
                    <div class="stat-icon">📊</div>
                    <div class="stat-number"><?php echo $stats['total_records']; ?></div>
                    <div class="stat-label">Total Records</div>
                </div>
                <div class="stat-card present">
                    <div class="stat-icon">✅</div>
                    <div class="stat-number"><?php echo $stats['present_count']; ?></div>
                    <div class="stat-label">Present</div>
                </div>
                <div class="stat-card absent">
                    <div class="stat-icon">❌</div>
                    <div class="stat-number"><?php echo $stats['absent_count']; ?></div>
                    <div class="stat-label">Absent</div>
                </div>
                <div class="stat-card today">
                    <div class="stat-icon">📅</div>
                    <div class="stat-number"><?php echo $stats['today_records']; ?></div>
                    <div class="stat-label">Today's Records</div>
                </div>
                <div class="stat-card week">
                    <div class="stat-icon">📅</div>
                    <div class="stat-number"><?php echo $stats['this_week_records']; ?></div>
                    <div class="stat-label">This Week</div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab active" onclick="switchTab('overview')">📋 Attendance Overview</button>
                <button class="tab" onclick="switchTab('manual')">➕ Manual Entry</button>
                <button class="tab" onclick="switchTab('analytics')">📊 Analytics</button>
            </div>

            <!-- Overview Tab -->
            <div id="overview" class="tab-content active">
                <div class="content-section">
                    <div class="section-header">
                        <h2>Attendance Records</h2>
                        <div>
                            <button class="btn export-btn" onclick="exportAttendance()">📥 Export Data</button>
                        </div>
                    </div>

                    <!-- Bulk Actions -->
                    <div id="bulkActions" class="bulk-actions">
                        <form method="POST" class="bulk-form">
                            <div class="selected-count" id="selectedCount">0 records selected</div>
                            <select name="bulk_status" required>
                                <option value="">Select Status</option>
                                <option value="present">Mark as Present</option>
                                <option value="absent">Mark as Absent</option>
                            </select>
                            <button type="submit" name="bulk_update" class="btn btn-warning">Apply to Selected</button>
                            <button type="button" class="btn btn-secondary" onclick="clearSelection()">Clear Selection</button>
                        </form>
                    </div>

                    <!-- Filter Form -->
                    <form method="GET" class="filter-form">
                        <div class="form-group">
                            <label for="search">Search:</label>
                            <input type="text" name="search" id="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Student, subject, or lecturer...">
                        </div>

                        <div class="form-group">
                            <label for="subject">Subject:</label>
                            <select name="subject" id="subject">
                                <option value="">All Subjects</option>
                                <?php 
                                $subjects_result->data_seek(0); // Reset pointer
                                while ($subject = $subjects_result->fetch_assoc()): ?>
                                    <option value="<?php echo $subject['subject_id']; ?>" 
                                        <?php echo $subject_filter == $subject['subject_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($subject['subject_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="student">Student:</label>
                            <select name="student" id="student">
                                <option value="">All Students</option>
                                <?php while ($student = $students_result->fetch_assoc()): ?>
                                    <option value="<?php echo $student['student_id']; ?>" 
                                        <?php echo $student_filter == $student['student_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($student['name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="date_from">From Date:</label>
                            <input type="date" name="date_from" id="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                        </div>

                        <div class="form-group">
                            <label for="date_to">To Date:</label>
                            <input type="date" name="date_to" id="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                        </div>

                        <div class="form-group">
                            <label for="status">Status:</label>
                            <select name="status" id="status">
                                <option value="">All Status</option>
                                <option value="present" <?php echo $status_filter === 'present' ? 'selected' : ''; ?>>Present</option>
                                <option value="absent" <?php echo $status_filter === 'absent' ? 'selected' : ''; ?>>Absent</option>
                            </select>
                        </div>

                        <div class="filter-actions">
                            <button type="submit" class="btn">Apply Filters</button>
                            <a href="attendance.php" class="btn btn-warning">Reset</a>
                        </div>
                    </form>

                    <!-- Attendance Table -->
                    <?php if ($attendance_result && $attendance_result->num_rows > 0): ?>
                        <form id="attendanceForm">
                            <table>
                                <thead>
                                    <tr>
                                        <th width="30">
                                            <input type="checkbox" id="selectAll" class="select-checkbox">
                                        </th>
                                        <th>Date</th>
                                        <th>Student</th>
                                        <th>Subject</th>
                                        <th>Lecturer</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($attendance = $attendance_result->fetch_assoc()): ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" name="attendance_ids[]" value="<?php echo $attendance['attendance_id']; ?>" class="select-checkbox attendance-checkbox">
                                            </td>
                                            <td><?php echo date('M j, Y', strtotime($attendance['attendance_date'])); ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($attendance['student_name']); ?></strong>
                                            </td>
                                            <td>
                                                <span class="subject-badge">
                                                    <?php echo htmlspecialchars($attendance['subject_name']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($attendance['lecturer_name'] ?? 'N/A'); ?></td>
                                            <td class="<?php echo $attendance['status'] === 'present' ? 'status-present' : 'status-absent'; ?>">
                                                <?php echo ucfirst($attendance['status']); ?>
                                            </td>
                                            <td class="action-buttons">
                                                <a href="attendance.php?delete_id=<?php echo $attendance['attendance_id']; ?>" 
                                                   class="btn btn-danger btn-sm" 
                                                   onclick="return confirm('Are you sure you want to delete this attendance record?')">
                                                    Delete
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </form>
                    <?php else: ?>
                        <div class="no-data">No attendance records found matching your criteria.</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Manual Entry Tab -->
            <div id="manual" class="tab-content">
                <div class="content-section">
                    <div class="section-header">
                        <h2>Manual Attendance Entry</h2>
                    </div>

                    <form method="POST" class="manual-entry-form">
                        <div class="form-group">
                            <label for="student_id">Student:</label>
                            <select name="student_id" id="student_id" required>
                                <option value="">Select Student</option>
                                <?php 
                                $students_result->data_seek(0); // Reset pointer
                                while ($student = $students_result->fetch_assoc()): ?>
                                    <option value="<?php echo $student['student_id']; ?>">
                                        <?php echo htmlspecialchars($student['name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="subject_id">Subject:</label>
                            <select name="subject_id" id="subject_id" required>
                                <option value="">Select Subject</option>
                                <?php 
                                $subjects_result->data_seek(0); // Reset pointer
                                while ($subject = $subjects_result->fetch_assoc()): ?>
                                    <option value="<?php echo $subject['subject_id']; ?>">
                                        <?php echo htmlspecialchars($subject['subject_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="attendance_date">Date:</label>
                            <input type="date" name="attendance_date" id="attendance_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="status">Status:</label>
                            <select name="status" id="status" required>
                                <option value="present">Present</option>
                                <option value="absent">Absent</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <button type="submit" name="add_attendance" class="btn btn-success">Add Attendance Record</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Analytics Tab -->
            <div id="analytics" class="tab-content">
                <!-- Subject Statistics -->
                <?php if ($subject_stats_result && $subject_stats_result->num_rows > 0): ?>
                    <div class="content-section">
                        <div class="section-header">
                            <h2>Subject Attendance Rates</h2>
                        </div>
                        <div class="subject-stats">
                            <?php 
                            $subject_stats_result->data_seek(0); // Reset pointer
                            while ($subject_stat = $subject_stats_result->fetch_assoc()): 
                                $rate_class = '';
                                if ($subject_stat['attendance_rate'] >= 80) {
                                    $rate_class = 'rate-good';
                                } elseif ($subject_stat['attendance_rate'] >= 60) {
                                    $rate_class = 'rate-warning';
                                } else {
                                    $rate_class = 'rate-critical';
                                }
                            ?>
                                <div class="subject-stat-card">
                                    <h4><?php echo htmlspecialchars($subject_stat['subject_name']); ?></h4>
                                    <div class="attendance-rate <?php echo $rate_class; ?>">
                                        <?php echo $subject_stat['attendance_rate']; ?>%
                                    </div>
                                    <div class="stat-details">
                                        <?php echo $subject_stat['present_count']; ?> present / 
                                        <?php echo $subject_stat['total_records']; ?> total
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- At-Risk Students -->
                <?php if ($at_risk_result && $at_risk_result->num_rows > 0): ?>
                    <div class="content-section">
                        <div class="section-header">
                            <h2>At-Risk Students (Below 80% Attendance)</h2>
                        </div>
                        <div class="at-risk-grid">
                            <?php while ($at_risk = $at_risk_result->fetch_assoc()): ?>
                                <div class="at-risk-card">
                                    <h4><?php echo htmlspecialchars($at_risk['student_name']); ?></h4>
                                    <div class="attendance-percentage">
                                        <?php echo $at_risk['attendance_percentage']; ?>%
                                    </div>
                                    <div class="stat-details">
                                        <?php echo $at_risk['attended_classes']; ?> attended / 
                                        <?php echo $at_risk['total_classes']; ?> total classes
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Bulk selection functionality
        const selectAllCheckbox = document.getElementById('selectAll');
        const attendanceCheckboxes = document.querySelectorAll('.attendance-checkbox');
        const bulkActions = document.getElementById('bulkActions');
        const selectedCount = document.getElementById('selectedCount');

        function updateBulkActions() {
            const selectedCountValue = document.querySelectorAll('.attendance-checkbox:checked').length;
            selectedCount.textContent = selectedCountValue + ' records selected';
            
            if (selectedCountValue > 0) {
                bulkActions.style.display = 'block';
            } else {
                bulkActions.style.display = 'none';
            }
        }

        selectAllCheckbox.addEventListener('change', function() {
            attendanceCheckboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
            updateBulkActions();
        });

        attendanceCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', updateBulkActions);
        });

        function clearSelection() {
            attendanceCheckboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
            selectAllCheckbox.checked = false;
            updateBulkActions();
        }

        // Tab switching functionality
        function switchTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabName).classList.add('active');
            
            // Add active class to clicked tab
            event.target.classList.add('active');
        }

        // Export functionality
        function exportAttendance() {
            const params = new URLSearchParams(window.location.search);
            window.open('export_attendance.php?' + params.toString(), '_blank');
        }

        // Auto-set date range to last 30 days if not set
        document.addEventListener('DOMContentLoaded', function() {
            const dateFrom = document.getElementById('date_from');
            const dateTo = document.getElementById('date_to');
            
            if (!dateFrom.value && !dateTo.value) {
                const today = new Date();
                const thirtyDaysAgo = new Date();
                thirtyDaysAgo.setDate(today.getDate() - 30);
                
                dateFrom.value = thirtyDaysAgo.toISOString().split('T')[0];
                dateTo.value = today.toISOString().split('T')[0];
            }

            // Initialize bulk actions
            updateBulkActions();
        });
    </script>
</body>
</html>