/* Dispatch CP Scripts */

(function() {
    'use strict';

    // Auto-generate handle from name field
    var nameField = document.getElementById('name');
    var handleField = document.getElementById('handle');

    if (nameField && handleField && !handleField.value) {
        nameField.addEventListener('input', function() {
            if (!handleField.dataset.modified) {
                handleField.value = nameField.value
                    .toLowerCase()
                    .replace(/[^a-z0-9]+/g, '_')
                    .replace(/^_|_$/g, '');
            }
        });

        handleField.addEventListener('input', function() {
            handleField.dataset.modified = '1';
        });
    }

    // Campaign preview button
    var previewBtn = document.getElementById('preview-btn');
    if (previewBtn) {
        previewBtn.addEventListener('click', function() {
            var campaignId = document.querySelector('input[name="campaignId"]');
            if (campaignId) {
                window.open(
                    Craft.getActionUrl('dispatch/campaigns/preview', { campaignId: campaignId.value }),
                    'dispatch-preview',
                    'width=700,height=600,scrollbars=yes'
                );
            }
        });
    }
})();
