<?php
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    
    <form method="post" action="options.php">
        <?php
        settings_fields('property_importer_settings');
        do_settings_sections('property_importer_settings');
        submit_button('Ayarları Kaydet');
        ?>
    </form>
    
    <hr>
    
    <button id="start-import" class="button button-primary">İçe Aktarmayı Başlat</button>
    
    <hr>

    <p>Webhook URL'niz: <?php echo esc_url(rest_url('property-importer/v1/webhook')); ?></p>
    
    <div class="cron-settings">
        <h3>Cron Ayarları</h3>
        <p>Şu anki cron durumu: <span id="cron-status"><?php echo wp_next_scheduled('property_importer_cron') ? 'Aktif' : 'Pasif'; ?></span></p>
        <button id="toggle-cron" class="button button-secondary"><?php echo wp_next_scheduled('property_importer_cron') ? 'Cron\'u Durdur' : 'Cron\'u Başlat'; ?></button>
    </div>
</div>
