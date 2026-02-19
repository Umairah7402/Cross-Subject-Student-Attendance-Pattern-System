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

// Handle lecturer deletion
if (isset($_GET['delete_id'])) {
    $delete_id = $_GET['delete_id'];
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Get lecturer user_id before deletion
        $lecturer_sql = "SELECT user_id FROM lecturers WHERE lecturer_id = ?";
        $lecturer_stmt = $conn->prepare($lecturer_sql);
        $lecturer_stmt->bind_param("i", $delete_id);
        $lecturer_stmt->execute();
        $lecturer_result = $lecturer_stmt->get_result();
        $lecturer_data = $lecturer_result->fetch_assoc();
        
        if ($lecturer_data && $lecturer_data['user_id']) {
            // Delete from lecturer_subjects first
            $subject_sql = "DELETE FROM lecturer_subjects WHERE lecturer_id = ?";
            $subject_stmt = $conn->prepare($subject_sql);
            $subject_stmt->bind_param("i", $delete_id);
            $subject_stmt->execute();
            
            // Delete from lecturers table
            $lecturer_delete_sql = "DELETE FROM lecturers WHERE lecturer_id = ?";
            $lecturer_delete_stmt = $conn->prepare($lecturer_delete_sql);
            $lecturer_delete_stmt->bind_param("i", $delete_id);
            
            // Delete from users table using user_id
            $user_sql = "DELETE FROM users WHERE user_id = ?";
            $user_stmt = $conn->prepare($user_sql);
            $user_stmt->bind_param("i", $lecturer_data['user_id']);
            
            if ($user_stmt->execute() && $lecturer_delete_stmt->execute()) {
                $conn->commit();
                $message = "✅ Lecturer deleted successfully!";
                $message_class = "success";
            } else {
                throw new Exception("Error deleting lecturer records");
            }
        } else {
            throw new Exception("Lecturer not found or missing user relationship");
        }
    } catch (Exception $e) {
        $conn->rollback();
        $message = "❌ " . $e->getMessage();
        $message_class = "error";
    }
}

// Handle subject assignment (now supports multiple subjects)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['assign_subjects'])) {
    $lecturer_id = $_POST['lecturer_id'];
    $subject_ids = $_POST['subject_ids'] ?? [];
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Remove existing subjects for this lecturer
        $delete_sql = "DELETE FROM lecturer_subjects WHERE lecturer_id = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param("i", $lecturer_id);
        $delete_stmt->execute();
        
        // Insert new subject assignments if any subjects selected
        if (!empty($subject_ids)) {
            $insert_sql = "INSERT INTO lecturer_subjects (lecturer_id, subject_id) VALUES (?, ?)";
            $insert_stmt = $conn->prepare($insert_sql);
            
            foreach ($subject_ids as $subject_id) {
                if (!empty($subject_id)) {
                    $insert_stmt->bind_param("ii", $lecturer_id, $subject_id);
                    $insert_stmt->execute();
                }
            }
        }
        
        $conn->commit();
        $message = "✅ Subjects assigned successfully!";
        $message_class = "success";
        
    } catch (Exception $e) {
        $conn->rollback();
        $message = "❌ Error assigning subjects: " . $e->getMessage();
        $message_class = "error";
    }
}

