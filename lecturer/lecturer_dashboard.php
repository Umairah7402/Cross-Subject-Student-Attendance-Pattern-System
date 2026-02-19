<?php
session_start();
include('db_connect.php');

// Check if user is logged in and is a lecturer
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'lecturer') {
    header("Location: lecturer/login.php");
    exit();
}

$lecturer_id = $_SESSION['lecturer_id'];
$lecturer_name = $_SESSION['lecturer_name'];

// Get subjects taught by this lecturer (FIXED: using lecturer_subjects table)
$subjects_sql = "SELECT DISTINCT s.subject_id, s.subject_name 
                 FROM subjects s 
                 JOIN lecturer_subjects ls ON s.subject_id = ls.subject_id 
                 WHERE ls.lecturer_id = ?";
$stmt = $conn->prepare($subjects_sql);
$stmt->bind_param("i", $lecturer_id);
$stmt->execute();
$subjects_result = $stmt->get_result();

// Get at-risk students (below 80% attendance) - FIXED: using enrollments and correct structure
$at_risk_sql = "
    SELECT 
        st.student_id,
        st.name as student_name,
        s.subject_id,
        s.subject_name,
        COUNT(CASE WHEN a.status = 'Present' THEN 1 END) as attended_classes,
        COUNT(a.attendance_id) as total_classes,
        CASE 
            WHEN COUNT(a.attendance_id) > 0 THEN 
                ROUND((COUNT(CASE WHEN a.status = 'Present' THEN 1 END) / COUNT(a.attendance_id)) * 100, 2)
            ELSE 0 
        END as attendance_percentage,
        (SELECT COUNT(*) FROM interventions WHERE student_id = st.student_id) as total_interventions
    FROM students st
    JOIN enrollments e ON st.student_id = e.student_id
    JOIN subjects s ON e.subject_id = s.subject_id
    LEFT JOIN attendance a ON st.student_id = a.student_id AND s.subject_id = a.subject_id
    WHERE s.subject_id IN (SELECT subject_id FROM lecturer_subjects WHERE lecturer_id = ?)
    GROUP BY st.student_id, s.subject_id, s.subject_name
    HAVING attendance_percentage < 80 OR attendance_percentage IS NULL
    ORDER BY attendance_percentage ASC
    LIMIT 10
";
$stmt = $conn->prepare($at_risk_sql);
$stmt->bind_param("i", $lecturer_id);
$stmt->execute();
$at_risk_result = $stmt->get_result();

// Get recent interventions (SIMPLIFIED: Remove CASE statement that was causing error)
$interventions_sql = "
    SELECT 
        i.*, 
        st.name as student_name, 
        s.subject_name,
        l.lecturer_name,
        i.created_by
    FROM interventions i 
    JOIN students st ON i.student_id = st.student_id 
    JOIN subjects s ON i.subject_id = s.subject_id 
    LEFT JOIN lecturers l ON i.created_by = l.lecturer_id
    WHERE i.student_id IN (
        SELECT DISTINCT e.student_id 
        FROM enrollments e 
        JOIN lecturer_subjects ls ON e.subject_id = ls.subject_id 
        WHERE ls.lecturer_id = ?
    )
    ORDER BY i.intervention_date DESC 
    LIMIT 5
";
$stmt = $conn->prepare($interventions_sql);
if ($stmt) {
    $stmt->bind_param("i", $lecturer_id);
    $stmt->execute();
    $interventions_result = $stmt->get_result();
} else {
    error_log("Interventions SQL Error: " . $conn->error);
    $interventions_result = [];
}

// Get statistics for dashboard cards (SIMPLIFIED)
$stats_sql = "
    SELECT 
        COUNT(DISTINCT e.student_id) as total_students,
        COUNT(DISTINCT ls.subject_id) as total_subjects,
        (SELECT COUNT(*) FROM interventions WHERE created_by = ?) as my_interventions
    FROM enrollments e
    JOIN lecturer_subjects ls ON e.subject_id = ls.subject_id
    WHERE ls.lecturer_id = ?
