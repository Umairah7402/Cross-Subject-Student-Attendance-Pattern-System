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

// Handle user deletion
if (isset($_GET['delete_id'])) {
    $delete_id = $_GET['delete_id'];
    
    // Prevent admin from deleting themselves
    if ($delete_id == $_SESSION['user_id']) {
        $message = "❌ You cannot delete your own account!";
        $message_class = "error";
    } else {
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Get user role before deletion
            $user_sql = "SELECT role FROM users WHERE user_id = ?";
            $user_stmt = $conn->prepare($user_sql);
            $user_stmt->bind_param("i", $delete_id);
            $user_stmt->execute();
            $user_result = $user_stmt->get_result();
            $user_data = $user_result->fetch_assoc();
            
            if ($user_data) {
                // If deleted user was a lecturer, also remove from lecturers table
                if ($user_data['role'] === 'lecturer') {
                    // Get lecturer_id first
                    $lecturer_id_sql = "SELECT lecturer_id FROM lecturers WHERE user_id = ?";
                    $lecturer_id_stmt = $conn->prepare($lecturer_id_sql);
                    $lecturer_id_stmt->bind_param("i", $delete_id);
                    $lecturer_id_stmt->execute();
                    $lecturer_id_result = $lecturer_id_stmt->get_result();
                    $lecturer_data = $lecturer_id_result->fetch_assoc();
                    
                    if ($lecturer_data) {
                        $lecturer_id = $lecturer_data['lecturer_id'];
                        
                        // Delete from lecturer_subjects
                        $subject_sql = "DELETE FROM lecturer_subjects WHERE lecturer_id = ?";
                        $subject_stmt = $conn->prepare($subject_sql);
                        $subject_stmt->bind_param("i", $lecturer_id);
                        $subject_stmt->execute();
                        
                        // Delete from interventions created by this lecturer
                        $intervention_sql = "DELETE FROM interventions WHERE created_by = ?";
                        $intervention_stmt = $conn->prepare($intervention_sql);
                        $intervention_stmt->bind_param("i", $lecturer_id);
                        $intervention_stmt->execute();
                        
                        // Delete from lecturers table
                        $lecturer_sql = "DELETE FROM lecturers WHERE lecturer_id = ?";
                        $lecturer_stmt = $conn->prepare($lecturer_sql);
                        $lecturer_stmt->bind_param("i", $lecturer_id);
                        $lecturer_stmt->execute();
                    }
                }
                
                // Delete user from users table
                $delete_sql = "DELETE FROM users WHERE user_id = ?";
                $delete_stmt = $conn->prepare($delete_sql);
                $delete_stmt->bind_param("i", $delete_id);
                
                if ($delete_stmt->execute()) {
                    $conn->commit();
                    $message = "✅ User deleted successfully!";
                    $message_class = "success";
                } else {
                    throw new Exception("Error deleting user: " . $delete_stmt->error);
                }
            } else {
                throw new Exception("User not found");
            }
        } catch (Exception $e) {
            $conn->rollback();
            $message = "❌ " . $e->getMessage();
            $message_class = "error";
        }
    }
}

// Handle user role update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_role'])) {
    $user_id = $_POST['user_id'];
    $new_role = $_POST['role'];
    
    // Prevent admin from changing their own role
    if ($user_id == $_SESSION['user_id']) {
        $message = "❌ You cannot change your own role!";
        $message_class = "error";
    } else {
        $update_sql = "UPDATE users SET role = ? WHERE user_id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("si", $new_role, $user_id);
        
        if ($update_stmt->execute()) {
            $message = "✅ User role updated successfully!";
            $message_class = "success";
        } else {
            $message = "❌ Error updating user role: " . $update_stmt->error;
            $message_class = "error";
        }
    }
}

