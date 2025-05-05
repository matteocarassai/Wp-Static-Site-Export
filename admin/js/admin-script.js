jQuery(document).ready(function($) {
    'use strict';

    const generateBtn = $('#wp-static-exporter-generate-btn');
    const statusDiv = $('#wp-static-exporter-status');
    const downloadLinkDiv = $('#wp-static-exporter-download-link');
    const deployLinkDiv = $('#wp-static-exporter-deploy-link');
    const exportsTableBody = $('#wp-static-exporter-exports-table tbody');
    const exportsListDiv = $('#wp-static-exporter-exports-list');
    const optimizeCheckbox = $('#wp-static-exporter-optimize-output');
    const useFaCdnCheckbox = $('#wp-static-exporter-use-fa-cdn');
    const convertFormsCheckbox = $('#wp-static-exporter-convert-forms');
    const recipientEmailInput = $('#wp-static-exporter-recipient-email');
    const generate404Checkbox = $('#wp-static-exporter-generate-404');
    const originalButtonText = wpStaticExporter.text.generateButtonDefault;

    // URL Rewriting Elements
    const urlRewriteRadios = $('input[name="url_rewrite_mode"]');
    const absoluteUrlOptionsDiv = $('#wp-static-exporter-absolute-url-options');
    const absoluteSchemeSelect = $('#wp-static-exporter-absolute-scheme');
    const absoluteUrlInput = $('#wp-static-exporter-absolute-url');

    // Hide status div initially
    statusDiv.hide();
    deployLinkDiv.empty();

    // --- URL Rewriting UI Logic ---
    urlRewriteRadios.on('change', function() {
        if ($(this).val() === 'absolute') {
            absoluteUrlOptionsDiv.slideDown();
        } else {
            absoluteUrlOptionsDiv.slideUp();
        }
    });
    // Trigger change on load to set initial state
    $('input[name="url_rewrite_mode"]:checked').trigger('change');


    // --- Generate Export ---
    generateBtn.on('click', function() {
        generateBtn.prop('disabled', true).text(wpStaticExporter.text.generating);
        statusDiv.empty().html('<p>' + wpStaticExporter.text.generating + '</p>').show();
        downloadLinkDiv.empty();
        deployLinkDiv.empty();

        const optimizeOutput = optimizeCheckbox.is(':checked') ? '1' : '0';
        const useFaCdn = useFaCdnCheckbox.is(':checked') ? '1' : '0';
        const convertForms = convertFormsCheckbox.is(':checked') ? '1' : '0';
        const recipientEmailOverride = convertForms === '1' ? recipientEmailInput.val() : '';

        // URL Rewriting Data
        const urlRewriteMode = $('input[name="url_rewrite_mode"]:checked').val();
        const absoluteScheme = absoluteSchemeSelect.val();
        const absoluteUrl = absoluteUrlInput.val();

        // Validation
        if (convertForms === '1' && recipientEmailOverride !== '' && !isValidEmail(recipientEmailOverride)) {
             handleAjaxError('Please enter a valid recipient email address.');
             generateBtn.prop('disabled', false).text(originalButtonText);
             return;
        }
        if (urlRewriteMode === 'absolute' && absoluteUrl.trim() === '') {
            handleAjaxError('Please enter the Base URL for absolute path rewriting.');
            generateBtn.prop('disabled', false).text(originalButtonText);
            return;
        }

        $.ajax({
            url: wpStaticExporter.ajax_url, type: 'POST',
            data: {
                action: 'wp_static_export_run', nonce: wpStaticExporter.generate_nonce,
                optimize_output: optimizeOutput, use_fa_cdn: useFaCdn,
                convert_forms: convertForms, recipient_email_override: recipientEmailOverride,
                // URL Rewriting params
                url_rewrite_mode: urlRewriteMode,
                absolute_scheme: absoluteScheme,
                absolute_url: absoluteUrl,
                generate_404: generate404Checkbox.is(':checked') ? '1' : '0'
            },
            success: function(response) {
                let progressHTML = '';
                if (response.data && response.data.progress && Array.isArray(response.data.progress)) {
                    progressHTML = response.data.progress.map(escapeHtml).join('<br>');
                }
                if (response.success) {
                    statusDiv.html('<p style="color: green;">' + escapeHtml(response.data.message) + '</p><hr>' + progressHTML);
                    downloadLinkDiv.html('<p><strong>' + wpStaticExporter.text.success + '</strong><br><a href="' + escapeHtml(response.data.download_url) + '" class="button button-primary" download>' + wpStaticExporter.text.download + '</a></p>');
                    if (response.data.new_export) { addNewExportRow(response.data.new_export); }
                } else { handleAjaxError(response.data); }
                generateBtn.prop('disabled', false).text(originalButtonText);
            },
            error: function(jqXHR, textStatus, errorThrown) {
                handleAjaxError(textStatus + ' - ' + errorThrown);
                generateBtn.prop('disabled', false).text(originalButtonText);
            }
        });
    });

    // --- Delete Export ---
    exportsListDiv.on('click', '.wp-static-exporter-delete-btn', function() {
        const deleteBtn = $(this); const filename = deleteBtn.data('filename'); const row = deleteBtn.closest('tr');
        if (!filename) { console.error('Delete button missing filename data.'); return; }
        if (confirm(wpStaticExporter.text.confirm_delete)) {
            deleteBtn.prop('disabled', true).text(wpStaticExporter.text.deleting);
            statusDiv.html('<p>' + wpStaticExporter.text.deleting + ' ' + escapeHtml(filename) + '...</p>').show();
            downloadLinkDiv.empty(); deployLinkDiv.empty();
            $.ajax({
                url: wpStaticExporter.ajax_url, type: 'POST',
                data: { action: 'wp_static_export_delete', nonce: wpStaticExporter.delete_nonce, filename: filename },
                success: function(response) {
                    if (response.success) {
                        statusDiv.html('<p style="color: green;">' + escapeHtml(response.data) + '</p>');
                        row.fadeOut(400, function() { $(this).remove(); if ($('#wp-static-exporter-exports-table tbody tr').length === 0) { exportsListDiv.html('<p>' + wpStaticExporter.text.noExports + '</p>'); } });
                    } else { handleAjaxError(response.data); deleteBtn.prop('disabled', false).text(wpStaticExporter.text.delete); }
                },
                error: function(jqXHR, textStatus, errorThrown) { handleAjaxError(textStatus + ' - ' + errorThrown); deleteBtn.prop('disabled', false).text(wpStaticExporter.text.delete); }
            });
        }
    });

    // --- Deploy Export ---
    exportsListDiv.on('click', '.wp-static-exporter-deploy-btn', function() {
        const deployBtn = $(this); const filename = deployBtn.data('filename');
        if (!filename) { console.error('Deploy button missing filename data.'); return; }
        const folderName = prompt(wpStaticExporter.text.prompt_deploy_folder, 'my-static-site');
        if (folderName === null) { return; }
        if (folderName.trim() === '' || folderName.includes('/') || folderName.includes('\\') || folderName.includes('..')) { alert(wpStaticExporter.text.invalid_folder_name); return; }
        deployBtn.prop('disabled', true).text(wpStaticExporter.text.deploying);
        statusDiv.html('<p>' + wpStaticExporter.text.deploying + ' ' + escapeHtml(filename) + ' to folder "' + escapeHtml(folderName) + '"...</p>').show();
        downloadLinkDiv.empty(); deployLinkDiv.empty();
        $.ajax({
            url: wpStaticExporter.ajax_url, type: 'POST',
            data: { action: 'wp_static_export_deploy', nonce: wpStaticExporter.deploy_nonce, zip_filename: filename, deploy_folder: folderName },
            success: function(response) {
                if (response.success) {
                    statusDiv.html('<p style="color: green;">' + escapeHtml(response.data.message) + '</p>');
                    deployLinkDiv.html('<p><strong>' + wpStaticExporter.text.deploy_success + '</strong> <a href="' + escapeHtml(response.data.deploy_url) + '" target="_blank">' + escapeHtml(response.data.deploy_url) + '</a></p>');
                } else { handleAjaxError(response.data); }
                 deployBtn.prop('disabled', false).text(wpStaticExporter.text.deploy);
            },
            error: function(jqXHR, textStatus, errorThrown) { handleAjaxError(textStatus + ' - ' + errorThrown); deployBtn.prop('disabled', false).text(wpStaticExporter.text.deploy); }
        });
    });

    // --- Helper Functions ---
    function handleAjaxError(errorData) {
        let errorMessage = wpStaticExporter.text.error; let progressHTML = '';
        if (typeof errorData === 'string') { errorMessage += ' ' + escapeHtml(errorData); }
        else if (typeof errorData === 'object') {
            if (errorData.message) { errorMessage += ' ' + escapeHtml(errorData.message); }
            if (errorData.progress && Array.isArray(errorData.progress)) { progressHTML = errorData.progress.map(escapeHtml).join('<br>'); }
        }
        statusDiv.html('<p style="color: red;">' + errorMessage + '</p><hr>' + progressHTML).show();
    }
    function addNewExportRow(exportData) {
        if (!exportData || !exportData.timestamp || !exportData.filename || !exportData.url) { console.error("Invalid export data received for adding row:", exportData); return; }
        let tableBody = $('#wp-static-exporter-exports-table tbody');
        if (tableBody.length === 0) {
            exportsListDiv.find('p').remove();
            exportsListDiv.html('<table class="wp-list-table widefat fixed striped" id="wp-static-exporter-exports-table"><thead><tr><th>Date Created</th><th>Filename</th><th>Actions</th></tr></thead><tbody></tbody></table>');
            tableBody = $('#wp-static-exporter-exports-table tbody');
        }
        const date = new Date(exportData.timestamp * 1000);
        const formattedDate = date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
        const newRow = `
            <tr data-filename="${escapeHtml(exportData.filename)}">
                <td>${escapeHtml(formattedDate)}</td>
                <td>${escapeHtml(exportData.filename)}</td>
                <td>
                    <a href="${escapeHtml(exportData.url)}" class="button button-small" download>${wpStaticExporter.text.download}</a>
                    <button type="button" class="button button-small wp-static-exporter-delete-btn" data-filename="${escapeHtml(exportData.filename)}">${wpStaticExporter.text.delete}</button>
                    <button type="button" class="button button-small wp-static-exporter-deploy-btn" data-filename="${escapeHtml(exportData.filename)}">${wpStaticExporter.text.deploy}</button>
                </td>
            </tr>
        `;
        tableBody.prepend(newRow);
    }
    function escapeHtml(unsafe) {
        if (typeof unsafe !== 'string') return unsafe;
        return unsafe.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
     }
     function isValidEmail(email) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return emailRegex.test(email);
    }
});