";
$stmt = $conn->prepare($stats_sql);
if ($stmt) {
    $stmt->bind_param("ii", $lecturer_id, $lecturer_id);
    $stmt->execute();
    $stats_result = $stmt->get_result();
    $stats = $stats_result->fetch_assoc();
} else {
    error_log("Stats SQL Error: " . $conn->error);
    $stats = ['total_students' => 0, 'total_subjects' => 0, 'my_interventions' => 0];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lecturer Dashboard - CSSAP</title>
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

        .dashboard-container {
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

        .sidebar-header p {
            font-size: 0.9rem;
            opacity: 0.8;
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

        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
            border-left: 4px solid #28a745;
        }

        .stat-card h3 {
            color: #6c757d;
            font-size: 0.9rem;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: #28a745;
        }

        .dashboard-section {
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
            padding: 8px 16px;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: background 0.3s ease;
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

        .btn-danger {
            background: #dc3545;
        }

        .btn-danger:hover {
            background: #c82333;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
        }

        th {
            background-color: #f8f9fa;
            font-weight: 600;
            color: #495057;
        }

        tr:hover {
            background-color: #f8f9fa;
        }

        .attendance-low {
            color: #dc3545;
            font-weight: bold;
        }

        .attendance-medium {
            color: #ffc107;
            font-weight: bold;
        }

        .attendance-good {
            color: #28a745;
            font-weight: bold;
        }

        .no-data {
            text-align: center;
            color: #6c757d;
            padding: 40px;
            font-style: italic;
        }

        .subject-badge {
            display: inline-block;
            padding: 4px 8px;
            background: #e9ecef;
            border-radius: 15px;
            font-size: 0.8rem;
            margin: 2px;
        }

        .alert-badge {
            background: #dc3545;
            color: white;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 0.7rem;
            margin-left: 5px;
        }

        .intervention-badge {
            background: #17a2b8;
            color: white;
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 0.7rem;
            margin-left: 5px;
        }

        .cross-subject-indicator {
            background: #6f42c1;
            color: white;
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 0.7rem;
            margin-left: 5px;
        }

        .other-lecturer {
            font-style: italic;
            color: #6c757d;
            font-size: 0.9rem;
        }

        .action-buttons {
            display: flex;
            gap: 5px;
        }

        .action-buttons .btn {
            padding: 6px 12px;
            font-size: 0.8rem;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <h2>CSSAP</h2>
                <p>Lecturer Portal</p>
            </div>
            <ul class="nav-links">
                <li><a href="lecturer_dashboard.php" class="active">Dashboard</a></li>
                <li><a href="attendance.php">Mark Attendance</a></li>
                <li><a href="students.php">Student Management</a></li>
                <li><a href="interventions.php">Interventions</a></li>
                <li><a href="reports.php">Reports</a></li>
                <li><a href="profile.php">My Profile</a></li>
            </ul>
            <form method="POST" action="logout.php">
                <button type="submit" class="logout-btn">Logout</button>
            </form>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <div class="header">
                <h1>Lecturer Dashboard</h1>
                <div>
                    <span>Welcome, <strong><?php echo htmlspecialchars($_SESSION['lecturer_name'] ?? 'Lecturer'); ?></strong></span>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="stats-cards">
                <div class="stat-card">
                    <h3>My Subjects</h3>
                    <div class="stat-number"><?php echo $subjects_result->num_rows; ?></div>
                </div>
                <div class="stat-card">
                    <h3>Total Students</h3>
                    <div class="stat-number"><?php echo $stats['total_students'] ?? 0; ?></div>
                </div>
                <div class="stat-card">
                    <h3>My Interventions</h3>
                    <div class="stat-number"><?php echo $stats['my_interventions'] ?? 0; ?></div>
                </div>
                <div class="stat-card">
                    <h3>At-Risk Students</h3>
                    <div class="stat-number"><?php echo $at_risk_result->num_rows; ?></div>
                </div>
            </div>

            <!-- My Subjects Section -->
            <div class="dashboard-section">
                <div class="section-header">
                    <h2>My Subjects</h2>
                </div>
                <?php if ($subjects_result->num_rows > 0): ?>
                    <div class="subjects-list">
                        <?php while ($subject = $subjects_result->fetch_assoc()): ?>
                            <span class="subject-badge"><?php echo htmlspecialchars($subject['subject_name']); ?></span>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="no-data">No subjects assigned</div>
                <?php endif; ?>
            </div>

            <!-- At-Risk Students Section -->
            <div class="dashboard-section">
                <div class="section-header">
                    <h2>At-Risk Students <span class="alert-badge">Attention Required</span></h2>
                    <a href="interventions.php?action=add" class="btn">Add Intervention</a>
                </div>
                <?php if ($at_risk_result->num_rows > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Student Name</th>
                                <th>Subject</th>
                                <th>Classes Attended</th>
                                <th>Total Classes</th>
                                <th>Attendance %</th>
                                <th>Interventions</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($student = $at_risk_result->fetch_assoc()): 
                                $attendance_class = '';
                                if (($student['attendance_percentage'] ?? 0) < 60) {
                                    $attendance_class = 'attendance-low';
                                } elseif (($student['attendance_percentage'] ?? 0) < 80) {
                                    $attendance_class = 'attendance-medium';
                                }
                            ?>
                                <tr>
                                    <td>
                                        <?php echo htmlspecialchars($student['student_name']); ?>
                                        <?php if ($student['total_interventions'] > 0): ?>
                                            <span class="intervention-badge"><?php echo $student['total_interventions']; ?> intv</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($student['subject_name']); ?></td>
                                    <td><?php echo $student['attended_classes'] ?? 0; ?></td>
                                    <td><?php echo $student['total_classes'] ?? 0; ?></td>
                                    <td class="<?php echo $attendance_class; ?>">
                                        <?php echo $student['attendance_percentage'] ?? 0; ?>%
                                    </td>
                                    <td>
                                        <?php if ($student['total_interventions'] > 0): ?>
                                            <span class="cross-subject-indicator" title="Has interventions">🔍</span>
                                        <?php else: ?>
                                            <span style="color: #6c757d;">None</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <!-- Quick Add Intervention -->
                                            <a href="interventions.php?action=add&student_id=<?php echo $student['student_id']; ?>&subject_id=<?php echo $student['subject_id'] ?? ''; ?>" 
                                               class="btn btn-secondary" 
                                               title="Add Intervention">➕</a>
                                            <!-- View Student Details -->
                                            <a href="students.php?view=<?php echo $student['student_id']; ?>" 
                                               class="btn" 
                                               title="View Student">👁️</a>
                                            <!-- View All Interventions for this student -->
                                            <a href="interventions.php?student_id=<?php echo $student['student_id']; ?>" 
                                               class="btn btn-danger" 
                                               title="View Interventions">📋</a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-data">No at-risk students found. Great job!</div>
                <?php endif; ?>
            </div>

            <!-- Recent Interventions Section -->
            <div class="dashboard-section">
                <div class="section-header">
                    <h2>Recent Interventions</h2>
                    <a href="interventions.php" class="btn">View All Interventions</a>
                </div>
                <?php if (is_object($interventions_result) && $interventions_result->num_rows > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Subject</th>
                                <th>Date</th>
                                <th>Lecturer</th>
                                <th>Comments</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($intervention = $interventions_result->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($intervention['student_name']); ?></td>
                                    <td><?php echo htmlspecialchars($intervention['subject_name']); ?></td>
                                    <td><?php echo date('M j, Y', strtotime($intervention['intervention_date'])); ?></td>
                                    <td>
                                        <?php if ($intervention['created_by'] == $lecturer_id): ?>
                                            <strong>You</strong>
                                        <?php else: ?>
                                            <span class="other-lecturer"><?php echo htmlspecialchars($intervention['lecturer_name'] ?? 'Unknown'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars(substr($intervention['comments'] ?? '', 0, 50)) . '...'; ?></td>
                                    <td>
                                        <a href="interventions.php?view=<?php echo $intervention['intervention_id']; ?>" class="btn btn-secondary">View</a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-data">No interventions recorded yet</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // JavaScript for better user experience
        document.addEventListener('DOMContentLoaded', function() {
            // Add confirmation for critical actions
            const interveneButtons = document.querySelectorAll('a[href*="interventions.php?action=add"]');
            interveneButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    if (!confirm('Are you sure you want to add an intervention for this student?')) {
                        e.preventDefault();
                    }
                });
            });

            // Auto-refresh dashboard every 5 minutes
            setTimeout(() => {
                window.location.reload();
            }, 300000); // 5 minutes
        });
    </script>
</body>
</html>