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
            
            // Get all attachment IDs
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
                        currentIndex++;
                        processNextAttachment();
                    } else {
                        handleError(response.data);
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    handleError(textStatus + ': ' + errorThrown);
                }
            });
        }
        
        // Complete regeneration
        function completeRegeneration() {
            progressBar.progressbar('value', 100);
            statusText.text(ism_data.regenerate_complete);
            
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
    });
    
})(jQuery);