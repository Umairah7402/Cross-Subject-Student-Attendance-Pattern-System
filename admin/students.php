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

// First, let's check the actual structure of the students table
$check_structure_sql = "SHOW COLUMNS FROM students";
$structure_result = $conn->query($check_structure_sql);
$students_columns = [];
while ($column = $structure_result->fetch_assoc()) {
    $students_columns[] = $column['Field'];
}

// Check if students table has subject_id column
$has_subject_id = in_array('subject_id', $students_columns);

// Handle student deletion
if (isset($_GET['delete_id'])) {
    $delete_id = $_GET['delete_id'];
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Delete student's attendance records first
        $attendance_sql = "DELETE FROM attendance WHERE student_id = ?";
        $attendance_stmt = $conn->prepare($attendance_sql);
        if (!$attendance_stmt) {
            throw new Exception("Error preparing attendance deletion: " . $conn->error);
        }
        $attendance_stmt->bind_param("i", $delete_id);
        $attendance_stmt->execute();
        
        // Delete student's interventions
        $intervention_sql = "DELETE FROM interventions WHERE student_id = ?";
        $intervention_stmt = $conn->prepare($intervention_sql);
        if (!$intervention_stmt) {
            throw new Exception("Error preparing intervention deletion: " . $conn->error);
        }
        $intervention_stmt->bind_param("i", $delete_id);
        $intervention_stmt->execute();
        
        // Delete student's enrollments
        $enrollment_sql = "DELETE FROM enrollments WHERE student_id = ?";
        $enrollment_stmt = $conn->prepare($enrollment_sql);
        if (!$enrollment_stmt) {
            throw new Exception("Error preparing enrollment deletion: " . $conn->error);
        }
        $enrollment_stmt->bind_param("i", $delete_id);
        $enrollment_stmt->execute();
        
        // Delete student
        $student_sql = "DELETE FROM students WHERE student_id = ?";
        $student_stmt = $conn->prepare($student_sql);
        if (!$student_stmt) {
            throw new Exception("Error preparing student deletion: " . $conn->error);
        }
        $student_stmt->bind_param("i", $delete_id);
        
        if ($student_stmt->execute()) {
            $conn->commit();
            $message = "✅ Student deleted successfully!";
            $message_class = "success";
        } else {
            throw new Exception("Error deleting student: " . $student_stmt->error);
        }
    } catch (Exception $e) {
        $conn->rollback();
        $message = "❌ " . $e->getMessage();
        $message_class = "error";
    }
}

// Handle student enrollment (multiple subjects)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['enroll_subjects'])) {
    $student_id = $_POST['student_id'];
    $subject_ids = $_POST['subject_ids'] ?? [];
    $semester = $_POST['semester'] ?? '2024-1';
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Remove existing enrollments for this student
        $delete_sql = "DELETE FROM enrollments WHERE student_id = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        if (!$delete_stmt) {
            throw new Exception("Error preparing enrollment deletion: " . $conn->error);
        }
        $delete_stmt->bind_param("i", $student_id);
        $delete_stmt->execute();
        
        // Insert new enrollments if any subjects selected
        if (!empty($subject_ids)) {
            $insert_sql = "INSERT INTO enrollments (student_id, subject_id, semester) VALUES (?, ?, ?)";
            $insert_stmt = $conn->prepare($insert_sql);
            if (!$insert_stmt) {
                throw new Exception("Error preparing enrollment insertion: " . $conn->error);
            }
            
            foreach ($subject_ids as $subject_id) {
                if (!empty($subject_id)) {
                    $insert_stmt->bind_param("iis", $student_id, $subject_id, $semester);
                    if (!$insert_stmt->execute()) {
                        throw new Exception("Error enrolling student in subject: " . $insert_stmt->error);
                    }
                }
            }
        }
        
        $conn->commit();
        $message = "✅ Subjects enrolled successfully!";
        $message_class = "success";
        
    } catch (Exception $e) {
        $conn->rollback();
        $message = "❌ Error enrolling subjects: " . $e->getMessage();
        $message_class = "error";
    }
}

