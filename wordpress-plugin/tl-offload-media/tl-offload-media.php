<?php
/**
 * Plugin Name: TL Offload Media
 * Description: Offload WordPress image/video to a Telegraph-Image compatible endpoint. Supports separate TL upload page and old-domain URL sync.
 * Version: 1.1.0
 * Author: TL
 */

if (!defined('ABSPATH')) {
    exit;
}

final class TL_Offload_Media {
    const OPTION_BASE_URL = 'tl_offload_base_url';
    const OPTION_AUTO_OFFLOAD = 'tl_offload_auto_offload';
    const OPTION_LAST_BASE_URL = 'tl_offload_last_base_url';
    const META_EXTERNAL_URL = '_tl_external_url';
    const DEFAULT_BASE_URL = 'https://tl.18uk.uk/';

    public static function init() {
        add_action('admin_init', array(__CLASS__, 'register_settings'));
        add_action('admin_menu', array(__CLASS__, 'register_admin_pages'));
        add_action('media_buttons', array(__CLASS__, 'render_media_button'), 20);
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_editor_assets'));
        add_action('admin_post_tl_offload_upload_direct', array(__CLASS__, 'handle_direct_upload'));
        add_action('admin_post_tl_offload_sync_domain', array(__CLASS__, 'handle_domain_sync'));
        add_action('wp_ajax_tl_offload_quick_upload', array(__CLASS__, 'handle_quick_upload'));

        if (self::is_auto_offload_enabled()) {
            add_action('add_attachment', array(__CLASS__, 'offload_attachment'));
        }

        add_filter('wp_get_attachment_url', array(__CLASS__, 'filter_attachment_url'), 10, 2);
    }

    public static function activate() {
        if (get_option(self::OPTION_BASE_URL) === false) {
            add_option(self::OPTION_BASE_URL, self::DEFAULT_BASE_URL);
        }
        if (get_option(self::OPTION_AUTO_OFFLOAD) === false) {
            add_option(self::OPTION_AUTO_OFFLOAD, '0');
        }
        if (get_option(self::OPTION_LAST_BASE_URL) === false) {
            add_option(self::OPTION_LAST_BASE_URL, self::DEFAULT_BASE_URL);
        }
    }

    public static function register_settings() {
        register_setting(
            'tl_offload_media_settings',
            self::OPTION_BASE_URL,
            array(
                'type' => 'string',
                'sanitize_callback' => array(__CLASS__, 'sanitize_base_url'),
                'default' => self::DEFAULT_BASE_URL,
            )
        );

        register_setting(
            'tl_offload_media_settings',
            self::OPTION_AUTO_OFFLOAD,
            array(
                'type' => 'string',
                'sanitize_callback' => array(__CLASS__, 'sanitize_auto_offload'),
                'default' => '0',
            )
        );
    }

    public static function register_admin_pages() {
        add_options_page(
            'TL Offload Media',
            'TL Offload Media',
            'manage_options',
            'tl-offload-media',
            array(__CLASS__, 'render_settings_page')
        );

        add_media_page(
            'TL Upload',
            'TL Upload',
            'upload_files',
            'tl-offload-media-upload',
            array(__CLASS__, 'render_upload_page')
        );
    }

    public static function render_media_button() {
        if (!current_user_can('upload_files')) {
            return;
        }
        echo '<button type="button" id="tl-offload-quick-upload-btn" class="button">TL 上传并插入</button>';
    }

    public static function enqueue_editor_assets($hook_suffix) {
        if (!in_array($hook_suffix, array('post.php', 'post-new.php'), true)) {
            return;
        }
        if (!current_user_can('upload_files')) {
            return;
        }

        wp_enqueue_script('jquery');
        wp_register_script('tl-offload-editor-upload', false, array('jquery'), '1.1.0', true);
        wp_enqueue_script('tl-offload-editor-upload');

        $config = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tl_offload_quick_upload'),
            'buttonText' => 'TL 上传并插入',
            'uploadingText' => '上传中...',
        );

        wp_add_inline_script(
            'tl-offload-editor-upload',
            'window.TLOffloadConfig=' . wp_json_encode($config) . ';',
            'before'
        );

