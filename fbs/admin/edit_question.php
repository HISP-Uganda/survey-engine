<?php
session_start();

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit();
}

require 'connect.php';

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Function to generate language options
function generateLanguageOptionsPhp($selectedLang = '') {
    $availableLanguages = [
        ['code' => 'en', 'name' => 'English'],
        ['code' => 'lg', 'name' => 'Luganda'],
        ['code' => 'rn', 'name' => 'Runyakole'],
        ['code' => 'rk', 'name' => 'Rukiga'],
        ['code' => 'ac', 'name' => 'Acholi'],
        ['code' => 'at', 'name' => 'Ateso'],
        ['code' => 'ls', 'name' => 'Lusoga'],
        ['code' => 'ur', 'name' => 'Alur'],
        ['code' => 'ak', 'name' => 'Kakwa'],
        ['code' => 'kj', 'name' => 'Karamojong'],
        ['code' => 'fb', 'name' => 'Kifumbira'],
        ['code' => 'ku', 'name' => 'Kupsabiny'],
        ['code' => 'ln', 'name' => 'Langi'],
        ['code' => 'tr', 'name' => 'Lebtrur'],
        ['code' => 'lb', 'name' => 'Lugbara'],
        ['code' => 'li', 'name' => 'Lugisu'],
        ['code' => 'md', 'name' => 'Madi'],
        ['code' => 'ry', 'name' => 'Runyoro'],
        ['code' => 'rt', 'name' => 'Rutooro'],
        ['code' => 'sa', 'name' => 'Samia'],
        ['code' => 'kw', 'name' => 'Swahili'],
    ];
    
    $options = '';
    foreach ($availableLanguages as $lang) {
        $selected = ($selectedLang === $lang['code']) ? 'selected' : '';
        $options .= "<option value='{$lang['code']}' {$selected}>{$lang['name']}</option>";
    }
    return $options;
}

// Fetch the question ID from the URL
if (!isset($_GET['id'])) {
    $_SESSION['error_message'] = "Question ID is missing.";
    header("Location: manage_form");
    exit();
}

$questionId = $_GET['id'];

// Fetch the question details
$stmt = $pdo->prepare("SELECT * FROM question WHERE id = ?");
$stmt->execute([$questionId]);
$question = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$question) {
    $_SESSION['error_message'] = "Question not found.";
    header("Location: manage_form");
    exit();
}

// Fetch the question's translations
$translations = $question['translations'] ? json_decode($question['translations'], true) : [];

// Fetch the question's options (if applicable)
$options = [];
if ($question['option_set_id']) {
    $stmt = $pdo->prepare("SELECT * FROM option_set_values WHERE option_set_id = ?");
    $stmt->execute([$question['option_set_id']]);
    $options = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Handle form submission for updating the question
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        // Update the question label
        $questionLabel = trim($_POST['question_label']);
        if (empty($questionLabel)) {
            throw new Exception("Question label is required.");
        }

        // Update the question type
        $questionType = $_POST['question_type'];
        if (empty($questionType)) {
            throw new Exception("Question type is required.");
        }

        // Get the 'is_required' status
        $isRequired = isset($_POST['is_required']) ? 1 : 0;

        // Update translations
        $newTranslations = [];
        if (isset($_POST['lang']) && is_array($_POST['lang'])) {
            foreach ($_POST['lang'] as $index => $lang) {
                if (!empty($lang) && !empty($_POST['text'][$index])) {
                    $newTranslations[$lang] = $_POST['text'][$index];
                }
            }
        }
        $translationsJson = !empty($newTranslations) ? json_encode($newTranslations) : null;

        // Update the question in the database, including 'is_required'
        $stmt = $pdo->prepare("UPDATE question SET label = ?, question_type = ?, is_required = ?, translations = ? WHERE id = ?");
        $stmt->execute([$questionLabel, $questionType, $isRequired, $translationsJson, $questionId]);

        // Handle options (if applicable)
        if (in_array($questionType, ['radio', 'checkbox', 'select'])) {
            if ($_POST['option_source'] === 'existing') {
                $optionSetId = $_POST['option_set_id'];
            } else {
                // Create new option set for custom options
                $stmt = $pdo->prepare("INSERT INTO option_set (name) VALUES (?)");
                $stmt->execute(["Custom_" . time()]);
                $optionSetId = $pdo->lastInsertId();

                // Add custom options
                if (isset($_POST['custom_options'])) {
                    $optionStmt = $pdo->prepare("INSERT INTO option_set_values (option_set_id, option_value) VALUES (?, ?)");
                    foreach ($_POST['custom_options'] as $option) {
                        if (!empty(trim($option))) {
                            $optionStmt->execute([$optionSetId, trim($option)]);
                        }
                    }
                }
            }

            // Update the question's option_set_id
            $stmt = $pdo->prepare("UPDATE question SET option_set_id = ? WHERE id = ?");
            $stmt->execute([$optionSetId, $questionId]);
        }

        $pdo->commit();
        $_SESSION['success_message'] = "Question updated successfully!";
        header("Location: manage_form");
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error_message'] = "Error: " . $e->getMessage();
        header("Location: edit_question?id=" . $questionId);
        exit();
    }
}