// Handle add new student
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_student'])) {
    $name = trim($_POST['name']);
    
    // Validate inputs
    if (empty($name)) {
        $message = "❌ Please fill in student name!";
        $message_class = "error";
    } else {
        // Check if student name already exists
        $check_sql = "SELECT student_id FROM students WHERE name = ?";
        $check_stmt = $conn->prepare($check_sql);
        if (!$check_stmt) {
            $message = "❌ Database error: " . $conn->error;
            $message_class = "error";
        } else {
            $check_stmt->bind_param("s", $name);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $message = "❌ Student name already exists!";
                $message_class = "error";
            } else {
                // Get the next available student_id
                $max_id_sql = "SELECT MAX(student_id) as max_id FROM students";
                $max_result = $conn->query($max_id_sql);
                $max_id = 3000; // Default starting ID
                if ($max_result && $max_result->num_rows > 0) {
                    $row = $max_result->fetch_assoc();
                    $max_id = $row['max_id'] + 1;
                }
                
                // Prepare SQL based on whether students table has subject_id
                if ($has_subject_id) {
                    // If students table has subject_id, we need to provide a default value or NULL
                    // First, let's check if the column allows NULL
                    $check_null_sql = "SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_NAME = 'students' AND COLUMN_NAME = 'subject_id'";
                    $null_result = $conn->query($check_null_sql);
                    $allows_null = false;
                    if ($null_result && $null_result->num_rows > 0) {
                        $null_row = $null_result->fetch_assoc();
                        $allows_null = ($null_row['IS_NULLABLE'] === 'YES');
                    }
                    
                    if ($allows_null) {
                        $insert_sql = "INSERT INTO students (student_id, name, subject_id) VALUES (?, ?, NULL)";
                        $insert_stmt = $conn->prepare($insert_sql);
                        if ($insert_stmt) {
                            $insert_stmt->bind_param("is", $max_id, $name);
                        }
                    } else {
                        // If subject_id is required, we need to provide a default subject
                        // Let's get the first available subject
                        $first_subject_sql = "SELECT subject_id FROM subjects ORDER BY subject_id LIMIT 1";
                        $first_subject_result = $conn->query($first_subject_sql);
                        if ($first_subject_result && $first_subject_result->num_rows > 0) {
                            $first_subject = $first_subject_result->fetch_assoc();
                            $insert_sql = "INSERT INTO students (student_id, name, subject_id) VALUES (?, ?, ?)";
                            $insert_stmt = $conn->prepare($insert_sql);
                            if ($insert_stmt) {
                                $insert_stmt->bind_param("isi", $max_id, $name, $first_subject['subject_id']);
                            }
                        } else {
                            $message = "❌ No subjects available. Please create subjects first!";
                            $message_class = "error";
                            $insert_stmt = false;
                        }
                    }
                } else {
                    // If students table doesn't have subject_id
                    $insert_sql = "INSERT INTO students (student_id, name) VALUES (?, ?)";
                    $insert_stmt = $conn->prepare($insert_sql);
                    if ($insert_stmt) {
                        $insert_stmt->bind_param("is", $max_id, $name);
                    }
                }
                
                if ($insert_stmt && $insert_stmt->execute()) {
                    $message = "✅ Student added successfully! Student ID: " . $max_id;
                    $message_class = "success";
                    // Clear form
                    $_POST = array();
                } else {
                    $error_msg = $insert_stmt ? $insert_stmt->error : "Failed to prepare statement";
                    $message = "❌ Error adding student: " . $error_msg;
                    $message_class = "error";
                }
            }
        }
    }
}

