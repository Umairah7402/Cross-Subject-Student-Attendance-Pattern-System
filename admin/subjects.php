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

// Handle subject deletion
if (isset($_GET['delete_id'])) {
    $delete_id = $_GET['delete_id'];
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Check if subject has any enrollments (students)
        $check_enrollments_sql = "SELECT COUNT(*) as enrollment_count FROM enrollments WHERE subject_id = ?";
        $check_enrollments_stmt = $conn->prepare($check_enrollments_sql);
        if (!$check_enrollments_stmt) {
            throw new Exception("Error preparing enrollment check: " . $conn->error);
        }
        $check_enrollments_stmt->bind_param("i", $delete_id);
        $check_enrollments_stmt->execute();
        $enrollment_count = $check_enrollments_stmt->get_result()->fetch_assoc()['enrollment_count'];
        
        // Check if subject has any lecturers assigned
        $check_lecturers_sql = "SELECT COUNT(*) as lecturer_count FROM lecturer_subjects WHERE subject_id = ?";
        $check_lecturers_stmt = $conn->prepare($check_lecturers_sql);
        if (!$check_lecturers_stmt) {
            throw new Exception("Error preparing lecturer check: " . $conn->error);
        }
        $check_lecturers_stmt->bind_param("i", $delete_id);
        $check_lecturers_stmt->execute();
        $lecturer_count = $check_lecturers_stmt->get_result()->fetch_assoc()['lecturer_count'];
        
        if ($enrollment_count > 0 || $lecturer_count > 0) {
            throw new Exception("Cannot delete subject. It has $enrollment_count student enrollments and $lecturer_count lecturers assigned. Please reassign them first.");
        }
        
        // Delete subject's attendance records
        $attendance_sql = "DELETE FROM attendance WHERE subject_id = ?";
        $attendance_stmt = $conn->prepare($attendance_sql);
        if (!$attendance_stmt) {
            throw new Exception("Error preparing attendance deletion: " . $conn->error);
        }
        $attendance_stmt->bind_param("i", $delete_id);
        $attendance_stmt->execute();
        
        // Delete subject's interventions
        $intervention_sql = "DELETE FROM interventions WHERE subject_id = ?";
        $intervention_stmt = $conn->prepare($intervention_sql);
        if (!$intervention_stmt) {
            throw new Exception("Error preparing intervention deletion: " . $conn->error);
        }
        $intervention_stmt->bind_param("i", $delete_id);
        $intervention_stmt->execute();
        
        // Delete subject
        $subject_sql = "DELETE FROM subjects WHERE subject_id = ?";
        $subject_stmt = $conn->prepare($subject_sql);
        if (!$subject_stmt) {
            throw new Exception("Error preparing subject deletion: " . $conn->error);
        }
        $subject_stmt->bind_param("i", $delete_id);
        
        if ($subject_stmt->execute()) {
            $conn->commit();
            $message = "✅ Subject deleted successfully!";
            $message_class = "success";
        } else {
            throw new Exception("Error deleting subject: " . $subject_stmt->error);
        }
    } catch (Exception $e) {
        $conn->rollback();
        $message = "❌ " . $e->getMessage();
        $message_class = "error";
    }
}

// Handle add new subject
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_subject'])) {
    $subject_name = trim($_POST['subject_name']);
    
    // Validate inputs
    if (empty($subject_name)) {
        $message = "❌ Subject name is required!";
        $message_class = "error";
    } else {
        // Check if subject name already exists
        $check_sql = "SELECT subject_id FROM subjects WHERE subject_name = ?";
        $check_stmt = $conn->prepare($check_sql);
        if (!$check_stmt) {
            $message = "❌ Database error: " . $conn->error;
            $message_class = "error";
        } else {
            $check_stmt->bind_param("s", $subject_name);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $message = "❌ Subject name already exists!";
                $message_class = "error";
            } else {
                $insert_sql = "INSERT INTO subjects (subject_name) VALUES (?)";
                $insert_stmt = $conn->prepare($insert_sql);
                if (!$insert_stmt) {
                    $message = "❌ Database error: " . $conn->error;
                    $message_class = "error";
                } else {
                    $insert_stmt->bind_param("s", $subject_name);
                    
                    if ($insert_stmt->execute()) {
                        $message = "✅ Subject added successfully!";
                        $message_class = "success";
                        // Clear form
                        $_POST = array();
                    } else {
                        $message = "❌ Error adding subject: " . $insert_stmt->error;
                        $message_class = "error";
                    }
                }
            }
        }
    }
}

