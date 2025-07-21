document.getElementById('languageSelect').addEventListener('change', function () {
    const selectedLanguage = this.value;

    // Fetch translations for the selected language
    fetch(`../get_translations.php?language=${selectedLanguage}`)
        .then(response => response.json())
        .then(translations => {
            // Update question labels
            document.querySelectorAll('.question-label').forEach(label => {
                const questionId = label.dataset.questionId;
                if (translations.questions[questionId]) {
                    label.textContent = translations.questions[questionId];
                }
            });

            // Update option labels
            document.querySelectorAll('.option-label').forEach(label => {
                const optionId = label.dataset.optionId;
                if (translations.options[optionId]) {
                    label.textContent = translations.options[optionId];
                }
            });

            // Update default text (e.g., headings, buttons)
            document.querySelectorAll('[data-translate]').forEach(element => {
                const key = element.dataset.translate;
                if (translations.defaultText[key]) {
                    element.textContent = translations.defaultText[key];
                }
            });
        });
});