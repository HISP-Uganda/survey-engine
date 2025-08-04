<?php
session_start();

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit();
}

require_once 'connect.php';
require_once 'includes/question_helper.php';

$search = $_GET['search'] ?? '';
$type_filter = $_GET['type'] ?? '';
$dhis2_filter = $_GET['dhis2'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 50;
$offset = ($page - 1) * $per_page;

// Build query conditions
$conditions = ['1 = 1'];
$params = [];

if (!empty($search)) {
    $conditions[] = 'q.label LIKE ?';
    $params[] = '%' . $search . '%';
}

if (!empty($type_filter)) {
    $conditions[] = 'q.question_type = ?';
    $params[] = $type_filter;
}

if ($dhis2_filter === 'yes') {
    $conditions[] = 'qm.question_id IS NOT NULL';
} elseif ($dhis2_filter === 'no') {
    $conditions[] = 'qm.question_id IS NULL';
}

$where_clause = implode(' AND ', $conditions);

// Get total count
$count_sql = "
    SELECT COUNT(DISTINCT q.id) as total
    FROM question q
    LEFT JOIN question_dhis2_mapping qm ON q.id = qm.question_id
    WHERE $where_clause
";
$stmt = $pdo->prepare($count_sql);
$stmt->execute($params);
$total_questions = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_questions / $per_page);

