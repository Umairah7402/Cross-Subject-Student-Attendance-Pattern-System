<?php
session_start();
include('db_connect.php');

// Check if user is logged in and is a lecturer
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'lecturer') {
    header("Location: login.php");
    exit();
}

$lecturer_id = $_SESSION['lecturer_id'];
$message = '';
$message_class = '';

// Handle URL parameters for auto-selection
$auto_student_id = $_GET['student_id'] ?? '';
$auto_subject_id = $_GET['subject_id'] ?? '';
$auto_action = $_GET['action'] ?? '';

// Define common intervention reasons
$common_reasons = [
    'health' => 'Health Issues',
    'transport' => 'Transport Problems',
    'family' => 'Family Matters',
    'financial' => 'Financial Difficulties',
    'academic' => 'Academic Struggles',
    'motivation' => 'Lack of Motivation',
    'personal' => 'Personal Issues',
    'work' => 'Work Commitments',
    'technical' => 'Technical Problems',
    'unknown' => 'Unknown Reasons'
];

// Handle form submission for new intervention
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_intervention'])) {
    $student_id = $_POST['student_id'];
    $subject_id = $_POST['subject_id'];
    $intervention_date = $_POST['intervention_date'];
    
    // Build comments from quick reasons and custom text
    $quick_reasons = isset($_POST['quick_reasons']) ? $_POST['quick_reasons'] : [];
    $custom_comments = trim($_POST['custom_comments'] ?? '');
    
    $comments_parts = [];
    
    // Add quick reasons
    if (!empty($quick_reasons)) {
        $reason_texts = [];
        foreach ($quick_reasons as $reason) {
            if (isset($common_reasons[$reason])) {
                $reason_texts[] = $common_reasons[$reason];
            }
        }
        if (!empty($reason_texts)) {
            $comments_parts[] = "Identified Issues: " . implode(', ', $reason_texts);
        }
    }
    
    // Add custom comments
    if (!empty($custom_comments)) {
        $comments_parts[] = "Additional Notes: " . $custom_comments;
    }
    
    // Add cross-subject context if available
    $cross_subject_note = $_POST['cross_subject_note'] ?? '';
    if (!empty($cross_subject_note)) {
        $comments_parts[] = "Cross-Subject Context: " . $cross_subject_note;
    }
    
    // Combine all parts
    $comments = implode("\n\n", $comments_parts);
    
    // Validate the lecturer has access to this subject
    $verify_sql = "SELECT ls.subject_id FROM lecturer_subjects ls WHERE ls.lecturer_id = ? AND ls.subject_id = ?";
    $verify_stmt = $conn->prepare($verify_sql);
    if ($verify_stmt) {
        $verify_stmt->bind_param("ii", $lecturer_id, $subject_id);
        $verify_stmt->execute();
        $verify_result = $verify_stmt->get_result();
        
        if ($verify_result->num_rows > 0) {
            // Use existing database structure without new columns
            $sql = "INSERT INTO interventions (student_id, subject_id, intervention_date, comments, created_by) 
                    VALUES (?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param("iissi", $student_id, $subject_id, $intervention_date, $comments, $lecturer_id);

                if ($stmt->execute()) {
                    $message = "Intervention recorded successfully!";
                    $message_class = "success";
                    
                    // Clear auto-selection after successful submission
                    $auto_student_id = '';
                    $auto_subject_id = '';
                    $auto_action = '';
                } else {
                    $message = "Error recording intervention: " . $stmt->error;
                    $message_class = "error";
                }
                $stmt->close();
            } else {
                $message = "Database error: " . $conn->error;
                $message_class = "error";
            }
        } else {
            $message = "Access denied: You are not assigned to this subject!";
            $message_class = "error";
        }
        $verify_stmt->close();
    } else {
        $message = "Database error: " . $conn->error;
        $message_class = "error";
    }
}

