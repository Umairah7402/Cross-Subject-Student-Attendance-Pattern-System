<?php
session_start();
include('db_connect.php');

// Check if user is logged in as admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Get filter parameters
$report_type = $_GET['report_type'] ?? 'attendance_summary';
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$subject_filter = $_GET['subject'] ?? '';
$export_format = $_GET['export'] ?? '';

// Get all subjects for dropdown
$subjects_sql = "SELECT subject_id, subject_name FROM subjects ORDER BY subject_name";
$subjects_result = $conn->query($subjects_sql);

// System Overview Statistics
$overview_stats_sql = "
    SELECT 
        (SELECT COUNT(*) FROM students) as total_students,
        (SELECT COUNT(*) FROM lecturers) as total_lecturers,
        (SELECT COUNT(*) FROM subjects) as total_subjects,
        (SELECT COUNT(*) FROM attendance WHERE attendance_date BETWEEN ? AND ?) as total_attendance_records,
        (SELECT COUNT(*) FROM interventions WHERE intervention_date BETWEEN ? AND ?) as total_interventions,
        (SELECT COUNT(DISTINCT student_id) FROM attendance WHERE status = 'Absent' AND attendance_date BETWEEN ? AND ?) as students_with_absences,
        (SELECT COUNT(DISTINCT student_id) FROM interventions WHERE intervention_date BETWEEN ? AND ?) as students_with_interventions
";

$overview_stmt = $conn->prepare($overview_stats_sql);
$overview_stmt->bind_param("ssssssss", $date_from, $date_to, $date_from, $date_to, $date_from, $date_to, $date_from, $date_to);
$overview_stmt->execute();
$overview_stats = $overview_stmt->get_result()->fetch_assoc();

// Attendance Summary Report
if ($report_type == 'attendance_summary') {
    $attendance_summary_sql = "
        SELECT 
            sub.subject_name,
            COUNT(*) as total_records,
            COUNT(CASE WHEN a.status = 'Present' THEN 1 END) as present_count,
            COUNT(CASE WHEN a.status = 'Absent' THEN 1 END) as absent_count,
            ROUND((COUNT(CASE WHEN a.status = 'Present' THEN 1 END) * 100.0 / COUNT(*)), 2) as attendance_rate,
            COUNT(DISTINCT a.student_id) as unique_students
        FROM attendance a
        JOIN subjects sub ON a.subject_id = sub.subject_id
        WHERE a.attendance_date BETWEEN ? AND ?
    ";
    
    if (!empty($subject_filter)) {
        $attendance_summary_sql .= " AND a.subject_id = ?";
        $attendance_summary_sql .= " GROUP BY sub.subject_id, sub.subject_name ORDER BY attendance_rate DESC";
        $stmt = $conn->prepare($attendance_summary_sql);
        $stmt->bind_param("ssi", $date_from, $date_to, $subject_filter);
    } else {
        $attendance_summary_sql .= " GROUP BY sub.subject_id, sub.subject_name ORDER BY attendance_rate DESC";
        $stmt = $conn->prepare($attendance_summary_sql);
        $stmt->bind_param("ss", $date_from, $date_to);
    }
    
    $stmt->execute();
    $attendance_summary = $stmt->get_result();
}

