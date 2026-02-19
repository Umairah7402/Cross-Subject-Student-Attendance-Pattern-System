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

// Get subjects taught by this lecturer (FIXED: using lecturer_subjects table)
$subjects_sql = "SELECT DISTINCT s.subject_id, s.subject_name 
                 FROM subjects s 
                 JOIN lecturer_subjects ls ON s.subject_id = ls.subject_id 
                 WHERE ls.lecturer_id = ?";
$stmt = $conn->prepare($subjects_sql);
$stmt->bind_param("i", $lecturer_id);
$stmt->execute();
$subjects_result = $stmt->get_result();

// Get filter parameters
$subject_id = $_GET['subject_id'] ?? '';
$search_term = $_GET['search'] ?? '';

// Get students with their attendance data (FIXED: using enrollments and cross-subject interventions)
$students_sql = "
    SELECT 
        st.student_id,
        st.name as student_name,
        s.subject_id,
        s.subject_name,
        COUNT(a.attendance_id) as total_classes,
        SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) as attended_classes,
        CASE 
            WHEN COUNT(a.attendance_id) > 0 THEN 
                ROUND((SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) / COUNT(a.attendance_id)) * 100, 2)
            ELSE 0 
        END as attendance_percentage,
        (SELECT COUNT(*) FROM interventions i WHERE i.student_id = st.student_id) as total_intervention_count,
        (SELECT COUNT(*) FROM interventions i WHERE i.student_id = st.student_id AND i.subject_id = s.subject_id) as subject_intervention_count
    FROM students st
    JOIN enrollments e ON st.student_id = e.student_id
    JOIN subjects s ON e.subject_id = s.subject_id
    LEFT JOIN attendance a ON st.student_id = a.student_id AND s.subject_id = a.subject_id
    WHERE s.subject_id IN (
        SELECT subject_id FROM lecturer_subjects WHERE lecturer_id = ?
    )
";

$params = [$lecturer_id];
$types = "i";

// Add subject filter if provided
if ($subject_id) {
    $students_sql .= " AND s.subject_id = ?";
    $params[] = $subject_id;
    $types .= "i";
}

// Add search filter if provided
if ($search_term) {
    $students_sql .= " AND st.name LIKE ?";
    $params[] = "%$search_term%";
    $types .= "s";
}

$students_sql .= " GROUP BY st.student_id, s.subject_id ORDER BY s.subject_name, st.name";

$stmt = $conn->prepare($students_sql);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $students_result = $stmt->get_result();
} else {
    error_log("Students SQL Error: " . $conn->error);
    $students_result = [];
}