        $script = <<<'JS'
(function($){
  function getBtn(){ return $('#tl-offload-quick-upload-btn'); }
  function setLoading(loading){
    var $btn = getBtn();
    if(!$btn.length) return;
    if(loading){
      $btn.prop('disabled', true).text((window.TLOffloadConfig && window.TLOffloadConfig.uploadingText) || 'Uploading...');
    }else{
      $btn.prop('disabled', false).text((window.TLOffloadConfig && window.TLOffloadConfig.buttonText) || 'TL Upload');
    }
  }

  function insertIntoEditor(url, mime){
    var isImage = typeof mime === 'string' && mime.indexOf('image/') === 0;
    var isVideo = typeof mime === 'string' && mime.indexOf('video/') === 0;

    if(window.wp && wp.data && wp.blocks && wp.data.dispatch){
      try{
        if(isImage){
          wp.data.dispatch('core/block-editor').insertBlocks(wp.blocks.createBlock('core/image', { url: url }));
          return;
        }
        if(isVideo){
          wp.data.dispatch('core/block-editor').insertBlocks(wp.blocks.createBlock('core/video', { src: url }));
          return;
        }
      }catch(e){}
    }

    if(window.tinymce && tinymce.activeEditor && !tinymce.activeEditor.isHidden()){
      if(isVideo){
        tinymce.activeEditor.insertContent('<video controls src="'+url+'"></video>');
      }else{
        tinymce.activeEditor.insertContent('<img src="'+url+'" alt="" />');
      }
      return;
    }

    if(window.send_to_editor){
      if(isVideo){
        window.send_to_editor('<video controls src="'+url+'"></video>');
      }else{
        window.send_to_editor('<img src="'+url+'" alt="" />');
      }
      return;
    }

    var active = document.activeElement;
    if(active && (active.tagName === 'TEXTAREA' || active.tagName === 'INPUT')){
      var insertText = isVideo ? '<video controls src="'+url+'"></video>' : '<img src="'+url+'" alt="" />';
      var start = active.selectionStart || 0;
      var end = active.selectionEnd || 0;
      var val = active.value || '';
      active.value = val.slice(0, start) + insertText + val.slice(end);
    }
  }

  function doUpload(file){
    if(!file) return;
    var cfg = window.TLOffloadConfig || {};
    var fd = new FormData();
    fd.append('action', 'tl_offload_quick_upload');
    fd.append('nonce', cfg.nonce || '');
    fd.append('file', file);
    setLoading(true);
    $.ajax({
      url: cfg.ajaxUrl,
      method: 'POST',
      data: fd,
      processData: false,
      contentType: false
    }).done(function(resp){
      if(resp && resp.success && resp.data && resp.data.url){
        insertIntoEditor(resp.data.url, resp.data.mime || file.type || '');
      }else{
        alert((resp && resp.data && resp.data.message) ? resp.data.message : 'TL upload failed');
      }
    }).fail(function(){
      alert('TL upload failed');
    }).always(function(){
      setLoading(false);
    });
  }

  $(document).on('click', '#tl-offload-quick-upload-btn', function(e){
    e.preventDefault();
    var input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/*,video/*';
    input.style.display = 'none';
    input.addEventListener('change', function(){
      if(input.files && input.files[0]){
        doUpload(input.files[0]);
      }
      if(input.parentNode) input.parentNode.removeChild(input);
    });
    document.body.appendChild(input);
    input.click();
  });
})(jQuery);
JS;
        wp_add_inline_script('tl-offload-editor-upload', $script, 'after');
    }

    public static function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $base_url = esc_attr(self::get_base_url());
        $auto_offload = self::is_auto_offload_enabled();
        $last_base_url = esc_attr(self::sanitize_base_url(get_option(self::OPTION_LAST_BASE_URL, self::DEFAULT_BASE_URL)));
        ?>
        <div class="wrap">
            <h1>TL Offload Media</h1>
            <p>Upload endpoint: <code><?php echo esc_html(self::get_upload_endpoint()); ?></code></p>

            <form method="post" action="options.php">
                <?php settings_fields('tl_offload_media_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(self::OPTION_BASE_URL); ?>">TL Base URL</label></th>
                        <td>
                            <input
                                name="<?php echo esc_attr(self::OPTION_BASE_URL); ?>"
                                id="<?php echo esc_attr(self::OPTION_BASE_URL); ?>"
                                type="url"
                                class="regular-text"
                                value="<?php echo $base_url; ?>"
                                placeholder="https://tl.18uk.uk/"
                                required
                            />
                            <p class="description">Default: <code><?php echo esc_html(self::DEFAULT_BASE_URL); ?></code></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Auto Offload New Uploads</th>
                        <td>
                            <label>
                                <input
                                    type="checkbox"
                                    name="<?php echo esc_attr(self::OPTION_AUTO_OFFLOAD); ?>"
                                    value="1"
                                    <?php checked($auto_offload); ?>
                                />
                                Enable auto offload on normal WordPress media upload
                            </label>
                            <p class="description">Leave off if you want a separate TL upload workflow only.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Save Changes'); ?>
            </form>

            <hr />
            <h2>Sync Old Domain Links</h2>
            <p>Batch replace old TL domain with new domain in attachment external URL meta and post content.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('tl_offload_sync_domain_action', 'tl_offload_sync_domain_nonce'); ?>
                <input type="hidden" name="action" value="tl_offload_sync_domain" />
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="tl_old_domain">Old Domain</label></th>
                        <td>
                            <input id="tl_old_domain" name="old_domain" type="url" class="regular-text" value="<?php echo $last_base_url; ?>" required />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tl_new_domain">New Domain</label></th>
                        <td>
                            <input id="tl_new_domain" name="new_domain" type="url" class="regular-text" value="<?php echo $base_url; ?>" required />
                        </td>
                    </tr>
                </table>
                <?php submit_button('Run Domain Sync', 'secondary'); ?>
            </form>
        </div>
        <?php
    }

    public static function render_upload_page() {
        if (!current_user_can('upload_files')) {
            return;
        }

        $result_url = isset($_GET['tl_url']) ? esc_url_raw(wp_unslash($_GET['tl_url'])) : '';
        $error = isset($_GET['tl_error']) ? sanitize_text_field(wp_unslash($_GET['tl_error'])) : '';
        ?>
        <div class="wrap">
            <h1>TL Upload</h1>
            <p>This is a separate upload entry and does not use normal WordPress media upload flow.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field('tl_offload_upload_direct_action', 'tl_offload_upload_direct_nonce'); ?>
                <input type="hidden" name="action" value="tl_offload_upload_direct" />
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="tl_upload_file">File</label></th>
                        <td>
                            <input id="tl_upload_file" name="tl_upload_file" type="file" accept="image/*,video/*" required />
                            <p class="description">Supported: image/*, video/*</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Upload To TL'); ?>
            </form>

            <?php if (!empty($error)) : ?>
                <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
            <?php endif; ?>

            <?php if (!empty($result_url)) : ?>
                <hr />
                <h2>Result</h2>
                <p><strong>URL:</strong> <code><?php echo esc_html($result_url); ?></code></p>
                <p><strong>Markdown:</strong> <code><?php echo esc_html('![](' . $result_url . ')'); ?></code></p>
                <p><strong>HTML:</strong> <code><?php echo esc_html('<img src="' . $result_url . '" alt="" />'); ?></code></p>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function handle_direct_upload() {
        if (!current_user_can('upload_files')) {
            wp_die('Permission denied');
        }
        check_admin_referer('tl_offload_upload_direct_action', 'tl_offload_upload_direct_nonce');

        $redirect = admin_url('upload.php?page=tl-offload-media-upload');
        if (!isset($_FILES['tl_upload_file']) || empty($_FILES['tl_upload_file']['tmp_name'])) {
            wp_safe_redirect(add_query_arg('tl_error', rawurlencode('No file uploaded.'), $redirect));
            exit;
        }

        $file = $_FILES['tl_upload_file'];
        $mime = isset($file['type']) ? (string) $file['type'] : '';
        if (strpos($mime, 'image/') !== 0 && strpos($mime, 'video/') !== 0) {
            wp_safe_redirect(add_query_arg('tl_error', rawurlencode('Only image/video is supported.'), $redirect));
            exit;
        }

        $url = self::upload_to_tl($file['tmp_name'], $file['name'], $mime);
        if (!$url) {
            wp_safe_redirect(add_query_arg('tl_error', rawurlencode('Upload failed.'), $redirect));
            exit;
        }

        wp_safe_redirect(add_query_arg('tl_url', rawurlencode($url), $redirect));
        exit;
    }

    public static function handle_domain_sync() {
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied');
        }
        check_admin_referer('tl_offload_sync_domain_action', 'tl_offload_sync_domain_nonce');

        $old = isset($_POST['old_domain']) ? self::sanitize_base_url(wp_unslash($_POST['old_domain'])) : '';
        $new = isset($_POST['new_domain']) ? self::sanitize_base_url(wp_unslash($_POST['new_domain'])) : '';

        if ($old === '' || $new === '' || $old === $new) {
            wp_safe_redirect(admin_url('options-general.php?page=tl-offload-media'));
            exit;
        }

        global $wpdb;

        // 1) Replace in attachment external URL meta
        $meta_like = $wpdb->esc_like($old) . '%';
        $meta_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
                self::META_EXTERNAL_URL,
                $meta_like
            )
        );
        if ($meta_rows) {
            foreach ($meta_rows as $row) {
                $new_value = str_replace($old, $new, $row->meta_value);
                $wpdb->update(
                    $wpdb->postmeta,
                    array('meta_value' => $new_value),
                    array('meta_id' => (int) $row->meta_id),
                    array('%s'),
                    array('%d')
                );
            }
        }

        // 2) Replace in post content
        $content_like = '%' . $wpdb->esc_like($old) . '%';
        $post_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_content LIKE %s",
                $content_like
            )
        );
        if ($post_rows) {
            foreach ($post_rows as $row) {
                $new_content = str_replace($old, $new, $row->post_content);
                if ($new_content !== $row->post_content) {
                    $wpdb->update(
                        $wpdb->posts,
                        array('post_content' => $new_content),
                        array('ID' => (int) $row->ID),
                        array('%s'),
                        array('%d')
                    );
                }
            }
        }

        update_option(self::OPTION_LAST_BASE_URL, $old);
        wp_safe_redirect(admin_url('options-general.php?page=tl-offload-media'));
        exit;
    }

    public static function handle_quick_upload() {
        if (!current_user_can('upload_files')) {
            wp_send_json_error(array('message' => 'Permission denied'), 403);
        }
        check_ajax_referer('tl_offload_quick_upload', 'nonce');

        if (!isset($_FILES['file']) || empty($_FILES['file']['tmp_name'])) {
            wp_send_json_error(array('message' => 'No file uploaded'), 400);
        }

        $file = $_FILES['file'];
        $mime = isset($file['type']) ? (string) $file['type'] : '';
        if (strpos($mime, 'image/') !== 0 && strpos($mime, 'video/') !== 0) {
            wp_send_json_error(array('message' => 'Only image/video supported'), 400);
        }

        $url = self::upload_to_tl($file['tmp_name'], $file['name'], $mime);
        if (!$url) {
            wp_send_json_error(array('message' => 'Upload failed'), 500);
        }

        wp_send_json_success(array(
            'url' => $url,
            'mime' => $mime,
        ));
    }

    public static function sanitize_base_url($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return self::DEFAULT_BASE_URL;
        }
        $value = esc_url_raw($value);
        if ($value === '') {
            return self::DEFAULT_BASE_URL;
        }
        return trailingslashit($value);
    }

    public static function sanitize_auto_offload($value) {
        return ((string) $value === '1') ? '1' : '0';
    }

    private static function is_auto_offload_enabled() {
        return get_option(self::OPTION_AUTO_OFFLOAD, '0') === '1';
    }

    private static function get_base_url() {
        $value = get_option(self::OPTION_BASE_URL, self::DEFAULT_BASE_URL);
        return self::sanitize_base_url($value);
    }

    private static function get_upload_endpoint() {
        return untrailingslashit(self::get_base_url()) . '/upload';
    }

    public static function offload_attachment($attachment_id) {
        $already = get_post_meta($attachment_id, self::META_EXTERNAL_URL, true);
        if (!empty($already)) {
            return;
        }

        $file_path = get_attached_file($attachment_id);
        if (!$file_path || !file_exists($file_path)) {
            return;
        }

        $mime = (string) get_post_mime_type($attachment_id);
        if (strpos($mime, 'image/') !== 0 && strpos($mime, 'video/') !== 0) {
            return;
        }

        $external_url = self::upload_to_tl($file_path, basename($file_path), $mime);
        if ($external_url) {
            update_post_meta($attachment_id, self::META_EXTERNAL_URL, esc_url_raw($external_url));
        }
    }

    public static function filter_attachment_url($url, $attachment_id) {
        $external = get_post_meta($attachment_id, self::META_EXTERNAL_URL, true);
        return !empty($external) ? $external : $url;
    }

    private static function upload_to_tl($file_path, $file_name, $mime) {
        if (!function_exists('curl_init') || !function_exists('curl_file_create')) {
            return false;
        }

        $ch = curl_init(self::get_upload_endpoint());
        if (!$ch) {
            return false;
        }

        $cfile = curl_file_create($file_path, $mime, $file_name);
        $post_fields = array('file' => $cfile);

        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_POSTFIELDS => $post_fields,
        ));

        $response_body = curl_exec($ch);
        $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status_code !== 200 || !$response_body) {
            return false;
        }

        $json = json_decode($response_body, true);
        if (!is_array($json) || empty($json[0]['src'])) {
            return false;
        }

        $src = (string) $json[0]['src'];
        if (preg_match('#^https?://#i', $src)) {
            return $src;
        }
        return untrailingslashit(self::get_base_url()) . '/' . ltrim($src, '/');
    }
}

register_activation_hook(__FILE__, array('TL_Offload_Media', 'activate'));
TL_Offload_Media::init();