// At-Risk Students Report
if ($report_type == 'at_risk_students') {
    $at_risk_sql = "
        SELECT 
            s.student_id,
            s.name as student_name,
            s.matric_number,
            COUNT(DISTINCT a.subject_id) as total_subjects,
            COUNT(*) as total_classes,
            COUNT(CASE WHEN a.status = 'Present' THEN 1 END) as present_count,
            ROUND((COUNT(CASE WHEN a.status = 'Present' THEN 1 END) * 100.0 / COUNT(*)), 2) as overall_attendance,
            COUNT(DISTINCT i.intervention_id) as intervention_count,
            MAX(i.intervention_date) as last_intervention
        FROM students s
        JOIN attendance a ON s.student_id = a.student_id
        LEFT JOIN interventions i ON s.student_id = i.student_id
        WHERE a.attendance_date BETWEEN ? AND ?
    ";
    
    if (!empty($subject_filter)) {
        $at_risk_sql .= " AND a.subject_id = ?";
    }
    
    $at_risk_sql .= " 
        GROUP BY s.student_id, s.name, s.matric_number
        HAVING overall_attendance < 80 OR intervention_count > 0
        ORDER BY overall_attendance ASC, intervention_count DESC
        LIMIT 50
    ";
    
    if (!empty($subject_filter)) {
        $stmt = $conn->prepare($at_risk_sql);
        $stmt->bind_param("ssi", $date_from, $date_to, $subject_filter);
    } else {
        $stmt = $conn->prepare($at_risk_sql);
        $stmt->bind_param("ss", $date_from, $date_to);
    }
    
    $stmt->execute();
    $at_risk_students = $stmt->get_result();
}

// Lecturer Activity Report
if ($report_type == 'lecturer_activity') {
    $lecturer_activity_sql = "
        SELECT 
            l.lecturer_id,
            l.lecturer_name,
            l.email,
            COUNT(DISTINCT ls.subject_id) as subjects_taught,
            COUNT(DISTINCT a.attendance_id) as attendance_records,
            COUNT(DISTINCT i.intervention_id) as interventions_made,
            MIN(a.attendance_date) as first_activity,
            MAX(a.attendance_date) as last_activity
        FROM lecturers l
        LEFT JOIN lecturer_subjects ls ON l.lecturer_id = ls.lecturer_id
        LEFT JOIN attendance a ON l.lecturer_id = a.lecturer_id AND a.attendance_date BETWEEN ? AND ?
        LEFT JOIN interventions i ON l.lecturer_id = i.lecturer_id AND i.intervention_date BETWEEN ? AND ?
        GROUP BY l.lecturer_id, l.lecturer_name, l.email
        ORDER BY attendance_records DESC, interventions_made DESC
    ";
    
    $stmt = $conn->prepare($lecturer_activity_sql);
    $stmt->bind_param("ssss", $date_from, $date_to, $date_from, $date_to);
    $stmt->execute();
    $lecturer_activity = $stmt->get_result();
}

// Intervention Analysis Report
if ($report_type == 'intervention_analysis') {
    $intervention_analysis_sql = "
        SELECT 
            sub.subject_name,
            COUNT(i.intervention_id) as total_interventions,
            COUNT(DISTINCT i.student_id) as unique_students,
            COUNT(DISTINCT i.lecturer_id) as involved_lecturers,
            AVG(TIMESTAMPDIFF(DAY, a.attendance_date, i.intervention_date)) as avg_days_to_intervention,
            MIN(i.intervention_date) as first_intervention,
            MAX(i.intervention_date) as last_intervention
        FROM interventions i
        JOIN subjects sub ON i.subject_id = sub.subject_id
        LEFT JOIN attendance a ON i.student_id = a.student_id AND i.subject_id = a.subject_id
        WHERE i.intervention_date BETWEEN ? AND ?
    ";
    
    if (!empty($subject_filter)) {
        $intervention_analysis_sql .= " AND i.subject_id = ?";
        $intervention_analysis_sql .= " GROUP BY sub.subject_id, sub.subject_name ORDER BY total_interventions DESC";
        $stmt = $conn->prepare($intervention_analysis_sql);
        $stmt->bind_param("ssi", $date_from, $date_to, $subject_filter);
    } else {
        $intervention_analysis_sql .= " GROUP BY sub.subject_id, sub.subject_name ORDER BY total_interventions DESC";
        $stmt = $conn->prepare($intervention_analysis_sql);
        $stmt->bind_param("ss", $date_from, $date_to);
    }
    
    $stmt->execute();
    $intervention_analysis = $stmt->get_result();
}