// Handle bulk lecturer actions
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['bulk_action'])) {
    $lecturer_ids = $_POST['lecturer_ids'] ?? [];
    $bulk_action = $_POST['bulk_action_type'];
    
    if (empty($lecturer_ids)) {
        $message = "❌ Please select lecturers to perform bulk action!";
        $message_class = "error";
    } else {
        $placeholders = str_repeat('?,', count($lecturer_ids) - 1) . '?';
        
        switch ($bulk_action) {
            case 'assign_subjects':
                $assign_subject_id = $_POST['bulk_subject_id'];
                if (empty($assign_subject_id)) {
                    $message = "❌ Please select a subject to assign!";
                    $message_class = "error";
                } else {
                    $success_count = 0;
                    foreach ($lecturer_ids as $lecturer_id) {
                        // Check if assignment already exists
                        $check_sql = "SELECT lecturer_subject_id FROM lecturer_subjects WHERE lecturer_id = ? AND subject_id = ?";
                        $check_stmt = $conn->prepare($check_sql);
                        $check_stmt->bind_param("ii", $lecturer_id, $assign_subject_id);
                        $check_stmt->execute();
                        
                        if ($check_stmt->get_result()->num_rows == 0) {
                            $insert_sql = "INSERT INTO lecturer_subjects (lecturer_id, subject_id) VALUES (?, ?)";
                            $insert_stmt = $conn->prepare($insert_sql);
                            $insert_stmt->bind_param("ii", $lecturer_id, $assign_subject_id);
                            if ($insert_stmt->execute()) {
                                $success_count++;
                            }
                        }
                    }
                    $message = "✅ Subject assigned to $success_count lecturers successfully!";
                    $message_class = "success";
                }
                break;
                
            case 'remove_subjects':
                $remove_subject_id = $_POST['bulk_subject_id'];
                if (empty($remove_subject_id)) {
                    $message = "❌ Please select a subject to remove!";
                    $message_class = "error";
                } else {
                    $delete_sql = "DELETE FROM lecturer_subjects WHERE lecturer_id IN ($placeholders) AND subject_id = ?";
                    $delete_stmt = $conn->prepare($delete_sql);
                    $params = array_merge($lecturer_ids, [$remove_subject_id]);
                    $types = str_repeat('i', count($lecturer_ids)) . 'i';
                    $delete_stmt->bind_param($types, ...$params);
                    
                    if ($delete_stmt->execute()) {
                        $message = "✅ Subject removed from " . $delete_stmt->affected_rows . " lecturers successfully!";
                        $message_class = "success";
                    } else {
                        $message = "❌ Error removing subjects: " . $delete_stmt->error;
                        $message_class = "error";
                    }
                }
                break;
        }
    }
}

// Get all subjects for dropdown
$subjects_sql = "SELECT subject_id, subject_name FROM subjects ORDER BY subject_name";
$subjects_result = $conn->query($subjects_sql);

// Get enhanced lecturer statistics
$stats = [
    'total_lecturers' => 0,
    'with_subjects' => 0,
    'without_subjects' => 0,
    'active_lecturers' => 0,
    'total_interventions' => 0,
    'total_subject_assignments' => 0
];

// Get total lecturers count
$total_lecturers_sql = "SELECT COUNT(*) as total_lecturers FROM lecturers";
$total_result = $conn->query($total_lecturers_sql);
if ($total_result) {
    $row = $total_result->fetch_assoc();
    $stats['total_lecturers'] = $row['total_lecturers'];
}

// Get lecturers with subjects count
$with_subjects_sql = "SELECT COUNT(DISTINCT lecturer_id) as with_subjects FROM lecturer_subjects";
$with_subjects_result = $conn->query($with_subjects_sql);
if ($with_subjects_result) {
    $row = $with_subjects_result->fetch_assoc();
    $stats['with_subjects'] = $row['with_subjects'];
}

// Calculate without subjects
$stats['without_subjects'] = $stats['total_lecturers'] - $stats['with_subjects'];

// Get active lecturers count (all lecturers are active in your system)
$stats['active_lecturers'] = $stats['total_lecturers'];

// Get total interventions count
$interventions_sql = "SELECT COUNT(*) as total_interventions FROM interventions";
$interventions_result = $conn->query($interventions_sql);
if ($interventions_result) {
    $row = $interventions_result->fetch_assoc();
    $stats['total_interventions'] = $row['total_interventions'];
}

// Get total subject assignments count
$assignments_sql = "SELECT COUNT(*) as total_subject_assignments FROM lecturer_subjects";
$assignments_result = $conn->query($assignments_sql);
if ($assignments_result) {
    $row = $assignments_result->fetch_assoc();
    $stats['total_subject_assignments'] = $row['total_subject_assignments'];
}