// Handle intervention deletion
if (isset($_GET['delete_id'])) {
    $delete_id = $_GET['delete_id'];
    
    // Verify the intervention belongs to this lecturer
    $verify_sql = "SELECT intervention_id FROM interventions WHERE intervention_id = ? AND created_by = ?";
    $verify_stmt = $conn->prepare($verify_sql);
    if ($verify_stmt) {
        $verify_stmt->bind_param("ii", $delete_id, $lecturer_id);
        $verify_stmt->execute();
        $verify_result = $verify_stmt->get_result();
        
        if ($verify_result->num_rows > 0) {
            $delete_sql = "DELETE FROM interventions WHERE intervention_id = ?";
            $delete_stmt = $conn->prepare($delete_sql);
            if ($delete_stmt) {
                $delete_stmt->bind_param("i", $delete_id);
                
                if ($delete_stmt->execute()) {
                    $message = "Intervention deleted successfully!";
                    $message_class = "success";
                } else {
                    $message = "Error deleting intervention: " . $delete_stmt->error;
                    $message_class = "error";
                }
                $delete_stmt->close();
            } else {
                $message = "Database error: " . $conn->error;
                $message_class = "error";
            }
        } else {
            $message = "Intervention not found or access denied!";
            $message_class = "error";
        }
        $verify_stmt->close();
    } else {
        $message = "Database error: " . $conn->error;
        $message_class = "error";
    }
}

// Get filter parameters
$filter_student = $_GET['student_id'] ?? $auto_student_id; // Auto-apply student filter if coming from dashboard
$filter_subject = $_GET['subject_id'] ?? '';
$filter_reason = $_GET['reason'] ?? '';

// Build filter conditions
$filter_conditions = [];
$filter_params = [];
$filter_types = "";

if (!empty($filter_student)) {
    $filter_conditions[] = "i.student_id = ?";
    $filter_params[] = $filter_student;
    $filter_types .= "i";
}

if (!empty($filter_subject)) {
    $filter_conditions[] = "i.subject_id = ?";
    $filter_params[] = $filter_subject;
    $filter_types .= "i";
}

if (!empty($filter_reason)) {
    $filter_conditions[] = "i.comments LIKE ?";
    $filter_params[] = "%{$common_reasons[$filter_reason]}%";
    $filter_types .= "s";
}

// Get interventions for this lecturer with filters
$interventions_sql = "
    SELECT i.*, st.name as student_name, s.subject_name, 
           l.lecturer_name as created_by_name,
           (SELECT COUNT(*) FROM interventions i2 
            WHERE i2.student_id = i.student_id 
            AND i2.intervention_date < i.intervention_date) as previous_interventions
    FROM interventions i 
    JOIN students st ON i.student_id = st.student_id 
    JOIN subjects s ON i.subject_id = s.subject_id 
    JOIN lecturers l ON i.created_by = l.lecturer_id
    WHERE i.subject_id IN (
        SELECT ls.subject_id 
        FROM lecturer_subjects ls 
        WHERE ls.lecturer_id = ?
    ) 
";

if (!empty($filter_conditions)) {
    $interventions_sql .= " AND " . implode(" AND ", $filter_conditions);
}

$interventions_sql .= " ORDER BY i.intervention_date DESC, i.intervention_id DESC";

$interventions_stmt = $conn->prepare($interventions_sql);
if ($interventions_stmt) {
    if (!empty($filter_params)) {
        $interventions_stmt->bind_param("i" . $filter_types, $lecturer_id, ...$filter_params);
    } else {
        $interventions_stmt->bind_param("i", $lecturer_id);
    }
    $interventions_stmt->execute();
    $interventions_result = $interventions_stmt->get_result();
} else {
    $message = "Database error: " . $conn->error;
    $message_class = "error";
    $interventions_result = false;
}

// Get at-risk students for dropdown (cross-subject view)
$at_risk_sql = "
    SELECT DISTINCT 
        st.student_id, 
        st.name as student_name,
        s.subject_id, 
        s.subject_name,
        COUNT(a.attendance_id) as total_classes,
        SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as attended_classes,
        ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) / NULLIF(COUNT(a.attendance_id), 0)) * 100, 2) as attendance_percentage,
        (SELECT COUNT(*) FROM interventions i2 WHERE i2.student_id = st.student_id) as total_interventions,
        (SELECT COUNT(DISTINCT e2.subject_id) 
         FROM enrollments e2 
         WHERE e2.student_id = st.student_id) as total_subjects_enrolled
    FROM students st
    JOIN enrollments e ON st.student_id = e.student_id
    JOIN subjects s ON e.subject_id = s.subject_id
    LEFT JOIN attendance a ON st.student_id = a.student_id AND s.subject_id = a.subject_id
    WHERE s.subject_id IN (
        SELECT ls.subject_id 
        FROM lecturer_subjects ls 
        WHERE ls.lecturer_id = ?
    )
    GROUP BY st.student_id, st.name, s.subject_id, s.subject_name
    HAVING attendance_percentage < 80 OR attendance_percentage IS NULL
    ORDER BY attendance_percentage ASC, total_interventions DESC