// Handle bulk user actions
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['bulk_action'])) {
    $user_ids = $_POST['user_ids'] ?? [];
    $bulk_action = $_POST['bulk_action_type'];
    
    if (empty($user_ids)) {
        $message = "❌ Please select users to perform bulk action!";
        $message_class = "error";
    } else {
        $placeholders = str_repeat('?,', count($user_ids) - 1) . '?';
        
        switch ($bulk_action) {
            case 'delete':
                // Filter out current user from deletion
                $filtered_ids = array_filter($user_ids, function($id) {
                    return $id != $_SESSION['user_id'];
                });
                
                if (empty($filtered_ids)) {
                    $message = "❌ You cannot delete your own account!";
                    $message_class = "error";
                } else {
                    // Start transaction for bulk delete
                    $conn->begin_transaction();
                    try {
                        foreach ($filtered_ids as $user_id) {
                            // Get user role
                            $role_sql = "SELECT role FROM users WHERE user_id = ?";
                            $role_stmt = $conn->prepare($role_sql);
                            $role_stmt->bind_param("i", $user_id);
                            $role_stmt->execute();
                            $role_result = $role_stmt->get_result();
                            $user_data = $role_result->fetch_assoc();
                            
                            if ($user_data && $user_data['role'] === 'lecturer') {
                                // Get lecturer_id
                                $lecturer_sql = "SELECT lecturer_id FROM lecturers WHERE user_id = ?";
                                $lecturer_stmt = $conn->prepare($lecturer_sql);
                                $lecturer_stmt->bind_param("i", $user_id);
                                $lecturer_stmt->execute();
                                $lecturer_result = $lecturer_stmt->get_result();
                                $lecturer_data = $lecturer_result->fetch_assoc();
                                
                                if ($lecturer_data) {
                                    $lecturer_id = $lecturer_data['lecturer_id'];
                                    
                                    // Delete related records
                                    $delete_tables = [
                                        "DELETE FROM lecturer_subjects WHERE lecturer_id = ?",
                                        "DELETE FROM interventions WHERE created_by = ?",
                                        "DELETE FROM lecturers WHERE lecturer_id = ?"
                                    ];
                                    
                                    foreach ($delete_tables as $sql) {
                                        $stmt = $conn->prepare($sql);
                                        $stmt->bind_param("i", $lecturer_id);
                                        $stmt->execute();
                                    }
                                }
                            }
                            
                            // Delete user
                            $delete_sql = "DELETE FROM users WHERE user_id = ?";
                            $delete_stmt = $conn->prepare($delete_sql);
                            $delete_stmt->bind_param("i", $user_id);
                            $delete_stmt->execute();
                        }
                        
                        $conn->commit();
                        $message = "✅ " . count($filtered_ids) . " users deleted successfully!";
                        $message_class = "success";
                    } catch (Exception $e) {
                        $conn->rollback();
                        $message = "❌ Error deleting users: " . $e->getMessage();
                        $message_class = "error";
                    }
                }
                break;
        }
    }
}

// Get user statistics - FIXED to match your database structure
$stats = [
    'total_users' => 0,
    'admin_count' => 0,
    'lecturer_count' => 0,
    'student_count' => 0,
    'active_users' => 0,
    'total_interventions' => 0
];

// Get total users from users table
$total_users_sql = "SELECT COUNT(*) as total_users FROM users";
$total_result = $conn->query($total_users_sql);
if ($total_result) {
    $row = $total_result->fetch_assoc();
    $stats['total_users'] = $row['total_users'];
}

// Get admin count
$admin_sql = "SELECT COUNT(*) as admin_count FROM users WHERE role = 'admin'";
$admin_result = $conn->query($admin_sql);
if ($admin_result) {
    $row = $admin_result->fetch_assoc();
    $stats['admin_count'] = $row['admin_count'];
}

// Get lecturer count
$lecturer_sql = "SELECT COUNT(*) as lecturer_count FROM users WHERE role = 'lecturer'";
$lecturer_result = $conn->query($lecturer_sql);
if ($lecturer_result) {
    $row = $lecturer_result->fetch_assoc();
    $stats['lecturer_count'] = $row['lecturer_count'];
}

// Get student count
$student_sql = "SELECT COUNT(*) as student_count FROM students";
$student_result = $conn->query($student_sql);
if ($student_result) {
    $row = $student_result->fetch_assoc();
    $stats['student_count'] = $row['student_count'];
}

// Get active users count - REMOVED is_active column reference since it doesn't exist
$stats['active_users'] = $stats['total_users'];

// Get total interventions count
$interventions_sql = "SELECT COUNT(*) as total_interventions FROM interventions";
$interventions_result = $conn->query($interventions_sql);
if ($interventions_result) {
    $row = $interventions_result->fetch_assoc();
    $stats['total_interventions'] = $row['total_interventions'];
}

// Get sorting parameters
$sort = $_GET['sort'] ?? 'u.user_id';
$order = $_GET['order'] ?? 'ASC';