// Build query for lecturers with cross-subject metrics (SIMPLIFIED - no search/filters)
$lecturers_sql = "
    SELECT 
        l.lecturer_id,
        l.lecturer_name,
        l.email,
        l.user_id,
        u.username,
        COALESCE(GROUP_CONCAT(DISTINCT s.subject_name ORDER BY s.subject_name SEPARATOR ', '), 'No subjects assigned') as subjects_taught,
        COUNT(DISTINCT ls.subject_id) as subject_count,
        COALESCE((SELECT COUNT(*) FROM interventions i 
         WHERE i.created_by = l.lecturer_id), 0) as intervention_count,
        COALESCE((SELECT COUNT(DISTINCT a.student_id) 
         FROM attendance a 
         JOIN lecturer_subjects ls2 ON a.subject_id = ls2.subject_id 
         WHERE ls2.lecturer_id = l.lecturer_id), 0) as unique_students,
        COALESCE((SELECT COUNT(*) 
         FROM attendance a 
         JOIN lecturer_subjects ls3 ON a.subject_id = ls3.subject_id 
         WHERE ls3.lecturer_id = l.lecturer_id), 0) as total_attendance_records,
        COALESCE((SELECT COUNT(DISTINCT e.student_id) 
         FROM enrollments e 
         JOIN lecturer_subjects ls4 ON e.subject_id = ls4.subject_id 
         WHERE ls4.lecturer_id = l.lecturer_id), 0) as enrolled_students
    FROM lecturers l
    LEFT JOIN users u ON l.user_id = u.user_id
    LEFT JOIN lecturer_subjects ls ON l.lecturer_id = ls.lecturer_id
    LEFT JOIN subjects s ON ls.subject_id = s.subject_id
    GROUP BY l.lecturer_id, l.lecturer_name, l.email, l.user_id, u.username
    ORDER BY l.lecturer_name ASC
";

// Execute query
$lecturers_result = $conn->query($lecturers_sql);

// Get subject distribution statistics
$subject_dist_sql = "
    SELECT 
        s.subject_name,
        COUNT(DISTINCT ls.lecturer_id) as lecturer_count,
        COUNT(DISTINCT e.student_id) as student_count
    FROM subjects s
    LEFT JOIN lecturer_subjects ls ON s.subject_id = ls.subject_id
    LEFT JOIN enrollments e ON s.subject_id = e.subject_id
    GROUP BY s.subject_id, s.subject_name
    ORDER BY lecturer_count DESC
    LIMIT 5
";