// Handle subject update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_subject'])) {
    $subject_id = $_POST['subject_id'];
    $subject_name = trim($_POST['subject_name']);
    
    // Validate inputs
    if (empty($subject_name)) {
        $message = "❌ Subject name is required!";
        $message_class = "error";
    } else {
        // Check if subject name already exists (excluding current subject)
        $check_sql = "SELECT subject_id FROM subjects WHERE subject_name = ? AND subject_id != ?";
        $check_stmt = $conn->prepare($check_sql);
        if (!$check_stmt) {
            $message = "❌ Database error: " . $conn->error;
            $message_class = "error";
        } else {
            $check_stmt->bind_param("si", $subject_name, $subject_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $message = "❌ Subject name already exists!";
                $message_class = "error";
            } else {
                $update_sql = "UPDATE subjects SET subject_name = ? WHERE subject_id = ?";
                $update_stmt = $conn->prepare($update_sql);
                if (!$update_stmt) {
                    $message = "❌ Database error: " . $conn->error;
                    $message_class = "error";
                } else {
                    $update_stmt->bind_param("si", $subject_name, $subject_id);
                    
                    if ($update_stmt->execute()) {
                        $message = "✅ Subject updated successfully!";
                        $message_class = "success";
                    } else {
                        $message = "❌ Error updating subject: " . $update_stmt->error;
                        $message_class = "error";
                    }
                }
            }
        }
    }
}

// Build enhanced query for subjects with cross-subject metrics (SIMPLIFIED - no search/filters)
$subjects_sql = "
    SELECT 
        s.subject_id,
        s.subject_name,
        COUNT(DISTINCT e.student_id) as student_count,
        COUNT(DISTINCT ls.lecturer_id) as lecturer_count,
        COUNT(DISTINCT a.attendance_id) as attendance_records,
        COUNT(DISTINCT i.intervention_id) as intervention_count,
        CASE 
            WHEN COUNT(DISTINCT a.attendance_id) > 0 THEN 
                ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(DISTINCT a.attendance_id)), 2)
            ELSE 0 
        END as avg_attendance,
        (SELECT COUNT(DISTINCT e2.student_id) 
         FROM enrollments e2 
         WHERE e2.student_id IN (
             SELECT student_id FROM enrollments WHERE subject_id = s.subject_id
         ) 
         AND e2.subject_id != s.subject_id
        ) as cross_subject_students
    FROM subjects s
    LEFT JOIN enrollments e ON s.subject_id = e.subject_id
    LEFT JOIN lecturer_subjects ls ON s.subject_id = ls.subject_id
    LEFT JOIN attendance a ON s.subject_id = a.subject_id
    LEFT JOIN interventions i ON s.subject_id = i.subject_id
    GROUP BY s.subject_id, s.subject_name
    ORDER BY s.subject_name ASC
";

// Execute query
$subjects_result = $conn->query($subjects_sql);

// Get subject statistics
$stats = [
    'total_subjects' => 0,
    'with_students' => 0,
    'with_lecturers' => 0,
    'active_subjects' => 0,
    'high_enrollment' => 0,
    'cross_subject' => 0
];