";

$at_risk_stmt = $conn->prepare($at_risk_sql);
if ($at_risk_stmt) {
    $at_risk_stmt->bind_param("i", $lecturer_id);
    $at_risk_stmt->execute();
    $at_risk_result = $at_risk_stmt->get_result();
} else {
    $at_risk_result = false;
    $message = "Database error in at-risk query: " . $conn->error;
    $message_class = "error";
}

// Get students for filter dropdown
$students_sql = "
    SELECT DISTINCT st.student_id, st.name 
    FROM students st
    JOIN enrollments e ON st.student_id = e.student_id
    JOIN subjects s ON e.subject_id = s.subject_id
    WHERE s.subject_id IN (SELECT subject_id FROM lecturer_subjects WHERE lecturer_id = ?)
    ORDER BY st.name
";
$students_stmt = $conn->prepare($students_sql);
if ($students_stmt) {
    $students_stmt->bind_param("i", $lecturer_id);
    $students_stmt->execute();
    $students_result = $students_stmt->get_result();
} else {
    $students_result = false;
}

// Get subjects for filter dropdown
$subjects_sql = "
    SELECT DISTINCT s.subject_id, s.subject_name 
    FROM subjects s
    JOIN lecturer_subjects ls ON s.subject_id = ls.subject_id
    WHERE ls.lecturer_id = ?
    ORDER BY s.subject_name
";
$subjects_stmt = $conn->prepare($subjects_sql);
if ($subjects_stmt) {
    $subjects_stmt->bind_param("i", $lecturer_id);
    $subjects_stmt->execute();
    $subjects_result = $subjects_stmt->get_result();
} else {
    $subjects_result = false;
}

// Get intervention statistics
$stats_sql = "
    SELECT 
        COUNT(*) as total_interventions,
        COUNT(DISTINCT student_id) as unique_students,
        0 as pending_follow_ups,
        COUNT(CASE WHEN intervention_date = CURDATE() THEN 1 END) as today_interventions
    FROM interventions 
    WHERE created_by = ?
