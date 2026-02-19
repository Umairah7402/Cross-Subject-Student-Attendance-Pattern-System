<?php
session_start();
include('db_connect.php');

// Check if user is logged in as admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Initialize variables
$stats = [];
$activities_result = null;
$at_risk_result = null;
$system_info = [];

try {
    // Get system statistics
    $stats_sql = "
        SELECT 
            (SELECT COUNT(*) FROM users) as total_users,
            (SELECT COUNT(*) FROM users WHERE role = 'admin') as admin_count,
            (SELECT COUNT(*) FROM users WHERE role = 'lecturer') as lecturer_count,
            (SELECT COUNT(*) FROM students) as student_count,
            (SELECT COUNT(*) FROM subjects) as subject_count,
            (SELECT COUNT(*) FROM attendance) as attendance_records,
            (SELECT COUNT(*) FROM interventions) as intervention_count,
            (SELECT COUNT(DISTINCT student_id) FROM interventions) as students_with_interventions
    ";

    $stats_result = $conn->query($stats_sql);
    if ($stats_result) {
        $stats = $stats_result->fetch_assoc();
    } else {
        error_log("Stats SQL Error: " . $conn->error);
        $stats = [
            'total_users' => 0,
            'admin_count' => 0,
            'lecturer_count' => 0,
            'student_count' => 0,
            'subject_count' => 0,
            'attendance_records' => 0,
            'intervention_count' => 0,
            'students_with_interventions' => 0
        ];
    }

    // Get recent activities - SIMPLIFIED VERSION
    $activities_sql = "
        SELECT 'user' as type, username as title, 'User registered' as description, created_at as date 
        FROM users 
        ORDER BY created_at DESC 
        LIMIT 5
    ";

    $activities_result = $conn->query($activities_sql);
    if (!$activities_result) {
        error_log("Activities SQL Error: " . $conn->error);
        $activities_result = null;
    }

    // Get at-risk students (SIMPLIFIED VERSION)
    $at_risk_sql = "
        SELECT 
            st.student_id,
            st.name as student_name,
            COUNT(DISTINCT s.subject_id) as subject_count,
            (
                SELECT COUNT(*) 
                FROM attendance a 
                WHERE a.student_id = st.student_id AND a.status = 'Present'
            ) as present_count,
            (
                SELECT COUNT(*) 
                FROM attendance a 
                WHERE a.student_id = st.student_id
            ) as total_classes
        FROM students st
        JOIN subjects s ON st.subject_id = s.subject_id
        GROUP BY st.student_id
        HAVING (present_count * 100.0 / NULLIF(total_classes, 0)) < 80 
            OR total_classes = 0
        ORDER BY (present_count * 100.0 / NULLIF(total_classes, 0)) ASC
        LIMIT 8
    ";

    $at_risk_result = $conn->query($at_risk_sql);
    if (!$at_risk_result) {
        error_log("At-Risk SQL Error: " . $conn->error);
        $at_risk_result = null;
    }

    // Get system health info
    $system_sql = "
        SELECT 
            (SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE()) as new_users_today,
            (SELECT COUNT(*) FROM attendance WHERE DATE(attendance_date) = CURDATE()) as attendance_today,
            (SELECT COUNT(*) FROM interventions WHERE DATE(intervention_date) = CURDATE()) as interventions_today
    ";

    $system_result = $conn->query($system_sql);
    if ($system_result) {
        $system_info = $system_result->fetch_assoc();
    } else {
        error_log("System Info SQL Error: " . $conn->error);
        $system_info = [
            'new_users_today' => 0,
            'attendance_today' => 0,
            'interventions_today' => 0
        ];
    }

} catch (Exception $e) {
    error_log("Dashboard Error: " . $e->getMessage());
    // Set default values to prevent fatal errors
    $stats = $stats ?? [
        'total_users' => 0,
        'admin_count' => 0,
        'lecturer_count' => 0,
        'student_count' => 0,
        'subject_count' => 0,
        'attendance_records' => 0,
        'intervention_count' => 0,
        'students_with_interventions' => 0
    ];
    $system_info = $system_info ?? [
        'new_users_today' => 0,
        'attendance_today' => 0,
        'interventions_today' => 0
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - CSSAP</title>
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
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            text-align: center;
            border-left: 5px solid #3498db;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
        }

        .stat-card.users { border-left-color: #3498db; }
        .stat-card.lecturers { border-left-color: #2ecc71; }
        .stat-card.students { border-left-color: #e74c3c; }
        .stat-card.subjects { border-left-color: #f39c12; }
        .stat-card.attendance { border-left-color: #9b59b6; }
        .stat-card.interventions { border-left-color: #1abc9c; }

        .stat-icon {
            font-size: 2.5rem;
            margin-bottom: 15px;
        }

        .stat-number {
            font-size: 2.2rem;
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

        /* Dashboard Sections */
        .dashboard-section {
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

        .btn-secondary {
            background: linear-gradient(135deg, #95a5a6, #7f8c8d);
        }

        /* Activity and At-Risk Grid */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
        }

        @media (max-width: 1024px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Activity List */
        .activity-list {
            list-style: none;
        }

        .activity-item {
            display: flex;
            align-items: center;
            padding: 15px;
            border-bottom: 1px solid #f1f2f6;
            transition: background-color 0.3s ease;
        }

        .activity-item:hover {
            background-color: #f8f9fa;
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            font-size: 1.1rem;
        }

        .activity-icon.user { background: #3498db; color: white; }

        .activity-content {
            flex: 1;
        }

        .activity-title {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 3px;
        }

        .activity-description {
            color: #7f8c8d;
            font-size: 0.9rem;
        }

        .activity-time {
            color: #95a5a6;
            font-size: 0.8rem;
            text-align: right;
            min-width: 80px;
        }

        /* At-Risk Table */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }

        th {
            background-color: #f8f9fa;
            font-weight: 600;
            color: #2c3e50;
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

        .no-data {
            text-align: center;
            color: #7f8c8d;
            padding: 40px;
            font-style: italic;
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        .action-card {
            background: linear-gradient(135deg, #3498db, #2980b9);
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .action-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(52, 152, 219, 0.4);
        }

        .action-card.lecturers { background: linear-gradient(135deg, #2ecc71, #27ae60); }
        .action-card.students { background: linear-gradient(135deg, #e74c3c, #c0392b); }
        .action-card.reports { background: linear-gradient(135deg, #9b59b6, #8e44ad); }

        .action-icon {
            font-size: 2rem;
            margin-bottom: 10px;
        }

        .action-title {
            font-weight: 600;
            font-size: 1.1rem;
        }

        /* System Health */
        .system-health {
            background: linear-gradient(135deg, #34495e, #2c3e50);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-top: 20px;
        }

        .health-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            text-align: center;
        }

        .health-stat .number {
            font-size: 1.8rem;
            font-weight: bold;
            color: #3498db;
        }

        .health-stat .label {
            font-size: 0.9rem;
            opacity: 0.8;
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
                <li><a href="index.php" class="active">📊 Dashboard</a></li>
                <li><a href="users.php">👥 User Management</a></li>
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
                <h1>Admin Dashboard</h1>
                <div class="user-welcome">
                    <div class="name">Welcome, <?php echo htmlspecialchars($_SESSION['username'] ?? 'Admin'); ?></div>
                    <div class="role">Administrator</div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="quick-actions">
                <a href="register.php" class="action-card">
                    <div class="action-icon">👥</div>
                    <div class="action-title">Add New User</div>
                </a>
                <a href="lecturers.php" class="action-card lecturers">
                    <div class="action-icon">👨‍🏫</div>
                    <div class="action-title">Manage Lecturers</div>
                </a>
                <a href="students.php" class="action-card students">
                    <div class="action-icon">🎓</div>
                    <div class="action-title">Manage Students</div>
                </a>
                <a href="reports.php" class="action-card reports">
                    <div class="action-icon">📈</div>
                    <div class="action-title">View Reports</div>
                </a>
            </div>

            <!-- Statistics Grid -->
            <div class="stats-grid">
                <div class="stat-card users">
                    <div class="stat-icon">👥</div>
                    <div class="stat-number"><?php echo $stats['total_users'] ?? 0; ?></div>
                    <div class="stat-label">Total Users</div>
                </div>
                <div class="stat-card lecturers">
                    <div class="stat-icon">👨‍🏫</div>
                    <div class="stat-number"><?php echo $stats['lecturer_count'] ?? 0; ?></div>
                    <div class="stat-label">Lecturers</div>
                </div>
                <div class="stat-card students">
                    <div class="stat-icon">🎓</div>
                    <div class="stat-number"><?php echo $stats['student_count'] ?? 0; ?></div>
                    <div class="stat-label">Students</div>
                </div>
                <div class="stat-card subjects">
                    <div class="stat-icon">📚</div>
                    <div class="stat-number"><?php echo $stats['subject_count'] ?? 0; ?></div>
                    <div class="stat-label">Subjects</div>
                </div>
                <div class="stat-card attendance">
                    <div class="stat-icon">📝</div>
                    <div class="stat-number"><?php echo $stats['attendance_records'] ?? 0; ?></div>
                    <div class="stat-label">Attendance Records</div>
                </div>
                <div class="stat-card interventions">
                    <div class="stat-icon">🔄</div>
                    <div class="stat-number"><?php echo $stats['intervention_count'] ?? 0; ?></div>
                    <div class="stat-label">Interventions</div>
                </div>
            </div>

            <div class="dashboard-grid">
                <!-- Recent Activities -->
                <div class="dashboard-section">
                    <div class="section-header">
                        <h2>Recent Activities</h2>
                        <a href="reports.php" class="btn">View All</a>
                    </div>
                    <?php if ($activities_result && $activities_result->num_rows > 0): ?>
                        <ul class="activity-list">
                            <?php while ($activity = $activities_result->fetch_assoc()): ?>
                                <li class="activity-item">
                                    <div class="activity-icon user">
                                        👥
                                    </div>
                                    <div class="activity-content">
                                        <div class="activity-title"><?php echo htmlspecialchars($activity['title']); ?></div>
                                        <div class="activity-description"><?php echo htmlspecialchars($activity['description']); ?></div>
                                    </div>
                                    <div class="activity-time">
                                        <?php echo date('M j', strtotime($activity['date'])); ?>
                                    </div>
                                </li>
                            <?php endwhile; ?>
                        </ul>
                    <?php else: ?>
                        <div class="no-data">No recent activities found.</div>
                    <?php endif; ?>
                </div>

                <!-- At-Risk Students -->
                <div class="dashboard-section">
                    <div class="section-header">
                        <h2>At-Risk Students</h2>
                        <a href="reports.php" class="btn">View Report</a>
                    </div>
                    <?php if ($at_risk_result && $at_risk_result->num_rows > 0): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Student Name</th>
                                    <th>Subjects</th>
                                    <th>Present/Total</th>
                                    <th>Attendance %</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($student = $at_risk_result->fetch_assoc()): 
                                    $present_count = $student['present_count'] ?? 0;
                                    $total_classes = $student['total_classes'] ?? 0;
                                    $attendance_percentage = $total_classes > 0 ? round(($present_count / $total_classes) * 100, 2) : 0;
                                    $attendance_class = '';
                                    if ($attendance_percentage < 60) {
                                        $attendance_class = 'attendance-critical';
                                    } elseif ($attendance_percentage < 80) {
                                        $attendance_class = 'attendance-warning';
                                    }
                                ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($student['student_name']); ?></td>
                                        <td><?php echo $student['subject_count']; ?> subjects</td>
                                        <td><?php echo $present_count; ?>/<?php echo $total_classes; ?></td>
                                        <td class="<?php echo $attendance_class; ?>">
                                            <?php echo $attendance_percentage; ?>%
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="no-data">No at-risk students found.</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- System Health -->
            <div class="system-health">
                <div class="section-header" style="border-bottom-color: rgba(255,255,255,0.2);">
                    <h2 style="color: white;">Today's Activity</h2>
                </div>
                <div class="health-stats">
                    <div class="health-stat">
                        <div class="number"><?php echo $system_info['new_users_today'] ?? 0; ?></div>
                        <div class="label">New Users</div>
                    </div>
                    <div class="health-stat">
                        <div class="number"><?php echo $system_info['attendance_today'] ?? 0; ?></div>
                        <div class="label">Attendance Records</div>
                    </div>
                    <div class="health-stat">
                        <div class="number"><?php echo $system_info['interventions_today'] ?? 0; ?></div>
                        <div class="label">Interventions</div>
                    </div>
                    <div class="health-stat">
                        <div class="number"><?php echo $stats['students_with_interventions'] ?? 0; ?></div>
                        <div class="label">Students Helped</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>