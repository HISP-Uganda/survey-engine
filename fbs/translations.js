// document.getElementById('languageSelect').addEventListener('change', function () {
//     const selectedLanguage = this.value;

//     // Fetch translations for the selected language
//     fetch(`get_translations.php?language=${selectedLanguage}`)
//         .then(response => response.json())
//         .then(translations => {
//             // Update question labels
//             document.querySelectorAll('.question-label').forEach(label => {
//                 const questionId = label.dataset.questionId;
//                 if (translations.questions[questionId]) {
//                     label.textContent = translations.questions[questionId];
//                 }
//             });

//             // Update option labels
//             document.querySelectorAll('.option-label').forEach(label => {
//                 const optionId = label.dataset.optionId;
//                 if (translations.options[optionId]) {
//                     label.textContent = translations.options[optionId];
//                 }
//             });

//             // Update default text (e.g., headings, buttons)
//             document.querySelectorAll('[data-translate]').forEach(element => {
//                 const key = element.dataset.translate;
//                 if (translations.defaultText[key]) {
//                     element.textContent = translations.defaultText[key];
//                 }
//             });
//         });
// });

// translations.js

document.addEventListener('DOMContentLoaded', function() {
    const languageSelect = document.getElementById('languageSelect');

    // ONLY add event listener if the element actually exists
    if (languageSelect) {
        languageSelect.addEventListener('change', function() {
            var selectedLang = this.value;
            // Assuming surveyId is passed via URL or available globally in survey_page.php
            // If surveyId is not global, you would need to pass it from PHP into survey_page.php's main script
            // and then potentially via a data attribute or global JS variable for translations.js to access.
            // For now, let's assume it's global as per survey_page.php's structure.
            const currentUrlParams = new URLSearchParams(window.location.search);
            currentUrlParams.set('language', selectedLang);
            // Assuming survey_id is already in the URL
            window.location.search = currentUrlParams.toString();
        });
    }

    // Function to apply translations on the fly (if needed by your design)
    // This part is illustrative and depends on how you store/apply translations
    window.applyTranslations = function(translationsData, currentLanguage) {
        document.querySelectorAll('[data-translate]').forEach(element => {
            const key = element.getAttribute('data-translate');
            if (translationsData && translationsData[key] && translationsData[key][currentLanguage]) {
                element.textContent = translationsData[key][currentLanguage];
            }
        });
    };
});