";
$stats_stmt = $conn->prepare($stats_sql);
if ($stats_stmt) {
    $stats_stmt->bind_param("i", $lecturer_id);
    $stats_stmt->execute();
    $stats_result = $stats_stmt->get_result();
    $stats = $stats_result->fetch_assoc();
} else {
    $stats = ['total_interventions' => 0, 'unique_students' => 0, 'pending_follow_ups' => 0, 'today_interventions' => 0];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Interventions - CSSAP</title>
    <style>
        /* Enhanced CSS with quick reasons features */
        .quick-reasons-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
            margin-bottom: 20px;
        }

        .reason-checkbox {
            display: none;
        }

        .reason-label {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            background: #f8f9fa;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 14px;
        }

        .reason-label:hover {
            background: #e9ecef;
            border-color: #28a745;
        }

        .reason-checkbox:checked + .reason-label {
            background: #d4edda;
            border-color: #28a745;
            color: #155724;
        }

        .reason-emoji {
            margin-right: 0;
            font-size: 0;
        }

        .intervention-preview {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
            display: none;
        }

        .intervention-preview.active {
            display: block;
        }

        .preview-title {
            font-weight: bold;
            margin-bottom: 10px;
            color: #495057;
        }

        .preview-content {
            white-space: pre-line;
            line-height: 1.5;
        }

        .quick-actions {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }

        .quick-action-btn {
            padding: 8px 15px;
            background: #6c757d;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 12px;
            transition: background 0.3s ease;
        }

        .quick-action-btn:hover {
            background: #545b62;
        }

        .reason-tag {
            display: inline-block;
            padding: 4px 8px;
            background: #e7f3ff;
            color: #0056b3;
            border-radius: 12px;
            font-size: 0.8rem;
            margin: 2px;
        }

        .intervention-reasons {
            margin-bottom: 10px;
        }

        .comments-section {
            margin-top: 20px;
        }

        .cross-subject-note {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 5px;
            padding: 10px;
            margin-top: 10px;
            font-size: 0.9rem;
        }

        .multi-subject-badge {
            background: #0056b3;
            color: white;
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 0.7rem;
            margin-left: 5px;
        }

        /* Rest of your existing CSS remains the same */
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

        .btn-danger {
            background: #dc3545;
        }

        .btn-danger:hover {
            background: #c82333;
        }

        .btn-sm {
            padding: 5px 10px;
            font-size: 12px;
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

        select, input[type="date"], textarea {
            width: 100%;
            padding: 10px;
            border: 2px solid #e9ecef;
            border-radius: 5px;
            font-size: 16px;
            transition: border-color 0.3s ease;
        }

        textarea {
            height: 120px;
            resize: vertical;
        }

        select:focus, input[type="date"]:focus, textarea:focus {
            outline: none;
            border-color: #28a745;
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
            padding: 40px;
            font-style: italic;
        }

        .action-buttons {
            display: flex;
            gap: 5px;
        }

        .student-info {
            background: #e7f3ff;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 15px;
            border-left: 4px solid #007bff;
        }

        .student-info h4 {
            margin-bottom: 5px;
            color: #0056b3;
        }

        .filter-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #dee2e6;
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            align-items: end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-buttons {
            display: flex;
            gap: 10px;
        }

        .intervention-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 15px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border-left: 4px solid #007bff;
        }

        .intervention-card.cross-subject {
            border-left-color: #dc3545;
            background: #fff5f5;
        }

        .intervention-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
        }

        .intervention-meta {
            color: #6c757d;
            font-size: 0.9em;
        }

        .cross-subject-alert {
            background: #f8d7da;
            color: #721c24;
            padding: 8px 12px;
            border-radius: 4px;
            margin: 10px 0;
            font-size: 0.9em;
        }

        .intervention-comments {
            line-height: 1.5;
            margin: 10px 0;
            white-space: pre-line;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .stat-number {
            font-size: 2em;
            font-weight: bold;
            color: #28a745;
        }

        .stat-label {
            color: #6c757d;
            font-size: 0.9em;
        }
        
        .auto-selection-notice {
            background: #d1ecf1;
            color: #0c5460;
            padding: 10px 15px;
            border-radius: 5px;
            margin-bottom: 15px;
            border-left: 4px solid #17a2b8;
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
                <li><a href="interventions.php" class="active">Interventions</a></li>
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
                <h1>Student Interventions</h1>
                <div>
                    <span>Welcome, <strong><?php echo htmlspecialchars($_SESSION['lecturer_name']); ?></strong></span>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="message <?php echo $message_class; ?>">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['total_interventions']; ?></div>
                    <div class="stat-label">Total Interventions</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['unique_students']; ?></div>
                    <div class="stat-label">Students Assisted</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['pending_follow_ups']; ?></div>
                    <div class="stat-label">Pending Follow-ups</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['today_interventions']; ?></div>
                    <div class="stat-label">Today's Interventions</div>
                </div>
            </div>

            <!-- Add Intervention Form -->
            <div class="content-section">
                <div class="section-header">
                    <h2>Record New Intervention</h2>
                    <span class="btn btn-secondary" onclick="clearForm()">Clear Form</span>
                </div>
                
                <?php if (!empty($auto_student_id) && $auto_action === 'add'): ?>
                    <div class="auto-selection-notice">
                        ✅ Student pre-selected from dashboard. The form is ready for you to add intervention details.
                    </div>
                <?php endif; ?>
                
                <form method="POST" id="interventionForm">
                    <div class="form-group">
                        <label for="student_id">Select Student:</label>
                        <select name="student_id" id="student_id" required onchange="updateStudentInfo()">
                            <option value="">-- Select Student --</option>
                            <?php if ($at_risk_result): ?>
                                <?php while ($student = $at_risk_result->fetch_assoc()): 
                                    $is_selected = ($student['student_id'] == $auto_student_id && $student['subject_id'] == $auto_subject_id);
                                ?>
                                    <option value="<?php echo $student['student_id']; ?>" 
                                            data-subject="<?php echo $student['subject_id']; ?>"
                                            data-attendance="<?php echo $student['attendance_percentage']; ?>"
                                            data-total-interventions="<?php echo $student['total_interventions']; ?>"
                                            data-total-subjects="<?php echo $student['total_subjects_enrolled']; ?>"
                                            <?php echo $is_selected ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($student['student_name']); ?> 
                                        - <?php echo htmlspecialchars($student['subject_name']); ?>
                                        (<?php echo $student['attendance_percentage'] ?? 0; ?>%)
                                        <?php if ($student['total_interventions'] > 0): ?>
                                            [<?php echo $student['total_interventions']; ?> prev]
                                        <?php endif; ?>
                                        <?php if ($student['total_subjects_enrolled'] > 1): ?>
                                            <span class="multi-subject-badge"><?php echo $student['total_subjects_enrolled']; ?> subs</span>
                                        <?php endif; ?>
                                    </option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                        <div id="studentInfo" class="student-info" style="display: none;">
                            <h4>Student Overview</h4>
                            <div id="studentDetails"></div>
                        </div>
                    </div>

                    <input type="hidden" name="subject_id" id="subject_id">
                    <input type="hidden" name="cross_subject_note" id="cross_subject_note">

                    <div class="form-group">
                        <label for="intervention_date">Intervention Date:</label>
                        <input type="date" name="intervention_date" id="intervention_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>

                    <!-- Quick Intervention Reasons -->
                    <div class="form-group">
                        <label>Quick Intervention Reasons (Click to select):</label>
                        <div class="quick-reasons-grid">
                            <?php foreach ($common_reasons as $key => $reason): ?>
                                <div>
                                    <input type="checkbox" name="quick_reasons[]" value="<?php echo $key; ?>" 
                                           id="reason_<?php echo $key; ?>" class="reason-checkbox">
                                    <label for="reason_<?php echo $key; ?>" class="reason-label">
                                        <?php echo $reason; ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Custom Comments -->
                    <div class="form-group comments-section">
                        <label for="custom_comments">Additional Notes (Optional):</label>
                        <textarea name="custom_comments" id="custom_comments" 
                                  placeholder="Add any additional details, specific observations, or follow-up plans..."></textarea>
                    </div>

                    <!-- Intervention Preview -->
                    <div class="intervention-preview" id="interventionPreview">
                        <div class="preview-title">Intervention Preview:</div>
                        <div class="preview-content" id="previewContent"></div>
                    </div>

                    <button type="submit" name="add_intervention" class="btn">Record Intervention</button>
                    <button type="button" class="btn btn-secondary" onclick="clearForm()">Clear Form</button>
                </form>
            </div>

            <!-- Filters -->
            <div class="filter-section">
                <form method="GET" class="filter-form">
                    <div class="filter-group">
                        <label>Filter by Student:</label>
                        <select name="student_id">
                            <option value="">All Students</option>
                            <?php if ($students_result): ?>
                                <?php while ($student = $students_result->fetch_assoc()): ?>
                                    <option value="<?php echo $student['student_id']; ?>" <?php echo $filter_student == $student['student_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($student['name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label>Filter by Subject:</label>
                        <select name="subject_id">
                            <option value="">All Subjects</option>
                            <?php if ($subjects_result): ?>
                                <?php while ($subject = $subjects_result->fetch_assoc()): ?>
                                    <option value="<?php echo $subject['subject_id']; ?>" <?php echo $filter_subject == $subject['subject_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($subject['subject_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Filter by Reason:</label>
                        <select name="reason">
                            <option value="">All Reasons</option>
                            <?php foreach ($common_reasons as $key => $reason): ?>
                                <option value="<?php echo $key; ?>" <?php echo $filter_reason == $key ? 'selected' : ''; ?>>
                                    <?php echo $reason; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-buttons">
                        <button type="submit" class="btn">Apply Filters</button>
                        <a href="interventions.php" class="btn btn-secondary">Clear</a>
                    </div>
                </form>
            </div>

            <!-- Interventions List -->
            <div class="content-section">
                <div class="section-header">
                    <h2>Intervention History</h2>
                    <span class="btn btn-secondary">
                        Total: <?php echo ($interventions_result && $interventions_result->num_rows) ? $interventions_result->num_rows : 0; ?>
                    </span>
                </div>

                <?php if (!empty($filter_student)): ?>
                    <div class="auto-selection-notice">
                        🔍 Showing interventions for selected student only. 
                        <a href="interventions.php" style="color: #0c5460; text-decoration: underline;">Show all interventions</a>
                    </div>
                <?php endif; ?>

                <?php if ($interventions_result && $interventions_result->num_rows > 0): ?>
                    <div id="interventionsList">
                        <?php while ($intervention = $interventions_result->fetch_assoc()): 
                            $is_cross_subject = $intervention['previous_interventions'] > 0;
                            
                            // Extract reasons from comments
                            $reasons = [];
                            foreach ($common_reasons as $key => $reason) {
                                if (strpos($intervention['comments'], $reason) !== false) {
                                    $reasons[] = $key;
                                }
                            }
                        ?>
                            <div class="intervention-card <?php echo $is_cross_subject ? 'cross-subject' : ''; ?>">
                                <div class="intervention-header">
                                    <div>
                                        <h4><?php echo htmlspecialchars($intervention['student_name']); ?> 
                                            - <?php echo htmlspecialchars($intervention['subject_name']); ?></h4>
                                        <div class="intervention-meta">
                                            <?php echo date('M j, Y', strtotime($intervention['intervention_date'])); ?> 
                                            • By <?php echo htmlspecialchars($intervention['created_by_name']); ?>
                                            <?php if ($intervention['previous_interventions'] > 0): ?>
                                                • <?php echo $intervention['previous_interventions']; ?> previous interventions
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <?php if ($is_cross_subject): ?>
                                    <div class="cross-subject-alert">
                                        ⚠️ This student has multiple interventions across subjects
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($reasons)): ?>
                                    <div class="intervention-reasons">
                                        <?php foreach ($reasons as $reason): ?>
                                            <span class="reason-tag"><?php echo $common_reasons[$reason]; ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <div class="intervention-comments">
                                    <?php echo nl2br(htmlspecialchars($intervention['comments'])); ?>
                                </div>

                                <div class="action-buttons" style="margin-top: 10px;">
                                    <?php if ($intervention['created_by'] == $lecturer_id): ?>
                                        <a href="interventions.php?delete_id=<?php echo $intervention['intervention_id']; ?>" 
                                           class="btn btn-danger btn-sm" 
                                           onclick="return confirm('Are you sure you want to delete this intervention?')">Delete</a>
                                    <?php else: ?>
                                        <span class="btn btn-secondary btn-sm" style="opacity: 0.6;">Created by <?php echo htmlspecialchars($intervention['created_by_name']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="no-data">No interventions found matching your criteria.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        function updateStudentInfo() {
            const studentSelect = document.getElementById('student_id');
            const subjectInput = document.getElementById('subject_id');
            const crossSubjectNote = document.getElementById('cross_subject_note');
            const studentInfo = document.getElementById('studentInfo');
            const studentDetails = document.getElementById('studentDetails');
            
            const selectedOption = studentSelect.options[studentSelect.selectedIndex];
            if (selectedOption.value) {
                const subjectId = selectedOption.getAttribute('data-subject');
                const attendance = selectedOption.getAttribute('data-attendance');
                const totalInterventions = selectedOption.getAttribute('data-total-interventions');
                const totalSubjects = selectedOption.getAttribute('data-total-subjects');
                
                subjectInput.value = subjectId;
                
                let details = `Attendance: <strong>${attendance}%</strong>`;
                if (totalInterventions > 0) {
                    details += ` | Previous Interventions: <strong>${totalInterventions}</strong>`;
                }
                if (totalSubjects > 1) {
                    details += ` | Enrolled in <strong>${totalSubjects}</strong> subjects`;
                    crossSubjectNote.value = `Student enrolled in ${totalSubjects} subjects - consider cross-subject pattern`;
                } else {
                    crossSubjectNote.value = '';
                }
                if (attendance < 80) {
                    details += ' | <span style="color: #dc3545;">⚠️ Below 80% threshold</span>';
                }
                
                studentDetails.innerHTML = details;
                studentInfo.style.display = 'block';
            } else {
                studentInfo.style.display = 'none';
                crossSubjectNote.value = '';
            }
            updatePreview();
        }

        function updatePreview() {
            const quickReasons = document.querySelectorAll('.reason-checkbox:checked');
            const customComments = document.getElementById('custom_comments').value;
            const preview = document.getElementById('previewContent');
            const previewContainer = document.getElementById('interventionPreview');
            
            let previewText = '';
            
            // Add quick reasons
            if (quickReasons.length > 0) {
                const reasonLabels = Array.from(quickReasons).map(checkbox => {
                    return checkbox.nextElementSibling.textContent.trim();
                });
                previewText += "Identified Issues: " + reasonLabels.join(', ') + "\n\n";
            }
            
            // Add custom comments
            if (customComments) {
                previewText += "Additional Notes: " + customComments + "\n\n";
            }
            
            // Add cross-subject note if available
            const crossSubjectNote = document.getElementById('cross_subject_note').value;
            if (crossSubjectNote) {
                previewText += "Cross-Subject Context: " + crossSubjectNote;
            }
            
            if (previewText) {
                preview.textContent = previewText;
                previewContainer.classList.add('active');
            } else {
                previewContainer.classList.remove('active');
            }
        }

        function clearForm() {
            document.getElementById('interventionForm').reset();
            document.getElementById('studentInfo').style.display = 'none';
            document.getElementById('interventionPreview').classList.remove('active');
            document.getElementById('cross_subject_note').value = '';
            updatePreview();
        }

        // Add event listeners for real-time preview
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize student info and auto-select if coming from dashboard
            updateStudentInfo();
            
            // Auto-scroll to form if coming from dashboard with add action
            <?php if (!empty($auto_student_id) && $auto_action === 'add'): ?>
                document.querySelector('.content-section').scrollIntoView({ 
                    behavior: 'smooth' 
                });
            <?php endif; ?>
            
            // Add event listeners to all reason checkboxes
            document.querySelectorAll('.reason-checkbox').forEach(checkbox => {
                checkbox.addEventListener('change', updatePreview);
            });
            
            // Add event listener to custom comments
            document.getElementById('custom_comments').addEventListener('input', updatePreview);
            
            // Add quick action buttons functionality
            const quickActions = document.createElement('div');
            quickActions.className = 'quick-actions';
            quickActions.innerHTML = `
                <button type="button" class="quick-action-btn" onclick="selectCommonReasons(['health', 'academic'])">Health & Academic</button>
                <button type="button" class="quick-action-btn" onclick="selectCommonReasons(['transport', 'financial'])">Transport & Financial</button>
                <button type="button" class="quick-action-btn" onclick="selectCommonReasons(['motivation', 'personal'])">Motivation & Personal</button>
                <button type="button" class="quick-action-btn" onclick="clearReasons()">Clear All Reasons</button>
            `;
            
            const reasonsSection = document.querySelector('.quick-reasons-grid').parentNode;
            reasonsSection.insertBefore(quickActions, document.querySelector('.quick-reasons-grid'));
        });

        function selectCommonReasons(reasons) {
            // Clear all first
            clearReasons();
            
            // Select the specified reasons
            reasons.forEach(reason => {
                const checkbox = document.getElementById('reason_' + reason);
                if (checkbox) {
                    checkbox.checked = true;
                }
            });
            updatePreview();
        }

        function clearReasons() {
            document.querySelectorAll('.reason-checkbox').forEach(checkbox => {
                checkbox.checked = false;
            });
            updatePreview();
        }

        // Auto-fill common phrases when typing
        document.getElementById('custom_comments').addEventListener('input', function(e) {
            const text = e.target.value.toLowerCase();
            const commonPhrases = {
                'contacted': '📞 Contacted student via ',
                'emailed': '📧 Emailed student regarding ',
                'meeting': '🤝 Scheduled meeting to discuss ',
                'referred': '🔗 Referred to academic advisor for ',
                'follow up': '🔄 Follow up required for '
            };
            
            for (const [phrase, replacement] of Object.entries(commonPhrases)) {
                if (text.includes(phrase) && !text.includes(replacement)) {
                    const startPos = e.target.selectionStart;
                    const endPos = e.target.selectionEnd;
                    const newText = e.target.value.replace(new RegExp(phrase, 'gi'), replacement);
                    e.target.value = newText;
                    
                    // Restore cursor position
                    e.target.setSelectionRange(startPos + (replacement.length - phrase.length), endPos + (replacement.length - phrase.length));
                    updatePreview();
                    break;
                }
            }
        });
    </script>
</body>
</html>