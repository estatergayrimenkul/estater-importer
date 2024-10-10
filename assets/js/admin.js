jQuery(document).ready(function($) {
    try {
        const api = {
            startImport: () => ajaxRequest('start_import'),
            getStats: () => ajaxRequest('get_import_stats'),
            toggleCron: () => ajaxRequest('toggle_cron')
        };
    
        function ajaxRequest(action, data = {}) {
            return $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: { ...data, action, nonce: property_importer_ajax.nonce },
                dataType: 'json'
            });
        }
    
        function updateStats(stats) {
            $('#total-properties').text(stats.total);
            $('#imported-properties').text(stats.imported);
            $('#queued-properties').text(stats.queued);
        }
    
        function handleAjaxError(xhr, status, error) {
            console.error('AJAX Hatası:', xhr.responseText);
            alert('Hata oluştu: ' + error + '\nLütfen konsolu kontrol edin ve yöneticinize başvurun.');
        }

        function updateUI(data) {
            updateStats(data.stats);
        }
    
        $('#start-import').on('click', () => {
            api.startImport()
                .done(response => {
                    if (response.success) {
                        updateUI(response.data);
                        alert('İçe aktarma başlatıldı.');
                    } else {
                        alert('İçe aktarma başlatılamadı: ' + response.data);
                    }
                })
                .fail(handleAjaxError);
        });
    
        // Sayfa yüklendiğinde istatistikleri al
        api.getStats()
            .done(response => {
                if (response.success) {
                    updateStats(response.data);
                } else {
                    console.error('İstatistikler alınamadı:', response.data);
                }
            })
            .fail(handleAjaxError);
    
        $('#toggle-cron').on('click', () => {
            api.toggleCron()
                .done(response => {
                    if (response.success) {
                        $('#cron-status').text(response.data.status);
                        $('#toggle-cron').text(response.data.status === 'Aktif' ? 'Cron\'u Durdur' : 'Cron\'u Başlat');
                        alert('Cron durumu güncellendi: ' + response.data.status);
                    } else {
                        alert('Cron durumu güncellenemedi: ' + response.data);
                    }
                })
                .fail(handleAjaxError);
        });
    } catch (error) {
        console.error('Property Importer Error:', error);
    }
});