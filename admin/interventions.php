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

// Handle intervention deletion
if (isset($_GET['delete_id'])) {
    $delete_id = $_GET['delete_id'];
    
    $delete_sql = "DELETE FROM interventions WHERE intervention_id = ?";
    $delete_stmt = $conn->prepare($delete_sql);
    $delete_stmt->bind_param("i", $delete_id);
    
    if ($delete_stmt->execute()) {
        $message = "✅ Intervention record deleted successfully!";
        $message_class = "success";
    } else {
        $message = "❌ Error deleting intervention record: " . $delete_stmt->error;
        $message_class = "error";
    }
}

// Handle add intervention (Admin can add interventions for coordination)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_intervention'])) {
    $student_id = $_POST['student_id'];
    $subject_id = $_POST['subject_id'];
    $comments = trim($_POST['comments']);
    $intervention_date = $_POST['intervention_date'] ?? date('Y-m-d');
    
    // Validate inputs
    if (empty($student_id) || empty($subject_id) || empty($comments)) {
        $message = "❌ Please fill in all required fields!";
        $message_class = "error";
    } else {
        // Use admin user_id (1111) as created_by for admin-added interventions
        $admin_user_id = 1111;
        
        $insert_sql = "INSERT INTO interventions (student_id, subject_id, intervention_date, comments, created_by) 
                       VALUES (?, ?, ?, ?, ?)";
        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->bind_param("iissi", $student_id, $subject_id, $intervention_date, $comments, $admin_user_id);
        
        if ($insert_stmt->execute()) {
            $message = "✅ Intervention added successfully!";
            $message_class = "success";
            // Clear form
            $_POST = array();
        } else {
            $message = "❌ Error adding intervention: " . $insert_stmt->error;
            $message_class = "error";
        }
    }
}

// Handle bulk intervention actions
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['bulk_action'])) {
    $intervention_ids = $_POST['intervention_ids'] ?? [];
    $action = $_POST['bulk_action_type'];
    
    if (empty($intervention_ids)) {
        $message = "❌ Please select intervention records to update!";
        $message_class = "error";
    } else {
        if ($action === 'delete') {
            $placeholders = str_repeat('?,', count($intervention_ids) - 1) . '?';
            $delete_sql = "DELETE FROM interventions WHERE intervention_id IN ($placeholders)";
            $delete_stmt = $conn->prepare($delete_sql);
            
            $delete_stmt->bind_param(str_repeat('i', count($intervention_ids)), ...$intervention_ids);
            
            if ($delete_stmt->execute()) {
                $message = "✅ " . $delete_stmt->affected_rows . " intervention records deleted successfully!";
                $message_class = "success";
            } else {
                $message = "❌ Error deleting intervention records: " . $delete_stmt->error;
                $message_class = "error";
            }
        }
    }
}