// Get questions with usage statistics
$sql = "
    SELECT 
        q.id,
        q.label,
        q.question_type,
        q.is_required,
        q.created,
        q.updated,
        COUNT(DISTINCT sq.survey_id) as survey_count,
        GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ', ') as survey_names,
        qm.dhis2_dataelement_id,
        qm.dhis2_attribute_id,
        qm.dhis2_option_set_id
    FROM question q
    LEFT JOIN survey_question sq ON q.id = sq.question_id
    LEFT JOIN survey s ON sq.survey_id = s.id
    LEFT JOIN question_dhis2_mapping qm ON q.id = qm.question_id
    WHERE $where_clause
    GROUP BY q.id, q.label, q.question_type, q.is_required, q.created, q.updated, 
             qm.dhis2_dataelement_id, qm.dhis2_attribute_id, qm.dhis2_option_set_id
    ORDER BY q.created DESC
    LIMIT $per_page OFFSET $offset
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get question types for filter
$stmt = $pdo->prepare("SELECT DISTINCT question_type FROM question ORDER BY question_type");
$stmt->execute();
$question_types = $stmt->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Question Bank - Survey Engine</title>
    <link href="argon-dashboard-master/assets/css/nucleo-icons.css" rel="stylesheet">
    <link href="argon-dashboard-master/assets/css/nucleo-svg.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <link href="argon-dashboard-master/assets/css/argon-dashboard.min.css" rel="stylesheet">
    <style>
        .question-card {
            transition: all 0.2s;
        }
        .question-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .dhis2-badge {
            background: linear-gradient(45deg, #007bff, #0056b3);
        }
        .usage-stats {
            font-size: 0.875rem;
            color: #6c757d;
        }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100">
    <?php include 'components/aside.php'; ?>
    
    <main class="main-content position-relative border-radius-lg">
        <?php include 'components/navbar.php'; ?>
        
        <div class="container-fluid py-4">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h4 class="mb-0">Question Bank</h4>
                            <div>
                                <a href="question_manager.php?action=create" class="btn btn-primary btn-sm">
                                    <i class="fas fa-plus"></i> Add Question
                                </a>
                            </div>
                        </div>
                        
                        <div class="card-body">
                            <form method="get" class="row g-3 mb-4">
                                <div class="col-md-4">
                                    <input type="text" name="search" class="form-control" 
                                           placeholder="Search questions..." value="<?= htmlspecialchars($search) ?>">
                                </div>
                                <div class="col-md-2">
                                    <select name="type" class="form-select">
                                        <option value="">All Types</option>
                                        <?php foreach ($question_types as $type): ?>
                                            <option value="<?= htmlspecialchars($type) ?>" 
                                                    <?= $type_filter === $type ? 'selected' : '' ?>>
                                                <?= ucfirst(str_replace('_', ' ', $type)) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <select name="dhis2" class="form-select">
                                        <option value="">All Questions</option>
                                        <option value="yes" <?= $dhis2_filter === 'yes' ? 'selected' : '' ?>>DHIS2 Mapped</option>
                                        <option value="no" <?= $dhis2_filter === 'no' ? 'selected' : '' ?>>Not DHIS2 Mapped</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <button type="submit" class="btn btn-outline-primary w-100">
                                        <i class="fas fa-search"></i> Filter
                                    </button>
                                </div>
                                <div class="col-md-2">
                                    <a href="question_bank.php" class="btn btn-outline-secondary w-100">
                                        <i class="fas fa-times"></i> Clear
                                    </a>
                                </div>
                            </form>
                            
                            <div class="row mb-3">
                                <div class="col-12">
                                    <small class="text-muted">
                                        Showing <?= number_format(count($questions)) ?> of <?= number_format($total_questions) ?> questions
                                        (Page <?= $page ?> of <?= $total_pages ?>)
                                    </small>
                                </div>
                            </div>
                            
                            <div class="row">
                                <?php foreach ($questions as $question): ?>
                                    <div class="col-md-6 col-lg-4 mb-4">
                                        <div class="card question-card h-100">
                                            <div class="card-header d-flex justify-content-between align-items-start">
                                                <div>
                                                    <span class="badge bg-secondary">
                                                        <?= ucfirst(str_replace('_', ' ', $question['question_type'])) ?>
                                                    </span>
                                                    <?php if ($question['dhis2_dataelement_id'] || $question['dhis2_attribute_id']): ?>
                                                        <span class="badge dhis2-badge text-white">DHIS2</span>
                                                    <?php endif; ?>
                                                    <?php if ($question['is_required']): ?>
                                                        <span class="badge bg-danger">Required</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="dropdown">
                                                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" 
                                                            type="button" 
                                                            id="dropdownMenuButton<?= $question['id'] ?>"
                                                            data-bs-toggle="dropdown" 
                                                            data-bs-auto-close="true"
                                                            aria-expanded="false">
                                                        <i class="fas fa-ellipsis-v"></i>
                                                    </button>
                                                    <ul class="dropdown-menu" aria-labelledby="dropdownMenuButton<?= $question['id'] ?>">
                                                        <li><a class="dropdown-item" href="question_manager.php?action=edit&id=<?= $question['id'] ?>">
                                                            <i class="fas fa-edit"></i> Edit
                                                        </a></li>
                                                        <li><a class="dropdown-item" href="#" onclick="viewUsage(<?= $question['id'] ?>)">
                                                            <i class="fas fa-chart-bar"></i> View Usage
                                                        </a></li>
                                                        <?php if ($question['survey_count'] == 0): ?>
                                                            <li><hr class="dropdown-divider"></li>
                                                            <li><a class="dropdown-item text-danger" href="#" 
                                                                   onclick="deleteQuestion(<?= $question['id'] ?>)">
                                                                <i class="fas fa-trash"></i> Delete
                                                            </a></li>
                                                        <?php endif; ?>
                                                    </ul>
                                                </div>
                                            </div>
                                            
                                            <div class="card-body">
                                                <h6 class="card-title"><?= htmlspecialchars($question['label']) ?></h6>
                                                
                                                <div class="usage-stats">
                                                    <div class="mb-2">
                                                        <i class="fas fa-poll text-primary"></i> 
                                                        Used in <strong><?= $question['survey_count'] ?></strong> survey(s)
                                                    </div>
                                                    
                                                    <?php if ($question['survey_names']): ?>
                                                        <div class="mb-2">
                                                            <small><strong>Surveys:</strong> <?= htmlspecialchars($question['survey_names']) ?></small>
                                                        </div>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($question['dhis2_dataelement_id']): ?>
                                                        <div class="mb-1">
                                                            <small><strong>Data Element:</strong> 
                                                            <code><?= htmlspecialchars($question['dhis2_dataelement_id']) ?></code></small>
                                                        </div>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($question['dhis2_attribute_id']): ?>
                                                        <div class="mb-1">
                                                            <small><strong>Attribute:</strong> 
                                                            <code><?= htmlspecialchars($question['dhis2_attribute_id']) ?></code></small>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            
                                            <div class="card-footer text-muted">
                                                <small>
                                                    Created: <?= date('Y-m-d H:i', strtotime($question['created'])) ?>
                                                    <?php if ($question['updated'] !== $question['created']): ?>
                                                        <br>Updated: <?= date('Y-m-d H:i', strtotime($question['updated'])) ?>
                                                    <?php endif; ?>
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <?php if ($total_pages > 1): ?>
                                <nav aria-label="Questions pagination">
                                    <ul class="pagination justify-content-center">
                                        <?php if ($page > 1): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&type=<?= urlencode($type_filter) ?>&dhis2=<?= urlencode($dhis2_filter) ?>">Previous</a>
                                            </li>
                                        <?php endif; ?>
                                        
                                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                                <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&type=<?= urlencode($type_filter) ?>&dhis2=<?= urlencode($dhis2_filter) ?>"><?= $i ?></a>
                                            </li>
                                        <?php endfor; ?>
                                        
                                        <?php if ($page < $total_pages): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&type=<?= urlencode($type_filter) ?>&dhis2=<?= urlencode($dhis2_filter) ?>">Next</a>
                                            </li>
                                        <?php endif; ?>
                                    </ul>
                                </nav>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script src="argon-dashboard-master/assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="argon-dashboard-master/assets/js/plugins/smooth-scrollbar.min.js"></script>
    
    <script>
        function viewUsage(questionId) {
            // TODO: Implement usage detail modal
            alert('Usage details for question ' + questionId);
        }
        
        function deleteQuestion(questionId) {
            if (confirm('Are you sure you want to delete this question? This action cannot be undone.')) {
                fetch('delete_question.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ question_id: questionId })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while deleting the question.');
                });
            }
        }
    </script>
</body>
</html>