<?php
require 'db.php';

$language = $_GET['language'] ?? 'en'; // Default to English

//  Fetch translations for questions
$questions = $pdo->query("SELECT id, translations FROM question")->fetchAll(PDO::FETCH_ASSOC);
$questionTranslations = [];
foreach ($questions as $question) {
    if ($question['translations']) {
        $translations = json_decode($question['translations'], true);
        if (isset($translations[$language])) {
            $questionTranslations[$question['id']] = $translations[$language];
        }
    }
}

// // Fetch translations for options
$options = $pdo->query("SELECT id, translations FROM question_options")->fetchAll(PDO::FETCH_ASSOC);
$optionTranslations = [];
foreach ($options as $option) {
    if ($option['translations']) {
        $translations = json_decode($option['translations'], true);
        if (isset($translations[$language])) {
            $optionTranslations[$option['id']] = $translations[$language];
        }
    }
}

// Fetch default text translations
$defaultText = [];
$defaultTextRows = $pdo->query("SELECT key_name, translations FROM default_text")->fetchAll(PDO::FETCH_ASSOC);
foreach ($defaultTextRows as $row) {
    if ($row['translations']) {
        $translations = json_decode($row['translations'], true);
        if (isset($translations[$language])) {
            $defaultText[$row['key_name']] = $translations[$language];
        }
    }
}

// Return translations as JSON
echo json_encode([
    'questions' => $questionTranslations,
    'options' => $optionTranslations,
    'defaultText' => $defaultText,
]);
?>