// Get filter parameters
$search = $_GET['search'] ?? '';
$subject_filter = $_GET['subject'] ?? '';
$lecturer_filter = $_GET['lecturer'] ?? '';
$student_filter = $_GET['student'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$intervention_type = $_GET['intervention_type'] ?? '';

// Build enhanced query for intervention records with cross-subject compatibility
$interventions_sql = "
    SELECT 
        i.intervention_id,
        i.intervention_date,
        i.comments,
        s.student_id,
        s.name as student_name,
        sub.subject_id,
        sub.subject_name,
        l.lecturer_id,
        l.lecturer_name,
        u.username as created_by_username,
        (SELECT COUNT(*) FROM interventions i2 WHERE i2.student_id = s.student_id) as total_student_interventions,
        (SELECT COUNT(DISTINCT subject_id) FROM interventions i3 WHERE i3.student_id = s.student_id) as intervention_subjects_count
    FROM interventions i
    JOIN students s ON i.student_id = s.student_id
    JOIN subjects sub ON i.subject_id = sub.subject_id
    LEFT JOIN lecturers l ON i.created_by = l.lecturer_id
    LEFT JOIN users u ON i.created_by = u.user_id
    WHERE 1=1
";

$params = [];
$types = "";

// Add search filter
if (!empty($search)) {
    $interventions_sql .= " AND (s.name LIKE ? OR sub.subject_name LIKE ? OR i.comments LIKE ? OR l.lecturer_name LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= "ssss";
}

// Add subject filter
if (!empty($subject_filter)) {
    $interventions_sql .= " AND i.subject_id = ?";
    $params[] = $subject_filter;
    $types .= "i";
}

// Add lecturer filter
if (!empty($lecturer_filter)) {
    $interventions_sql .= " AND i.created_by = ?";
    $params[] = $lecturer_filter;
    $types .= "i";
}

// Add student filter
if (!empty($student_filter)) {
    $interventions_sql .= " AND i.student_id = ?";
    $params[] = $student_filter;
    $types .= "i";
}

// Add date filters
if (!empty($date_from)) {
    $interventions_sql .= " AND i.intervention_date >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if (!empty($date_to)) {
    $interventions_sql .= " AND i.intervention_date <= ?";
    $params[] = $date_to;
    $types .= "s";
}

// Add intervention type filter
if (!empty($intervention_type)) {
    switch ($intervention_type) {
        case 'multi_subject':
            $interventions_sql .= " AND s.student_id IN (
                SELECT student_id FROM interventions GROUP BY student_id HAVING COUNT(DISTINCT subject_id) > 1
            )";
            break;
        case 'single_subject':
            $interventions_sql .= " AND s.student_id IN (
                SELECT student_id FROM interventions GROUP BY student_id HAVING COUNT(DISTINCT subject_id) = 1
            )";
            break;
        case 'recurring':
            $interventions_sql .= " AND s.student_id IN (
                SELECT student_id FROM interventions GROUP BY student_id HAVING COUNT(*) > 1
            )";
            break;
    }
}

$interventions_sql .= " ORDER BY i.intervention_date DESC, s.name, sub.subject_name";

// Execute query
$stmt = $conn->prepare($interventions_sql);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $interventions_result = $stmt->get_result();
} else {
    error_log("Interventions SQL Error: " . $conn->error);
    $interventions_result = null;
}

// Get all subjects for dropdown
$subjects_sql = "SELECT subject_id, subject_name FROM subjects ORDER BY subject_name";
$subjects_result = $conn->query($subjects_sql);

// Get all lecturers for dropdown
$lecturers_sql = "SELECT lecturer_id, lecturer_name FROM lecturers ORDER BY lecturer_name";
$lecturers_result = $conn->query($lecturers_sql);

// Get all students for dropdown
$students_sql = "SELECT student_id, name FROM students ORDER BY name";
$students_result = $conn->query($students_sql);

// Get enhanced intervention statistics
$stats = [
    'total_interventions' => 0,
    'today_interventions' => 0,
    'unique_students' => 0,
    'active_lecturers' => 0,
    'multi_subject_cases' => 0,
    'cross_subject_coordination' => 0
];

$stats_sql = "
    SELECT 
        COUNT(*) as total_interventions,
        COUNT(CASE WHEN DATE(intervention_date) = CURDATE() THEN 1 END) as today_interventions,
        COUNT(DISTINCT student_id) as unique_students,
        COUNT(DISTINCT created_by) as active_lecturers,
        COUNT(DISTINCT CASE WHEN student_id IN (
            SELECT student_id FROM interventions GROUP BY student_id HAVING COUNT(DISTINCT subject_id) > 1
        ) THEN student_id END) as multi_subject_cases,
        COUNT(DISTINCT CASE WHEN student_id IN (
            SELECT student_id FROM interventions GROUP BY student_id HAVING COUNT(*) > 1
        ) THEN student_id END) as cross_subject_coordination
    FROM interventions
";

$stats_result = $conn->query($stats_sql);
if ($stats_result) {
    $stats = $stats_result->fetch_assoc();
} else {
    error_log("Stats SQL Error: " . $conn->error);
}

// Get subject-wise intervention statistics
$subject_stats_sql = "
    SELECT 
        sub.subject_name,
        COUNT(*) as intervention_count,
        COUNT(DISTINCT i.student_id) as affected_students,
        COUNT(DISTINCT i.created_by) as involved_lecturers
    FROM interventions i
    JOIN subjects sub ON i.subject_id = sub.subject_id
    GROUP BY sub.subject_id, sub.subject_name
    ORDER BY intervention_count DESC
    LIMIT 6
";

$subject_stats_result = $conn->query($subject_stats_sql);