// Handle bulk student actions
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['bulk_action'])) {
    $student_ids = $_POST['student_ids'] ?? [];
    $bulk_action = $_POST['bulk_action_type'];
    
    if (empty($student_ids)) {
        $message = "❌ Please select students to perform bulk action!";
        $message_class = "error";
    } else {
        $placeholders = str_repeat('?,', count($student_ids) - 1) . '?';
        
        switch ($bulk_action) {
            case 'delete':
                try {
                    $conn->begin_transaction();
                    
                    // Delete attendance records
                    $attendance_sql = "DELETE FROM attendance WHERE student_id IN ($placeholders)";
                    $attendance_stmt = $conn->prepare($attendance_sql);
                    if (!$attendance_stmt) {
                        throw new Exception("Error preparing attendance deletion");
                    }
                    $attendance_stmt->bind_param(str_repeat('i', count($student_ids)), ...$student_ids);
                    $attendance_stmt->execute();
                    
                    // Delete interventions
                    $intervention_sql = "DELETE FROM interventions WHERE student_id IN ($placeholders)";
                    $intervention_stmt = $conn->prepare($intervention_sql);
                    if (!$intervention_stmt) {
                        throw new Exception("Error preparing intervention deletion");
                    }
                    $intervention_stmt->bind_param(str_repeat('i', count($student_ids)), ...$student_ids);
                    $intervention_stmt->execute();
                    
                    // Delete enrollments
                    $enrollment_sql = "DELETE FROM enrollments WHERE student_id IN ($placeholders)";
                    $enrollment_stmt = $conn->prepare($enrollment_sql);
                    if (!$enrollment_stmt) {
                        throw new Exception("Error preparing enrollment deletion");
                    }
                    $enrollment_stmt->bind_param(str_repeat('i', count($student_ids)), ...$student_ids);
                    $enrollment_stmt->execute();
                    
                    // Delete students
                    $delete_sql = "DELETE FROM students WHERE student_id IN ($placeholders)";
                    $delete_stmt = $conn->prepare($delete_sql);
                    if (!$delete_stmt) {
                        throw new Exception("Error preparing student deletion");
                    }
                    $delete_stmt->bind_param(str_repeat('i', count($student_ids)), ...$student_ids);
                    
                    if ($delete_stmt->execute()) {
                        $conn->commit();
                        $message = "✅ " . count($student_ids) . " students deleted successfully!";
                        $message_class = "success";
                    } else {
                        throw new Exception("Error deleting students");
                    }
                } catch (Exception $e) {
                    $conn->rollback();
                    $message = "❌ Error deleting students: " . $e->getMessage();
                    $message_class = "error";
                }
                break;
                
            case 'enroll_subjects':
                $enroll_subject_id = $_POST['bulk_subject_id'];
                $semester = $_POST['bulk_semester'] ?? '2024-1';
                
                if (empty($enroll_subject_id)) {
                    $message = "❌ Please select a subject to enroll!";
                    $message_class = "error";
                } else {
                    $success_count = 0;
                    $error_count = 0;
                    
                    foreach ($student_ids as $student_id) {
                        // Check if enrollment already exists
                        $check_sql = "SELECT enrollment_id FROM enrollments WHERE student_id = ? AND subject_id = ?";
                        $check_stmt = $conn->prepare($check_sql);
                        if ($check_stmt) {
                            $check_stmt->bind_param("ii", $student_id, $enroll_subject_id);
                            $check_stmt->execute();
                            
                            if ($check_stmt->get_result()->num_rows == 0) {
                                $insert_sql = "INSERT INTO enrollments (student_id, subject_id, semester) VALUES (?, ?, ?)";
                                $insert_stmt = $conn->prepare($insert_sql);
                                if ($insert_stmt) {
                                    $insert_stmt->bind_param("iis", $student_id, $enroll_subject_id, $semester);
                                    if ($insert_stmt->execute()) {
                                        $success_count++;
                                    } else {
                                        $error_count++;
                                    }
                                } else {
                                    $error_count++;
                                }
                            }
                        } else {
                            $error_count++;
                        }
                    }
                    
                    if ($error_count > 0) {
                        $message = "⚠️ $success_count students enrolled, $error_count failed!";
                        $message_class = "error";
                    } else {
                        $message = "✅ $success_count students enrolled in subject successfully!";
                        $message_class = "success";
                    }
                }
                break;
        }
    }
}

// Get search and filter parameters
$search = $_GET['search'] ?? '';
$subject_filter = $_GET['subject'] ?? '';
$attendance_filter = $_GET['attendance'] ?? '';
$intervention_filter = $_GET['intervention'] ?? '';
$semester_filter = $_GET['semester'] ?? '2024-1';

// Build enhanced query for students with cross-subject metrics
$students_sql = "
    SELECT 
        s.student_id,
        s.name,
        COUNT(DISTINCT e.subject_id) as enrolled_subjects,
        GROUP_CONCAT(DISTINCT sub.subject_name ORDER BY sub.subject_name SEPARATOR ', ') as subjects_enrolled,
        COUNT(a.attendance_id) as total_classes,
        SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as attended_classes,
        CASE 
            WHEN COUNT(a.attendance_id) > 0 THEN 
                ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) / COUNT(a.attendance_id)) * 100, 2)
            ELSE 0 
        END as attendance_percentage,
        (SELECT COUNT(*) FROM interventions WHERE student_id = s.student_id) as intervention_count,
        (SELECT COUNT(DISTINCT subject_id) FROM interventions WHERE student_id = s.student_id) as intervention_subjects,
        (SELECT MAX(intervention_date) FROM interventions WHERE student_id = s.student_id) as last_intervention
    FROM students s
    LEFT JOIN enrollments e ON s.student_id = e.student_id
    LEFT JOIN subjects sub ON e.subject_id = sub.subject_id
    LEFT JOIN attendance a ON s.student_id = a.student_id
    WHERE 1=1