// FIXED: Get all users query - removed non-existent columns and fixed joins
$users_sql = "
    SELECT 
        u.user_id,
        u.username,
        u.role,
        u.password,
        COALESCE(l.lecturer_name, 'Not Set') as lecturer_name,
        COALESCE(l.email, u.username) as email,
        l.lecturer_id,
        (SELECT COUNT(*) FROM interventions i WHERE i.created_by = l.lecturer_id) as intervention_count,
        (SELECT COUNT(DISTINCT ls.subject_id) 
         FROM lecturer_subjects ls 
         WHERE ls.lecturer_id = l.lecturer_id) as subject_count,
        (SELECT GROUP_CONCAT(s.subject_name SEPARATOR ', ') 
         FROM lecturer_subjects ls 
         JOIN subjects s ON ls.subject_id = s.subject_id 
         WHERE ls.lecturer_id = l.lecturer_id) as subjects_taught
    FROM users u
    LEFT JOIN lecturers l ON u.user_id = l.user_id
    WHERE u.role IN ('admin', 'lecturer')
";

// Add sorting
$valid_sorts = ['username', 'role', 'lecturer_name', 'u.user_id'];
$valid_orders = ['ASC', 'DESC'];
$sort = in_array($sort, $valid_sorts) ? $sort : 'u.user_id';
$order = in_array($order, $valid_orders) ? $order : 'ASC';

// Handle sorting for lecturer_name (which can be NULL)
if ($sort === 'lecturer_name') {
    $users_sql .= " ORDER BY IFNULL(l.lecturer_name, 'ZZZ') $order, u.username $order";
} else {
    $users_sql .= " ORDER BY $sort $order";
}

// Execute query
$users_result = $conn->query($users_sql);

// Get lecturer performance stats
$lecturer_stats_sql = "
    SELECT 
        l.lecturer_name,
        l.email,
        COUNT(DISTINCT i.intervention_id) as interventions,
        COUNT(DISTINCT ls.subject_id) as subjects,
        (SELECT COUNT(DISTINCT e.student_id) 
         FROM enrollments e 
         JOIN lecturer_subjects ls2 ON e.subject_id = ls2.subject_id 
         WHERE ls2.lecturer_id = l.lecturer_id) as students_taught
    FROM lecturers l
    LEFT JOIN interventions i ON l.lecturer_id = i.created_by
    LEFT JOIN lecturer_subjects ls ON l.lecturer_id = ls.lecturer_id
    GROUP BY l.lecturer_id, l.lecturer_name, l.email
    ORDER BY interventions DESC
";