// Daily Trends Report
if ($report_type == 'daily_trends') {
    $daily_trends_sql = "
        SELECT 
            DATE(attendance_date) as attendance_day,
            COUNT(*) as total_records,
            COUNT(CASE WHEN status = 'Present' THEN 1 END) as present_count,
            COUNT(CASE WHEN status = 'Absent' THEN 1 END) as absent_count,
            ROUND((COUNT(CASE WHEN status = 'Present' THEN 1 END) * 100.0 / COUNT(*)), 2) as daily_attendance_rate
        FROM attendance
        WHERE attendance_date BETWEEN ? AND ?
        GROUP BY DATE(attendance_date)
        ORDER BY attendance_day DESC
        LIMIT 30
    ";
    
    $stmt = $conn->prepare($daily_trends_sql);
    $stmt->bind_param("ss", $date_from, $date_to);
    $stmt->execute();
    $daily_trends = $stmt->get_result();
}

// Handle PDF Export
if ($export_format == 'pdf' && isset($_GET['report'])) {
    // In a real implementation, you would use a PDF library like TCPDF or Dompdf
    // This is a simplified version that would redirect to a PDF generator
    header("Location: generate_pdf.php?report_type=" . $_GET['report'] . "&date_from=$date_from&date_to=$date_to&subject=$subject_filter");
    exit();
}