$stats_sql = "
    SELECT 
        COUNT(*) as total_subjects,
        COUNT(CASE WHEN EXISTS (
            SELECT 1 FROM enrollments WHERE subject_id = s.subject_id
        ) THEN 1 END) as with_students,
        COUNT(CASE WHEN EXISTS (
            SELECT 1 FROM lecturer_subjects WHERE subject_id = s.subject_id
        ) THEN 1 END) as with_lecturers,
        COUNT(CASE WHEN EXISTS (
            SELECT 1 FROM attendance WHERE subject_id = s.subject_id
        ) THEN 1 END) as active_subjects,
        COUNT(CASE WHEN (
            SELECT COUNT(DISTINCT student_id) FROM enrollments WHERE subject_id = s.subject_id
        ) > 20 THEN 1 END) as high_enrollment,
        COUNT(CASE WHEN (
            SELECT COUNT(DISTINCT e2.student_id) 
            FROM enrollments e2 
            WHERE e2.student_id IN (
                SELECT student_id FROM enrollments WHERE subject_id = s.subject_id
            ) 
            AND e2.subject_id != s.subject_id
        ) > 0 THEN 1 END) as cross_subject
    FROM subjects s
";

$stats_result = $conn->query($stats_sql);
if ($stats_result) {
    $stats = $stats_result->fetch_assoc();
} else {
    error_log("Stats SQL Error: " . $conn->error);
}

// Get enrollment analytics
$enrollment_analytics_sql = "
    SELECT 
        s.subject_name,
        COUNT(DISTINCT e.student_id) as enrolled_students,
        COUNT(DISTINCT ls.lecturer_id) as assigned_lecturers,
        ROUND(AVG(
            CASE WHEN (
                SELECT COUNT(a.attendance_id) 
                FROM attendance a 
                WHERE a.subject_id = s.subject_id
            ) > 0 THEN (
                SELECT SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(a.attendance_id)
                FROM attendance a 
                WHERE a.subject_id = s.subject_id
            ) ELSE 0 END
        ), 2) as avg_attendance,
        COUNT(DISTINCT i.intervention_id) as total_interventions,
        (SELECT COUNT(DISTINCT e2.student_id) 
         FROM enrollments e2 
         WHERE e2.student_id IN (
             SELECT student_id FROM enrollments WHERE subject_id = s.subject_id
         ) 
         AND e2.subject_id != s.subject_id
        ) as cross_subject_students
    FROM subjects s
    LEFT JOIN enrollments e ON s.subject_id = e.subject_id
    LEFT JOIN lecturer_subjects ls ON s.subject_id = ls.subject_id
    LEFT JOIN interventions i ON s.subject_id = i.subject_id
    GROUP BY s.subject_id, s.subject_name
    ORDER BY enrolled_students DESC
    LIMIT 10
";

$enrollment_analytics_result = $conn->query($enrollment_analytics_sql);

// Get cross-subject patterns
$cross_subject_sql = "
    SELECT 
        s.subject_name,
        COUNT(DISTINCT e.student_id) as total_students,
        ROUND(AVG(student_stats.enrolled_subjects), 1) as avg_subjects_per_student,
        SUM(CASE WHEN student_stats.enrolled_subjects > 1 THEN 1 ELSE 0 END) as multi_subject_students,
        ROUND((SUM(CASE WHEN student_stats.enrolled_subjects > 1 THEN 1 ELSE 0 END) * 100.0 / COUNT(DISTINCT e.student_id)), 1) as multi_subject_percentage
    FROM subjects s
    LEFT JOIN enrollments e ON s.subject_id = e.subject_id
    LEFT JOIN (
        SELECT 
            student_id,
            COUNT(DISTINCT subject_id) as enrolled_subjects
        FROM enrollments
        GROUP BY student_id
    ) student_stats ON e.student_id = student_stats.student_id
    GROUP BY s.subject_id, s.subject_name
    HAVING total_students > 0
    ORDER BY multi_subject_percentage DESC
    LIMIT 8
";

$cross_subject_result = $conn->query($cross_subject_sql);

// Get lecturer distribution stats
$lecturer_stats_sql = "
    SELECT 
        s.subject_name,
        COUNT(DISTINCT ls.lecturer_id) as lecturer_count
    FROM subjects s
    LEFT JOIN lecturer_subjects ls ON s.subject_id = ls.subject_id
    GROUP BY s.subject_id, s.subject_name
    HAVING lecturer_count > 0
    ORDER BY lecturer_count DESC
    LIMIT 8
