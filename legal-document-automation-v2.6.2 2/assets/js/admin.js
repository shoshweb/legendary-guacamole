jQuery(document).ready(function($) {
    console.log('LDA Admin initialized');

    // Reusable notification function
    function showNotification(message, type = 'info', autoHide = true) {
        var $notification = $('<div class="notice is-dismissible"></div>');
        $notification.addClass('notice-' + type);
        
        // Add close button for manual dismissal
        var closeButton = '<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>';
        $notification.html('<p>' + message + '</p>' + closeButton);
        
        $('.wrap h1').after($notification);
        
        // Handle manual dismissal
        $notification.find('.notice-dismiss').on('click', function() {
            $notification.fadeOut('slow', function() {
                $(this).remove();
            });
        });
        
        // Auto-hide for success messages, but keep validation messages longer
        if (autoHide) {
            var timeout = (type === 'success') ? 8000 : 15000; // 8s for success, 15s for validation
            setTimeout(function() {
                if ($notification.is(':visible')) {
                    $notification.fadeOut('slow', function() {
                        $(this).remove();
                    });
                }
            }, timeout);
        }
    }

    // --- Test Email Handler ---
    $(document).on('click', '#save_test_email', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var testEmail = $('#test_email').val();
        
        $button.prop('disabled', true).text('Saving...');
        
        $.ajax({
            url: lda_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'lda_save_test_email',
                test_email: testEmail,
                nonce: lda_admin.nonce
            }
        })
        .done(function(response) {
            if (response.success) {
                showNotification('Test email address saved successfully!', 'success');
            } else {
                showNotification('Failed to save test email: ' + (response.data || 'Unknown error'), 'error');
            }
        })
        .fail(function(xhr, status, error) {
            console.error('Save test email AJAX error:', status, error);
            showNotification('An unexpected error occurred while saving test email.', 'error');
        })
        .always(function() {
            $button.prop('disabled', false).text('Save Test Email');
        });
    });

    // --- Template Button Handlers ---

    // Validate Template Handler
    $(document).on('click', '.validate-template', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var template = JSON.parse($button.data('template'));
        
        console.log('Raw data-template:', $button.data('template'));
        console.log('Parsed template:', template);
        console.log('Template type:', typeof template);
        $button.prop('disabled', true).text('Validating...');
        
        $.ajax({
            url: lda_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'lda_validate_template',
                template_file: template,
                nonce: lda_admin.nonce
            }
        })
        .done(function(response) {
            console.log('Validation response:', response);
            if (response.success) {
                var message = response.data.message || 'Template validation completed successfully.';
                var details = response.data.details || '';
                var fullMessage = message + (details ? '<br><br><strong>Validation Details:</strong><br><pre style="background: #f1f1f1; padding: 10px; border-radius: 4px; white-space: pre-wrap; font-size: 12px; max-height: 300px; overflow-y: auto;">' + details + '</pre>' : '');
                showNotification(fullMessage, 'success', false); // Don't auto-hide validation messages
            } else {
                var errorMessage = response.data.message || 'Template validation failed.';
                var details = response.data.details || '';
                var fullMessage = errorMessage + (details ? '<br><br><strong>Error Details:</strong><br><pre style="background: #f1f1f1; padding: 10px; border-radius: 4px; white-space: pre-wrap; font-size: 12px; max-height: 300px; overflow-y: auto;">' + details + '</pre>' : '');
                showNotification(fullMessage, 'error', false); // Don't auto-hide validation messages
            }
        })
        .fail(function(xhr, status, error) {
            console.error('Validation AJAX error:', status, error);
            showNotification('An unexpected error occurred during validation. Check the browser console for details.', 'error');
        })
        .always(function() {
            $button.prop('disabled', false).text('Validate');
        });
    });

    // Test Template Handler
    $(document).on('click', '.test-template', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var template = JSON.parse($button.data('template'));
        
        console.log('Testing template:', template);
        $button.prop('disabled', true).text('Testing...');
        
        $.ajax({
            url: lda_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'lda_test_template',
                template_file: template,
                nonce: lda_admin.nonce
            }
        })
        .done(function(response) {
            console.log('Test response:', response);
            if (response.success) {
                var message = response.data.message || 'Template test completed successfully.';
                var fullMessage = message;
                
                // Add email test results
                if (response.data.email_sent) {
                    fullMessage += '<br><br><strong>✅ Email Test:</strong> Test document sent successfully!';
                } else if (response.data.email_error) {
                    fullMessage += '<br><br><strong>❌ Email Test Failed:</strong> ' + response.data.email_error;
                } else {
                    fullMessage += '<br><br><strong>ℹ️ Email Test:</strong> Skipped (no test email configured)';
                }
                
                // Add Google Drive test results
                if (response.data.google_drive_uploaded) {
                    fullMessage += '<br><br><strong>✅ Google Drive Test:</strong> Document uploaded successfully!';
                    if (response.data.gdrive_url) {
                        fullMessage += '<br><a href="' + response.data.gdrive_url + '" target="_blank">View in Google Drive</a>';
                    }
                } else if (response.data.gdrive_error) {
                    fullMessage += '<br><br><strong>❌ Google Drive Test Failed:</strong> ' + response.data.gdrive_error;
                } else {
                    fullMessage += '<br><br><strong>ℹ️ Google Drive Test:</strong> Skipped (not enabled)';
                }
                
                showNotification(fullMessage, 'success', false); // Don't auto-hide comprehensive test results
            } else {
                var errorMessage = response.data.message || 'Template test failed.';
                var details = response.data.details || '';
                var fullMessage = errorMessage + (details ? '<br><br><strong>Error Details:</strong><br><pre style="background: #f1f1f1; padding: 10px; border-radius: 4px; white-space: pre-wrap; font-size: 12px; max-height: 300px; overflow-y: auto;">' + details + '</pre>' : '');
                showNotification(fullMessage, 'error', false); // Don't auto-hide error messages
            }
        })
        .fail(function(xhr, status, error) {
            console.error('Test AJAX error:', status, error);
            showNotification('An unexpected error occurred during testing. Check the browser console for details.', 'error');
        })
        .always(function() {
            $button.prop('disabled', false).text('Test');
        });
    });

    $(document).on('click', '.delete-template', function(e) {
        e.preventDefault();
        
        if (!confirm(lda_admin.strings.confirm_delete || 'Are you sure you want to delete this template?')) {
            return;
        }

        var $button = $(this);
        var template = JSON.parse($button.data('template'));
        var $row = $button.closest('tr');

        console.log('Deleting template:', template);
        $button.prop('disabled', true).text('Deleting...');

        $.ajax({
            url: lda_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'lda_delete_template',
                template: template,
                nonce: lda_admin.nonce
            }
        })
        .done(function(response) {
            console.log('Delete response:', response);
            if (response.success) {
                showNotification('Template "' + template + '" deleted successfully.', 'success');
                $row.fadeOut('slow', function() {
                    $(this).remove();
                });
            } else {
                showNotification('Error deleting template: ' + response.data, 'error');
            }
        })
        .fail(function(xhr, status, error) {
            console.error('AJAX error:', status, error);
            showNotification('An unexpected error occurred while deleting the template. Check the browser console for details.', 'error');
        })
        .always(function() {
            $button.prop('disabled', false).text('Delete');
        });
    });

    // --- Email Test Handler ---

    $(document).on('click', '#send-test-email', function(e) {
        e.preventDefault();
        
        var testEmail = $('#test-email').val();
        if (!testEmail) {
            showNotification('Please enter a test email address.', 'error');
            return;
        }
        
        if (!isValidEmail(testEmail)) {
            showNotification('Please enter a valid email address.', 'error');
            return;
        }
        
        var $button = $(this);
        var $result = $('#email-test-result');
        
        $button.prop('disabled', true).text(lda_admin.strings.testing || 'Testing...');
        $result.html('<p>Sending test email...</p>');
        
        $.ajax({
            url: lda_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'lda_test_email',
                test_email: testEmail,
                nonce: lda_admin.nonce
            }
        })
        .done(function(response) {
            console.log('Email test response:', response);
            if (response.success) {
                var message = typeof response.data === 'string' ? response.data : 'Test email sent successfully!';
                $result.html('<div class="notice notice-success inline"><p>' + message + '</p></div>');
                showNotification('Test email sent successfully!', 'success');
            } else {
                var errorMessage = typeof response.data === 'string' ? response.data : 'Failed to send test email.';
                $result.html('<div class="notice notice-error inline"><p>' + errorMessage + '</p></div>');
                showNotification('Failed to send test email: ' + errorMessage, 'error');
            }
        })
        .fail(function(xhr, status, error) {
            console.error('Email test AJAX error:', status, error);
            $result.html('<div class="notice notice-error inline"><p>An unexpected error occurred. Check the browser console for details.</p></div>');
            showNotification('An unexpected error occurred while testing email.', 'error');
        })
        .always(function() {
            $button.prop('disabled', false).text('Send Test Email');
        });
    });

    // Email validation helper
    function isValidEmail(email) {
        var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return emailRegex.test(email);
    }

    // --- Google Drive Credentials Upload Handler ---


    $(document).on('click', '#upload_credentials', function(e) {
        e.preventDefault();
        console.log('Upload credentials button clicked!');
        
        var fileInput = document.getElementById('gdrive_credentials_upload');
        console.log('File input found:', fileInput);
        
        if (!fileInput) {
            console.error('File input not found!');
            showNotification('File input not found. Please refresh the page.', 'error');
            return;
        }
        
        var file = fileInput.files[0];
        console.log('Selected file:', file);
        
        if (!file) {
            showNotification('Please select a credentials file to upload.', 'error');
            return;
        }
        
        if (file.type !== 'application/json') {
            showNotification('Please select a valid JSON file.', 'error');
            return;
        }
        
        var $button = $(this);
        var $result = $('#upload_result');
        
        $button.prop('disabled', true).text('Uploading...');
        $result.html('<p>Uploading credentials file...</p>');
        
        console.log('lda_admin object:', typeof lda_admin !== 'undefined' ? lda_admin : 'UNDEFINED');
        
        if (typeof lda_admin === 'undefined') {
            console.error('lda_admin object not available!');
            showNotification('Admin configuration not loaded. Please refresh the page.', 'error');
            return;
        }
        
        var formData = new FormData();
        formData.append('action', 'lda_upload_gdrive_credentials');
        formData.append('credentials_file', file);
        formData.append('nonce', lda_admin.nonce);
        
        console.log('Form data prepared, sending AJAX request...');
        
        $.ajax({
            url: lda_admin.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false
        })
        .done(function(response) {
            console.log('Credentials upload response:', response);
            if (response.success) {
                $result.html('<div class="notice notice-success inline"><p>' + response.data + '</p></div>');
                showNotification('Google Drive credentials uploaded successfully!', 'success');
                // Reload the page to show the updated status
                setTimeout(function() {
                    location.reload();
                }, 2000);
            } else {
                $result.html('<div class="notice notice-error inline"><p>' + response.data + '</p></div>');
                showNotification('Failed to upload credentials: ' + response.data, 'error');
            }
        })
        .fail(function(xhr, status, error) {
            console.error('Credentials upload AJAX error:', status, error);
            $result.html('<div class="notice notice-error inline"><p>An unexpected error occurred. Check the browser console for details.</p></div>');
            showNotification('An unexpected error occurred while uploading credentials.', 'error');
        })
        .always(function() {
            $button.prop('disabled', false).text('Upload Credentials');
        });
    });

    // --- Log Button Handlers ---

    $(document).on('click', '#refresh-logs', function() {
        console.log('Refreshing logs...');
        $(this).prop('disabled', true).text('Refreshing...');
        
        $.ajax({
            url: lda_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'lda_get_logs',
                nonce: lda_admin.nonce
            }
        })
        .done(function(response) {
            if (response.success) {
                $('#log-entries').html(response.data);
                showNotification('Logs refreshed.', 'info');
            } else {
                showNotification('Failed to refresh logs.', 'error');
            }
        })
        .fail(function() {
            showNotification('An error occurred while refreshing logs.', 'error');
        })
        .always(function() {
            $('#refresh-logs').prop('disabled', false).text('Refresh');
        });
    });

    $(document).on('click', '#copy-logs', function() {
        console.log('Copying logs to clipboard...');
        var $button = $(this);
        var originalText = $button.text();
        
        $button.prop('disabled', true).text('Copying...');
        
        // Get all log entries text
        var logText = '';
        $('#log-entries .log-entry').each(function() {
            var $entry = $(this);
            var timestamp = $entry.find('.log-timestamp').text() || '';
            var level = $entry.find('.log-level').text() || '';
            var message = $entry.find('.log-message').text() || '';
            
            // Clean up the text and format it nicely
            logText += timestamp + ' ' + level + ' ' + message + '\n';
        });
        
        // If no log entries found, try to get the raw text
        if (!logText.trim()) {
            logText = $('#log-entries').text();
        }
        
        // Clean up the text
        logText = logText.trim();
        
        if (!logText) {
            showNotification('No logs to copy.', 'warning');
            $button.prop('disabled', false).text(originalText);
            return;
        }
        
        // Add a header to the copied text
        var headerText = '=== A Legal Documents Plugin Logs ===\n';
        headerText += 'Copied on: ' + new Date().toLocaleString() + '\n';
        headerText += '=====================================\n\n';
        
        var fullText = headerText + logText;
        
        // Use the modern clipboard API if available
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(fullText).then(function() {
                showNotification('Logs copied to clipboard successfully!', 'success');
            }).catch(function(err) {
                console.error('Failed to copy to clipboard:', err);
                fallbackCopyTextToClipboard(fullText);
            });
        } else {
            // Fallback for older browsers
            fallbackCopyTextToClipboard(fullText);
        }
        
        $button.prop('disabled', false).text(originalText);
    });
    
    // Fallback copy function for older browsers
    function fallbackCopyTextToClipboard(text) {
        var textArea = document.createElement("textarea");
        textArea.value = text;
        
        // Avoid scrolling to bottom
        textArea.style.top = "0";
        textArea.style.left = "0";
        textArea.style.position = "fixed";
        textArea.style.opacity = "0";
        
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        
        try {
            var successful = document.execCommand('copy');
            if (successful) {
                showNotification('Logs copied to clipboard successfully!', 'success');
            } else {
                showNotification('Failed to copy logs. Please select and copy manually.', 'error');
            }
        } catch (err) {
            console.error('Fallback copy failed:', err);
            showNotification('Failed to copy logs. Please select and copy manually.', 'error');
        }
        
        document.body.removeChild(textArea);
    }

    $(document).on('click', '#clear-logs', function() {
        if (!confirm('Are you sure you want to clear all logs? This cannot be undone.')) {
            return;
        }

        console.log('Clearing logs...');
        $(this).prop('disabled', true).text('Clearing...');

        $.ajax({
            url: lda_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'lda_clear_logs',
                nonce: lda_admin.nonce
            }
        })
        .done(function(response) {
            if (response.success) {
                $('#log-entries').html('<p>No log entries found.</p>');
                showNotification('Logs cleared successfully.', 'success');
            } else {
                showNotification('Failed to clear logs: ' + response.data, 'error');
            }
        })
        .fail(function() {
            showNotification('An error occurred while clearing logs.', 'error');
        })
        .always(function() {
            $('#clear-logs').prop('disabled', false).text('Clear Logs');
        });
    });

    // --- Field Mapping Handlers ---
    
    // Add mapping row
    $(document).on('click', '#add_mapping_row', function(e) {
        e.preventDefault();
        
        var $table = $('#field_mapping_table tbody');
        var rowCount = $table.find('tr').length;
        var formId = $('select[name="form_id"]').val();
        
        // Debug: Log the form selector and its value
        console.log('Form selector found:', $('select[name="form_id"]').length);
        console.log('Form ID value:', formId);
        
        if (!formId) {
            showNotification('Please select a form first.', 'error');
            return;
        }
        
        // Get form fields from existing select elements (fallback approach)
        var $firstSelect = $table.find('select[name^="field_ids"]:first');
        var options = '';
        
        if ($firstSelect.length > 0) {
            // Clone options from existing select
            options = $firstSelect.html();
        } else {
            // Fallback: create basic options
            options = '<option value="">Select field...</option>';
        }
        
        var newRow = `
            <tr class="mapping-row">
                <td><input type="text" name="merge_tags[${rowCount}]" placeholder="{$USR_Name}" class="regular-text" style="width: 100%;" /></td>
                <td>
                    <select name="field_ids[${rowCount}]" style="width: 100%;">
                        ${options}
                    </select>
                </td>
                <td><button type="button" class="button remove-row" style="color: #d63638;">−</button></td>
            </tr>
        `;
        
        $table.append(newRow);
        
        // Try AJAX approach for better field filtering (optional enhancement)
        if (typeof lda_admin !== 'undefined' && lda_admin.ajax_url) {
            // Get currently assigned field IDs to exclude them
            var excludeFields = [];
            $table.find('select[name^="field_ids"]').each(function() {
                var fieldId = $(this).val();
                if (fieldId && fieldId !== '') {
                    excludeFields.push(fieldId);
                }
            });
            
            // Get available form fields via AJAX (enhancement)
            $.ajax({
                url: lda_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'lda_get_form_fields',
                    form_id: formId,
                    exclude_fields: excludeFields,
                    nonce: lda_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        var $newSelect = $table.find('tr:last select[name^="field_ids"]');
                        var newOptions = '<option value="">Select field...</option>';
                        $.each(response.data, function(fieldId, fieldLabel) {
                            newOptions += '<option value="' + fieldId + '">' + fieldLabel + '</option>';
                        });
                        $newSelect.html(newOptions);
                    }
                },
                error: function() {
                    // Silently fail - the basic functionality still works
                    console.log('AJAX enhancement failed, but basic add row still works');
                }
            });
        }
    });
    
    // Remove mapping row
    $(document).on('click', '.remove-row', function(e) {
        e.preventDefault();
        $(this).closest('tr').remove();
    });
    
    // Assign template to form - confirm button
    $(document).on('click', '.assign-template-confirm', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var $select = $button.siblings('.assign-template-form');
        var formId = $select.val();
        var template = JSON.parse($button.data('template'));
        
        if (!formId) {
            showNotification('Please select a form first.', 'error');
            return;
        }
        
        if (formId && template) {
            $button.prop('disabled', true).text('Assigning...');
            
            $.ajax({
                url: lda_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'lda_assign_template',
                    form_id: formId,
                    template: template,
                    nonce: lda_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Show prominent success message right next to the button
                        var $successDiv = $('<div class="notice notice-success inline" style="margin-top: 5px; padding: 8px 12px; font-size: 13px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; color: #155724;"><p style="margin: 0; font-weight: 600;">✓ ' + response.data + '</p></div>');
                        $button.after($successDiv);
                        
                        // Update the display with green checkmark
                        var $assignedCell = $button.closest('tr').find('td:nth-child(5)');
                        var formName = $select.find('option:selected').text().replace('Form #' + formId + ': ', '');
                        $assignedCell.html('<span class="assigned-form" style="color: #46b450; font-weight: 600;">✓ Form #' + formId + ': ' + formName + '</span>');
                        $button.prop('disabled', false).text('Assign Template');
                        
                        // Remove success message after 4 seconds
                        setTimeout(function() {
                            $successDiv.fadeOut(function() {
                                $successDiv.remove();
                            });
                        }, 4000);
                    } else {
                        showNotification(response.data, 'error');
                        $button.prop('disabled', false).text('Assign Template');
                    }
                },
                error: function() {
                    showNotification('An error occurred while assigning template.', 'error');
                    $button.prop('disabled', false).text('Assign Template');
                }
            });
        }
    });
    
    // Auto-populate merge tags
    $(document).on('click', '.auto-populate-tags', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var formId = $button.data('form-id');
        var template = JSON.parse($button.data('template'));
        
        if (formId && template) {
            $button.prop('disabled', true).text('Auto-populating...');
            
            $.ajax({
                url: lda_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'lda_auto_populate',
                    form_id: formId,
                    template: template,
                    nonce: lda_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        showNotification(response.data.message, 'success');
                        // Reload the page to show auto-populated fields
                        setTimeout(function() {
                            window.location.reload();
                        }, 2000);
                    } else {
                        showNotification(response.data, 'error');
                    }
                },
                error: function() {
                    showNotification('An error occurred while auto-populating merge tags.', 'error');
                },
                always: function() {
                    $button.prop('disabled', false).text('Auto-Populate Merge Tags');
                }
            });
        }
    });
    
    // Save field mappings
    $(document).on('submit', '#field_mapping_form', function(e) {
        e.preventDefault();
        
        var $form = $(this);
        var $submitBtn = $form.find('input[type="submit"]');
        var formId = $form.find('input[name="form_id"]').val();
        
        // Prevent multiple submissions
        if ($submitBtn.prop('disabled')) {
            console.log('Save already in progress, ignoring duplicate submission');
            return false;
        }
        
        $submitBtn.prop('disabled', true).val('Saving...');
        
        var formData = new FormData($form[0]);
        formData.append('action', 'lda_save_field_mapping');
        formData.append('nonce', lda_admin.nonce);
        
        $.ajax({
            url: lda_admin.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            timeout: 30000, // 30 second timeout
            success: function(response) {
                console.log('Save response:', response);
                if (response.success) {
                    showNotification(response.data, 'success');
                    // Reload the page to show updated mappings
                    setTimeout(function() {
                        window.location.reload();
                    }, 1500);
                } else {
                    showNotification(response.data, 'error');
                    // Reset button on error
                    $submitBtn.prop('disabled', false).val('Save Mappings');
                }
            },
            error: function(xhr, status, error) {
                console.error('Save error:', status, error);
                var errorMessage = 'An error occurred while saving field mappings.';
                if (status === 'timeout') {
                    errorMessage = 'Save request timed out. Please try again.';
                } else if (xhr.responseText) {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        if (response.data) {
                            errorMessage = response.data;
                        }
                    } catch (e) {
                        // Use default error message
                    }
                }
                showNotification(errorMessage, 'error');
                // Reset button on error
                $submitBtn.prop('disabled', false).val('Save Mappings');
            },
            complete: function() {
                // This runs after success or error
                console.log('Save request completed');
            }
        });
    });

    // Manual reset for stuck save button (double-click to reset)
    $(document).on('dblclick', 'input[type="submit"][value="Saving..."]', function() {
        console.log('Manual reset of stuck save button');
        $(this).prop('disabled', false).val('Save Mappings');
        showNotification('Save button reset. You can try saving again.', 'info');
    });

    // Reset save button after 10 seconds if still stuck
    $(document).on('click', 'input[type="submit"]', function() {
        var $btn = $(this);
        if ($btn.val() === 'Saving...') {
            setTimeout(function() {
                if ($btn.val() === 'Saving...' && $btn.prop('disabled')) {
                    console.log('Auto-resetting stuck save button after 10 seconds');
                    $btn.prop('disabled', false).val('Save Mappings');
                    showNotification('Save button was stuck and has been reset. Please try again.', 'warning');
                }
            }, 10000);
        }
    });

});