";

$params = [];
$types = "";

// Add search filter
if (!empty($search)) {
    $students_sql .= " AND (s.name LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $types .= "s";
}

// Add subject filter
if (!empty($subject_filter)) {
    $students_sql .= " AND s.student_id IN (
        SELECT student_id FROM enrollments WHERE subject_id = ?
    )";
    $params[] = $subject_filter;
    $types .= "i";
}

$students_sql .= " GROUP BY s.student_id, s.name";

// Add attendance filter
if (!empty($attendance_filter)) {
    if ($attendance_filter === 'critical') {
        $students_sql .= " HAVING attendance_percentage < 60";
    } elseif ($attendance_filter === 'at-risk') {
        $students_sql .= " HAVING attendance_percentage < 80 AND attendance_percentage >= 60";
    } elseif ($attendance_filter === 'good') {
        $students_sql .= " HAVING attendance_percentage >= 80";
    }
}

// Add intervention filter
if (!empty($intervention_filter)) {
    if ($intervention_filter === 'with') {
        $students_sql .= " HAVING intervention_count > 0";
    } elseif ($intervention_filter === 'without') {
        $students_sql .= " HAVING intervention_count = 0";
    }
}

$students_sql .= " ORDER BY s.name ASC";

// Execute query
$stmt = $conn->prepare($students_sql);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $students_result = $stmt->get_result();
} else {
    error_log("Students SQL Error: " . $conn->error);
    $message = "❌ Database error: " . $conn->error;
    $message_class = "error";
    $students_result = null;
}

// Get all subjects for dropdown
$subjects_sql = "SELECT subject_id, subject_name FROM subjects ORDER BY subject_name";
$subjects_result = $conn->query($subjects_sql);

// Get enhanced student statistics
$stats = [
    'total_students' => 0,
    'critical_attendance' => 0,
    'at_risk' => 0,
    'good_attendance' => 0,
    'with_interventions' => 0,
    'multi_subject' => 0
];

$stats_sql = "
    SELECT 
        COUNT(*) as total_students,
        COUNT(CASE WHEN (
            SELECT COUNT(a.attendance_id) 
            FROM attendance a 
            WHERE a.student_id = s.student_id
        ) > 0 AND (
            SELECT SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(a.attendance_id)
            FROM attendance a 
            WHERE a.student_id = s.student_id
        ) < 60 THEN 1 END) as critical_attendance,
        COUNT(CASE WHEN (
            SELECT COUNT(a.attendance_id) 
            FROM attendance a 
            WHERE a.student_id = s.student_id
        ) > 0 AND (
            SELECT SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(a.attendance_id)
            FROM attendance a 
            WHERE a.student_id = s.student_id
        ) BETWEEN 60 AND 79.99 THEN 1 END) as at_risk,
        COUNT(CASE WHEN (
            SELECT COUNT(a.attendance_id) 
            FROM attendance a 
            WHERE a.student_id = s.student_id
        ) > 0 AND (
            SELECT SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(a.attendance_id)
            FROM attendance a 
            WHERE a.student_id = s.student_id
        ) >= 80 THEN 1 END) as good_attendance,
        COUNT(CASE WHEN (
            SELECT COUNT(*) FROM interventions WHERE student_id = s.student_id
        ) > 0 THEN 1 END) as with_interventions,
        COUNT(CASE WHEN (
            SELECT COUNT(DISTINCT subject_id) FROM enrollments WHERE student_id = s.student_id
        ) > 1 THEN 1 END) as multi_subject
    FROM students s
";

$stats_result = $conn->query($stats_sql);
if ($stats_result) {
    $stats = $stats_result->fetch_assoc();
} else {
    error_log("Stats SQL Error: " . $conn->error);
}

// Get subject enrollment statistics
$enrollment_stats_sql = "
    SELECT 
        sub.subject_name,
        COUNT(DISTINCT e.student_id) as enrolled_students,
        ROUND(AVG(
            CASE WHEN (
                SELECT COUNT(a.attendance_id) 
                FROM attendance a 
                WHERE a.student_id = e.student_id AND a.subject_id = sub.subject_id
            ) > 0 THEN (
                SELECT SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(a.attendance_id)
                FROM attendance a 
                WHERE a.student_id = e.student_id AND a.subject_id = sub.subject_id
            ) ELSE 0 END
        ), 2) as avg_attendance
    FROM subjects sub
    LEFT JOIN enrollments e ON sub.subject_id = e.subject_id
    GROUP BY sub.subject_id, sub.subject_name
    ORDER BY enrolled_students DESC
    LIMIT 5