// Get cross-subject interventions for modal view
if (isset($_GET['student_id'])) {
    $modal_student_id = $_GET['student_id'];
    
    // Get ALL interventions for this student (from ALL lecturers)
    $interventions_sql = "
        SELECT 
            i.intervention_id,
            i.student_id,
            i.subject_id,
            s.subject_name,
            i.intervention_date,
            i.comments,
            i.created_by,
            l.lecturer_name
        FROM interventions i
        JOIN subjects s ON i.subject_id = s.subject_id
        LEFT JOIN lecturers l ON i.created_by = l.lecturer_id
        WHERE i.student_id = ?
        ORDER BY i.intervention_date DESC
    ";
    
    $stmt = $conn->prepare($interventions_sql);
    $stmt->bind_param("i", $modal_student_id);
    $stmt->execute();
    $interventions_result = $stmt->get_result();
    
    // Get cross-subject attendance
    $cross_subject_sql = "
        SELECT 
            s.subject_id,
            s.subject_name,
            COUNT(a.attendance_id) as total_classes,
            SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) as attended_classes,
            CASE 
                WHEN COUNT(a.attendance_id) > 0 THEN 
                    ROUND((SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) / COUNT(a.attendance_id)) * 100, 2)
                ELSE 0 
            END as attendance_percentage
        FROM enrollments e
        JOIN subjects s ON e.subject_id = s.subject_id
        LEFT JOIN attendance a ON e.student_id = a.student_id AND e.subject_id = a.subject_id
        WHERE e.student_id = ?
        GROUP BY s.subject_id
        ORDER BY attendance_percentage ASC
    ";
    
    $stmt = $conn->prepare($cross_subject_sql);
    $stmt->bind_param("i", $modal_student_id);
    $stmt->execute();
    $cross_subject_result = $stmt->get_result();
    
    // Get student name for modal
    $student_name_sql = "SELECT name FROM students WHERE student_id = ?";
    $stmt = $conn->prepare($student_name_sql);
    $stmt->bind_param("i", $modal_student_id);
    $stmt->execute();
    $student_name_result = $stmt->get_result();
    $student_name = $student_name_result->fetch_assoc()['name'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Management - CSSAP</title>
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

        .btn-warning {
            background: #ffc107;
            color: #212529;
        }

        .btn-warning:hover {
            background: #e0a800;
        }

        .btn-sm {
            padding: 5px 10px;
            font-size: 12px;
        }

        /* Filter Styles */
        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
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

        select, input[type="text"] {
            width: 100%;
            padding: 8px 12px;
            border: 2px solid #e9ecef;
            border-radius: 5px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }

        select:focus, input[type="text"]:focus {
            outline: none;
            border-color: #28a745;
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
            border-bottom: 1px solid #dee2e6;
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
            color: #dc3545;
            font-weight: bold;
        }

        .attendance-warning {
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

        .student-actions {
            display: flex;
            gap: 5px;
        }

        .intervention-badge {
            background: #dc3545;
            color: white;
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 0.7rem;
            margin-left: 5px;
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
            margin: 5% auto;
            padding: 30px;
            border-radius: 10px;
            width: 80%;
            max-width: 700px;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
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
            color: #495057;
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
            color: #dc3545;
        }

        .cross-subject-table {
            width: 100%;
            margin-top: 20px;
        }

        /* New styles for interventions */
        .intervention-item {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
        }
        
        .intervention-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        
        .intervention-subject {
            background: #17a2b8;
            color: white;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        
        .intervention-lecturer {
            color: #6c757d;
            font-size: 0.9rem;
            font-style: italic;
        }
        
        .intervention-date {
            color: #6c757d;
            font-size: 0.8rem;
        }
        
        .intervention-comment {
            margin-top: 8px;
            line-height: 1.4;
        }
        
        .cross-subject-badge {
            background: #6f42c1;
            color: white;
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 0.7rem;
            margin-left: 5px;
        }
        
        .other-lecturer-intervention {
            border-left: 4px solid #17a2b8;
        }
        
        .current-lecturer-intervention {
            border-left: 4px solid #28a745;
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
                <li><a href="students.php" class="active">Student Management</a></li>
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
                <h1>Student Management</h1>
                <div>
                    <span>Welcome, <strong><?php echo htmlspecialchars($_SESSION['lecturer_name']); ?></strong></span>
                </div>
            </div>

            <!-- Filter Form -->
            <div class="content-section">
                <div class="section-header">
                    <h2>Student Filters</h2>
                </div>
                <form method="GET" class="filter-form">
                    <div class="form-group">
                        <label for="subject_id">Subject:</label>
                        <select name="subject_id" id="subject_id">
                            <option value="">All Subjects</option>
                            <?php while ($subject = $subjects_result->fetch_assoc()): ?>
                                <option value="<?php echo $subject['subject_id']; ?>" 
                                    <?php echo $subject_id == $subject['subject_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($subject['subject_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="search">Search Student:</label>
                        <input type="text" name="search" id="search" value="<?php echo htmlspecialchars($search_term); ?>" placeholder="Enter student name...">
                    </div>

                    <div class="filter-actions">
                        <button type="submit" class="btn">Apply Filters</button>
                        <a href="students.php" class="btn btn-secondary">Reset</a>
                    </div>
                </form>
            </div>

            <!-- Students List -->
            <div class="content-section">
                <div class="section-header">
                    <h2>Student List</h2>
                    <span class="btn btn-info">Total: <?php echo is_object($students_result) ? $students_result->num_rows : 0; ?></span>
                </div>

                <?php if (is_object($students_result) && $students_result->num_rows > 0): ?>
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
                            ?>
                                <tr>
                                    <td>
                                        <?php echo htmlspecialchars($student['student_name']); ?>
                                        <?php if ($student['total_intervention_count'] > $student['subject_intervention_count']): ?>
                                            <span class="cross-subject-badge" title="Has interventions in other subjects">🔍</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($student['subject_name']); ?></td>
                                    <td><?php echo $student['attended_classes']; ?></td>
                                    <td><?php echo $student['total_classes']; ?></td>
                                    <td class="<?php echo $attendance_class; ?>">
                                        <?php echo $attendance_percentage; ?>%
                                    </td>
                                    <td>
                                        <?php if ($student['total_intervention_count'] > 0): ?>
                                            <span class="intervention-badge">
                                                <?php echo $student['subject_intervention_count']; ?> in this subject
                                                <?php if ($student['total_intervention_count'] > $student['subject_intervention_count']): ?>
                                                    <br><small>(+<?php echo $student['total_intervention_count'] - $student['subject_intervention_count']; ?> from other subjects)</small>
                                                <?php endif; ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color: #6c757d;">None</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="student-actions">
                                        <button class="btn btn-info btn-sm" onclick="viewStudentDetails(<?php echo $student['student_id']; ?>)">
                                            View All Interventions
                                        </button>
                                        <a href="interventions.php?student_id=<?php echo $student['student_id']; ?>&subject_id=<?php echo $student['subject_id']; ?>" 
                                           class="btn btn-warning btn-sm">
                                            Add Intervention
                                        </a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-data">No students found for the selected criteria.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Student Details Modal -->
    <div id="studentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Student Interventions - <span id="modalStudentName"></span></h3>
                <span class="close">&times;</span>
            </div>
            
            <div id="modalContent">
                <?php if (isset($modal_student_id)): ?>
                    <!-- Cross-Subject Attendance Summary -->
                    <div class="content-section">
                        <h4>Attendance Across All Subjects</h4>
                        <?php if ($cross_subject_result->num_rows > 0): ?>
                            <table class="cross-subject-table">
                                <thead>
                                    <tr>
                                        <th>Subject</th>
                                        <th>Attended</th>
                                        <th>Total</th>
                                        <th>Percentage</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($subject = $cross_subject_result->fetch_assoc()): 
                                        $attendance_class = '';
                                        if ($subject['attendance_percentage'] < 60) {
                                            $attendance_class = 'attendance-critical';
                                        } elseif ($subject['attendance_percentage'] < 80) {
                                            $attendance_class = 'attendance-warning';
                                        } else {
                                            $attendance_class = 'attendance-good';
                                        }
                                    ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($subject['subject_name']); ?></td>
                                            <td><?php echo $subject['attended_classes']; ?></td>
                                            <td><?php echo $subject['total_classes']; ?></td>
                                            <td class="<?php echo $attendance_class; ?>">
                                                <?php echo $subject['attendance_percentage']; ?>%
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="no-data">No attendance records found.</div>
                        <?php endif; ?>
                    </div>

                    <!-- All Interventions -->
                    <div class="content-section">
                        <h4>All Interventions</h4>
                        <?php if ($interventions_result->num_rows > 0): ?>
                            <div class="interventions-list">
                                <?php while ($intervention = $interventions_result->fetch_assoc()): 
                                    $is_current_lecturer = ($intervention['created_by'] == $lecturer_id);
                                ?>
                                    <div class="intervention-item <?php echo $is_current_lecturer ? 'current-lecturer-intervention' : 'other-lecturer-intervention'; ?>">
                                        <div class="intervention-header">
                                            <span class="intervention-subject"><?php echo htmlspecialchars($intervention['subject_name']); ?></span>
                                            <span class="intervention-date"><?php echo date('M j, Y', strtotime($intervention['intervention_date'])); ?></span>
                                        </div>
                                        <div class="intervention-lecturer">
                                            By: <?php echo htmlspecialchars($intervention['lecturer_name'] ?? 'Unknown Lecturer'); ?>
                                            <?php if ($is_current_lecturer): ?>
                                                <strong>(You)</strong>
                                            <?php endif; ?>
                                        </div>
                                        <div class="intervention-comment">
                                            <?php echo htmlspecialchars($intervention['comments']); ?>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            </div>
                        <?php else: ?>
                            <div class="no-data">No interventions found for this student.</div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Modal functionality
        const modal = document.getElementById('studentModal');
        const closeBtn = document.querySelector('.close');
        
        function viewStudentDetails(studentId) {
            // Redirect to same page with student_id parameter
            window.location.href = `students.php?student_id=${studentId}`;
        }

        // Auto-open modal if student_id is in URL
        <?php if (isset($modal_student_id)): ?>
            document.addEventListener('DOMContentLoaded', function() {
                document.getElementById('modalStudentName').textContent = '<?php echo htmlspecialchars($student_name); ?>';
                modal.style.display = 'block';
            });
        <?php endif; ?>

        // Close modal when clicking X
        closeBtn.onclick = function() {
            modal.style.display = 'none';
            // Remove student_id from URL
            window.history.replaceState({}, document.title, window.location.pathname);
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target == modal) {
                modal.style.display = 'none';
                // Remove student_id from URL
                window.history.replaceState({}, document.title, window.location.pathname);
            }
        }

        // Auto-submit form when subject changes
        document.getElementById('subject_id').addEventListener('change', function() {
            this.form.submit();
        });
    </script>
</body>
</html>