// Get recent multi-subject intervention cases
$multi_subject_cases_sql = "
    SELECT 
        s.student_id,
        s.name as student_name,
        COUNT(DISTINCT i.subject_id) as subject_count,
        GROUP_CONCAT(DISTINCT sub.subject_name ORDER BY sub.subject_name SEPARATOR ', ') as subjects,
        COUNT(*) as total_interventions,
        MAX(i.intervention_date) as last_intervention
    FROM interventions i
    JOIN students s ON i.student_id = s.student_id
    JOIN subjects sub ON i.subject_id = sub.subject_id
    GROUP BY s.student_id, s.name
    HAVING COUNT(DISTINCT i.subject_id) > 1
    ORDER BY total_interventions DESC, subject_count DESC
    LIMIT 5
";

$multi_subject_cases_result = $conn->query($multi_subject_cases_sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Interventions Monitoring - CSSAP Admin</title>
    <style>
        /* All the same CSS styles from the original file */
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
            background: #9b59b6;
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
            border-left: 5px solid #9b59b6;
        }

        .stat-card.total { border-left-color: #9b59b6; }
        .stat-card.today { border-left-color: #3498db; }
        .stat-card.students { border-left-color: #2ecc71; }
        .stat-card.lecturers { border-left-color: #f39c12; }
        .stat-card.multi-subject { border-left-color: #e74c3c; }
        .stat-card.coordination { border-left-color: #1abc9c; }

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
            background: linear-gradient(135deg, #9b59b6, #8e44ad);
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
            box-shadow: 0 5px 15px rgba(155, 89, 182, 0.3);
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

        .btn-secondary {
            background: linear-gradient(135deg, #95a5a6, #7f8c8d);
        }

        .btn-info {
            background: linear-gradient(135deg, #3498db, #2980b9);
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

        input[type="text"], input[type="date"], select, textarea {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #9b59b6;
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

        .comment-text {
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .comment-text.expanded {
            white-space: normal;
            max-width: none;
        }

        .subject-badge {
            background: #9b59b6;
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .multi-subject-indicator {
            background: #e74c3c;
            color: white;
            padding: 2px 6px;
            border-radius: 8px;
            font-size: 0.7rem;
            margin-left: 5px;
        }

        .intervention-count-badge {
            background: #3498db;
            color: white;
            padding: 2px 6px;
            border-radius: 8px;
            font-size: 0.7rem;
            margin-left: 5px;
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
            border-left: 4px solid #9b59b6;
        }

        .subject-stat-card h4 {
            color: #2c3e50;
            margin-bottom: 8px;
            font-size: 0.9rem;
        }

        .intervention-count {
            font-size: 1.5rem;
            font-weight: bold;
            color: #2c3e50;
        }

        .stat-details {
            font-size: 0.8rem;
            color: #7f8c8d;
            margin-top: 5px;
        }

        /* Multi-subject cases */
        .multi-subject-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        .multi-subject-card {
            background: white;
            padding: 15px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border-left: 4px solid #e74c3c;
        }

        .multi-subject-card h4 {
            color: #2c3e50;
            margin-bottom: 8px;
            font-size: 0.9rem;
        }

        .subject-list {
            font-size: 0.8rem;
            color: #7f8c8d;
            margin: 5px 0;
        }

        /* Checkbox Styles */
        .select-checkbox {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .expand-comment {
            background: none;
            border: none;
            color: #3498db;
            cursor: pointer;
            font-size: 12px;
            margin-left: 5px;
        }

        /* Add Intervention Form */
        .add-intervention-form {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            border: 1px solid #e9ecef;
            margin-bottom: 20px;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }

        textarea {
            min-height: 80px;
            resize: vertical;
        }

        /* Tabs */
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
            color: #9b59b6;
            border-bottom-color: #9b59b6;
        }

        .tab:hover {
            color: #9b59b6;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .filter-form, .bulk-form, .form-row {
                grid-template-columns: 1fr;
            }
            
            table {
                font-size: 12px;
            }
            
            th, td {
                padding: 8px 10px;
            }
            
            .comment-text {
                max-width: 150px;
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
                <li><a href="interventions.php" class="active">🔄 Interventions</a></li>
                <li><a href="reports.php">📈 System Reports</a></li>
                <li><a href="profile.php">⚙️ My Profile</a></li>
                <li><a href="logout.php">🚪 Logout</a></li>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <div class="header">
                <h1>Interventions Monitoring</h1>
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

            <!-- Enhanced Statistics -->
            <div class="stats-grid">
                <div class="stat-card total">
                    <div class="stat-icon">📋</div>
                    <div class="stat-number"><?php echo $stats['total_interventions']; ?></div>
                    <div class="stat-label">Total Interventions</div>
                </div>
                <div class="stat-card today">
                    <div class="stat-icon">📅</div>
                    <div class="stat-number"><?php echo $stats['today_interventions']; ?></div>
                    <div class="stat-label">Today's Interventions</div>
                </div>
                <div class="stat-card students">
                    <div class="stat-icon">🎓</div>
                    <div class="stat-number"><?php echo $stats['unique_students']; ?></div>
                    <div class="stat-label">Students Assisted</div>
                </div>
                <div class="stat-card lecturers">
                    <div class="stat-icon">👨‍🏫</div>
                    <div class="stat-number"><?php echo $stats['active_lecturers']; ?></div>
                    <div class="stat-label">Active Lecturers</div>
                </div>
                <div class="stat-card multi-subject">
                    <div class="stat-icon">🔀</div>
                    <div class="stat-number"><?php echo $stats['multi_subject_cases']; ?></div>
                    <div class="stat-label">Multi-Subject Cases</div>
                </div>
                <div class="stat-card coordination">
                    <div class="stat-icon">🤝</div>
                    <div class="stat-number"><?php echo $stats['cross_subject_coordination']; ?></div>
                    <div class="stat-label">Coordinated Cases</div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab active" onclick="switchTab('overview')">📊 Overview</button>
                <button class="tab" onclick="switchTab('management')">🔄 Intervention Management</button>
                <button class="tab" onclick="switchTab('analytics')">📈 Cross-Subject Analytics</button>
            </div>

            <!-- Overview Tab -->
            <div id="overview" class="tab-content active">
                <!-- Subject Statistics -->
                <?php if ($subject_stats_result && $subject_stats_result->num_rows > 0): ?>
                    <div class="content-section">
                        <div class="section-header">
                            <h2>Interventions by Subject</h2>
                        </div>
                        <div class="subject-stats">
                            <?php while ($subject_stat = $subject_stats_result->fetch_assoc()): ?>
                                <div class="subject-stat-card">
                                    <h4><?php echo htmlspecialchars($subject_stat['subject_name']); ?></h4>
                                    <div class="intervention-count">
                                        <?php echo $subject_stat['intervention_count']; ?>
                                    </div>
                                    <div class="stat-details">
                                        <?php echo $subject_stat['affected_students']; ?> students<br>
                                        <?php echo $subject_stat['involved_lecturers']; ?> lecturers
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Multi-Subject Cases -->
                <?php if ($multi_subject_cases_result && $multi_subject_cases_result->num_rows > 0): ?>
                    <div class="content-section">
                        <div class="section-header">
                            <h2>Multi-Subject Intervention Cases</h2>
                        </div>
                        <div class="multi-subject-grid">
                            <?php while ($case = $multi_subject_cases_result->fetch_assoc()): ?>
                                <div class="multi-subject-card">
                                    <h4><?php echo htmlspecialchars($case['student_name']); ?></h4>
                                    <div class="intervention-count">
                                        <span class="multi-subject-indicator"><?php echo $case['subject_count']; ?> subjects</span>
                                        <span class="intervention-count-badge"><?php echo $case['total_interventions']; ?> intv</span>
                                    </div>
                                    <div class="subject-list">
                                        <strong>Subjects:</strong> <?php echo htmlspecialchars($case['subjects']); ?>
                                    </div>
                                    <div class="stat-details">
                                        Last: <?php echo date('M j, Y', strtotime($case['last_intervention'])); ?>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Management Tab -->
            <div id="management" class="tab-content">
                <div class="content-section">
                    <div class="section-header">
                        <h2>Intervention Records Management</h2>
                    </div>

                    <!-- Add Intervention Form -->
                    <div class="add-intervention-form">
                        <h3 style="margin-bottom: 15px; color: #2c3e50;">Add New Intervention (Admin)</h3>
                        <form method="POST">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="student_id">Student *</label>
                                    <select name="student_id" id="student_id" required>
                                        <option value="">Select Student</option>
                                        <?php 
                                        $students_result->data_seek(0);
                                        while ($student = $students_result->fetch_assoc()): ?>
                                            <option value="<?php echo $student['student_id']; ?>" 
                                                <?php echo (isset($_POST['student_id']) && $_POST['student_id'] == $student['student_id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($student['name']); ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="subject_id">Subject *</label>
                                    <select name="subject_id" id="subject_id" required>
                                        <option value="">Select Subject</option>
                                        <?php 
                                        $subjects_result->data_seek(0);
                                        while ($subject = $subjects_result->fetch_assoc()): ?>
                                            <option value="<?php echo $subject['subject_id']; ?>" 
                                                <?php echo (isset($_POST['subject_id']) && $_POST['subject_id'] == $subject['subject_id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($subject['subject_name']); ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="intervention_date">Date</label>
                                    <input type="date" name="intervention_date" id="intervention_date" 
                                           value="<?php echo isset($_POST['intervention_date']) ? htmlspecialchars($_POST['intervention_date']) : date('Y-m-d'); ?>">
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="comments">Intervention Details *</label>
                                <textarea name="comments" id="comments" required placeholder="Enter intervention details, actions taken, recommendations..." 
                                          rows="4"><?php echo isset($_POST['comments']) ? htmlspecialchars($_POST['comments']) : ''; ?></textarea>
                            </div>
                            <div style="margin-top: 15px;">
                                <button type="submit" name="add_intervention" class="btn btn-success">Add Intervention Record</button>
                            </div>
                        </form>
                    </div>

                    <!-- Bulk Actions -->
                    <div id="bulkActions" class="bulk-actions">
                        <form method="POST" class="bulk-form">
                            <div class="selected-count" id="selectedCount">0 records selected</div>
                            <select name="bulk_action_type" required>
                                <option value="">Select Action</option>
                                <option value="delete">Delete Selected</option>
                            </select>
                            <button type="submit" name="bulk_action" class="btn btn-danger">Apply to Selected</button>
                            <button type="button" class="btn btn-secondary" onclick="clearSelection()">Clear Selection</button>
                        </form>
                    </div>

                    <!-- Enhanced Filter Form -->
                    <form method="GET" class="filter-form">
                        <div class="form-group">
                            <label for="search">Search:</label>
                            <input type="text" name="search" id="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Student, subject, lecturer, or comments...">
                        </div>

                        <div class="form-group">
                            <label for="subject">Subject:</label>
                            <select name="subject" id="subject">
                                <option value="">All Subjects</option>
                                <?php 
                                $subjects_result->data_seek(0);
                                while ($subject = $subjects_result->fetch_assoc()): ?>
                                    <option value="<?php echo $subject['subject_id']; ?>" 
                                        <?php echo $subject_filter == $subject['subject_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($subject['subject_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="lecturer">Lecturer:</label>
                            <select name="lecturer" id="lecturer">
                                <option value="">All Lecturers</option>
                                <?php 
                                $lecturers_result->data_seek(0);
                                while ($lecturer = $lecturers_result->fetch_assoc()): ?>
                                    <option value="<?php echo $lecturer['lecturer_id']; ?>" 
                                        <?php echo $lecturer_filter == $lecturer['lecturer_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($lecturer['lecturer_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="student">Student:</label>
                            <select name="student" id="student">
                                <option value="">All Students</option>
                                <?php 
                                $students_result->data_seek(0);
                                while ($student = $students_result->fetch_assoc()): ?>
                                    <option value="<?php echo $student['student_id']; ?>" 
                                        <?php echo $student_filter == $student['student_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($student['name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="intervention_type">Intervention Type:</label>
                            <select name="intervention_type" id="intervention_type">
                                <option value="">All Types</option>
                                <option value="multi_subject" <?php echo $intervention_type === 'multi_subject' ? 'selected' : ''; ?>>Multi-Subject Cases</option>
                                <option value="single_subject" <?php echo $intervention_type === 'single_subject' ? 'selected' : ''; ?>>Single Subject Only</option>
                                <option value="recurring" <?php echo $intervention_type === 'recurring' ? 'selected' : ''; ?>>Recurring Interventions</option>
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

                        <div class="filter-actions">
                            <button type="submit" class="btn">Apply Filters</button>
                            <a href="interventions.php" class="btn btn-warning">Reset</a>
                            <button type="button" class="btn btn-info" onclick="exportInterventions()">📥 Export Data</button>
                        </div>
                    </form>

                    <!-- Interventions Table -->
                    <?php if ($interventions_result && $interventions_result->num_rows > 0): ?>
                        <form id="interventionsForm">
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
                                        <th>Intervention Details</th>
                                        <th>Cross-Subject Info</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($intervention = $interventions_result->fetch_assoc()): ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" name="intervention_ids[]" value="<?php echo $intervention['intervention_id']; ?>" class="select-checkbox intervention-checkbox">
                                            </td>
                                            <td><?php echo date('M j, Y', strtotime($intervention['intervention_date'])); ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($intervention['student_name']); ?></strong>
                                            </td>
                                            <td>
                                                <span class="subject-badge">
                                                    <?php echo htmlspecialchars($intervention['subject_name']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($intervention['lecturer_name'] ?? 'Admin'); ?></td>
                                            <td>
                                                <span class="comment-text" id="comment-<?php echo $intervention['intervention_id']; ?>">
                                                    <?php echo htmlspecialchars($intervention['comments']); ?>
                                                </span>
                                                <?php if (strlen($intervention['comments']) > 100): ?>
                                                    <button type="button" class="expand-comment" onclick="toggleComment(<?php echo $intervention['intervention_id']; ?>)">
                                                        Show more
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($intervention['total_student_interventions'] > 1): ?>
                                                    <span class="intervention-count-badge">
                                                        <?php echo $intervention['total_student_interventions']; ?> total
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($intervention['intervention_subjects_count'] > 1): ?>
                                                    <span class="multi-subject-indicator">
                                                        <?php echo $intervention['intervention_subjects_count']; ?> subjects
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="action-buttons">
                                                <a href="interventions.php?delete_id=<?php echo $intervention['intervention_id']; ?>" 
                                                   class="btn btn-danger btn-sm" 
                                                   onclick="return confirm('Are you sure you want to delete this intervention record?')">
                                                    Delete
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </form>
                    <?php else: ?>
                        <div class="no-data">No intervention records found matching your criteria.</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Analytics Tab -->
            <div id="analytics" class="tab-content">
                <div class="content-section">
                    <div class="section-header">
                        <h2>Cross-Subject Intervention Analytics</h2>
                    </div>
                    <div style="text-align: center; padding: 40px; color: #7f8c8d;">
                        <h3>📈 Advanced Analytics Coming Soon</h3>
                        <p>This section will include:</p>
                        <ul style="list-style: none; margin-top: 15px;">
                            <li>• Intervention trends over time</li>
                            <li>• Cross-subject coordination effectiveness</li>
                            <li>• Student progress tracking</li>
                            <li>• Predictive analytics for at-risk students</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Bulk selection functionality
        const selectAllCheckbox = document.getElementById('selectAll');
        const interventionCheckboxes = document.querySelectorAll('.intervention-checkbox');
        const bulkActions = document.getElementById('bulkActions');
        const selectedCount = document.getElementById('selectedCount');

        function updateBulkActions() {
            const selectedCountValue = document.querySelectorAll('.intervention-checkbox:checked').length;
            selectedCount.textContent = selectedCountValue + ' records selected';
            
            if (selectedCountValue > 0) {
                bulkActions.style.display = 'block';
            } else {
                bulkActions.style.display = 'none';
            }
        }

        selectAllCheckbox.addEventListener('change', function() {
            interventionCheckboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
            updateBulkActions();
        });

        interventionCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', updateBulkActions);
        });

        function clearSelection() {
            interventionCheckboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
            selectAllCheckbox.checked = false;
            updateBulkActions();
        }

        // Toggle comment expansion
        function toggleComment(interventionId) {
            const commentElement = document.getElementById('comment-' + interventionId);
            const button = commentElement.nextElementSibling;
            
            if (commentElement.classList.contains('expanded')) {
                commentElement.classList.remove('expanded');
                button.textContent = 'Show more';
            } else {
                commentElement.classList.add('expanded');
                button.textContent = 'Show less';
            }
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
        function exportInterventions() {
            const params = new URLSearchParams(window.location.search);
            window.open('export_interventions.php?' + params.toString(), '_blank');
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