";
$lecturer_stats_result = $conn->query($lecturer_stats_sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subject Management - CSSAP Admin</title>
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
        .stat-card.students { border-left-color: #2ecc71; }
        .stat-card.lecturers { border-left-color: #e74c3c; }
        .stat-card.active { border-left-color: #f39c12; }
        .stat-card.high-enrollment { border-left-color: #1abc9c; }
        .stat-card.cross-subject { border-left-color: #9b59b6; }

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

        /* Add Subject Form */
        .add-subject-form {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            border: 1px solid #e9ecef;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #495057;
            font-size: 14px;
        }

        input[type="text"], select {
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

        /* Subject Card Styles */
        .subject-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-left: 4px solid #3498db;
        }

        .subject-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .subject-info h3 {
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .subject-stats {
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

        /* Analytics Grid */
        .analytics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .analytics-card {
            background: white;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }

        .analytics-card h3 {
            color: #2c3e50;
            margin-bottom: 15px;
            font-size: 1.1rem;
        }

        .analytics-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #e9ecef;
        }

        .analytics-item:last-child {
            border-bottom: none;
        }

        .analytics-label {
            color: #7f8c8d;
            font-size: 0.9rem;
        }

        .analytics-value {
            font-weight: 600;
            color: #2c3e50;
        }

        /* Progress bars */
        .progress-bar {
            width: 100%;
            height: 8px;
            background: #e9ecef;
            border-radius: 4px;
            overflow: hidden;
            margin-top: 5px;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(135deg, #3498db, #2980b9);
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        /* Badges and Indicators */
        .cross-subject-badge {
            background: #1abc9c;
            color: white;
            padding: 2px 6px;
            border-radius: 8px;
            font-size: 0.7rem;
            margin-left: 5px;
        }

        .attendance-critical {
            color: #e74c3c;
            font-weight: bold;
        }

        .attendance-warning {
            color: #f39c12;
            font-weight: bold;
        }

        .attendance-good {
            color: #27ae60;
            font-weight: bold;
        }

        .enrollment-high { color: #2ecc71; font-weight: bold; }
        .enrollment-medium { color: #f39c12; font-weight: bold; }
        .enrollment-low { color: #e74c3c; font-weight: bold; }

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
            .subject-stats {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .analytics-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .subject-header {
                flex-direction: column;
                gap: 10px;
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
            
            .subject-stats {
                grid-template-columns: 1fr;
            }
        }

        /* Form row for add subject */
        .form-row {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 15px;
            align-items: end;
        }

        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }

        /* Subject Count */
        .subject-count {
            background: #3498db;
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-left: 10px;
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

        /* Checkbox Styles */
        .select-checkbox {
            width: 18px;
            height: 18px;
            cursor: pointer;
            margin-right: 10px;
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
                <li><a href="subjects.php" class="active">📚 Subject Management</a></li>
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
                <h1>Subject Management</h1>
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
                    <div class="stat-icon">📚</div>
                    <div class="stat-number"><?php echo $stats['total_subjects']; ?></div>
                    <div class="stat-label">Total Subjects</div>
                </div>
                <div class="stat-card students">
                    <div class="stat-icon">🎓</div>
                    <div class="stat-number"><?php echo $stats['with_students']; ?></div>
                    <div class="stat-label">With Students</div>
                </div>
                <div class="stat-card lecturers">
                    <div class="stat-icon">👨‍🏫</div>
                    <div class="stat-number"><?php echo $stats['with_lecturers']; ?></div>
                    <div class="stat-label">With Lecturers</div>
                </div>
                <div class="stat-card active">
                    <div class="stat-icon">📊</div>
                    <div class="stat-number"><?php echo $stats['active_subjects']; ?></div>
                    <div class="stat-label">Active Subjects</div>
                </div>
                <div class="stat-card high-enrollment">
                    <div class="stat-icon">🔥</div>
                    <div class="stat-number"><?php echo $stats['high_enrollment']; ?></div>
                    <div class="stat-label">High Enrollment</div>
                </div>
                <div class="stat-card cross-subject">
                    <div class="stat-icon">🔗</div>
                    <div class="stat-number"><?php echo $stats['cross_subject']; ?></div>
                    <div class="stat-label">Cross-Subject</div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab active" onclick="switchTab('management')">📚 Subject Management 
                    <span class="subject-count"><?php echo $subjects_result ? $subjects_result->num_rows : 0; ?></span>
                </button>
                <button class="tab" onclick="switchTab('analytics')">📊 Enrollment Analytics</button>
                <button class="tab" onclick="switchTab('cross-subject')">🔗 Cross-Subject Patterns</button>
            </div>

            <!-- Management Tab -->
            <div id="management" class="tab-content active">
                <div class="content-section">
                    <div class="section-header">
                        <h2>All Subjects</h2>
                        <div>
                            <button class="btn btn-info" onclick="exportSubjects()">📥 Export Data</button>
                        </div>
                    </div>

                    <!-- Add Subject Form -->
                    <div class="add-subject-form">
                        <h3 style="margin-bottom: 15px; color: #2c3e50;">Add New Subject</h3>
                        <form method="POST">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="subject_name">Subject Name *</label>
                                    <input type="text" name="subject_name" id="subject_name" required 
                                           value="<?php echo isset($_POST['subject_name']) ? htmlspecialchars($_POST['subject_name']) : ''; ?>" 
                                           placeholder="Enter subject name">
                                </div>
                                <div class="form-group">
                                    <button type="submit" name="add_subject" class="btn btn-success">Add Subject</button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- Subjects List -->
                    <?php if ($subjects_result && $subjects_result->num_rows > 0): ?>
                        <div class="subjects-list">
                            <?php while ($subject = $subjects_result->fetch_assoc()): 
                                $attendance_class = '';
                                $avg_attendance = $subject['avg_attendance'] ?? 0;
                                if ($avg_attendance < 60) {
                                    $attendance_class = 'attendance-critical';
                                } elseif ($avg_attendance < 80) {
                                    $attendance_class = 'attendance-warning';
                                } else {
                                    $attendance_class = 'attendance-good';
                                }
                                
                                // Enrollment classification
                                $enrollment_class = '';
                                if ($subject['student_count'] > 20) {
                                    $enrollment_class = 'enrollment-high';
                                } elseif ($subject['student_count'] >= 10) {
                                    $enrollment_class = 'enrollment-medium';
                                } else {
                                    $enrollment_class = 'enrollment-low';
                                }
                            ?>
                                <div class="subject-card">
                                    <div class="subject-header">
                                        <div class="subject-info">
                                            <h3><?php echo htmlspecialchars($subject['subject_name']); ?></h3>
                                            <div style="display: flex; align-items: center; gap: 10px; margin-top: 5px;">
                                                <?php if ($subject['cross_subject_students'] > 0): ?>
                                                    <span class="cross-subject-badge">🔗 <?php echo $subject['cross_subject_students']; ?> cross-subject students</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="action-buttons">
                                            <button type="button" class="btn btn-warning btn-sm" onclick="openEditModal(<?php echo $subject['subject_id']; ?>, '<?php echo addslashes(htmlspecialchars($subject['subject_name'])); ?>')">
                                                Edit
                                            </button>
                                            <a href="subjects.php?delete_id=<?php echo $subject['subject_id']; ?>" 
                                               class="btn btn-danger btn-sm" 
                                               onclick="return confirm('Are you sure you want to delete this subject? This will also remove all associated attendance and intervention records.')">
                                                Delete
                                            </a>
                                        </div>
                                    </div>
                                    
                                    <div class="subject-stats">
                                        <div class="stat-item">
                                            <div class="stat-value <?php echo $enrollment_class; ?>"><?php echo $subject['student_count']; ?></div>
                                            <div class="stat-label">Students</div>
                                        </div>
                                        <div class="stat-item">
                                            <div class="stat-value"><?php echo $subject['lecturer_count']; ?></div>
                                            <div class="stat-label">Lecturers</div>
                                        </div>
                                        <div class="stat-item">
                                            <div class="stat-value"><?php echo $subject['attendance_records']; ?></div>
                                            <div class="stat-label">Attendance Records</div>
                                        </div>
                                        <div class="stat-item">
                                            <div class="stat-value <?php echo $attendance_class; ?>">
                                                <?php echo round($avg_attendance, 1); ?>%
                                            </div>
                                            <div class="stat-label">Avg Attendance</div>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <h3>No Subjects Found</h3>
                            <p>There are currently no subjects registered in the system.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Analytics Tab -->
            <div id="analytics" class="tab-content">
                <div class="content-section">
                    <div class="section-header">
                        <h2>Enrollment Analytics</h2>
                    </div>

                    <?php if ($enrollment_analytics_result && $enrollment_analytics_result->num_rows > 0): ?>
                        <div class="analytics-grid">
                            <div class="analytics-card">
                                <h3>📊 Top Subjects by Enrollment</h3>
                                <?php 
                                $enrollment_analytics_result->data_seek(0);
                                while ($subject = $enrollment_analytics_result->fetch_assoc()): 
                                    $max_students = max(1, $stats['with_students']);
                                    $percentage = ($subject['enrolled_students'] / $max_students) * 100;
                                ?>
                                    <div class="analytics-item">
                                        <div>
                                            <div style="font-weight: 600; color: #2c3e50;">
                                                <?php echo htmlspecialchars($subject['subject_name']); ?>
                                            </div>
                                            <div class="progress-bar">
                                                <div class="progress-fill" style="width: <?php echo min($percentage, 100); ?>%"></div>
                                            </div>
                                        </div>
                                        <div style="text-align: right;">
                                            <div class="analytics-value"><?php echo $subject['enrolled_students']; ?> students</div>
                                            <div class="analytics-label"><?php echo round($subject['avg_attendance'], 1); ?>% attendance</div>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            </div>

                            <div class="analytics-card">
                                <h3>👨‍🏫 Lecturer Distribution</h3>
                                <?php if ($lecturer_stats_result && $lecturer_stats_result->num_rows > 0): 
                                    while ($subject = $lecturer_stats_result->fetch_assoc()): ?>
                                        <div class="analytics-item">
                                            <span class="analytics-label"><?php echo htmlspecialchars($subject['subject_name']); ?></span>
                                            <span class="analytics-value"><?php echo $subject['lecturer_count']; ?> lecturers</span>
                                        </div>
                                    <?php endwhile; 
                                else: ?>
                                    <div class="no-data" style="padding: 20px;">No lecturer distribution data available.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="no-data">No enrollment data available.</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Cross-Subject Patterns Tab -->
            <div id="cross-subject" class="tab-content">
                <div class="content-section">
                    <div class="section-header">
                        <h2>Cross-Subject Student Patterns</h2>
                    </div>

                    <?php if ($cross_subject_result && $cross_subject_result->num_rows > 0): ?>
                        <div class="analytics-grid">
                            <div class="analytics-card">
                                <h3>🔗 Multi-Subject Enrollment Patterns</h3>
                                <?php while ($subject = $cross_subject_result->fetch_assoc()): ?>
                                    <div class="analytics-item">
                                        <div>
                                            <div style="font-weight: 600; color: #2c3e50;">
                                                <?php echo htmlspecialchars($subject['subject_name']); ?>
                                            </div>
                                            <div style="font-size: 0.8rem; color: #7f8c8d;">
                                                Avg <?php echo $subject['avg_subjects_per_student']; ?> subjects per student
                                            </div>
                                        </div>
                                        <div style="text-align: right;">
                                            <div class="analytics-value"><?php echo $subject['multi_subject_students']; ?> students</div>
                                            <div class="analytics-label"><?php echo $subject['multi_subject_percentage']; ?>% multi-subject</div>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            </div>

                            <div class="analytics-card">
                                <h3>📈 Cross-Subject Insights</h3>
                                <?php
                                // Get cross-subject statistics
                                $insights_sql = "
                                    SELECT 
                                        ROUND(AVG(student_stats.enrolled_subjects), 2) as avg_subjects_per_student,
                                        COUNT(CASE WHEN student_stats.enrolled_subjects > 1 THEN 1 END) as multi_subject_students,
                                        COUNT(DISTINCT student_stats.student_id) as total_students,
                                        (COUNT(CASE WHEN student_stats.enrolled_subjects > 1 THEN 1 END) * 100.0 / COUNT(DISTINCT student_stats.student_id)) as multi_subject_percentage
                                    FROM (
                                        SELECT 
                                            student_id,
                                            COUNT(DISTINCT subject_id) as enrolled_subjects
                                        FROM enrollments
                                        GROUP BY student_id
                                    ) student_stats
                                ";
                                $insights_result = $conn->query($insights_sql);
                                if ($insights_result) {
                                    $insights = $insights_result->fetch_assoc();
                                } else {
                                    $insights = ['avg_subjects_per_student' => 0, 'multi_subject_students' => 0, 'total_students' => 0, 'multi_subject_percentage' => 0];
                                }
                                ?>
                                <div class="analytics-item">
                                    <span class="analytics-label">Average Subjects per Student</span>
                                    <span class="analytics-value"><?php echo $insights['avg_subjects_per_student']; ?></span>
                                </div>
                                <div class="analytics-item">
                                    <span class="analytics-label">Multi-Subject Students</span>
                                    <span class="analytics-value"><?php echo $insights['multi_subject_students']; ?></span>
                                </div>
                                <div class="analytics-item">
                                    <span class="analytics-label">Total Students</span>
                                    <span class="analytics-value"><?php echo $insights['total_students']; ?></span>
                                </div>
                                <div class="analytics-item">
                                    <span class="analytics-label">Multi-Subject Percentage</span>
                                    <span class="analytics-value"><?php echo round($insights['multi_subject_percentage'], 1); ?>%</span>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="no-data">No cross-subject pattern data available.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Subject Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Subject</h3>
                <span class="close">&times;</span>
            </div>
            <form method="POST" id="editForm">
                <input type="hidden" name="subject_id" id="modalSubjectId">
                <input type="hidden" name="update_subject" value="1">
                
                <div class="form-group">
                    <label for="modalSubjectName">Subject Name *</label>
                    <input type="text" name="subject_name" id="modalSubjectName" required>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn btn-warning" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" class="btn btn-success">Update Subject</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Modal functionality
        const editModal = document.getElementById('editModal');
        const closeBtn = document.querySelector('.close');
        
        function openEditModal(subjectId, subjectName) {
            try {
                document.getElementById('modalSubjectId').value = subjectId;
                document.getElementById('modalSubjectName').value = subjectName;
                editModal.style.display = 'block';
                
                // Prevent click events from bubbling up
                if (event) {
                    event.stopPropagation();
                    event.preventDefault();
                }
            } catch (error) {
                console.error('Error opening modal:', error);
                alert('Error opening edit form. Please try again.');
            }
        }
        
        function closeEditModal() {
            editModal.style.display = 'none';
        }
        
        closeBtn.onclick = closeEditModal;
        
        window.onclick = function(event) {
            if (event.target == editModal) {
                closeEditModal();
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
        function exportSubjects() {
            window.open('export_subjects.php', '_blank');
        }

        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && editModal.style.display === 'block') {
                closeEditModal();
            }
        });

        // Add form validation
        const editForm = document.getElementById('editForm');
        if (editForm) {
            editForm.addEventListener('submit', function(e) {
                const subjectName = document.getElementById('modalSubjectName').value.trim();
                if (!subjectName) {
                    e.preventDefault();
                    alert('Subject name is required!');
                    document.getElementById('modalSubjectName').focus();
                }
            });
        }

        // Initialize by focusing on subject name input if modal opens
        const modalSubjectName = document.getElementById('modalSubjectName');
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.type === 'attributes' && mutation.attributeName === 'style') {
                    if (editModal.style.display === 'block' && modalSubjectName) {
                        modalSubjectName.focus();
                    }
                }
            });
        });
        
        observer.observe(editModal, { attributes: true });
    </script>
</body>
</html>