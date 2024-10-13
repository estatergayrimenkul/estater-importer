jQuery(document).ready(function($) {
    try {
        const api = {
            startImport: () => ajaxRequest('start_import'),
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
    
        function handleAjaxError(xhr, status, error) {
            console.error('AJAX Hatası:', xhr.responseText);
            alert('Hata oluştu: ' + error + '\nLütfen konsolu kontrol edin ve yöneticinize başvurun.');
        }

        function updateUI(data) {
            if (data && typeof data === 'object') {
                $('#cron-status').text(data.is_running ? 'Çalışıyor' : 'Durdu');
                $('#toggle-cron').text(data.is_running ? 'Cron\'u Durdur' : 'Cron\'u Başlat');
            } else {
                console.error('Geçersiz veri:', data);
            }
        }
    
        $('#start-import').on('click', function() {
            console.log('İçe aktarma başlatma düğmesine tıklandı');
            api.startImport()
                .done(response => {
                    console.log('AJAX Yanıtı:', response);
                    if (response && response.success) {
                        alert(response.data.message);
                        updateUI(response.data);
                    } else {
                        console.error('İçe aktarma başlatılamadı:', response);
                        alert('İçe aktarma başlatılamadı. Lütfen konsolu kontrol edin.');
                    }
                })
                .fail(handleAjaxError);
        });
    
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