";

$enrollment_stats_result = $conn->query($enrollment_stats_sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Management - CSSAP Admin</title>
    <style>
        /* ALL YOUR EXISTING CSS STYLES REMAIN EXACTLY THE SAME */
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
        .stat-card.critical { border-left-color: #e74c3c; }
        .stat-card.at-risk { border-left-color: #f39c12; }
        .stat-card.good { border-left-color: #2ecc71; }
        .stat-card.interventions { border-left-color: #9b59b6; }
        .stat-card.multi-subject { border-left-color: #1abc9c; }

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

        .filter-actions {
            display: flex;
            gap: 10px;
            align-items: end;
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

        /* Checkbox Styles */
        .select-checkbox {
            width: 18px;
            height: 18px;
            cursor: pointer;
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

        .subject-list {
            max-width: 250px;
            font-size: 0.8rem;
            color: #7f8c8d;
        }

        .subject-badge {
            padding: 2px 6px;
            border-radius: 8px;
            font-size: 0.7rem;
            font-weight: 600;
            background: #3498db;
            color: white;
            margin: 1px;
            display: inline-block;
        }

        .multi-subject-indicator {
            background: #1abc9c;
            color: white;
            padding: 2px 6px;
            border-radius: 8px;
            font-size: 0.7rem;
            margin-left: 5px;
        }

        .no-subject {
            color: #e74c3c;
            font-style: italic;
            font-size: 0.8rem;
        }

        .intervention-indicator {
            background: #9b59b6;
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

        /* Add Student Form */
        .add-student-form {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            border: 1px solid #e9ecef;
            margin-bottom: 20px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 15px;
            align-items: end;
        }

        /* Enrollment Stats */
        .enrollment-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        .enrollment-card {
            background: white;
            padding: 15px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border-left: 4px solid #3498db;
        }

        .enrollment-card h4 {
            color: #2c3e50;
            margin-bottom: 8px;
            font-size: 0.9rem;
        }

        .enrollment-stats {
            display: flex;
            justify-content: space-between;
            margin-top: 8px;
        }

        .enrollment-stat {
            text-align: center;
        }

        .enrollment-number {
            font-size: 1.2rem;
            font-weight: bold;
            color: #3498db;
        }

        .enrollment-label {
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
        }

        .modal-content {
            background-color: white;
            margin: 10% auto;
            padding: 30px;
            border-radius: 15px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
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
            
            .action-buttons {
                flex-direction: column;
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
                <li><a href="students.php" class="active">🎓 Student Management</a></li>
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
                <h1>Student Management</h1>
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
                    <div class="stat-icon">🎓</div>
                    <div class="stat-number"><?php echo $stats['total_students']; ?></div>
                    <div class="stat-label">Total Students</div>
                </div>
                <div class="stat-card critical">
                    <div class="stat-icon">🔥</div>
                    <div class="stat-number"><?php echo $stats['critical_attendance']; ?></div>
                    <div class="stat-label">Critical (&lt;60%)</div>
                </div>
                <div class="stat-card at-risk">
                    <div class="stat-icon">⚠️</div>
                    <div class="stat-number"><?php echo $stats['at_risk']; ?></div>
                    <div class="stat-label">At Risk (60-79%)</div>
                </div>
                <div class="stat-card good">
                    <div class="stat-icon">✅</div>
                    <div class="stat-number"><?php echo $stats['good_attendance']; ?></div>
                    <div class="stat-label">Good (≥80%)</div>
                </div>
                <div class="stat-card interventions">
                    <div class="stat-icon">🔄</div>
                    <div class="stat-number"><?php echo $stats['with_interventions']; ?></div>
                    <div class="stat-label">With Interventions</div>
                </div>
                <div class="stat-card multi-subject">
                    <div class="stat-icon">📚</div>
                    <div class="stat-number"><?php echo $stats['multi_subject']; ?></div>
                    <div class="stat-label">Multi-Subject</div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab active" onclick="switchTab('students')">👥 Student Management</button>
                <button class="tab" onclick="switchTab('analytics')">📊 Enrollment Analytics</button>
            </div>

            <!-- Students Tab -->
            <div id="students" class="tab-content active">
                <div class="content-section">
                    <div class="section-header">
                        <h2>All Students</h2>
                        <div>
                            <button class="btn btn-info" onclick="exportStudents()">📥 Export Data</button>
                        </div>
                    </div>

                    <!-- Bulk Actions -->
                    <div id="bulkActions" class="bulk-actions">
                        <form method="POST" class="bulk-form">
                            <input type="hidden" name="bulk_action" value="1">
                            <div class="selected-count" id="selectedCount">0 students selected</div>
                            <select name="bulk_action_type" id="bulkActionType" required onchange="toggleBulkSubject()">
                                <option value="">Select Action</option>
                                <option value="delete">Delete Selected</option>
                                <option value="enroll_subjects">Enroll in Subject</option>
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
                            <input type="hidden" name="bulk_semester" value="2024-1">
                            <button type="submit" class="btn btn-warning">Apply to Selected</button>
                            <button type="button" class="btn btn-secondary" onclick="clearSelection()">Clear Selection</button>
                        </form>
                    </div>

                    <!-- Add Student Form -->
                    <div class="add-student-form">
                        <h3 style="margin-bottom: 15px; color: #2c3e50;">Add New Student</h3>
                        <?php if ($has_subject_id): ?>
                            <div class="message warning" style="margin-bottom: 15px; background: #fff3cd; color: #856404; padding: 10px; border-radius: 5px;">
                                <strong>Note:</strong> Your database requires students to be associated with a subject. The system will automatically assign the first available subject.
                            </div>
                        <?php endif; ?>
                        <form method="POST">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="name">Student Name *</label>
                                    <input type="text" name="name" id="name" required 
                                           value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>" 
                                           placeholder="Enter student name">
                                </div>
                                <div class="form-group">
                                    <button type="submit" name="add_student" class="btn btn-success">Add Student</button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- Filter Form -->
                    <form method="GET" class="filter-form">
                        <div class="form-group">
                            <label for="search">Search Students:</label>
                            <input type="text" name="search" id="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by name...">
                        </div>

                        <div class="form-group">
                            <label for="subject">Filter by Subject:</label>
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
                            <label for="attendance">Filter by Attendance:</label>
                            <select name="attendance" id="attendance">
                                <option value="">All Students</option>
                                <option value="critical" <?php echo $attendance_filter === 'critical' ? 'selected' : ''; ?>>Critical (&lt;60%)</option>
                                <option value="at-risk" <?php echo $attendance_filter === 'at-risk' ? 'selected' : ''; ?>>At Risk (60-79%)</option>
                                <option value="good" <?php echo $attendance_filter === 'good' ? 'selected' : ''; ?>>Good (≥80%)</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="intervention">Filter by Interventions:</label>
                            <select name="intervention" id="intervention">
                                <option value="">All Students</option>
                                <option value="with" <?php echo $intervention_filter === 'with' ? 'selected' : ''; ?>>With Interventions</option>
                                <option value="without" <?php echo $intervention_filter === 'without' ? 'selected' : ''; ?>>Without Interventions</option>
                            </select>
                        </div>

                        <div class="filter-actions">
                            <button type="submit" class="btn">Apply Filters</button>
                            <a href="students.php" class="btn btn-warning">Reset</a>
                        </div>
                    </form>

                    <!-- Students Table -->
                    <?php if ($students_result && $students_result->num_rows > 0): ?>
                        <form id="studentsForm">
                            <table>
                                <thead>
                                    <tr>
                                        <th width="30">
                                            <input type="checkbox" id="selectAll" class="select-checkbox">
                                        </th>
                                        <th>Student ID</th>
                                        <th>Student Name</th>
                                        <th>Subjects</th>
                                        <th>Attendance</th>
                                        <th>Interventions</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($student = $students_result->fetch_assoc()): 
                                        $attendance_class = '';
                                        $attendance_percentage = $student['attendance_percentage'] ?? 0;
                                        if ($attendance_percentage < 60) {
                                            $attendance_class = 'attendance-critical';
                                        } elseif ($attendance_percentage < 80) {
                                            $attendance_class = 'attendance-warning';
                                        } else {
                                            $attendance_class = 'attendance-good';
                                        }
                                        
                                        // Get current subjects for this student for the modal
                                        $current_subjects_sql = "SELECT subject_id FROM enrollments WHERE student_id = ?";
                                        $current_stmt = $conn->prepare($current_subjects_sql);
                                        $current_stmt->bind_param("i", $student['student_id']);
                                        $current_stmt->execute();
                                        $current_result = $current_stmt->get_result();
                                        $current_subjects = [];
                                        while ($row = $current_result->fetch_assoc()) {
                                            $current_subjects[] = $row['subject_id'];
                                        }
                                    ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" name="student_ids[]" value="<?php echo $student['student_id']; ?>" class="select-checkbox student-checkbox">
                                            </td>
                                            <td>
                                                <strong><?php echo $student['student_id']; ?></strong>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($student['name']); ?></strong>
                                            </td>
                                            <td>
                                                <div class="subject-list">
                                                    <?php if (!empty($student['subjects_enrolled'])): ?>
                                                        <?php 
                                                        $subjects = explode(', ', $student['subjects_enrolled']);
                                                        foreach ($subjects as $subject): ?>
                                                            <span class="subject-badge">📚 <?php echo htmlspecialchars($subject); ?></span>
                                                        <?php endforeach; ?>
                                                        <?php if ($student['enrolled_subjects'] > 1): ?>
                                                            <span class="multi-subject-indicator"><?php echo $student['enrolled_subjects']; ?> subs</span>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="no-subject">No subjects enrolled</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td class="<?php echo $attendance_class; ?>">
                                                <?php echo $attendance_percentage; ?>%
                                                <br>
                                                <small style="color: #7f8c8d;">
                                                    (<?php echo $student['attended_classes']; ?>/<?php echo $student['total_classes']; ?>)
                                                </small>
                                            </td>
                                            <td>
                                                <?php if ($student['intervention_count'] > 0): ?>
                                                    <span style="color: #9b59b6; font-weight: 600;">
                                                        <?php echo $student['intervention_count']; ?> intv
                                                    </span>
                                                    <?php if ($student['intervention_subjects'] > 1): ?>
                                                        <span class="intervention-indicator"><?php echo $student['intervention_subjects']; ?> subs</span>
                                                    <?php endif; ?>
                                                    <?php if ($student['last_intervention']): ?>
                                                        <br><small style="color: #7f8c8d;">Last: <?php echo date('M j', strtotime($student['last_intervention'])); ?></small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span style="color: #7f8c8d;">None</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="action-buttons">
                                                <button class="btn btn-warning btn-sm" onclick="openEnrollmentModal(<?php echo $student['student_id']; ?>, <?php echo htmlspecialchars(json_encode($current_subjects)); ?>)">
                                                    Manage Subjects
                                                </button>
                                                <a href="students.php?delete_id=<?php echo $student['student_id']; ?>" 
                                                   class="btn btn-danger btn-sm" 
                                                   onclick="return confirm('Are you sure you want to delete this student? This will also remove their attendance, intervention, and enrollment records.')">
                                                    Delete
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </form>
                    <?php else: ?>
                        <div class="no-data">No students found matching your criteria.</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Analytics Tab -->
            <div id="analytics" class="tab-content">
                <div class="content-section">
                    <div class="section-header">
                        <h2>Subject Enrollment Analytics</h2>
                    </div>

                    <?php if ($enrollment_stats_result && $enrollment_stats_result->num_rows > 0): ?>
                        <div class="enrollment-grid">
                            <?php while ($subject = $enrollment_stats_result->fetch_assoc()): ?>
                                <div class="enrollment-card">
                                    <h4><?php echo htmlspecialchars($subject['subject_name']); ?></h4>
                                    <div class="enrollment-stats">
                                        <div class="enrollment-stat">
                                            <div class="enrollment-number"><?php echo $subject['enrolled_students']; ?></div>
                                            <div class="enrollment-label">Students</div>
                                        </div>
                                        <div class="enrollment-stat">
                                            <div class="enrollment-number"><?php echo $subject['avg_attendance']; ?>%</div>
                                            <div class="enrollment-label">Avg Attendance</div>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="no-data">No enrollment data available.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Subject Enrollment Modal -->
    <div id="enrollmentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Manage Subject Enrollment</h3>
                <span class="close">&times;</span>
            </div>
            <form method="POST" id="enrollmentForm">
                <input type="hidden" name="student_id" id="modalStudentId">
                <input type="hidden" name="enroll_subjects" value="1">
                <input type="hidden" name="semester" value="2024-1">
                
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
                    <button type="button" class="btn btn-warning" onclick="closeEnrollmentModal()">Cancel</button>
                    <button type="submit" class="btn btn-success">Save Enrollment</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    // Modal functionality
    const modal = document.getElementById('enrollmentModal');
    const closeBtn = document.querySelector('.close');
    
    function openEnrollmentModal(studentId, currentSubjectIds, event) {
        // CRITICAL: Prevent the form submission
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }
        
        document.getElementById('modalStudentId').value = studentId;
        
        // Clear all checkboxes first
        const checkboxes = document.querySelectorAll('.subject-checkbox');
        checkboxes.forEach(checkbox => {
            checkbox.checked = false;
        });
        
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
        return false; // Prevent default behavior
    }
    
    function closeEnrollmentModal() {
        modal.style.display = 'none';
    }
    
    closeBtn.onclick = closeEnrollmentModal;
    
    window.onclick = function(event) {
        if (event.target == modal) {
            closeEnrollmentModal();
        }
    }

    // Tab switching functionality
    function switchTab(tabName, event) {
        if (event) {
            event.preventDefault();
        }
        
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
        if (event) {
            event.target.classList.add('active');
        }
    }

    // Bulk selection functionality
    const selectAllCheckbox = document.getElementById('selectAll');
    const studentCheckboxes = document.querySelectorAll('.student-checkbox');
    const bulkActions = document.getElementById('bulkActions');
    const selectedCount = document.getElementById('selectedCount');
    const bulkActionType = document.getElementById('bulkActionType');
    const bulkSubjectId = document.getElementById('bulkSubjectId');

    function updateBulkActions() {
        const selectedCountValue = document.querySelectorAll('.student-checkbox:checked').length;
        selectedCount.textContent = selectedCountValue + ' students selected';
        
        if (selectedCountValue > 0) {
            bulkActions.style.display = 'block';
        } else {
            bulkActions.style.display = 'none';
        }
    }

    function toggleBulkSubject() {
        if (bulkActionType.value === 'enroll_subjects') {
            bulkSubjectId.style.display = 'block';
        } else {
            bulkSubjectId.style.display = 'none';
        }
    }

    function clearSelection() {
        studentCheckboxes.forEach(checkbox => {
            checkbox.checked = false;
        });
        selectAllCheckbox.checked = false;
        updateBulkActions();
    }

    selectAllCheckbox.addEventListener('change', function() {
        studentCheckboxes.forEach(checkbox => {
            checkbox.checked = this.checked;
        });
        updateBulkActions();
    });

    studentCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', updateBulkActions);
    });

    // Export functionality
    function exportStudents() {
        const params = new URLSearchParams(window.location.search);
        window.open('export_students.php?' + params.toString(), '_blank');
    }

    // Add confirmation for enrollment changes
    document.getElementById('enrollmentForm').addEventListener('submit', function(e) {
        const selectedSubjects = document.querySelectorAll('.subject-checkbox:checked');
        if (selectedSubjects.length === 0) {
            if (!confirm('No subjects selected. This will remove all current enrollments. Continue?')) {
                e.preventDefault();
            }
        }
    });

    // FIX: Change button type to prevent form submission
    document.addEventListener('DOMContentLoaded', function() {
        updateBulkActions();
        
        // Change all Manage Subjects buttons to type="button" to prevent form submission
        document.querySelectorAll('.btn-warning.btn-sm').forEach(button => {
            if (button.textContent.includes('Manage Subjects')) {
                button.type = 'button';
                
                // Remove old onclick and add proper event listener
                const oldOnclick = button.getAttribute('onclick');
                if (oldOnclick) {
                    button.removeAttribute('onclick');
                    
                    button.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        
                        // Extract parameters from the old onclick
                        const match = oldOnclick.match(/openEnrollmentModal\((\d+), (\[.*?\])\)/);
                        if (match) {
                            const studentId = parseInt(match[1]);
                            const currentSubjects = JSON.parse(match[2]);
                            openEnrollmentModal(studentId, currentSubjects, e);
                        }
                    });
                }
            }
        });
        
        // Also fix tab buttons
        document.querySelectorAll('.tab').forEach(tab => {
            const oldOnclick = tab.getAttribute('onclick');
            if (oldOnclick) {
                tab.removeAttribute('onclick');
                
                tab.addEventListener('click', function(e) {
                    e.preventDefault();
                    const tabName = oldOnclick.match(/switchTab\('(\w+)'\)/)[1];
                    switchTab(tabName, e);
                });
            }
        });
    });
</script>
</body>
</html>