// Fetch all option sets for the form
$optionSets = $pdo->query("
    SELECT 
        os.id AS option_set_id,
        os.name AS option_set_name,
        GROUP_CONCAT(osv.option_value ORDER BY osv.id SEPARATOR ', ') AS options
    FROM option_set os
    LEFT JOIN option_set_values osv ON os.id = osv.option_set_id
    GROUP BY os.id
")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Question</title>
    <link href="asets/asets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="asets/asets/css/nucleo-svg.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="asets/asets/css/argon-dashboard.css" rel="stylesheet" />
    <style>
        .translations-container {
            margin-top: 20px;
        }
        .translation-entry {
            margin-bottom: 10px;
        }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100">
<?php include 'components/aside.php'; ?>

    <div class="main-content position-relative border-radius-lg ">
      <div class="container-fluid py-4">
        <?php include 'components/navbar.php'; ?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>Edit Question</h1>
            <a href="manage_form.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back
            </a>
        </div>

        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success"><?= $_SESSION['success_message'] ?></div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger"><?= $_SESSION['error_message'] ?></div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="mb-3">
                <label for="question_label" class="form-label">Question Label</label>
                <input type="text" class="form-control" id="question_label" name="question_label" value="<?= htmlspecialchars($question['label']) ?>" required>
            </div>
            <div class="mb-3">
                <label for="question_type" class="form-label">Question Type</label>
                <select class="form-select" id="question_type" name="question_type" required>
                    <option value="text" <?= $question['question_type'] === 'text' ? 'selected' : '' ?>>Text</option>
                    <option value="textarea" <?= $question['question_type'] === 'textarea' ? 'selected' : '' ?>>Text Area</option>
                    <option value="radio" <?= $question['question_type'] === 'radio' ? 'selected' : '' ?>>Radio</option>
                    <option value="checkbox" <?= $question['question_type'] === 'checkbox' ? 'selected' : '' ?>>Checkbox</option>
                    <option value="select" <?= $question['question_type'] === 'select' ? 'selected' : '' ?>>Dropdown</option>
                    <option value="rating" <?= $question['question_type'] === 'rating' ? 'selected' : '' ?>>Rating</option>
                </select>
            </div>

            <div class="mb-3 form-check">
                <input type="checkbox" class="form-check-input" id="is_required" name="is_required" <?= $question['is_required'] ? 'checked' : '' ?>>
                <label class="form-check-label" for="is_required">Is Required</label>
            </div>

            <div class="translations-container">
                <h4>Question Translations (Optional)</h4>
                <p>Add translations for this question in different languages.</p>
                <div id="translation-entries">
                    <?php foreach ($translations as $lang => $text): ?>
                        <div class="translation-entry row mb-2">
                            <div class="col">
                                <select class="form-select" name="lang[]">
                                    <?= generateLanguageOptionsPhp($lang) ?>
                                </select>
                            </div>
                            <div class="col">
                                <input type="text" class="form-control" name="text[]" value="<?= htmlspecialchars($text) ?>" placeholder="Translated Text">
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-secondary" onclick="addTranslation()">Add Translation</button>
            </div>

            <div class="mb-3" id="options_section" style="display: <?= in_array($question['question_type'], ['radio', 'checkbox', 'select', 'rating']) ? 'block' : 'none' ?>;">                <label class="form-label">Options</label>
                <div class="mb-2">
                    <div class="form-check">
                        <input type="radio" class="form-check-input" name="option_source" value="existing" checked onclick="toggleOptions('existing')">
                        <label class="form-check-label">Use Existing Option Set</label>
                    </div>
                    <div class="form-check">
                        <input type="radio" class="form-check-input" name="option_source" value="custom" onclick="toggleOptions('custom')">
                        <label class="form-check-label">Create Custom Options</label>
                    </div>
                </div>
                <div id="existing_options">
                    <select class="form-select" name="option_set_id">
                        <?php foreach ($optionSets as $optionSet): ?>
                            <option value="<?= $optionSet['option_set_id'] ?>" <?= $optionSet['option_set_id'] === $question['option_set_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($optionSet['option_set_name']) ?> (<?= htmlspecialchars($optionSet['options']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="custom_options" style="display: none;">
                    <div id="custom_options_list">
                        <?php foreach ($options as $option): ?>
                            <input type="text" class="form-control mb-2" name="custom_options[]" value="<?= htmlspecialchars($option['option_value']) ?>" placeholder="Option">
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="addCustomOption()">Add Option</button>
                </div>
            </div>

            <button type="submit" class="btn btn-primary" name="update_question">Update Question</button>
        </form>
        </div>
     </div>
    <?php include 'components/fixednav.php'; ?>
    <script src="asets/asets/js/core/popper.min.js"></script>
    <script src="asets/asets/js/core/bootstrap.min.js"></script>
    <script src="asets/asets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="asets/asets/js/plugins/smooth-scrollbar.min.js"></script>
    <script src="asets/asets/js/argon-dashboard.js"></script>
    <script>
        // Show/hide options section based on question type
        document.getElementById('question_type').addEventListener('change', function() {
            const optionsSection = document.getElementById('options_section');
           if (['radio', 'checkbox', 'select', 'rating'].includes(this.value)) {
    optionsSection.style.display = 'block';
} else {
    optionsSection.style.display = 'none';
}
        });

        // Toggle between existing and custom options
        function toggleOptions(source) {
            const existingOptions = document.getElementById('existing_options');
            const customOptions = document.getElementById('custom_options');
            if (source === 'existing') {
                existingOptions.style.display = 'block';
                customOptions.style.display = 'none';
            } else {
                existingOptions.style.display = 'none';
                customOptions.style.display = 'block';
            }
        }

        // Add translation field
        function addTranslation() {
            const translationEntries = document.getElementById('translation-entries');
            const newField = `
                <div class="translation-entry row mb-2">
                    <div class="col">
                        <select class="form-select" name="lang[]">
                            <?= generateLanguageOptionsPhp() ?>
                        </select>
                    </div>
                    <div class="col">
                        <input type="text" class="form-control" name="text[]" placeholder="Translated Text">
                    </div>
                </div>
            `;
            translationEntries.insertAdjacentHTML('beforeend', newField);
        }

        // Add custom option field
        function addCustomOption() {
            const customOptionsList = document.getElementById('custom_options_list');
            const newOption = `<input type="text" class="form-control mb-2" name="custom_options[]" placeholder="Option">`;
            customOptionsList.insertAdjacentHTML('beforeend', newOption);
        }
    </script>
</body>
</html>