// Handle Excel Export
if ($export_format == 'excel' && isset($_GET['report'])) {
    // In a real implementation, you would use PHPExcel or PhpSpreadsheet
    // This is a simplified version that would redirect to an Excel generator
    header("Location: generate_excel.php?report_type=" . $_GET['report'] . "&date_from=$date_from&date_to=$date_to&subject=$subject_filter");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Reports - CSSAP Admin</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Reuse all the same base styles from previous pages */
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
            background: #e67e22;
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

        /* Report Controls */
        .report-controls {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }

        .controls-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 0;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #495057;
            font-size: 14px;
        }

        select, input[type="date"] {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }

        select:focus, input:focus {
            outline: none;
            border-color: #e67e22;
        }

        .export-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
        }

        /* Stats Overview */
        .stats-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            text-align: center;
            border-left: 5px solid #e67e22;
        }

        .stat-card.students { border-left-color: #3498db; }
        .stat-card.lecturers { border-left-color: #9b59b6; }
        .stat-card.subjects { border-left-color: #2ecc71; }
        .stat-card.attendance { border-left-color: #f39c12; }
        .stat-card.interventions { border-left-color: #e74c3c; }
        .stat-card.absences { border-left-color: #95a5a6; }

        .stat-icon {
            font-size: 2.5rem;
            margin-bottom: 15px;
        }

        .stat-number {
            font-size: 2.2rem;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 8px;
        }

        .stat-label {
            font-size: 0.9rem;
            color: #7f8c8d;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }

        /* Report Content */
        .report-content {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }

        .report-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e9ecef;
        }

        .report-header h2 {
            color: #2c3e50;
            font-size: 1.6rem;
            font-weight: 700;
        }

        .report-actions {
            display: flex;
            gap: 10px;
        }

        .btn {
            padding: 12px 25px;
            background: linear-gradient(135deg, #e67e22, #d35400);
            color: white;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            font-size: 14px;
            font-weight: 600;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(230, 126, 34, 0.3);
        }

        .btn-success {
            background: linear-gradient(135deg, #2ecc71, #27ae60);
        }

        .btn-warning {
            background: linear-gradient(135deg, #f39c12, #e67e22);
        }

        .btn-info {
            background: linear-gradient(135deg, #3498db, #2980b9);
        }

        /* Table Styles */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            font-size: 14px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        th, td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }

        th {
            background: linear-gradient(135deg, #34495e, #2c3e50);
            color: white;
            font-weight: 600;
            position: sticky;
            top: 0;
        }

        tr:hover {
            background-color: #f8f9fa;
        }

        .attendance-good { color: #27ae60; font-weight: bold; }
        .attendance-warning { color: #f39c12; font-weight: bold; }
        .attendance-critical { color: #e74c3c; font-weight: bold; }

        .risk-high { background: #ffeaa7; }
        .risk-medium { background: #fff3cd; }
        .risk-low { background: #d4edda; }

        /* Chart Container */
        .chart-container {
            background: white;
            padding: 25px;
            border-radius: 15px;
            margin: 25px 0;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }

        .chart-title {
            text-align: center;
            margin-bottom: 20px;
            color: #2c3e50;
            font-size: 1.3rem;
            font-weight: 600;
        }

        .chart-wrapper {
            position: relative;
            height: 400px;
            width: 100%;
        }

        /* No Data State */
        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: #7f8c8d;
        }

        .no-data-icon {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .no-data h3 {
            font-size: 1.5rem;
            margin-bottom: 10px;
            color: #95a5a6;
        }

        /* Report Navigation */
        .report-nav {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }

        .nav-btn {
            padding: 12px 20px;
            background: #ecf0f1;
            color: #7f8c8d;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
        }

        .nav-btn.active {
            background: linear-gradient(135deg, #e67e22, #d35400);
            color: white;
        }

        .nav-btn:hover:not(.active) {
            background: #bdc3c7;
            color: #2c3e50;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .controls-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-overview {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .export-actions {
                flex-direction: column;
            }
            
            .report-header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
            
            .chart-wrapper {
                height: 300px;
            }
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
                <li><a href="attendance.php">📝 Attendance Overview</a></li>
                <li><a href="interventions.php">🔄 Interventions</a></li>
                <li><a href="reports.php" class="active">📈 System Reports</a></li>
                <li><a href="profile.php">⚙️ My Profile</a></li>
                <li><a href="logout.php">🚪 Logout</a></li>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <div class="header">
                <h1>System Reports & Analytics</h1>
                <div class="user-welcome">
                    <div class="name">Welcome, <?php echo htmlspecialchars($_SESSION['username'] ?? 'Admin'); ?></div>
                    <div class="role">Administrator</div>
                </div>
            </div>

            <!-- System Overview Statistics -->
            <div class="stats-overview">
                <div class="stat-card students">
                    <div class="stat-icon">🎓</div>
                    <div class="stat-number"><?php echo $overview_stats['total_students']; ?></div>
                    <div class="stat-label">Total Students</div>
                </div>
                <div class="stat-card lecturers">
                    <div class="stat-icon">👨‍🏫</div>
                    <div class="stat-number"><?php echo $overview_stats['total_lecturers']; ?></div>
                    <div class="stat-label">Lecturers</div>
                </div>
                <div class="stat-card subjects">
                    <div class="stat-icon">📚</div>
                    <div class="stat-number"><?php echo $overview_stats['total_subjects']; ?></div>
                    <div class="stat-label">Subjects</div>
                </div>
                <div class="stat-card attendance">
                    <div class="stat-icon">📊</div>
                    <div class="stat-number"><?php echo $overview_stats['total_attendance_records']; ?></div>
                    <div class="stat-label">Attendance Records</div>
                </div>
                <div class="stat-card interventions">
                    <div class="stat-icon">🔄</div>
                    <div class="stat-number"><?php echo $overview_stats['total_interventions']; ?></div>
                    <div class="stat-label">Interventions</div>
                </div>
                <div class="stat-card absences">
                    <div class="stat-icon">⚠️</div>
                    <div class="stat-number"><?php echo $overview_stats['students_with_absences']; ?></div>
                    <div class="stat-label">Students with Absences</div>
                </div>
            </div>

            <!-- Report Controls -->
            <div class="report-controls">
                <form method="GET">
                    <div class="controls-grid">
                        <div class="form-group">
                            <label for="report_type">Report Type</label>
                            <select name="report_type" id="report_type" onchange="this.form.submit()">
                                <option value="attendance_summary" <?php echo $report_type == 'attendance_summary' ? 'selected' : ''; ?>>Attendance Summary</option>
                                <option value="at_risk_students" <?php echo $report_type == 'at_risk_students' ? 'selected' : ''; ?>>At-Risk Students</option>
                                <option value="lecturer_activity" <?php echo $report_type == 'lecturer_activity' ? 'selected' : ''; ?>>Lecturer Activity</option>
                                <option value="intervention_analysis" <?php echo $report_type == 'intervention_analysis' ? 'selected' : ''; ?>>Intervention Analysis</option>
                                <option value="daily_trends" <?php echo $report_type == 'daily_trends' ? 'selected' : ''; ?>>Daily Trends</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="date_from">From Date</label>
                            <input type="date" name="date_from" id="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                        </div>

                        <div class="form-group">
                            <label for="date_to">To Date</label>
                            <input type="date" name="date_to" id="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                        </div>

                        <div class="form-group">
                            <label for="subject">Subject Filter</label>
                            <select name="subject" id="subject">
                                <option value="">All Subjects</option>
                                <?php while ($subject = $subjects_result->fetch_assoc()): ?>
                                    <option value="<?php echo $subject['subject_id']; ?>" 
                                        <?php echo $subject_filter == $subject['subject_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($subject['subject_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>

                    <div class="export-actions">
                        <button type="submit" class="btn">Generate Report</button>
                        <a href="?<?php echo http_build_query(['report_type' => $report_type, 'date_from' => $date_from, 'date_to' => $date_to, 'subject' => $subject_filter, 'export' => 'pdf', 'report' => $report_type]); ?>" class="btn btn-danger">📄 Export PDF</a>
                        <a href="?<?php echo http_build_query(['report_type' => $report_type, 'date_from' => $date_from, 'date_to' => $date_to, 'subject' => $subject_filter, 'export' => 'excel', 'report' => $report_type]); ?>" class="btn btn-success">📊 Export Excel</a>
                    </div>
                </form>
            </div>

            <!-- Report Content -->
            <div class="report-content">
                <!-- Attendance Summary Report -->
                <?php if ($report_type == 'attendance_summary'): ?>
                    <div class="report-header">
                        <h2>📊 Attendance Summary Report</h2>
                        <div class="report-period">
                            Period: <?php echo date('M j, Y', strtotime($date_from)); ?> to <?php echo date('M j, Y', strtotime($date_to)); ?>
                        </div>
                    </div>

                    <?php if ($attendance_summary && $attendance_summary->num_rows > 0): ?>
                        <div class="chart-container">
                            <div class="chart-title">Attendance Rates by Subject</div>
                            <div class="chart-wrapper">
                                <canvas id="attendanceChart"></canvas>
                            </div>
                        </div>

                        <table>
                            <thead>
                                <tr>
                                    <th>Subject</th>
                                    <th>Total Records</th>
                                    <th>Present</th>
                                    <th>Absent</th>
                                    <th>Attendance Rate</th>
                                    <th>Unique Students</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $chart_labels = [];
                                $chart_data = [];
                                while ($row = $attendance_summary->fetch_assoc()): 
                                    $chart_labels[] = $row['subject_name'];
                                    $chart_data[] = $row['attendance_rate'];
                                    
                                    $rate_class = '';
                                    if ($row['attendance_rate'] >= 80) $rate_class = 'attendance-good';
                                    elseif ($row['attendance_rate'] >= 60) $rate_class = 'attendance-warning';
                                    else $rate_class = 'attendance-critical';
                                ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($row['subject_name']); ?></strong></td>
                                        <td><?php echo $row['total_records']; ?></td>
                                        <td class="attendance-good"><?php echo $row['present_count']; ?></td>
                                        <td class="attendance-critical"><?php echo $row['absent_count']; ?></td>
                                        <td class="<?php echo $rate_class; ?>"><?php echo $row['attendance_rate']; ?>%</td>
                                        <td><?php echo $row['unique_students']; ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>

                        <script>
                            // Attendance Chart
                            const attendanceCtx = document.getElementById('attendanceChart').getContext('2d');
                            new Chart(attendanceCtx, {
                                type: 'bar',
                                data: {
                                    labels: <?php echo json_encode($chart_labels); ?>,
                                    datasets: [{
                                        label: 'Attendance Rate (%)',
                                        data: <?php echo json_encode($chart_data); ?>,
                                        backgroundColor: [
                                            '#2ecc71', '#3498db', '#9b59b6', '#f1c40f', 
                                            '#e67e22', '#e74c3c', '#1abc9c', '#34495e'
                                        ],
                                        borderColor: '#2c3e50',
                                        borderWidth: 1
                                    }]
                                },
                                options: {
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    scales: {
                                        y: {
                                            beginAtZero: true,
                                            max: 100,
                                            title: {
                                                display: true,
                                                text: 'Attendance Rate (%)'
                                            }
                                        }
                                    }
                                }
                            });
                        </script>
                    <?php else: ?>
                        <div class="no-data">
                            <div class="no-data-icon">📊</div>
                            <h3>No Attendance Data Found</h3>
                            <p>No attendance records match your selected criteria.</p>
                        </div>
                    <?php endif; ?>

                <!-- At-Risk Students Report -->
                <?php elseif ($report_type == 'at_risk_students'): ?>
                    <div class="report-header">
                        <h2>⚠️ At-Risk Students Report</h2>
                        <div class="report-period">
                            Period: <?php echo date('M j, Y', strtotime($date_from)); ?> to <?php echo date('M j, Y', strtotime($date_to)); ?>
                        </div>
                    </div>

                    <?php if ($at_risk_students && $at_risk_students->num_rows > 0): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Matric Number</th>
                                    <th>Subjects</th>
                                    <th>Total Classes</th>
                                    <th>Present</th>
                                    <th>Attendance Rate</th>
                                    <th>Interventions</th>
                                    <th>Last Intervention</th>
                                    <th>Risk Level</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($student = $at_risk_students->fetch_assoc()): 
                                    $risk_level = '';
                                    if ($student['overall_attendance'] < 60 || $student['intervention_count'] > 3) {
                                        $risk_level = 'risk-high';
                                    } elseif ($student['overall_attendance'] < 70 || $student['intervention_count'] > 1) {
                                        $risk_level = 'risk-medium';
                                    } else {
                                        $risk_level = 'risk-low';
                                    }
                                ?>
                                    <tr class="<?php echo $risk_level; ?>">
                                        <td><strong><?php echo htmlspecialchars($student['student_name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($student['matric_number']); ?></td>
                                        <td><?php echo $student['total_subjects']; ?></td>
                                        <td><?php echo $student['total_classes']; ?></td>
                                        <td><?php echo $student['present_count']; ?></td>
                                        <td class="<?php echo $student['overall_attendance'] < 80 ? 'attendance-critical' : 'attendance-warning'; ?>">
                                            <?php echo $student['overall_attendance']; ?>%
                                        </td>
                                        <td><?php echo $student['intervention_count']; ?></td>
                                        <td><?php echo $student['last_intervention'] ? date('M j, Y', strtotime($student['last_intervention'])) : 'Never'; ?></td>
                                        <td>
                                            <?php 
                                            if ($risk_level == 'risk-high') echo '🔴 High';
                                            elseif ($risk_level == 'risk-medium') echo '🟡 Medium';
                                            else echo '🟢 Low';
                                            ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="no-data">
                            <div class="no-data-icon">✅</div>
                            <h3>No At-Risk Students Found</h3>
                            <p>Great news! No students are currently identified as at-risk based on your criteria.</p>
                        </div>
                    <?php endif; ?>

                <!-- Lecturer Activity Report -->
                <?php elseif ($report_type == 'lecturer_activity'): ?>
                    <div class="report-header">
                        <h2>👨‍🏫 Lecturer Activity Report</h2>
                        <div class="report-period">
                            Period: <?php echo date('M j, Y', strtotime($date_from)); ?> to <?php echo date('M j, Y', strtotime($date_to)); ?>
                        </div>
                    </div>

                    <?php if ($lecturer_activity && $lecturer_activity->num_rows > 0): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Lecturer</th>
                                    <th>Email</th>
                                    <th>Subjects Taught</th>
                                    <th>Attendance Records</th>
                                    <th>Interventions Made</th>
                                    <th>First Activity</th>
                                    <th>Last Activity</th>
                                    <th>Activity Level</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($lecturer = $lecturer_activity->fetch_assoc()): 
                                    $activity_level = $lecturer['attendance_records'] + ($lecturer['interventions_made'] * 5);
                                ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($lecturer['lecturer_name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($lecturer['email']); ?></td>
                                        <td><?php echo $lecturer['subjects_taught']; ?></td>
                                        <td><?php echo $lecturer['attendance_records']; ?></td>
                                        <td><?php echo $lecturer['interventions_made']; ?></td>
                                        <td><?php echo $lecturer['first_activity'] ? date('M j, Y', strtotime($lecturer['first_activity'])) : 'Never'; ?></td>
                                        <td><?php echo $lecturer['last_activity'] ? date('M j, Y', strtotime($lecturer['last_activity'])) : 'Never'; ?></td>
                                        <td>
                                            <?php 
                                            if ($activity_level > 100) echo '🔴 High';
                                            elseif ($activity_level > 50) echo '🟡 Medium';
                                            elseif ($activity_level > 0) echo '🟢 Low';
                                            else echo '⚪ Inactive';
                                            ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="no-data">
                            <div class="no-data-icon">👨‍🏫</div>
                            <h3>No Lecturer Activity Data</h3>
                            <p>No lecturer activity records found for the selected period.</p>
                        </div>
                    <?php endif; ?>

                <!-- Intervention Analysis Report -->
                <?php elseif ($report_type == 'intervention_analysis'): ?>
                    <div class="report-header">
                        <h2>🔄 Intervention Analysis Report</h2>
                        <div class="report-period">
                            Period: <?php echo date('M j, Y', strtotime($date_from)); ?> to <?php echo date('M j, Y', strtotime($date_to)); ?>
                        </div>
                    </div>

                    <?php if ($intervention_analysis && $intervention_analysis->num_rows > 0): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Subject</th>
                                    <th>Total Interventions</th>
                                    <th>Unique Students</th>
                                    <th>Involved Lecturers</th>
                                    <th>Avg Days to Intervention</th>
                                    <th>First Intervention</th>
                                    <th>Last Intervention</th>
                                    <th>Intervention Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($analysis = $intervention_analysis->fetch_assoc()): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($analysis['subject_name']); ?></strong></td>
                                        <td><?php echo $analysis['total_interventions']; ?></td>
                                        <td><?php echo $analysis['unique_students']; ?></td>
                                        <td><?php echo $analysis['involved_lecturers']; ?></td>
                                        <td><?php echo round($analysis['avg_days_to_intervention']); ?> days</td>
                                        <td><?php echo date('M j, Y', strtotime($analysis['first_intervention'])); ?></td>
                                        <td><?php echo date('M j, Y', strtotime($analysis['last_intervention'])); ?></td>
                                        <td>
                                            <?php 
                                            $intervention_rate = $analysis['unique_students'] > 0 ? 
                                                round(($analysis['total_interventions'] / $analysis['unique_students']), 2) : 0;
                                            echo $intervention_rate . ' per student';
                                            ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="no-data">
                            <div class="no-data-icon">🔄</div>
                            <h3>No Intervention Data</h3>
                            <p>No intervention records found for the selected period.</p>
                        </div>
                    <?php endif; ?>

                <!-- Daily Trends Report -->
                <?php elseif ($report_type == 'daily_trends'): ?>
                    <div class="report-header">
                        <h2>📅 Daily Attendance Trends</h2>
                        <div class="report-period">
                            Period: <?php echo date('M j, Y', strtotime($date_from)); ?> to <?php echo date('M j, Y', strtotime($date_to)); ?>
                        </div>
                    </div>

                    <?php if ($daily_trends && $daily_trends->num_rows > 0): ?>
                        <div class="chart-container">
                            <div class="chart-title">Daily Attendance Rates</div>
                            <div class="chart-wrapper">
                                <canvas id="dailyTrendsChart"></canvas>
                            </div>
                        </div>

                        <table>
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Total Records</th>
                                    <th>Present</th>
                                    <th>Absent</th>
                                    <th>Attendance Rate</th>
                                    <th>Trend</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $trend_labels = [];
                                $trend_data = [];
                                while ($trend = $daily_trends->fetch_assoc()): 
                                    array_unshift($trend_labels, $trend['attendance_day']);
                                    array_unshift($trend_data, $trend['daily_attendance_rate']);
                                ?>
                                    <tr>
                                        <td><strong><?php echo date('M j, Y', strtotime($trend['attendance_day'])); ?></strong></td>
                                        <td><?php echo $trend['total_records']; ?></td>
                                        <td class="attendance-good"><?php echo $trend['present_count']; ?></td>
                                        <td class="attendance-critical"><?php echo $trend['absent_count']; ?></td>
                                        <td class="<?php echo $trend['daily_attendance_rate'] >= 80 ? 'attendance-good' : ($trend['daily_attendance_rate'] >= 60 ? 'attendance-warning' : 'attendance-critical'); ?>">
                                            <?php echo $trend['daily_attendance_rate']; ?>%
                                        </td>
                                        <td>
                                            <?php 
                                            if ($trend['daily_attendance_rate'] >= 80) echo '📈 Good';
                                            elseif ($trend['daily_attendance_rate'] >= 60) echo '➡️ Average';
                                            else echo '📉 Poor';
                                            ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>

                        <script>
                            // Daily Trends Chart
                            const trendsCtx = document.getElementById('dailyTrendsChart').getContext('2d');
                            new Chart(trendsCtx, {
                                type: 'line',
                                data: {
                                    labels: <?php echo json_encode($trend_labels); ?>,
                                    datasets: [{
                                        label: 'Daily Attendance Rate (%)',
                                        data: <?php echo json_encode($trend_data); ?>,
                                        borderColor: '#3498db',
                                        backgroundColor: 'rgba(52, 152, 219, 0.1)',
                                        borderWidth: 3,
                                        fill: true,
                                        tension: 0.4
                                    }]
                                },
                                options: {
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    scales: {
                                        y: {
                                            beginAtZero: true,
                                            max: 100,
                                            title: {
                                                display: true,
                                                text: 'Attendance Rate (%)'
                                            }
                                        }
                                    }
                                }
                            });
                        </script>
                    <?php else: ?>
                        <div class="no-data">
                            <div class="no-data-icon">📅</div>
                            <h3>No Daily Trends Data</h3>
                            <p>No attendance records found for the selected period.</p>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Auto-submit form when date or subject changes
        document.getElementById('date_from').addEventListener('change', function() {
            this.form.submit();
        });
        
        document.getElementById('date_to').addEventListener('change', function() {
            this.form.submit();
        });
        
        document.getElementById('subject').addEventListener('change', function() {
            this.form.submit();
        });

        // Set default date range to last 30 days if not set
        document.addEventListener('DOMContentLoaded', function() {
            const dateFrom = document.getElementById('date_from');
            const dateTo = document.getElementById('date_to');
            
            if (!dateFrom.value) {
                const today = new Date();
                const thirtyDaysAgo = new Date();
                thirtyDaysAgo.setDate(today.getDate() - 30);
                dateFrom.value = thirtyDaysAgo.toISOString().split('T')[0];
            }
            
            if (!dateTo.value) {
                const today = new Date();
                dateTo.value = today.toISOString().split('T')[0];
            }
        });
    </script>
</body>
</html>