$subject_dist_result = $conn->query($subject_dist_sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lecturer Management - CSSAP Admin</title>
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
            border-left: 5px solid #2ecc71;
        }

        .stat-card.total { border-left-color: #3498db; }
        .stat-card.assigned { border-left-color: #2ecc71; }
        .stat-card.unassigned { border-left-color: #e74c3c; }
        .stat-card.active { border-left-color: #f39c12; }
        .stat-card.interventions { border-left-color: #9b59b6; }
        .stat-card.assignments { border-left-color: #1abc9c; }

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

        .btn-secondary {
            background: linear-gradient(135deg, #95a5a6, #7f8c8d);
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
            flex-wrap: wrap;
        }

        .selected-count {
            font-weight: 600;
            color: #856404;
            min-width: 120px;
        }

        /* Checkbox Styles */
        .select-checkbox {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        /* Lecturer Card Styles */
        .lecturer-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-left: 4px solid #2ecc71;
            position: relative;
        }

        .lecturer-card.unassigned {
            border-left-color: #e74c3c;
            background: #fff5f5;
        }

        .lecturer-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .lecturer-info h3 {
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .lecturer-email {
            color: #7f8c8d;
            font-size: 0.9rem;
        }

        .lecturer-meta {
            display: flex;
            gap: 15px;
            margin-top: 8px;
            font-size: 0.8rem;
            color: #7f8c8d;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .subject-list {
            max-width: 400px;
            margin-top: 10px;
        }

        .subject-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
            background: #3498db;
            color: white;
            margin: 2px;
            display: inline-block;
        }

        .no-subject {
            color: #e74c3c;
            font-style: italic;
            background: #f8d7da;
            padding: 4px 8px;
            border-radius: 8px;
            font-size: 0.8rem;
        }

        .lecturer-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #e9ecef;
        }

        .stat-item {
            text-align: center;
        }

        .stat-value {
            font-size: 1.2rem;
            font-weight: bold;
            color: #2c3e50;
        }

        .stat-label {
            font-size: 0.8rem;
            color: #7f8c8d;
            text-transform: uppercase;
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

        /* Subject Distribution */
        .subject-dist-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        .subject-dist-card {
            background: white;
            padding: 15px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border-left: 4px solid #3498db;
        }

        .subject-dist-card h4 {
            color: #2c3e50;
            margin-bottom: 8px;
            font-size: 0.9rem;
        }

        .dist-stats {
            display: flex;
            justify-content: space-between;
            margin-top: 8px;
        }

        .dist-stat {
            text-align: center;
        }

        .dist-number {
            font-size: 1.2rem;
            font-weight: bold;
            color: #3498db;
        }

        .dist-label {
            font-size: 0.7rem;
            color: #7f8c8d;
            text-transform: uppercase;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            animation: fadeIn 0.3s ease;
        }

        .modal-content {
            background-color: white;
            margin: 10% auto;
            padding: 30px;
            border-radius: 15px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            animation: slideIn 0.3s ease;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e9ecef;
        }

        .modal-header h3 {
            color: #2c3e50;
            margin: 0;
        }

        .close {
            color: #6c757d;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            transition: color 0.3s ease;
        }

        .close:hover {
            color: #e74c3c;
        }

        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }

        .subject-checkboxes {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 10px;
            margin-top: 10px;
        }

        .checkbox-group {
            margin-bottom: 8px;
        }

        .checkbox-group label {
            font-weight: normal;
            margin-bottom: 0;
            cursor: pointer;
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

        /* Lecturer Count */
        .lecturer-count {
            background: #3498db;
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-left: 10px;
        }

        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideIn {
            from { 
                opacity: 0;
                transform: translateY(-20px);
            }
            to { 
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .bulk-form {
                flex-direction: column;
                align-items: stretch;
            }
            
            .lecturer-stats {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .lecturer-header {
                flex-direction: column;
                gap: 15px;
            }
            
            .admin-container {
                flex-direction: column;
            }
            
            .sidebar {
                width: 100%;
                padding: 20px;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .subject-dist-grid {
                grid-template-columns: 1fr;
            }
            
            .lecturer-stats {
                grid-template-columns: 1fr;
            }
        }

        select {
            padding: 8px 12px;
            border-radius: 6px;
            border: 1px solid #ddd;
            font-size: 14px;
            min-width: 150px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #495057;
        }

        /* Lecturer List Header */
        .lecturers-list-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e9ecef;
        }

        .total-lecturers {
            font-size: 0.9rem;
            color: #7f8c8d;
        }

        .total-lecturers strong {
            color: #2c3e50;
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 50px 20px;
            background: #f8f9fa;
            border-radius: 10px;
            border: 2px dashed #dee2e6;
        }

        .empty-state h3 {
            color: #6c757d;
            margin-bottom: 10px;
        }

        .empty-state p {
            color: #6c757d;
            margin-bottom: 20px;
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
                <li><a href="lecturers.php" class="active">👨‍🏫 Lecturer Management</a></li>
                <li><a href="students.php">🎓 Student Management</a></li>
                <li><a href="subjects.php">📚 Subject Management</a></li>
                <li><a href="attendance.php">📝 Attendance Overview</a></li>
                <li><a href="interventions.php">🔄 Interventions</a></li>
                <li><a href="reports.php">📈 System Reports</a></li>
                <li><a href="profile.php">⚙️ My Profile</a></li>
                <li><a href="logout.php">🚪 Logout</a></li>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <div class="header">
                <h1>Lecturer Management</h1>
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
                    <div class="stat-icon">👨‍🏫</div>
                    <div class="stat-number"><?php echo $stats['total_lecturers']; ?></div>
                    <div class="stat-label">Total Lecturers</div>
                </div>
                <div class="stat-card assigned">
                    <div class="stat-icon">✅</div>
                    <div class="stat-number"><?php echo $stats['with_subjects']; ?></div>
                    <div class="stat-label">With Subjects</div>
                </div>
                <div class="stat-card unassigned">
                    <div class="stat-icon">⏳</div>
                    <div class="stat-number"><?php echo $stats['without_subjects']; ?></div>
                    <div class="stat-label">Unassigned</div>
                </div>
                <div class="stat-card active">
                    <div class="stat-icon">📊</div>
                    <div class="stat-number"><?php echo $stats['active_lecturers']; ?></div>
                    <div class="stat-label">Active</div>
                </div>
                <div class="stat-card interventions">
                    <div class="stat-icon">🔄</div>
                    <div class="stat-number"><?php echo $stats['total_interventions']; ?></div>
                    <div class="stat-label">Total Interventions</div>
                </div>
                <div class="stat-card assignments">
                    <div class="stat-icon">📚</div>
                    <div class="stat-number"><?php echo $stats['total_subject_assignments']; ?></div>
                    <div class="stat-label">Subject Assignments</div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab active" onclick="switchTab('lecturers')">👥 Lecturer Management 
                    <span class="lecturer-count"><?php echo $lecturers_result ? $lecturers_result->num_rows : 0; ?></span>
                </button>
                <button class="tab" onclick="switchTab('analytics')">📊 Subject Analytics</button>
            </div>

            <!-- Lecturers Tab -->
            <div id="lecturers" class="tab-content active">
                <div class="content-section">
                    <div class="section-header">
                        <h2>All Lecturers</h2>
                        <div>
                            <a href="register.php" class="btn btn-success">➕ Add New Lecturer</a>
                            <button class="btn btn-info" onclick="exportLecturers()">📥 Export Data</button>
                        </div>
                    </div>

                    <!-- Lecturer List Header -->
                    <div class="lecturers-list-header">
                        <div class="total-lecturers">
                            Showing <strong><?php echo $lecturers_result ? $lecturers_result->num_rows : 0; ?></strong> lecturer<?php echo ($lecturers_result && $lecturers_result->num_rows != 1) ? 's' : ''; ?>
                        </div>
                    </div>

                    <!-- Bulk Actions -->
                    <div id="bulkActions" class="bulk-actions">
                        <form method="POST" class="bulk-form">
                            <input type="hidden" name="bulk_action" value="1">
                            <div class="selected-count" id="selectedCount">0 lecturers selected</div>
                            <select name="bulk_action_type" id="bulkActionType" required onchange="toggleBulkSubject()">
                                <option value="">Select Action</option>
                                <option value="assign_subjects">Assign Subject</option>
                                <option value="remove_subjects">Remove Subject</option>
                            </select>
                            <select name="bulk_subject_id" id="bulkSubjectId" style="display: none;" required>
                                <option value="">Select Subject</option>
                                <?php 
                                $subjects_result->data_seek(0);
                                while ($subject = $subjects_result->fetch_assoc()): ?>
                                    <option value="<?php echo $subject['subject_id']; ?>">
                                        <?php echo htmlspecialchars($subject['subject_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                            <button type="submit" class="btn btn-warning">Apply to Selected</button>
                            <button type="button" class="btn btn-secondary" onclick="clearSelection()">Clear Selection</button>
                        </form>
                    </div>

                    <!-- Lecturers List -->
                    <?php if ($lecturers_result && $lecturers_result->num_rows > 0): ?>
                        <form id="lecturersForm">
                            <div class="lecturers-list">
                                <?php while ($lecturer = $lecturers_result->fetch_assoc()): 
                                    $is_unassigned = empty($lecturer['subjects_taught']) || $lecturer['subjects_taught'] === 'No subjects assigned';
                                    // Get current subjects for this lecturer for the modal
                                    $current_subjects_sql = "SELECT subject_id FROM lecturer_subjects WHERE lecturer_id = ?";
                                    $current_stmt = $conn->prepare($current_subjects_sql);
                                    $current_stmt->bind_param("i", $lecturer['lecturer_id']);
                                    $current_stmt->execute();
                                    $current_result = $current_stmt->get_result();
                                    $current_subjects = [];
                                    while ($row = $current_result->fetch_assoc()) {
                                        $current_subjects[] = $row['subject_id'];
                                    }
                                ?>
                                    <div class="lecturer-card <?php echo $is_unassigned ? 'unassigned' : ''; ?>">
                                        <div style="position: absolute; top: 15px; right: 15px;">
                                            <input type="checkbox" name="lecturer_ids[]" value="<?php echo $lecturer['lecturer_id']; ?>" class="select-checkbox lecturer-checkbox">
                                        </div>
                                        
                                        <div class="lecturer-header">
                                            <div class="lecturer-info">
                                                <h3><?php echo htmlspecialchars($lecturer['lecturer_name']); ?></h3>
                                                <div class="lecturer-email"><?php echo htmlspecialchars($lecturer['email']); ?></div>
                                                <div class="lecturer-meta">
                                                    <span class="meta-item">👤 <?php echo htmlspecialchars($lecturer['username']); ?></span>
                                                </div>
                                                <div style="margin-top: 10px;" class="subject-list">
                                                    <?php if (!$is_unassigned): ?>
                                                        <?php 
                                                        $subjects = explode(', ', $lecturer['subjects_taught']);
                                                        foreach ($subjects as $subject): ?>
                                                            <span class="subject-badge">📚 <?php echo htmlspecialchars($subject); ?></span>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        <span class="no-subject">❌ No subjects assigned</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="action-buttons">
                                                <button type="button" class="btn btn-warning btn-sm" onclick="openSubjectModal(<?php echo $lecturer['lecturer_id']; ?>, '<?php echo htmlspecialchars(json_encode($current_subjects)); ?>')">
                                                    Manage Subjects
                                                </button>
                                                <a href="lecturers.php?delete_id=<?php echo $lecturer['lecturer_id']; ?>" 
                                                   class="btn btn-danger btn-sm" 
                                                   onclick="return confirm('Are you sure you want to delete this lecturer? This will also remove their user account and all subject assignments.')">
                                                    Delete
                                                </a>
                                            </div>
                                        </div>
                                        
                                        <div class="lecturer-stats">
                                            <div class="stat-item">
                                                <div class="stat-value"><?php echo $lecturer['subject_count'] ?? 0; ?></div>
                                                <div class="stat-label">Subjects</div>
                                            </div>
                                            <div class="stat-item">
                                                <div class="stat-value"><?php echo $lecturer['intervention_count'] ?? 0; ?></div>
                                                <div class="stat-label">Interventions</div>
                                            </div>
                                            <div class="stat-item">
                                                <div class="stat-value"><?php echo $lecturer['unique_students'] ?? 0; ?></div>
                                                <div class="stat-label">Students</div>
                                            </div>
                                            <div class="stat-item">
                                                <div class="stat-value"><?php echo $lecturer['total_attendance_records'] ?? 0; ?></div>
                                                <div class="stat-label">Attendance</div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="empty-state">
                            <h3>No Lecturers Found</h3>
                            <p>There are currently no lecturers registered in the system.</p>
                            <a href="register.php" class="btn btn-success">➕ Add New Lecturer</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Analytics Tab -->
            <div id="analytics" class="tab-content">
                <div class="content-section">
                    <div class="section-header">
                        <h2>Subject Distribution Analytics</h2>
                    </div>

                    <?php if ($subject_dist_result && $subject_dist_result->num_rows > 0): ?>
                        <div class="subject-dist-grid">
                            <?php while ($subject = $subject_dist_result->fetch_assoc()): ?>
                                <div class="subject-dist-card">
                                    <h4><?php echo htmlspecialchars($subject['subject_name']); ?></h4>
                                    <div class="dist-stats">
                                        <div class="dist-stat">
                                            <div class="dist-number"><?php echo $subject['lecturer_count']; ?></div>
                                            <div class="dist-label">Lecturers</div>
                                        </div>
                                        <div class="dist-stat">
                                            <div class="dist-number"><?php echo $subject['student_count']; ?></div>
                                            <div class="dist-label">Students</div>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="no-data">No subject distribution data available.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Subject Assignment Modal -->
    <div id="subjectModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Manage Subjects for Lecturer</h3>
                <span class="close">&times;</span>
            </div>
            <form method="POST" id="subjectForm">
                <input type="hidden" name="lecturer_id" id="modalLecturerId">
                <input type="hidden" name="assign_subjects" value="1">
                
                <div class="form-group">
                    <label>Select Subjects (Multiple Selection):</label>
                    <div class="subject-checkboxes" id="subjectCheckboxes">
                        <?php 
                        $subjects_result->data_seek(0);
                        while ($subject = $subjects_result->fetch_assoc()): ?>
                            <div class="checkbox-group">
                                <input type="checkbox" name="subject_ids[]" value="<?php echo $subject['subject_id']; ?>" 
                                       id="subject_<?php echo $subject['subject_id']; ?>"
                                       class="subject-checkbox">
                                <label for="subject_<?php echo $subject['subject_id']; ?>">
                                    <?php echo htmlspecialchars($subject['subject_name']); ?>
                                </label>
                            </div>
                        <?php endwhile; ?>
                    </div>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn btn-warning" onclick="closeSubjectModal()">Cancel</button>
                    <button type="submit" class="btn btn-success">Save Subjects</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Modal functionality
        const modal = document.getElementById('subjectModal');
        const closeBtn = document.querySelector('.close');
        
        function openSubjectModal(lecturerId, currentSubjectIdsJson) {
            try {
                document.getElementById('modalLecturerId').value = lecturerId;
                
                // Clear all checkboxes first
                const checkboxes = document.querySelectorAll('.subject-checkbox');
                checkboxes.forEach(checkbox => {
                    checkbox.checked = false;
                });
                
                // Parse the JSON string
                let currentSubjectIds = [];
                try {
                    currentSubjectIds = JSON.parse(currentSubjectIdsJson);
                } catch (e) {
                    console.log('No current subjects or invalid JSON:', currentSubjectIdsJson);
                }
                
                // Check the current subjects
                if (currentSubjectIds && currentSubjectIds.length > 0) {
                    currentSubjectIds.forEach(subjectId => {
                        const checkbox = document.getElementById('subject_' + subjectId);
                        if (checkbox) {
                            checkbox.checked = true;
                        }
                    });
                }
                
                modal.style.display = 'block';
                
                // Prevent click events from bubbling up
                event.stopPropagation();
            } catch (error) {
                console.error('Error opening modal:', error);
                alert('Error opening subject management. Please try again.');
            }
        }
        
        function closeSubjectModal() {
            modal.style.display = 'none';
        }
        
        closeBtn.onclick = closeSubjectModal;
        
        window.onclick = function(event) {
            if (event.target == modal) {
                closeSubjectModal();
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

        // Bulk selection functionality
        const lecturerCheckboxes = document.querySelectorAll('.lecturer-checkbox');
        const bulkActions = document.getElementById('bulkActions');
        const selectedCount = document.getElementById('selectedCount');
        const bulkActionType = document.getElementById('bulkActionType');
        const bulkSubjectId = document.getElementById('bulkSubjectId');

        function updateBulkActions() {
            const selectedCountValue = document.querySelectorAll('.lecturer-checkbox:checked').length;
            selectedCount.textContent = selectedCountValue + ' lecturers selected';
            
            if (selectedCountValue > 0) {
                bulkActions.style.display = 'block';
            } else {
                bulkActions.style.display = 'none';
            }
        }

        function toggleBulkSubject() {
            if (bulkActionType.value === 'assign_subjects' || bulkActionType.value === 'remove_subjects') {
                bulkSubjectId.style.display = 'block';
            } else {
                bulkSubjectId.style.display = 'none';
            }
        }

        function clearSelection() {
            lecturerCheckboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
            updateBulkActions();
        }

        if (lecturerCheckboxes.length > 0) {
            lecturerCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', updateBulkActions);
            });
        }

        // Export functionality
        function exportLecturers() {
            window.open('export_lecturers.php', '_blank');
        }

        // Add confirmation for subject assignment
        const subjectForm = document.getElementById('subjectForm');
        if (subjectForm) {
            subjectForm.addEventListener('submit', function(e) {
                const selectedSubjects = document.querySelectorAll('.subject-checkbox:checked');
                if (selectedSubjects.length === 0) {
                    if (!confirm('No subjects selected. This will remove all current subject assignments. Continue?')) {
                        e.preventDefault();
                    }
                }
            });
        }

        // Initialize bulk actions
        document.addEventListener('DOMContentLoaded', function() {
            updateBulkActions();
        });

        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && modal.style.display === 'block') {
                closeSubjectModal();
            }
        });
    </script>
</body>
</html>