$lecturer_stats_result = $conn->query($lecturer_stats_sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - CSSAP Admin</title>
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
        .stat-card.admins { border-left-color: #e74c3c; }
        .stat-card.lecturers { border-left-color: #2ecc71; }
        .stat-card.students { border-left-color: #f39c12; }
        .stat-card.active { border-left-color: #9b59b6; }
        .stat-card.interventions { border-left-color: #3498db; }

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

        th.sortable {
            cursor: pointer;
            transition: background-color 0.3s ease;
        }

        th.sortable:hover {
            background-color: #e9ecef;
        }

        tr:hover {
            background-color: #f8f9fa;
        }

        .role-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .role-admin {
            background: #e74c3c;
            color: white;
        }

        .role-lecturer {
            background: #2ecc71;
            color: white;
        }

        .status-badge {
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }

        .subject-list {
            font-size: 0.8rem;
            color: #7f8c8d;
            max-width: 200px;
        }

        .metrics {
            display: flex;
            gap: 10px;
            font-size: 0.8rem;
            margin-top: 5px;
        }

        .metric {
            background: #e9ecef;
            padding: 2px 6px;
            border-radius: 8px;
            color: #495057;
            font-size: 0.7rem;
        }

        .metric.interventions {
            background: #d1ecf1;
            color: #0c5460;
        }

        .metric.subjects {
            background: #d4edda;
            color: #155724;
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

        .current-user {
            background: #fff3cd !important;
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

        /* Lecturer Performance */
        .performance-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        .performance-card {
            background: white;
            padding: 15px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border-left: 4px solid #3498db;
        }

        .performance-card h4 {
            color: #2c3e50;
            margin-bottom: 8px;
            font-size: 0.9rem;
        }

        .performance-card .email {
            color: #7f8c8d;
            font-size: 0.8rem;
            margin-bottom: 10px;
        }

        .performance-stats {
            display: flex;
            justify-content: space-between;
            margin-top: 8px;
        }

        .stat {
            text-align: center;
        }

        .stat-number {
            font-size: 1.2rem;
            font-weight: bold;
            color: #3498db;
        }

        .stat-label {
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

        /* User Count Badge */
        .user-count {
            background: #3498db;
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-left: 10px;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .bulk-form {
                flex-direction: column;
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
            
            .performance-grid {
                grid-template-columns: 1fr;
            }
        }

        select {
            padding: 8px 12px;
            border-radius: 6px;
            border: 1px solid #ddd;
            font-size: 14px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #495057;
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
                <li><a href="users.php" class="active">👥 User Management</a></li>
                <li><a href="lecturers.php">👨‍🏫 Lecturer Management</a></li>
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
                <h1>User Management</h1>
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
                    <div class="stat-icon">👥</div>
                    <div class="stat-number"><?php echo $stats['total_users']; ?></div>
                    <div class="stat-label">System Users</div>
                </div>
                <div class="stat-card admins">
                    <div class="stat-icon">👑</div>
                    <div class="stat-number"><?php echo $stats['admin_count']; ?></div>
                    <div class="stat-label">Administrators</div>
                </div>
                <div class="stat-card lecturers">
                    <div class="stat-icon">👨‍🏫</div>
                    <div class="stat-number"><?php echo $stats['lecturer_count']; ?></div>
                    <div class="stat-label">Lecturers</div>
                </div>
                <div class="stat-card students">
                    <div class="stat-icon">🎓</div>
                    <div class="stat-number"><?php echo $stats['student_count']; ?></div>
                    <div class="stat-label">Students</div>
                </div>
                <div class="stat-card active">
                    <div class="stat-icon">✅</div>
                    <div class="stat-number"><?php echo $stats['active_users']; ?></div>
                    <div class="stat-label">Active Users</div>
                </div>
                <div class="stat-card interventions">
                    <div class="stat-icon">🔄</div>
                    <div class="stat-number"><?php echo $stats['total_interventions']; ?></div>
                    <div class="stat-label">Interventions</div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab active" onclick="switchTab('users')">👥 System Users 
                    <span class="user-count"><?php echo $users_result ? $users_result->num_rows : 0; ?></span>
                </button>
                <button class="tab" onclick="switchTab('performance')">📊 Lecturer Analytics</button>
            </div>

            <!-- Users Tab -->
            <div id="users" class="tab-content active">
                <div class="content-section">
                    <div class="section-header">
                        <h2>System Users (Admins & Lecturers)</h2>
                        <div>
                            <a href="register.php" class="btn btn-success">➕ Add New User</a>
                            <button class="btn btn-info" onclick="exportUsers()">📥 Export Users</button>
                        </div>
                    </div>

                    <!-- Bulk Actions -->
                    <div id="bulkActions" class="bulk-actions">
                        <form method="POST" class="bulk-form">
                            <input type="hidden" name="bulk_action" value="1">
                            <div class="selected-count" id="selectedCount">0 users selected</div>
                            <select name="bulk_action_type" required>
                                <option value="">Select Action</option>
                                <option value="delete">Delete Selected Users</option>
                            </select>
                            <button type="submit" class="btn btn-warning">Apply to Selected</button>
                            <button type="button" class="btn btn-secondary" onclick="clearSelection()">Clear Selection</button>
                        </form>
                    </div>

                    <!-- Users Table -->
                    <?php if ($users_result && $users_result->num_rows > 0): ?>
                        <?php $user_count = $users_result->num_rows; ?>
                        <form id="usersForm" method="POST">
                            <table>
                                <thead>
                                    <tr>
                                        <th width="30">
                                            <input type="checkbox" id="selectAll" class="select-checkbox">
                                        </th>
                                        <th class="sortable" onclick="sortTable('username')">Username 
                                            <?php echo $sort === 'username' ? ($order === 'ASC' ? '↑' : '↓') : ''; ?>
                                        </th>
                                        <th class="sortable" onclick="sortTable('lecturer_name')">Name
                                            <?php echo $sort === 'lecturer_name' ? ($order === 'ASC' ? '↑' : '↓') : ''; ?>
                                        </th>
                                        <th>Subjects & Metrics</th>
                                        <th class="sortable" onclick="sortTable('role')">Role
                                            <?php echo $sort === 'role' ? ($order === 'ASC' ? '↑' : '↓') : ''; ?>
                                        </th>
                                        <th>Status</th>
                                        <th class="sortable" onclick="sortTable('u.user_id')">User ID
                                            <?php echo $sort === 'u.user_id' ? ($order === 'ASC' ? '↑' : '↓') : ''; ?>
                                        </th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $users_result->data_seek(0); // Reset pointer
                                    while ($user = $users_result->fetch_assoc()): 
                                        $is_current_user = $user['user_id'] == $_SESSION['user_id'];
                                    ?>
                                        <tr class="<?php echo $is_current_user ? 'current-user' : ''; ?>">
                                            <td>
                                                <?php if (!$is_current_user): ?>
                                                    <input type="checkbox" name="user_ids[]" value="<?php echo $user['user_id']; ?>" class="select-checkbox user-checkbox">
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($user['username']); ?></strong>
                                                <?php if ($is_current_user): ?>
                                                    <br><small style="color: #f39c12;">(Current User)</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php 
                                                if (!empty($user['lecturer_name']) && $user['lecturer_name'] != 'Not Set') {
                                                    echo htmlspecialchars($user['lecturer_name']);
                                                    if (!empty($user['email']) && $user['email'] != $user['username']) {
                                                        echo '<br><small style="color: #7f8c8d;">' . htmlspecialchars($user['email']) . '</small>';
                                                    }
                                                } else if ($user['role'] === 'admin') {
                                                    echo '<em style="color: #7f8c8d;">System Administrator</em>';
                                                } else {
                                                    echo '<em style="color: #7f8c8d;">Lecturer profile not set</em>';
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <div class="subject-list">
                                                    <?php 
                                                    if (!empty($user['subjects_taught'])) {
                                                        echo htmlspecialchars($user['subjects_taught']);
                                                    } else if ($user['role'] === 'lecturer') {
                                                        echo '<em style="color: #7f8c8d;">No subjects assigned</em>';
                                                    } else {
                                                        echo '<em style="color: #7f8c8d;">N/A for admin</em>';
                                                    }
                                                    ?>
                                                </div>
                                                <?php if ($user['role'] === 'lecturer' && $user['lecturer_id']): ?>
                                                    <div class="metrics">
                                                        <span class="metric interventions"><?php echo $user['intervention_count'] ?? 0; ?> interventions</span>
                                                        <span class="metric subjects"><?php echo $user['subject_count'] ?? 0; ?> subjects</span>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="role-badge role-<?php echo $user['role']; ?>">
                                                    <?php echo ucfirst($user['role']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="status-badge status-active">
                                                    Active
                                                </span>
                                            </td>
                                            <td><?php echo $user['user_id']; ?></td>
                                            <td class="action-buttons">
                                                <?php if (!$is_current_user): ?>
                                                    <button type="button" class="btn btn-warning btn-sm" onclick="openRoleModal(<?php echo $user['user_id']; ?>, '<?php echo $user['role']; ?>')">
                                                        Edit Role
                                                    </button>
                                                    <a href="users.php?delete_id=<?php echo $user['user_id']; ?>" 
                                                       class="btn btn-danger btn-sm" 
                                                       onclick="return confirm('Are you sure you want to delete this user? This will remove all associated data including interventions and subject assignments.')">
                                                        Delete
                                                    </a>
                                                <?php else: ?>
                                                    <span style="color: #7f8c8d; font-size: 0.9rem;">Current user</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </form>
                        <p style="margin-top: 10px; color: #7f8c8d; font-size: 0.9rem;">
                            Showing <?php echo $user_count; ?> user<?php echo $user_count != 1 ? 's' : ''; ?>
                        </p>
                    <?php else: ?>
                        <div class="no-data">
                            <h3>No users found in the system.</h3>
                            <p>There are currently no users registered in the system.</p>
                            <a href="register.php" class="btn btn-success" style="margin-top: 20px;">➕ Add New User</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Performance Tab -->
            <div id="performance" class="tab-content">
                <div class="content-section">
                    <div class="section-header">
                        <h2>Lecturer Analytics Overview</h2>
                    </div>

                    <?php if ($lecturer_stats_result && $lecturer_stats_result->num_rows > 0): ?>
                        <div class="performance-grid">
                            <?php 
                            $lecturer_stats_result->data_seek(0);
                            while ($lecturer = $lecturer_stats_result->fetch_assoc()): 
                            ?>
                                <div class="performance-card">
                                    <h4><?php echo htmlspecialchars($lecturer['lecturer_name']); ?></h4>
                                    <div class="email"><?php echo htmlspecialchars($lecturer['email']); ?></div>
                                    <div class="performance-stats">
                                        <div class="stat">
                                            <div class="stat-number"><?php echo $lecturer['interventions']; ?></div>
                                            <div class="stat-label">Interventions</div>
                                        </div>
                                        <div class="stat">
                                            <div class="stat-number"><?php echo $lecturer['subjects']; ?></div>
                                            <div class="stat-label">Subjects</div>
                                        </div>
                                        <div class="stat">
                                            <div class="stat-number"><?php echo $lecturer['students_taught']; ?></div>
                                            <div class="stat-label">Students</div>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="no-data">No lecturer performance data available.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Role Update Modal -->
    <div id="roleModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Update User Role</h3>
                <span class="close">&times;</span>
            </div>
            <form method="POST" id="roleForm">
                <input type="hidden" name="user_id" id="modalUserId">
                <input type="hidden" name="update_role" value="1">
                
                <div class="form-group">
                    <label for="modalRole">Select New Role:</label>
                    <select name="role" id="modalRole" required>
                        <option value="admin">Administrator</option>
                        <option value="lecturer">Lecturer</option>
                    </select>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn btn-warning" onclick="closeRoleModal()">Cancel</button>
                    <button type="submit" class="btn btn-success">Update Role</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Modal functionality
        const modal = document.getElementById('roleModal');
        const closeBtn = document.querySelector('.close');
        
        function openRoleModal(userId, currentRole) {
            document.getElementById('modalUserId').value = userId;
            document.getElementById('modalRole').value = currentRole;
            modal.style.display = 'block';
        }
        
        function closeRoleModal() {
            modal.style.display = 'none';
        }
        
        closeBtn.onclick = closeRoleModal;
        
        window.onclick = function(event) {
            if (event.target == modal) {
                closeRoleModal();
            }
        }
        
        // Table sorting
        function sortTable(column) {
            const urlParams = new URLSearchParams(window.location.search);
            let currentSort = urlParams.get('sort') || 'u.user_id';
            let currentOrder = urlParams.get('order') || 'ASC';
            
            let newOrder = 'ASC';
            if (currentSort === column && currentOrder === 'ASC') {
                newOrder = 'DESC';
            }
            
            urlParams.set('sort', column);
            urlParams.set('order', newOrder);
            window.location.href = 'users.php?' + urlParams.toString();
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
        const selectAllCheckbox = document.getElementById('selectAll');
        const userCheckboxes = document.querySelectorAll('.user-checkbox');
        const bulkActions = document.getElementById('bulkActions');
        const selectedCount = document.getElementById('selectedCount');

        function updateBulkActions() {
            const selectedCountValue = document.querySelectorAll('.user-checkbox:checked').length;
            selectedCount.textContent = selectedCountValue + ' users selected';
            
            if (selectedCountValue > 0) {
                bulkActions.style.display = 'block';
            } else {
                bulkActions.style.display = 'none';
            }
        }

        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', function() {
                userCheckboxes.forEach(checkbox => {
                    checkbox.checked = this.checked;
                });
                updateBulkActions();
            });
        }

        if (userCheckboxes.length > 0) {
            userCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', updateBulkActions);
            });
        }

        function clearSelection() {
            userCheckboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
            if (selectAllCheckbox) {
                selectAllCheckbox.checked = false;
            }
            updateBulkActions();
        }

        // Export functionality
        function exportUsers() {
            const params = new URLSearchParams(window.location.search);
            window.open('export_users.php?' + params.toString(), '_blank');
        }

        // Add confirmation for role changes
        document.getElementById('roleForm').addEventListener('submit', function(e) {
            const newRole = document.getElementById('modalRole').value;
            if (!confirm(`Are you sure you want to change this user's role to ${newRole}?`)) {
                e.preventDefault();
            }
        });

        // Initialize bulk actions
        document.addEventListener('DOMContentLoaded', function() {
            updateBulkActions();
        });
    </script>
</body>
</html>