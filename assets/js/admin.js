/**
 * Image Size Manager Admin JavaScript
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        var regenerateButton = $('#ism-regenerate-button');
        var progressContainer = $('#ism-regenerate-progress-container');
        var progressBar = $('#ism-regenerate-progress');
        var statusText = $('#ism-regenerate-status');
        
        var attachmentIds = [];
        var totalAttachments = 0;
        var currentIndex = 0;
        var isRegenerating = false;
        var failedAttachments = [];
        var successCount = 0;
        
        // Initialize progress bar
        progressBar.progressbar({
            value: 0
        });
        
        // Add sorting functionality
        $('.ism-table th').each(function(index) {
            if (index > 0 && index < 6) { // Skip the first column (name) and last column (status)
                $(this).css('cursor', 'pointer');
                $(this).append(' <span class="sort-indicator">▼</span>');
                $(this).data('sort-direction', 'desc');
                
                $(this).on('click', function() {
                    sortTable(index, $(this));
                });
            }
        });
        
        function sortTable(columnIndex, headerElement) {
            var table = $('.ism-table');
            var rows = table.find('tbody tr').get();
            var sortDirection = headerElement.data('sort-direction') === 'asc' ? 'desc' : 'asc';
            
            // Reset all sort indicators
            $('.ism-table th .sort-indicator').text('▼');
            $('.ism-table th').data('sort-direction', 'desc');
            
            // Update current sort indicator and direction
            headerElement.data('sort-direction', sortDirection);
            headerElement.find('.sort-indicator').text(sortDirection === 'asc' ? '▲' : '▼');
            
            rows.sort(function(a, b) {
                var A = $(a).children('td').eq(columnIndex).text();
                var B = $(b).children('td').eq(columnIndex).text();
                
                // Handle numeric sorting for width, height, usage count
                if (columnIndex === 1 || columnIndex === 2 || columnIndex === 4) {
                    A = parseInt(A) || 0;
                    B = parseInt(B) || 0;
                }
                
                // Handle MB size sorting
                if (columnIndex === 5) {
                    A = parseFloat(A) || 0;
                    B = parseFloat(B) || 0;
                }
                
                if (A < B) {
                    return sortDirection === 'asc' ? -1 : 1;
                }
                if (A > B) {
                    return sortDirection === 'asc' ? 1 : -1;
                }
                return 0;
            });
            
            $.each(rows, function(index, row) {
                table.children('tbody').append(row);
            });
            
            // Update zebra striping
            table.find('tbody tr').removeClass('alternate');
            table.find('tbody tr:nth-child(even)').addClass('alternate');
            
            return false;
        }
        
        // Handle regenerate button click
        regenerateButton.on('click', function(e) {
            e.preventDefault();

            if (isRegenerating) {
                return;
            }

            isRegenerating = true;
            regenerateButton.attr('disabled', 'disabled');
            progressContainer.show();
            statusText.text(ism_data.regenerate_start);
            failedAttachments = [];
            successCount = 0;

            // First, store the current memory usage
            $.ajax({
                url: ism_data.ajax_url,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'ism_store_memory',
                    nonce: ism_data.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Now get all attachment IDs
                        $.ajax({
                            url: ism_data.ajax_url,
                            type: 'POST',
                            dataType: 'json',
                            data: {
                                action: 'ism_regenerate_thumbnails',
                                nonce: ism_data.nonce,
                                attachment_id: 0
                            },
                            success: function(response) {
                                if (response.success) {
                                    attachmentIds = response.data.ids;
                                    totalAttachments = response.data.total;
                                    currentIndex = 0;

                                    if (totalAttachments > 0) {
                                        processNextAttachment();
                                    } else {
                                        completeRegeneration();
                                    }
                                } else {
                                    handleError(response.data);
                                }
                            },
                            error: function(jqXHR, textStatus, errorThrown) {
                                handleError(textStatus + ': ' + errorThrown);
                            }
                        });
                    } else {
                        handleError(response.data);
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    handleError(textStatus + ': ' + errorThrown);
                }
            });
        });
        
        // Process next attachment
        function processNextAttachment() {
            if (currentIndex >= totalAttachments) {
                completeRegeneration();
                return;
            }

            var attachmentId = attachmentIds[currentIndex];
            var progress = Math.floor((currentIndex / totalAttachments) * 100);

            // Update progress bar
            progressBar.progressbar('value', progress);
            statusText.text(
                ism_data.regenerate_processing
                    .replace('%1$s', currentIndex + 1)
                    .replace('%2$s', totalAttachments)
                    .replace('%3$s', progress)
            );

            // Process attachment
            $.ajax({
                url: ism_data.ajax_url,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'ism_regenerate_thumbnails',
                    nonce: ism_data.nonce,
                    attachment_id: attachmentId
                },
                success: function(response) {
                    if (response.success) {
                        successCount++;
                    } else {
                        // Log error but continue processing
                        var errorMsg = typeof response.data === 'object' ? response.data.message : response.data;
                        var errorData = {
                            attachment_id: attachmentId,
                            error: errorMsg
                        };
                        if (typeof response.data === 'object') {
                            errorData.error_code = response.data.error_code;
                        }
                        failedAttachments.push(errorData);
                        console.error('Failed to regenerate attachment ' + attachmentId + ':', errorMsg);
                    }
                    // Always continue to next attachment
                    currentIndex++;
                    processNextAttachment();
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    // Log AJAX error but continue processing
                    failedAttachments.push({
                        attachment_id: attachmentId,
                        error: textStatus + ': ' + errorThrown
                    });
                    console.error('AJAX error for attachment ' + attachmentId + ':', textStatus, errorThrown);
                    currentIndex++;
                    processNextAttachment();
                }
            });
        }
        
        // Complete regeneration
        function completeRegeneration() {
            progressBar.progressbar('value', 100);

            // Build summary message
            var summaryMsg = 'Regeneration complete! ';
            summaryMsg += 'Success: ' + successCount + ' image(s)';

            if (failedAttachments.length > 0) {
                summaryMsg += ', Failed: ' + failedAttachments.length + ' image(s)';
                summaryMsg += ' (see console for details)';

                // Log detailed error information
                console.group('Image Regeneration Errors');
                console.log('Total failed:', failedAttachments.length);
                console.table(failedAttachments);
                console.groupEnd();

                // Add visual indicator for warnings
                statusText.html(summaryMsg + ' <span style="color: #d63638;">⚠</span>');
            } else {
                statusText.text(summaryMsg);
            }

            // Show refresh button
            $('#ism-refresh-container').show();

            setTimeout(function() {
                isRegenerating = false;
                regenerateButton.removeAttr('disabled');
            }, 2000);
        }
        
        // Handle error
        function handleError(errorMessage) {
            statusText.text(ism_data.regenerate_error + errorMessage);
            isRegenerating = false;
            regenerateButton.removeAttr('disabled');
        }

        // Handle clear savings button click
        $('#ism-clear-savings').on('click', function(e) {
            e.preventDefault();

            if (!confirm('Are you sure you want to clear the memory savings data?')) {
                return;
            }

            $.ajax({
                url: ism_data.ajax_url,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'ism_clear_savings',
                    nonce: ism_data.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Reload the page to show updated stats
                        window.location.reload();
                    } else {
                        alert('Error clearing savings data');
                    }
                },
                error: function() {
                    alert('Error clearing savings data');
                }
            });
        });

        // Handle individual size regeneration
        var sizeAttachmentIds = [];
        var sizeTotalAttachments = 0;
        var sizeCurrentIndex = 0;
        var sizeIsRegenerating = false;
        var currentSizeName = '';
        var currentButton = null;
        var sizeFailedAttachments = [];
        var sizeSuccessCount = 0;

        $('.ism-regenerate-size').on('click', function(e) {
            e.preventDefault();

            if (sizeIsRegenerating || isRegenerating) {
                alert('A regeneration process is already running. Please wait for it to complete.');
                return;
            }

            currentButton = $(this);
            currentSizeName = currentButton.data('size');

            if (!confirm('Regenerate "' + currentSizeName + '" size for all images?\n\nThis will regenerate this specific image size for all images in your media library.')) {
                return;
            }

            sizeIsRegenerating = true;
            currentButton.attr('disabled', 'disabled').text('Regenerating...');
            progressContainer.show();
            statusText.text('Starting regeneration of "' + currentSizeName + '"...');
            sizeFailedAttachments = [];
            sizeSuccessCount = 0;

            // Get all attachment IDs
            $.ajax({
                url: ism_data.ajax_url,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'ism_regenerate_size',
                    nonce: ism_data.nonce,
                    size_name: currentSizeName,
                    attachment_id: 0
                },
                success: function(response) {
                    if (response.success) {
                        sizeAttachmentIds = response.data.ids;
                        sizeTotalAttachments = response.data.total;
                        sizeCurrentIndex = 0;

                        if (sizeTotalAttachments > 0) {
                            processSizeNextAttachment();
                        } else {
                            completeSizeRegeneration();
                        }
                    } else {
                        handleSizeError(response.data);
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    handleSizeError(textStatus + ': ' + errorThrown);
                }
            });
        });

        // Process next attachment for size regeneration
        function processSizeNextAttachment() {
            if (sizeCurrentIndex >= sizeTotalAttachments) {
                completeSizeRegeneration();
                return;
            }

            var attachmentId = sizeAttachmentIds[sizeCurrentIndex];
            var progress = Math.floor((sizeCurrentIndex / sizeTotalAttachments) * 100);

            // Update progress bar
            progressBar.progressbar('value', progress);
            statusText.text(
                'Regenerating "' + currentSizeName + '" for image ' +
                (sizeCurrentIndex + 1) + ' of ' + sizeTotalAttachments +
                ' (' + progress + '%)'
            );

            // Process attachment
            $.ajax({
                url: ism_data.ajax_url,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'ism_regenerate_size',
                    nonce: ism_data.nonce,
                    size_name: currentSizeName,
                    attachment_id: attachmentId
                },
                success: function(response) {
                    if (response.success) {
                        sizeSuccessCount++;
                    } else {
                        // Log error but continue processing
                        var errorMsg = typeof response.data === 'object' ? response.data.message : response.data;
                        var errorData = {
                            attachment_id: attachmentId,
                            size: currentSizeName,
                            error: errorMsg
                        };
                        if (typeof response.data === 'object') {
                            errorData.error_code = response.data.error_code;
                        }
                        sizeFailedAttachments.push(errorData);
                        console.error('Failed to regenerate ' + currentSizeName + ' for attachment ' + attachmentId + ':', errorMsg);
                    }
                    // Always continue to next attachment
                    sizeCurrentIndex++;
                    processSizeNextAttachment();
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    // Log AJAX error but continue processing
                    sizeFailedAttachments.push({
                        attachment_id: attachmentId,
                        size: currentSizeName,
                        error: textStatus + ': ' + errorThrown
                    });
                    console.error('AJAX error for ' + currentSizeName + ' attachment ' + attachmentId + ':', textStatus, errorThrown);
                    sizeCurrentIndex++;
                    processSizeNextAttachment();
                }
            });
        }

        // Complete size regeneration
        function completeSizeRegeneration() {
            progressBar.progressbar('value', 100);

            // Build summary message
            var summaryMsg = 'Regeneration of "' + currentSizeName + '" complete! ';
            summaryMsg += 'Success: ' + sizeSuccessCount + ' image(s)';

            if (sizeFailedAttachments.length > 0) {
                summaryMsg += ', Failed: ' + sizeFailedAttachments.length + ' image(s)';
                summaryMsg += ' (see console for details)';

                // Log detailed error information
                console.group('Size "' + currentSizeName + '" Regeneration Errors');
                console.log('Total failed:', sizeFailedAttachments.length);
                console.table(sizeFailedAttachments);
                console.groupEnd();

                // Add visual indicator for warnings
                statusText.html(summaryMsg + ' <span style="color: #d63638;">⚠</span>');
            } else {
                statusText.text(summaryMsg);
            }

            // Show refresh button
            $('#ism-refresh-container').show();

            setTimeout(function() {
                sizeIsRegenerating = false;
                if (currentButton) {
                    currentButton.removeAttr('disabled').text('Regenerate');
                }
            }, 2000);
        }

        // Handle size regeneration error
        function handleSizeError(errorMessage) {
            statusText.text('Error regenerating "' + currentSizeName + '": ' + errorMessage);
            sizeIsRegenerating = false;
            if (currentButton) {
                currentButton.removeAttr('disabled').text('Regenerate');
            }
        }
    });

})(jQuery);