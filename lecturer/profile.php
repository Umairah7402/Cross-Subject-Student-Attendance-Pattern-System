<?php
session_start();
include('db_connect.php');

// Check if user is logged in and is a lecturer
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'lecturer') {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$lecturer_id = $_SESSION['lecturer_id'];
$message = '';
$message_class = '';

// Get lecturer's current information - FIXED JOIN
$lecturer_sql = "SELECT l.lecturer_id, l.lecturer_name, l.email, u.username 
                 FROM lecturers l 
                 JOIN users u ON l.user_id = u.user_id  // CORRECTED JOIN
                 WHERE l.lecturer_id = ?";
$stmt = $conn->prepare($lecturer_sql);

if ($stmt) {
    $stmt->bind_param("i", $lecturer_id);
    $stmt->execute();
    $lecturer_result = $stmt->get_result();
    $lecturer = $lecturer_result->fetch_assoc();
} else {
    // If query fails, set default values
    $lecturer = [
        'lecturer_id' => $lecturer_id,
        'lecturer_name' => $_SESSION['lecturer_name'] ?? 'Lecturer',
        'email' => $_SESSION['email'] ?? 'No email',
        'username' => $_SESSION['username'] ?? 'No username'
    ];
}

// Get subjects taught by this lecturer - FIXED QUERY
$subjects_sql = "SELECT DISTINCT s.subject_id, s.subject_name 
                 FROM subjects s 
                 JOIN lecturer_subjects ls ON s.subject_id = ls.subject_id
                 WHERE ls.lecturer_id = ?";
$stmt = $conn->prepare($subjects_sql);
if ($stmt) {
    $stmt->bind_param("i", $lecturer_id);
    $stmt->execute();
    $subjects_result = $stmt->get_result();
} else {
    $subjects_result = null;
}

// Handle profile update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_profile'])) {
    $lecturer_name = trim($_POST['lecturer_name']);
    $email = trim($_POST['email']);
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    $error = false;

    // Validate required fields
    if (empty($lecturer_name) || empty($email)) {
        $message = "Please fill in all required fields.";
        $message_class = "error";
        $error = true;
    }

    // Validate email format
    if (!$error && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Please enter a valid email address.";
        $message_class = "error";
        $error = true;
    }

    // Check if email already exists (excluding current user)
    if (!$error) {
        $check_email_sql = "SELECT lecturer_id FROM lecturers WHERE email = ? AND lecturer_id != ?";
        $check_stmt = $conn->prepare($check_email_sql);
        if ($check_stmt) {
            $check_stmt->bind_param("si", $email, $lecturer_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();

            if ($check_result->num_rows > 0) {
                $message = "Email address is already in use by another lecturer.";
                $message_class = "error";
                $error = true;
            }
        }
    }

    // Handle password change if provided
    if (!$error && !empty($current_password)) {
        // Verify current password
        $verify_sql = "SELECT password FROM users WHERE user_id = ?";
        $verify_stmt = $conn->prepare($verify_sql);
        if ($verify_stmt) {
            $verify_stmt->bind_param("i", $user_id);
            $verify_stmt->execute();
            $verify_result = $verify_stmt->get_result();
            
            if ($verify_result->num_rows > 0) {
                $user_data = $verify_result->fetch_assoc();

                if (!password_verify($current_password, $user_data['password'])) {
                    $message = "Current password is incorrect.";
                    $message_class = "error";
                    $error = true;
                } elseif ($new_password !== $confirm_password) {
                    $message = "New passwords do not match.";
                    $message_class = "error";
                    $error = true;
                } elseif (strlen($new_password) < 6) {
                    $message = "New password must be at least 6 characters long.";
                    $message_class = "error";
                    $error = true;
                }
            } else {
                $message = "Error verifying current password.";
                $message_class = "error";
                $error = true;
            }
        }
    }

    // Update profile if no errors
    if (!$error) {
        // Start transaction
        $conn->begin_transaction();

        try {
            // Update lecturers table
            $update_lecturer_sql = "UPDATE lecturers SET lecturer_name = ?, email = ? WHERE lecturer_id = ?";
            $stmt = $conn->prepare($update_lecturer_sql);
            $stmt->bind_param("ssi", $lecturer_name, $email, $lecturer_id);
            $stmt->execute();

            // Update users table (username)
            $update_user_sql = "UPDATE users SET username = ? WHERE user_id = ?";
            $stmt = $conn->prepare($update_user_sql);
            $stmt->bind_param("si", $email, $user_id);
            $stmt->execute();

            // Update password if provided
            if (!empty($new_password)) {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $update_password_sql = "UPDATE users SET password = ? WHERE user_id = ?";
                $stmt = $conn->prepare($update_password_sql);
                $stmt->bind_param("si", $hashed_password, $user_id);
                $stmt->execute();
            }

            // Commit transaction
            $conn->commit();

            // Update session variables
            $_SESSION['lecturer_name'] = $lecturer_name;
            $_SESSION['email'] = $email;
            $_SESSION['username'] = $email;

            $message = "Profile updated successfully!";
            $message_class = "success";

            // Refresh lecturer data
            $stmt = $conn->prepare($lecturer_sql);
            if ($stmt) {
                $stmt->bind_param("i", $lecturer_id);
                $stmt->execute();
                $lecturer_result = $stmt->get_result();
                $lecturer = $lecturer_result->fetch_assoc();
            }

        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            $message = "Error updating profile: " . $e->getMessage();
            $message_class = "error";
        }
    }
}

// Get enhanced statistics for dashboard with cross-subject insights
$stats_sql = "
    SELECT 
        COUNT(DISTINCT e.student_id) as total_students,
        COUNT(DISTINCT ls.subject_id) as total_subjects,
        (SELECT COUNT(*) FROM interventions i WHERE i.created_by = ?) as total_interventions,
        (SELECT COUNT(DISTINCT i.student_id) FROM interventions i WHERE i.created_by = ?) as students_with_interventions,
        (SELECT COUNT(DISTINCT e2.student_id) 
         FROM enrollments e2 
         WHERE e2.subject_id IN (SELECT ls2.subject_id FROM lecturer_subjects ls2 WHERE ls2.lecturer_id = ?)
         AND e2.student_id IN (
             SELECT DISTINCT e3.student_id 
             FROM enrollments e3 
             GROUP BY e3.student_id 
             HAVING COUNT(DISTINCT e3.subject_id) > 1
         )) as cross_subject_students,
        (SELECT COUNT(*) 
         FROM interventions i 
         JOIN students st ON i.student_id = st.student_id 
         WHERE i.created_by = ? 
         AND st.student_id IN (
             SELECT DISTINCT e4.student_id 
             FROM enrollments e4 
             GROUP BY e4.student_id 
             HAVING COUNT(DISTINCT e4.subject_id) > 1
         )) as cross_subject_interventions
    FROM lecturer_subjects ls
    LEFT JOIN enrollments e ON ls.subject_id = e.subject_id
    WHERE ls.lecturer_id = ?
";

$stats = [
    'total_students' => 0,
    'total_subjects' => 0,
    'total_interventions' => 0,
    'students_with_interventions' => 0,
    'cross_subject_students' => 0,
    'cross_subject_interventions' => 0
];

$stmt = $conn->prepare($stats_sql);
if ($stmt) {
    $stmt->bind_param("iiiii", $lecturer_id, $lecturer_id, $lecturer_id, $lecturer_id, $lecturer_id);
    $stmt->execute();
    $stats_result = $stmt->get_result();
    if ($stats_result) {
        $stats = $stats_result->fetch_assoc() ?? $stats;
    }
}

// Get recent cross-subject interventions
$recent_interventions_sql = "
    SELECT i.intervention_date, st.name as student_name, s.subject_name, i.comments,
           (SELECT COUNT(DISTINCT e.subject_id) 
            FROM enrollments e 
            WHERE e.student_id = st.student_id) as student_subject_count
    FROM interventions i
    JOIN students st ON i.student_id = st.student_id
    JOIN subjects s ON i.subject_id = s.subject_id
    WHERE i.created_by = ?
    ORDER BY i.intervention_date DESC
    LIMIT 5
";

$recent_interventions = [];
$stmt = $conn->prepare($recent_interventions_sql);
if ($stmt) {
    $stmt->bind_param("i", $lecturer_id);
    $stmt->execute();
    $recent_interventions_result = $stmt->get_result();
    if ($recent_interventions_result) {
        $recent_interventions = $recent_interventions_result->fetch_all(MYSQLI_ASSOC);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - CSSAP</title>
    <style>
        /* Enhanced CSS with cross-subject features */
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

        .container {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar Styles */
        .sidebar {
            width: 250px;
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            padding: 20px 0;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
        }

        .sidebar-header {
            padding: 0 20px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.2);
            margin-bottom: 20px;
        }

        .sidebar-header h2 {
            font-size: 1.5rem;
            margin-bottom: 5px;
        }

        .nav-links {
            list-style: none;
        }

        .nav-links li {
            margin-bottom: 5px;
        }

        .nav-links a {
            display: block;
            padding: 12px 20px;
            color: white;
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
        }

        .nav-links a:hover, .nav-links a.active {
            background: rgba(255,255,255,0.1);
            border-left-color: white;
        }

        .logout-btn {
            margin: 20px;
            padding: 10px;
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            border-radius: 5px;
            cursor: pointer;
            width: calc(100% - 40px);
            transition: background 0.3s ease;
        }

        .logout-btn:hover {
            background: rgba(255,255,255,0.3);
        }

        /* Main Content Styles */
        .main-content {
            flex: 1;
            padding: 20px;
            overflow-x: auto;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #dee2e6;
        }

        .header h1 {
            color: #28a745;
            font-size: 2rem;
        }

        .content-section {
            background: white;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
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
            color: #495057;
            font-size: 1.4rem;
        }

        .btn {
            padding: 10px 20px;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: background 0.3s ease;
            font-size: 14px;
        }

        .btn:hover {
            background: #218838;
        }

        .btn-secondary {
            background: #6c757d;
        }

        .btn-secondary:hover {
            background: #545b62;
        }

        .btn-info {
            background: #17a2b8;
        }

        .btn-info:hover {
            background: #138496;
        }

        /* Profile Styles */
        .profile-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
        }

        @media (max-width: 768px) {
            .profile-grid {
                grid-template-columns: 1fr;
            }
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #495057;
        }

        input[type="text"], input[type="email"], input[type="password"] {
            width: 100%;
            padding: 10px;
            border: 2px solid #e9ecef;
            border-radius: 5px;
            font-size: 16px;
            transition: border-color 0.3s ease;
        }

        input:focus {
            outline: none;
            border-color: #28a745;
        }

        .password-note {
            font-size: 0.8rem;
            color: #6c757d;
            margin-top: 5px;
        }

        .profile-info {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            border-left: 4px solid #28a745;
        }

        .info-item {
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid #dee2e6;
        }

        .info-item:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }

        .info-label {
            font-weight: 600;
            color: #495057;
            font-size: 0.9rem;
            margin-bottom: 5px;
        }

        .info-value {
            color: #6c757d;
            font-size: 1rem;
        }

        .subject-badge {
            display: inline-block;
            padding: 4px 8px;
            background: #e9ecef;
            border-radius: 15px;
            font-size: 0.8rem;
            margin: 2px;
            color: #495057;
        }

        .cross-subject-badge {
            display: inline-block;
            padding: 4px 8px;
            background: #e7f3ff;
            color: #0056b3;
            border-radius: 12px;
            font-size: 0.7rem;
            margin-left: 5px;
            font-weight: bold;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            text-align: center;
            border-left: 4px solid #28a745;
            transition: transform 0.2s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
        }

        .stat-card.info {
            border-left-color: #17a2b8;
        }

        .stat-card.warning {
            border-left-color: #ffc107;
        }

        .stat-card.cross-subject {
            border-left-color: #0056b3;
            background: #f8f9ff;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #28a745;
            margin-bottom: 5px;
        }

        .stat-card.info .stat-number {
            color: #17a2b8;
        }

        .stat-card.warning .stat-number {
            color: #ffc107;
        }

        .stat-card.cross-subject .stat-number {
            color: #0056b3;
        }

        .stat-label {
            font-size: 0.8rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .message {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
            font-weight: 500;
        }

        .success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .no-data {
            text-align: center;
            color: #6c757d;
            padding: 20px;
            font-style: italic;
        }

        .profile-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: linear-gradient(135deg, #28a745, #20c997);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2rem;
            font-weight: bold;
            margin: 0 auto 20px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }

        .recent-activity {
            margin-top: 20px;
        }

        .activity-item {
            padding: 10px;
            border-left: 3px solid #28a745;
            background: #f8f9fa;
            margin-bottom: 10px;
            border-radius: 0 5px 5px 0;
        }

        .activity-item.cross-subject {
            border-left-color: #0056b3;
            background: #e7f3ff;
        }

        .activity-date {
            font-size: 0.8rem;
            color: #6c757d;
            margin-bottom: 5px;
        }

        .activity-details {
            font-size: 0.9rem;
        }

        .cross-subject-indicator {
            background: #0056b3;
            color: white;
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 0.7rem;
            margin-left: 5px;
        }

        .security-tips {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 5px;
            padding: 15px;
            margin-top: 20px;
        }

        .security-tips h4 {
            color: #856404;
            margin-bottom: 10px;
        }

        .security-tips ul {
            padding-left: 20px;
            color: #856404;
        }

        .security-tips li {
            margin-bottom: 5px;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <h2>CSSAP</h2>
                <p>Lecturer Portal</p>
            </div>
            <ul class="nav-links">
                <li><a href="lecturer_dashboard.php">Dashboard</a></li>
                <li><a href="attendance.php">Mark Attendance</a></li>
                <li><a href="students.php">Student Management</a></li>
                <li><a href="interventions.php">Interventions</a></li>
                <li><a href="reports.php">Reports</a></li>
                <li><a href="profile.php" class="active">My Profile</a></li>
            </ul>
            <form method="POST" action="logout.php">
                <button type="submit" class="logout-btn">Logout</button>
            </form>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <div class="header">
                <h1>My Profile <span class="cross-subject-badge">CSSAP LECTURER</span></h1>
                <div>
                    <span>Welcome, <strong><?php echo htmlspecialchars($_SESSION['lecturer_name'] ?? 'Lecturer'); ?></strong></span>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="message <?php echo $message_class; ?>">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <!-- Enhanced Statistics with Cross-Subject Insights -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['total_subjects'] ?? 0; ?></div>
                    <div class="stat-label">Subjects Teaching</div>
                </div>
                <div class="stat-card info">
                    <div class="stat-number"><?php echo $stats['total_students'] ?? 0; ?></div>
                    <div class="stat-label">Total Students</div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-number"><?php echo $stats['total_interventions'] ?? 0; ?></div>
                    <div class="stat-label">Interventions</div>
                </div>
                <div class="stat-card cross-subject">
                    <div class="stat-number"><?php echo $stats['cross_subject_students'] ?? 0; ?></div>
                    <div class="stat-label">Cross-Subject Students</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['students_with_interventions'] ?? 0; ?></div>
                    <div class="stat-label">Students Assisted</div>
                </div>
                <div class="stat-card cross-subject">
                    <div class="stat-number"><?php echo $stats['cross_subject_interventions'] ?? 0; ?></div>
                    <div class="stat-label">Cross-Subject Interventions</div>
                </div>
            </div>

            <div class="profile-grid">
                <!-- Profile Information -->
                <div class="content-section">
                    <div class="section-header">
                        <h2>Profile Information</h2>
                        <span class="btn btn-info">Active</span>
                    </div>
                    
                    <div class="profile-avatar">
                        <?php echo strtoupper(substr(($lecturer['lecturer_name'] ?? 'L'), 0, 1)); ?>
                    </div>

                    <div class="profile-info">
                        <div class="info-item">
                            <div class="info-label">Full Name</div>
                            <div class="info-value"><?php echo htmlspecialchars($lecturer['lecturer_name'] ?? 'Not available'); ?></div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">Email Address</div>
                            <div class="info-value"><?php echo htmlspecialchars($lecturer['email'] ?? 'Not available'); ?></div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">Lecturer ID</div>
                            <div class="info-value"><?php echo htmlspecialchars($lecturer['lecturer_id'] ?? 'Not available'); ?></div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">User ID</div>
                            <div class="info-value"><?php echo htmlspecialchars($user_id); ?></div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">Assigned Subjects</div>
                            <div class="info-value">
                                <?php if ($subjects_result && $subjects_result->num_rows > 0): ?>
                                    <?php while ($subject = $subjects_result->fetch_assoc()): ?>
                                        <span class="subject-badge"><?php echo htmlspecialchars($subject['subject_name']); ?></span>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <span class="no-data">No subjects assigned</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">Account Role</div>
                            <div class="info-value">Lecturer <span class="cross-subject-badge">CSSAP</span></div>
                        </div>
                    </div>

                    <!-- Recent Activity -->
                    <div class="recent-activity">
                        <h3 style="margin-bottom: 15px; color: #495057;">Recent Cross-Subject Activity</h3>
                        <?php if (!empty($recent_interventions)): ?>
                            <?php foreach ($recent_interventions as $intervention): ?>
                                <div class="activity-item <?php echo ($intervention['student_subject_count'] > 1) ? 'cross-subject' : ''; ?>">
                                    <div class="activity-date">
                                        <?php echo date('M j, Y', strtotime($intervention['intervention_date'])); ?>
                                    </div>
                                    <div class="activity-details">
                                        <strong><?php echo htmlspecialchars($intervention['student_name']); ?></strong>
                                        - <?php echo htmlspecialchars($intervention['subject_name']); ?>
                                        <?php if ($intervention['student_subject_count'] > 1): ?>
                                            <span class="cross-subject-indicator" title="Student enrolled in <?php echo $intervention['student_subject_count']; ?> subjects">
                                                <?php echo $intervention['student_subject_count']; ?> subjects
                                            </span>
                                        <?php endif; ?>
                                        <br>
                                        <small><?php echo htmlspecialchars(substr($intervention['comments'], 0, 50)); ?>...</small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="no-data">No recent interventions</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Update Profile Form -->
                <div class="content-section">
                    <div class="section-header">
                        <h2>Update Profile</h2>
                    </div>
                    
                    <form method="POST">
                        <div class="form-group">
                            <label for="lecturer_name">Full Name:</label>
                            <input type="text" name="lecturer_name" id="lecturer_name" 
                                   value="<?php echo htmlspecialchars($lecturer['lecturer_name'] ?? ''); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="email">Email Address:</label>
                            <input type="email" name="email" id="email" 
                                   value="<?php echo htmlspecialchars($lecturer['email'] ?? ''); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="current_password">Current Password (for verification):</label>
                            <input type="password" name="current_password" id="current_password" 
                                   placeholder="Enter current password to make changes">
                            <div class="password-note">Required only if changing password or email</div>
                        </div>

                        <div class="form-group">
                            <label for="new_password">New Password:</label>
                            <input type="password" name="new_password" id="new_password" 
                                   placeholder="Leave blank to keep current password">
                            <div class="password-note">Minimum 6 characters</div>
                        </div>

                        <div class="form-group">
                            <label for="confirm_password">Confirm New Password:</label>
                            <input type="password" name="confirm_password" id="confirm_password" 
                                   placeholder="Confirm new password">
                        </div>

                        <button type="submit" name="update_profile" class="btn">Update Profile</button>
                        <a href="profile.php" class="btn btn-secondary">Cancel</a>
                    </form>

                    <!-- Security Tips -->
                    <div class="security-tips">
                        <h4>🔒 Security Tips</h4>
                        <ul>
                            <li>Use a strong, unique password</li>
                            <li>Never share your login credentials</li>
                            <li>Log out after each session</li>
                            <li>Regularly update your password</li>
                            <li>Report any suspicious activity immediately</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Enhanced System Information -->
            <div class="content-section">
                <div class="section-header">
                    <h2>System Information & Cross-Subject Role</h2>
                </div>
                <div class="profile-info">
                    <div class="info-item">
                        <div class="info-label">CSSAP Version</div>
                        <div class="info-value">1.0.0 <span class="cross-subject-badge">Cross-Subject Enabled</span></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Last Login</div>
                        <div class="info-value"><?php echo date('F j, Y \a\t g:i A'); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Account Created</div>
                        <div class="info-value"><?php echo date('F j, Y'); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Cross-Subject Access</div>
                        <div class="info-value">
                            <span style="color: #28a745;">✓ Enabled</span> - You can view student patterns across all your assigned subjects
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Collaboration Features</div>
                        <div class="info-value">
                            <span style="color: #28a745;">✓ Active</span> - Share interventions with other lecturers
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const newPassword = document.getElementById('new_password');
            const confirmPassword = document.getElementById('confirm_password');
            const currentPassword = document.getElementById('current_password');
            const emailInput = document.getElementById('email');
            const lecturerNameInput = document.getElementById('lecturer_name');

            function validatePasswords() {
                if (newPassword.value !== confirmPassword.value) {
                    confirmPassword.setCustomValidity('Passwords do not match');
                } else {
                    confirmPassword.setCustomValidity('');
                }
            }

            function checkCurrentPasswordRequirement() {
                const emailChanged = emailInput.value !== '<?php echo $lecturer['email'] ?? ''; ?>';
                const nameChanged = lecturerNameInput.value !== '<?php echo $lecturer['lecturer_name'] ?? ''; ?>';
                const passwordChanged = newPassword.value.length > 0;

                if ((emailChanged || nameChanged || passwordChanged) && currentPassword.value.length === 0) {
                    currentPassword.setCustomValidity('Current password is required to make changes');
                } else {
                    currentPassword.setCustomValidity('');
                }
            }

            newPassword.addEventListener('input', validatePasswords);
            confirmPassword.addEventListener('input', validatePasswords);
            emailInput.addEventListener('input', checkCurrentPasswordRequirement);
            lecturerNameInput.addEventListener('input', checkCurrentPasswordRequirement);
            newPassword.addEventListener('input', checkCurrentPasswordRequirement);
            currentPassword.addEventListener('input', checkCurrentPasswordRequirement);

            // Password strength indicator
            newPassword.addEventListener('input', function() {
                const strength = calculatePasswordStrength(newPassword.value);
                const note = document.querySelector('.password-note');
                if (newPassword.value.length > 0) {
                    note.innerHTML = `Password strength: <strong style="color: ${strength.color}">${strength.text}</strong>`;
                } else {
                    note.innerHTML = 'Minimum 6 characters';
                }
            });

            function calculatePasswordStrength(password) {
                let strength = 0;
                if (password.length >= 6) strength++;
                if (password.length >= 8) strength++;
                if (/[A-Z]/.test(password)) strength++;
                if (/[0-9]/.test(password)) strength++;
                if (/[^A-Za-z0-9]/.test(password)) strength++;

                const levels = [
                    { text: 'Very Weak', color: '#dc3545' },
                    { text: 'Weak', color: '#fd7e14' },
                    { text: 'Fair', color: '#ffc107' },
                    { text: 'Good', color: '#20c997' },
                    { text: 'Strong', color: '#28a745' }
                ];

                return levels[Math.min(strength, levels.length - 1)];
            }
        });
    </script>
</body>
</html>