<?php
/**
 * Plugin Name: Bulk Edit Custom Fields
 * Description: A plugin to bulk edit custom fields for all post types including custom post types in WordPress.
 * Version: 1.0.0
 * Author: Shota Takazawa
 * Author URI: https://sokulabo.com/products/bulk-edit-custom-fields/
 * License: GPL2
 * Text Domain: bulk-edit-custom-fields
 * Domain Path: /languages
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 管理画面にメニューを追加
add_action('admin_menu', 'becf_add_admin_menu');
function becf_add_admin_menu() {
    add_menu_page(
        'Bulk Edit Custom Fields',
        'Bulk Edit Custom Fields',
        'edit_posts',
        'bulk-edit-custom-fields',
        'becf_render_page',
        'dashicons-edit',
        100
    );
}

// AJAX保存ハンドラーを追加
add_action('wp_ajax_becf_save_fields', 'becf_ajax_save_fields');
function becf_ajax_save_fields() {
    // デバッグ情報をログに出力
    error_log('CFBE AJAX Save: 開始');
    error_log('POST data: ' . print_r($_POST, true));
    
    // Nonce確認
    if (!check_admin_referer('becf_bulk_edit', 'nonce')) {
        error_log('CFBE AJAX Save: Nonce確認失敗');
        wp_die('Security check failed');
    }
    
    // 権限確認
    if (!current_user_can('edit_posts')) {
        error_log('CFBE AJAX Save: 権限確認失敗');
        wp_die('Insufficient permissions');
    }
    
    $chunk_data = json_decode(stripslashes($_POST['chunk_data']), true);
    error_log('CFBE AJAX Save: デコードされたチャンクデータ: ' . print_r($chunk_data, true));
    
    $saved_count = 0;
    $errors = [];
    
    if (is_array($chunk_data)) {
        foreach ($chunk_data as $post_id => $fields) {
            if (is_array($fields)) {
                foreach ($fields as $field_key => $field_value) {
                    $field_key = sanitize_text_field($field_key);
                    
                    // 配列データ（チェックボックス等）の処理
                    if (is_array($field_value)) {
                        // 配列の各要素をサニタイズ
                        $field_value = array_map('sanitize_text_field', $field_value);
                        error_log("CFBE AJAX Save: 配列データ処理 - Post ID: {$post_id}, Field: {$field_key}, Array: " . json_encode($field_value));
                    } else {
                        // 通常の文字列データの処理
                        $field_value = sanitize_textarea_field($field_value);
                    }
                    
                    $result = update_post_meta($post_id, $field_key, $field_value);
                    if ($result) {
                        $saved_count++;
                        $value_for_log = is_array($field_value) ? json_encode($field_value) : $field_value;
                        error_log("CFBE AJAX Save: 保存成功 - Post ID: {$post_id}, Field: {$field_key}, Value: {$value_for_log}");
                    } else {
                        error_log("CFBE AJAX Save: 保存失敗 - Post ID: {$post_id}, Field: {$field_key}");
                    }
                }
            }
        }
    }
    
    error_log("CFBE AJAX Save: 完了 - 保存件数: {$saved_count}");
    
    wp_send_json_success([
        'saved_count' => $saved_count,
        'message' => "{$saved_count}件のフィールドを保存しました"
    ]);
}

// 管理画面のページをレンダリング
function becf_render_page() {
    // データ保存処理
    if (isset($_POST['becf_submit']) && check_admin_referer('becf_bulk_edit', 'becf_nonce')) {
        becf_save_custom_fields();
        echo '<div class="notice notice-success is-dismissible"><p>カスタムフィールドを更新しました。</p></div>';
    }

    // 選択された投稿タイプを取得
    $selected_post_type = isset($_GET['post_type']) ? sanitize_text_field($_GET['post_type']) : 'all';
    
    // ページネーション設定
    $posts_per_page = 100;
    $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($current_page - 1) * $posts_per_page;
    
    // 利用可能な投稿タイプを取得
    $post_types = get_post_types(array('public' => true, 'show_ui' => true), 'objects');
    // カスタム投稿タイプも含める
    $custom_post_types = get_post_types(array('public' => false, 'show_ui' => true, '_builtin' => false), 'objects');
    $post_types = array_merge($post_types, $custom_post_types);

    // 全投稿数を取得（ページネーション用）
    $count_args = array(
        'post_type' => ($selected_post_type === 'all') ? array_keys($post_types) : $selected_post_type,
        'posts_per_page' => -1,
        'post_status' => array('publish', 'draft', 'pending', 'private'),
        'fields' => 'ids'
    );
    $all_post_ids = get_posts($count_args);
    $total_posts = count($all_post_ids);
    $total_pages = ceil($total_posts / $posts_per_page);

    // ページネーション付きで投稿を取得
    $args = array(
        'post_type' => ($selected_post_type === 'all') ? array_keys($post_types) : $selected_post_type,
        'posts_per_page' => $posts_per_page,
        'offset' => $offset,
        'post_status' => array('publish', 'draft', 'pending', 'private'),
        'orderby' => 'title',
        'order' => 'ASC'
    );
    $posts = get_posts($args);

    // カスタムフィールドのキーを収集
    $custom_field_keys = array();
    $field_labels = array(); // フィールドのラベル情報を保存
    $post_data = array(); // 各投稿のデータを保存
    
    foreach ($posts as $post) {
        $post_data[$post->ID] = array(
            'title' => $post->post_title,
            'status' => $post->post_status,
            'type' => $post->post_type,
            'fields' => array()
        );
        
        // すべてのメタデータを取得
        $all_meta = get_post_meta($post->ID);
        
        if (is_array($all_meta) && !empty($all_meta)) {
            foreach ($all_meta as $meta_key => $meta_values) {
                // WordPressの内部フィールドを除外（_で始まるもの）
                if (substr($meta_key, 0, 1) !== '_') {
                    $custom_field_keys[$meta_key] = $meta_key;
                    
                    // フィールドラベルを取得（ACFの場合）
                    if (!isset($field_labels[$meta_key])) {
                        $field_label = $meta_key; // デフォルトはフィールド名
                        
                        // ACFの場合のラベル取得
                        if (function_exists('get_field_object')) {
                            $field_object = get_field_object($meta_key, $post->ID);
                            if ($field_object && isset($field_object['label'])) {
                                $field_label = $field_object['label'];
                            }
                        }
                        
                        $field_labels[$meta_key] = $field_label;
                    }
                    
                    // 値を取得（配列の最初の要素）
                    $value = isset($meta_values[0]) ? $meta_values[0] : '';
                    
                    // maybe_unserialize で自動的にシリアライズ解除
                    $value = maybe_unserialize($value);
                    
                    // 配列データはそのまま保持（JSON変換しない）
                    // チェックボックス配列として表示するため
                    
                    $post_data[$post->ID]['fields'][$meta_key] = $value;
                }
            }
        }
    }
    
    ksort($custom_field_keys);

    ?>

    <style>
        .becf-info {
            background: #fff;
            border-left: 4px solid #72aee6;
            padding: 12px 16px;
            margin: 15px 0;
        }

        .becf-info p {
            margin: 0;
            font-size: 14px;
        }
        
        .becf-filter-section {
            margin: 20px 0 0;
            padding: 16px;
            background: #fff;
            border: 1px solid #c3c4c7;
            border-radius: 4px;
        }
        
        .becf-filter-section select {
            min-width: 250px;
            margin: 0 10px 0 0;
            height: 32px;
            vertical-align: middle;
        }
        
        .becf-filter-section .button {
            vertical-align: middle;
            margin-right: 5px;
        }
        
        .becf-table-wrapper {
            overflow-x: auto;
            overflow-y: auto;
            max-height: 70vh;
            background: #fff;
            border: 1px solid #c3c4c7;
            margin: 20px 0;
            position: relative;
        }
        
        .becf-table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
        }
        
        .becf-table th,
        .becf-table td {
            padding: 12px;
            border: 1px solid #dcdcde;
            text-align: left;
            vertical-align: top;
        }
        
        .becf-table thead th {
            background: #f6f7f7;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .becf-col-fixed {
            position: sticky;
            background: #fff;
            z-index: 5;
        }
        
        .becf-table thead .becf-col-fixed {
            background: #f6f7f7;
            z-index: 15;
        }
        
        .becf-col-title {
            left: 0;
            min-width: 300px;
            max-width: 400px;
        }
        
        .becf-col-checkbox {
            left: 300px;
            text-align: center;
            padding: 8px !important;
            border-right: 1px solid #c3c4c7;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
        }

        .becf-col-checkbox input {
            margin-right: 0;
        }
        
        .becf-field-header,
        .becf-field-cell {
            min-width: 250px;
        }
        
        .becf-field-header small {
            color: #646970;
            font-weight: normal;
            font-size: 10px;
        }
        
        .becf-table tbody tr:hover td {
            background: #f6f7f7;
        }
        
        .becf-table tbody tr:hover .becf-col-fixed {
            background: #f0f0f1;
        }
        
        /* チェックボックス列の背景色設定 */
        .becf-table thead .becf-col-checkbox {
            background: #f6f7f7;
            z-index: 15;
        }
        
        .becf-table tbody .becf-col-checkbox {
            background: #fff;
            z-index: 5;
        }
        
        .becf-table tbody tr:hover .becf-col-checkbox {
            background: #f0f0f1;
        }
        
        .becf-input,
        .becf-textarea {
            width: 100%;
            padding: 6px 8px;
            font-size: 13px;
            line-height: 1.5;
            border: 1px solid #8c8f94;
            border-radius: 4px;
            box-sizing: border-box;
        }
        
        .becf-textarea {
            resize: vertical;
            min-height: 60px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        
        .becf-input:focus,
        .becf-textarea:focus {
            border-color: #2271b1;
            box-shadow: 0 0 0 1px #2271b1;
            outline: none;
        }
        
        .becf-status-publish {
            color: #00a32a;
        }
        
        .becf-status-draft {
            color: #dba617;
        }
        
        .becf-post-type {
            display: inline-block;
            font-size: 10px;
            font-weight: 500;
            color: #333;
            margin: 0 2px;
        }
        
        .becf-page-id {
            font-size: 11px;
            color: #646970;
            margin-top: 5px;
            font-weight: normal;
            line-height: 1.4;
        }
        
        .becf-submit-section {
            background: #fff;
            padding: 16px;
            border: 1px solid #c3c4c7;
            border-radius: 4px;
        }
        
        .becf-help-text {
            color: #646970;
            font-size: 13px;
        }
        
        /* 削除/復元ボタンのスタイル */
        .becf-clear-field-btn {
            transition: all 0.3s ease;
        }
        
        .becf-clear-field-btn.becf-cleared {
            background-color: #00a32a !important;
            border-color: #00a32a !important;
            color: white !important;
        }
        
        .becf-clear-field-btn.becf-cleared:hover {
            background-color: #008a20 !important;
            border-color: #008a20 !important;
        }
        
        /* 行選択チェックボックスのスタイル */
        
        .becf-row-checkbox, #becf-select-all-rows {
            margin: 0;
            cursor: pointer;
            transform: scale(1.2);
        }
        
        .becf-row-selected {
            background-color: #e8f4fd !important;
        }
        
        .becf-row-selected .becf-col-fixed {
            background-color: #e8f4fd !important;
        }
        
        /* 行操作ボタン群のスタイル */
        .becf-bulk-row-actions {
            margin: 10px 0;
            padding: 15px;
            background-color: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 4px;
            display: none;
            align-items: center;
            gap: 15px;
        }
        
        .becf-bulk-row-actions.show {
            display: flex;
        }
        
        .becf-selected-count {
            font-weight: bold;
            color: #0073aa;
        }
        
        .becf-row-action-btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .becf-delete-selected-btn {
            background-color: #dc3232;
            color: white;
        }
        
        .becf-delete-selected-btn:hover {
            background-color: #a02622;
        }
        
        .becf-restore-selected-btn {
            background-color: #00a32a;
            color: white;
        }
        
        .becf-restore-selected-btn:hover {
            background-color: #008a20;
        }
        
        /* 削除された行のスタイル */
        .becf-row-deleted {
            opacity: 0.5;
            background-color: #ffe6e6 !important;
        }
        
        .becf-row-deleted input,
        .becf-row-deleted textarea {
            background-color: #ffcccc !important;
        }
        
        .becf-row-deleted .becf-col-fixed {
            background-color: #ffe6e6 !important;
        }
        
        /* 保存プログレスバーのスタイル */
        .becf-progress-bar {
            width: 100%;
            height: 20px;
            background-color: #f1f1f1;
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 5px;
        }
        
        .becf-progress-fill {
            height: 100%;
            background-color: #00a32a;
            transition: width 0.3s ease;
        }
        
        .becf-progress-text {
            font-size: 14px;
            color: #666;
        }
        
        #becf-ajax-save-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .becf-table a {
            text-decoration: none;
            color: #2271b1;
        }
        
        .becf-table a:hover {
            color: #135e96;
            text-decoration: underline;
        }
        
        .becf-row-hidden {
            display: none !important;
        }
        
        .becf-search-highlight {
            background-color: #fff3cd;
        }
        
        .becf-pagination {
            margin: 20px 0;
            text-align: center;
            padding: 15px;
            background: #fff;
            border: 1px solid #c3c4c7;
            border-radius: 4px;
        }
        
        .becf-pagination-info {
            margin-right: 20px;
            font-weight: bold;
            color: #646970;
        }
        
        .becf-pagination-link {
            display: inline-block;
            padding: 8px 12px;
            margin: 0 3px;
            background: #f6f7f7;
            border: 1px solid #c3c4c7;
            border-radius: 3px;
            text-decoration: none;
            color: #2271b1;
            font-size: 13px;
        }
        
        .becf-pagination-link:hover {
            background: #2271b1;
            color: #fff;
            border-color: #2271b1;
        }
        
        .becf-pagination-current {
            display: inline-block;
            padding: 8px 12px;
            margin: 0 3px;
            background: #2271b1;
            color: #fff;
            border: 1px solid #2271b1;
            border-radius: 3px;
            font-weight: bold;
            font-size: 13px;
        }
        
        .becf-pagination-dots {
            padding: 8px 4px;
            margin: 0 3px;
            color: #646970;
        }
        
        /* 削除機能用スタイル */
        .becf-field-header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .becf-clear-actions {
            display: flex;
            align-items: center;
        }

        .becf-save-actions {
            display: flex;
            align-items: center;
            margin-top: 14px;
        }

        .becf-help-text {
            margin-left: 10px;
        }
        
        /* チェックボックス配列のスタイル */
        .becf-checkbox-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            min-width: 200px;
            max-width: 300px;
        }
        
        .becf-checkbox-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 8px;
            background-color: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 13px;
            cursor: pointer;
            transition: background-color 0.2s ease;
        }
        
        .becf-checkbox-item:hover {
            background-color: #f0f0f0;
        }
        
        .becf-checkbox-item input[type="checkbox"] {
            margin: 0;
            cursor: pointer;
        }
        
        .becf-checkbox-item span {
            flex: 1;
            word-break: break-word;
        }
        
        .becf-remove-checkbox {
            background: #dc3232;
            color: white;
            border: none;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background-color 0.2s ease;
        }
        
        .becf-remove-checkbox:hover {
            background: #b32d2e;
        }
        
        .becf-add-checkbox {
            display: flex;
            gap: 5px;
            margin-top: 5px;
            padding-top: 8px;
            border-top: 1px solid #ddd;
        }
        
        .becf-new-option {
            flex: 1;
            padding: 4px 6px;
            border: 1px solid #ddd;
            border-radius: 3px;
            font-size: 13px;
        }
        
        .becf-add-btn {
            padding: 4px 8px;
            background-color: #0073aa;
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 13px;
            white-space: nowrap;
        }
        
        .becf-add-btn:hover {
            background-color: #005a87;
        }
        
        /* カスタムフィールドキー表示セクション */
        .becf-field-keys-section {
            margin-top: 20px;
            padding: 15px;
            background: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        #toggle-field-keys {
            text-decoration: none;
            color: #0073aa;
            font-weight: 500;
            display: inline-block;
            padding: 5px 0;
            border-bottom: 1px dotted #0073aa;
            transition: color 0.2s ease;
        }
        
        #toggle-field-keys:hover {
            color: #005a87;
            border-bottom-color: #005a87;
        }
        
        #field-keys-list {
            margin-top: 15px;
        }
        
        #field-keys-list h4 {
            margin-top: 0;
            margin-bottom: 10px;
            color: #23282d;
            font-size: 14px;
        }
        
        #field-keys-list ul {
            margin: 0;
            padding-left: 20px;
            columns: 3;
            column-gap: 20px;
            list-style-type: disc;
        }
        
        #field-keys-list li {
            break-inside: avoid;
            margin-bottom: 5px;
            font-size: 13px;
        }
        
        #field-keys-list code {
            background: #f1f1f1;
            padding: 2px 4px;
            border-radius: 3px;
            font-family: Consolas, Monaco, monospace;
            font-size: 12px;
        }
        
        /* レスポンシブ対応 */
        @media (max-width: 1200px) {
            #field-keys-list ul {
                columns: 2;
            }
        }
        
        @media (max-width: 768px) {
            #field-keys-list ul {
                columns: 1;
            }
        }
    </style>

    <div class="wrap becf-wrap">
        <h1>
            Bulk Edit Custom Fields
        </h1>
        
        <div class="becf-info">
            <p>
                <strong>投稿表示:</strong> <?php echo count($posts); ?> 件 (全体: <?php echo $total_posts; ?> 件) | 
                <strong>ページ:</strong> <?php echo $current_page; ?> / <?php echo $total_pages; ?> | 
                <strong>カスタムフィールド数:</strong> <?php echo count($custom_field_keys); ?> 種類
            </p>
        </div>
        
        <!-- 投稿タイプフィルタ -->
        <div class="becf-filter-section">
            <form method="get" action="" style="display: inline-block;">
                <input type="hidden" name="page" value="bulk-edit-custom-fields">
                <label for="post_type_filter" style="margin-right: 10px;"><strong>投稿タイプ: </strong></label>
                <select name="post_type" id="post_type_filter" onchange="this.form.submit()">
                    <option value="all" <?php selected($selected_post_type, 'all'); ?>>すべて</option>
                    <?php foreach ($post_types as $post_type_key => $post_type_obj): ?>
                        <option value="<?php echo esc_attr($post_type_key); ?>" <?php selected($selected_post_type, $post_type_key); ?>>
                            <?php echo esc_html($post_type_obj->label); ?> (<?php echo esc_html($post_type_key); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
            
            <div style="margin-top: 10px;">
                <label for="becf_search_title" style="margin-right: 10px;"><strong>記事名検索: </strong></label>
                <input type="text" id="becf_search_title" placeholder="記事タイトルで検索..." style="width: 250px; margin-right: 10px;">
                <button type="button" class="button" onclick="cfbeSearchTitle()">検索</button>
                <button type="button" class="button" onclick="cfbeResetSearch()">リセット</button>
            </div>
        </div>
        
        <?php if (empty($posts)): ?>
            <div class="notice notice-warning">
                <p>編集可能な投稿がありません。</p>
            </div>
        <?php elseif (empty($custom_field_keys)): ?>
            <div class="notice notice-warning">
                <p>カスタムフィールドが見つかりません。</p>
                <p>確認事項：</p>
                <ul>
                    <li>投稿にカスタムフィールドが追加されていますか？</li>
                    <li>カスタムフィールド名が「_」（アンダースコア）で始まっていませんか？</li>
                    <li>Advanced Custom Fields (ACF) などのプラグインを使用している場合、フィールドが正しく設定されていますか？</li>
                </ul>
            </div>
        <?php else: ?>
        
        <form method="post" action="">
            <?php wp_nonce_field('becf_bulk_edit', 'becf_nonce'); ?>
            
            <div class="becf-filter-section">
                <label for="becf_filter_field" style="margin-right: 10px;"><strong>表示するフィールド: </strong></label>
                <select id="becf_filter_field">
                    <option value="">すべて表示 (<?php echo count($custom_field_keys); ?>件)</option>
                    <?php foreach ($custom_field_keys as $key): ?>
                        <option value="<?php echo esc_attr($key); ?>">
                            <?php 
                            $display_label = isset($field_labels[$key]) ? $field_labels[$key] : $key;
                            if ($display_label !== $key) {
                                echo esc_html($display_label) . ' (' . esc_html($key) . ')';
                            } else {
                                echo esc_html($key);
                            }
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                
                <button type="button" class="button" onclick="cfbeFilterFields()">フィルター</button>
                <button type="button" class="button" onclick="cfbeResetFilter()">リセット</button>
            </div>

            <?php
            // ページネーション表示関数
            function becf_render_pagination($current_page, $total_pages, $selected_post_type) {
                if ($total_pages <= 1) return;
                
                $base_url = '?page=bulk-edit-custom-fields&post_type=' . urlencode($selected_post_type);
                
                echo '<div class="becf-pagination">';
                echo '<span class="becf-pagination-info">ページ ' . $current_page . ' / ' . $total_pages . '</span>';
                
                // 前のページ
                if ($current_page > 1) {
                    echo '<a href="' . $base_url . '&paged=' . ($current_page - 1) . '" class="becf-pagination-link">‹ 前のページ</a>';
                }
                
                // ページ番号
                $start_page = max(1, $current_page - 2);
                $end_page = min($total_pages, $current_page + 2);
                
                if ($start_page > 1) {
                    echo '<a href="' . $base_url . '&paged=1" class="becf-pagination-link">1</a>';
                    if ($start_page > 2) {
                        echo '<span class="becf-pagination-dots">...</span>';
                    }
                }
                
                for ($i = $start_page; $i <= $end_page; $i++) {
                    if ($i == $current_page) {
                        echo '<span class="becf-pagination-current">' . $i . '</span>';
                    } else {
                        echo '<a href="' . $base_url . '&paged=' . $i . '" class="becf-pagination-link">' . $i . '</a>';
                    }
                }
                
                if ($end_page < $total_pages) {
                    if ($end_page < $total_pages - 1) {
                        echo '<span class="becf-pagination-dots">...</span>';
                    }
                    echo '<a href="' . $base_url . '&paged=' . $total_pages . '" class="becf-pagination-link">' . $total_pages . '</a>';
                }
                
                // 次のページ
                if ($current_page < $total_pages) {
                    echo '<a href="' . $base_url . '&paged=' . ($current_page + 1) . '" class="becf-pagination-link">次のページ ›</a>';
                }
                
                echo '</div>';
            }
            
            // 上部ページネーション
            becf_render_pagination($current_page, $total_pages, $selected_post_type);
            ?>

            <div class="becf-table-wrapper">
                <table class="becf-table">
                    <thead>
                        <tr>
                            <th class="becf-col-fixed becf-col-title">投稿タイトル</th>
                            <th class="becf-col-fixed becf-col-checkbox">
                                <label>
                                    <input type="checkbox" id="becf-select-all-rows" title="全行選択/解除">
                                </label>
                            </th>
                            <?php foreach ($custom_field_keys as $key): ?>
                                <th class="becf-field-header" data-field="<?php echo esc_attr($key); ?>">
                                    <div class="becf-field-header-content">
                                        <div class="becf-field-title">
                                            <?php 
                                            $display_label = isset($field_labels[$key]) ? $field_labels[$key] : $key;
                                            echo esc_html($display_label);
                                            if ($display_label !== $key) {
                                                echo '<br><small>(' . esc_html($key) . ')</small>';
                                            }
                                            ?>
                                        </div>
                                        <div class="becf-field-actions">
                                            <button type="button" class="becf-clear-field-btn button button-small" 
                                                    data-field="<?php echo esc_attr($key); ?>"
                                                    title="この項目の全ての値を削除">
                                                削除
                                            </button>
                                        </div>
                                    </div>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($posts as $post): ?>
                            <tr>
                                <td class="becf-col-fixed becf-col-title">
                                    <strong>
                                        <a href="<?php echo get_edit_post_link($post->ID); ?>" target="_blank" title="編集">
                                            <?php echo esc_html($post_data[$post->ID]['title'] ?: '(タイトルなし)'); ?>
                                        </a>
                                    </strong>
                                    <div class="becf-page-id">
                                        ID: <?php echo $post->ID; ?> | 
                                        <?php 
                                        $post_type_obj = get_post_type_object($post_data[$post->ID]['type']);
                                        echo '<span class="becf-post-type">' . esc_html($post_type_obj ? $post_type_obj->label : $post_data[$post->ID]['type']) . '</span>';
                                        ?> | 
                                        <?php 
                                        $status_labels = array(
                                            'publish' => '公開',
                                            'draft' => '下書き',
                                            'pending' => '承認待ち',
                                            'private' => '非公開'
                                        );
                                        $status = $post_data[$post->ID]['status'];
                                        echo '<span class="becf-status becf-status-' . esc_attr($status) . '">' . esc_html($status_labels[$status] ?? $status) . '</span>';
                                        ?>
                                    </div>
                                </td>
                                <td class="becf-col-fixed becf-col-checkbox">
                                    <input type="checkbox" class="becf-row-checkbox" 
                                           data-post-id="<?php echo esc_attr($post->ID); ?>"
                                           title="この行を選択">
                                </td>
                                <?php foreach ($custom_field_keys as $key): ?>
                                    <td class="becf-field-cell" data-field="<?php echo esc_attr($key); ?>">
                                        <?php
                                        $value = isset($post_data[$post->ID]['fields'][$key]) ? $post_data[$post->ID]['fields'][$key] : '';
                                        $field_name = "becf_field[{$post->ID}][{$key}]";
                                        
                                        // 配列データを文字列として表示（入力フィールド形式）
                                        if (is_array($value)) {
                                            // 配列を JSON 文字列として表示
                                            $value_str = json_encode($value, JSON_UNESCAPED_UNICODE);
                                        } else {
                                            // 通常の文字列データ
                                            $value_str = strval($value);
                                        }
                                        
                                        // 長いテキストや改行を含む場合はテキストエリア
                                        if (strlen($value_str) > 60 || strpos($value_str, "\n") !== false) {
                                            ?>
                                            <textarea 
                                                name="<?php echo esc_attr($field_name); ?>" 
                                                rows="3" 
                                                class="becf-textarea"
                                                placeholder="(空)"
                                            ><?php echo esc_textarea($value_str); ?></textarea>
                                            <?php
                                        } else {
                                            ?>
                                            <input 
                                                type="text" 
                                                name="<?php echo esc_attr($field_name); ?>" 
                                                value="<?php echo esc_attr($value_str); ?>" 
                                                class="becf-input"
                                                placeholder="(空)"
                                            />
                                            <?php
                                        }
                                        ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- 選択された行の操作ボタン -->
            <div class="becf-bulk-row-actions" id="becf-bulk-row-actions">
                <span class="becf-selected-count" id="becf-selected-count">0行選択中</span>
                <button type="button" class="becf-row-action-btn becf-delete-selected-btn" onclick="cfbeDeleteSelectedRows()">
                    選択した行を削除
                </button>
                <button type="button" class="becf-row-action-btn becf-restore-selected-btn" onclick="cfbeRestoreSelectedRows()">
                    選択した行を復元
                </button>
            </div>

            <?php
            // 下部ページネーション
            becf_render_pagination($current_page, $total_pages, $selected_post_type);
            ?>

            <div class="becf-submit-section">
                <div class="becf-clear-actions">
                    <button type="button" class="button becf-clear-all-btn" onclick="cfbeClearAllFields()">
                        全てのフィールドを削除
                    </button>
                    <span class="becf-help-text">※ 表示中の全ての入力値が削除されます</span>
                </div>
                <div class="becf-save-actions">
                    <button type="button" id="becf-ajax-save-btn" class="button button-primary button-large">
                        変更を保存
                    </button>
                    <span class="becf-help-text">※ 変更後、必ず保存ボタンをクリックしてください</span>
                </div>
                <div id="becf-save-progress" style="display: none; margin-top: 16px;">
                    <div class="becf-progress-bar">
                        <div class="becf-progress-fill" style="width: 0%;"></div>
                    </div>
                    <div class="becf-progress-text">保存中...</div>
                </div>
            </div>
            
            <!-- カスタムフィールドキー表示機能 -->
            <div class="becf-field-keys-section" style="margin-top: 20px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
                <a href="#" id="toggle-field-keys" style="text-decoration: none; color: #0073aa;">
                    収集されたカスタムフィールドキーを表示 (<?php echo count($custom_field_keys); ?>個)
                </a>
                <div id="field-keys-list" style="display: none; margin-top: 15px;">
                    <h4 style="margin-top: 0;">カスタムフィールドキー一覧</h4>
                    <div style="max-height: 300px; overflow-y: auto; background: white; padding: 10px; border: 1px solid #ccc; border-radius: 3px;">
                        <?php if (!empty($custom_field_keys)): ?>
                            <ul style="margin: 0; columns: 3; column-gap: 20px; list-style-type: disc;">
                                <?php foreach (array_keys($custom_field_keys) as $field_key): ?>
                                    <li style="break-inside: avoid; margin-bottom: 5px;">
                                        <code style="background: #f1f1f1; padding: 2px 4px; border-radius: 3px;"><?php echo esc_html($field_key); ?></code>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p style="margin: 0; color: #666;">カスタムフィールドが見つかりませんでした。</p>
                        <?php endif; ?>
                    </div>
                    <p style="margin-top: 10px; font-size: 13px; color: #666;">
                        <strong>注意:</strong> アンダースコア(_)で始まるWordPress内部フィールドは除外されています。
                    </p>
                </div>
            </div>
        </form>
        
        <!-- 一括削除確認モーダル -->
        
        <!-- 個別削除用の隠しフォーム -->
        
        <?php endif; ?>
    </div>

    <script>
        // WordPress AJAX URL定義
        var ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
        
        // AJAX保存機能
        document.addEventListener('DOMContentLoaded', function() {
            const saveBtn = document.getElementById('becf-ajax-save-btn');
            if (saveBtn) {
                saveBtn.addEventListener('click', cfbeAjaxSave);
            }
            
            // カスタムフィールドキー表示機能
            const toggleButton = document.getElementById('toggle-field-keys');
            const fieldKeysList = document.getElementById('field-keys-list');
            
            if (toggleButton && fieldKeysList) {
                toggleButton.addEventListener('click', function(e) {
                    e.preventDefault();
                    
                    if (fieldKeysList.style.display === 'none') {
                        fieldKeysList.style.display = 'block';
                        toggleButton.innerHTML = 'カスタムフィールドキーを非表示 (<?php echo count($custom_field_keys); ?>個)';
                    } else {
                        fieldKeysList.style.display = 'none';
                        toggleButton.innerHTML = '収集されたカスタムフィールドキーを表示 (<?php echo count($custom_field_keys); ?>個)';
                    }
                });
            }
        });
        
        async function cfbeAjaxSave() {
            console.log('🚀 AJAX保存処理開始');
            
            const saveBtn = document.getElementById('becf-ajax-save-btn');
            const progressDiv = document.getElementById('becf-save-progress');
            const progressFill = document.querySelector('.becf-progress-fill');
            const progressText = document.querySelector('.becf-progress-text');
            
            // 要素の存在確認
            if (!saveBtn) {
                console.error('❌ 保存ボタンが見つかりません');
                alert('保存ボタンが見つかりません');
                return;
            }
            
            if (!progressDiv || !progressFill || !progressText) {
                console.error('❌ プログレスバー要素が見つかりません');
                alert('プログレスバー要素が見つかりません');
                return;
            }
            
            console.log('✅ UI要素の確認完了');
            
            // ボタンを無効化
            saveBtn.disabled = true;
            saveBtn.textContent = '保存中...';
            progressDiv.style.display = 'block';
            
            try {
                console.log('📋 フォームデータを収集中...');
                // フォームデータを収集
                const formData = collectFormData();
                console.log('✅ フォームデータ収集完了:', Object.keys(formData).length, 'posts');
                
                // 統計情報を計算
                let totalPosts = Object.keys(formData).length;
                let totalFields = 0;
                let emptyFields = 0;
                
                for (const postId in formData) {
                    for (const fieldKey in formData[postId]) {
                        totalFields++;
                        const fieldValue = formData[postId][fieldKey];
                        
                        // 配列データか文字列データかで空判定を変える
                        let isEmpty = false;
                        if (Array.isArray(fieldValue)) {
                            isEmpty = fieldValue.length === 0;
                        } else if (typeof fieldValue === 'string') {
                            isEmpty = fieldValue.trim() === '';
                        }
                        
                        if (isEmpty) {
                            emptyFields++;
                        }
                    }
                }
                
                console.log('フォームデータ統計:', {
                    投稿数: totalPosts,
                    総フィールド数: totalFields,
                    空のフィールド数: emptyFields,
                    削除対象数: emptyFields
                });
                
                const chunks = chunkFormData(formData, 50); // 50件ずつ分割
                console.log('チャンク分割結果:', chunks.length, 'chunks');
                
                if (chunks.length === 0) {
                    throw new Error('保存するデータがありません');
                }
                
                let savedTotal = 0;
                
                for (let i = 0; i < chunks.length; i++) {
                    const progress = Math.round(((i + 1) / chunks.length) * 100);
                    progressFill.style.width = progress + '%';
                    progressText.textContent = `保存中... (${i + 1}/${chunks.length})`;
                    
                    console.log(`チャンク ${i + 1} を送信中:`, chunks[i]);
                    
                    const formData = new URLSearchParams({
                        action: 'becf_save_fields',
                        nonce: '<?php echo wp_create_nonce('becf_bulk_edit'); ?>',
                        chunk_data: JSON.stringify(chunks[i])
                    });
                    
                    console.log('送信データ:', {
                        action: 'becf_save_fields',
                        nonce: '<?php echo wp_create_nonce('becf_bulk_edit'); ?>',
                        chunk_data_size: JSON.stringify(chunks[i]).length,
                        ajaxurl: ajaxurl
                    });
                    
                    const response = await fetch(ajaxurl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: formData
                    });
                    
                    console.log('レスポンス:', response.status, response.statusText, response.url);
                    
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    
                    const responseText = await response.text();
                    console.log('生のレスポンステキスト:', responseText);
                    
                    let result;
                    try {
                        result = JSON.parse(responseText);
                    } catch (parseError) {
                        console.error('JSON解析エラー:', parseError);
                        console.error('レスポンス内容:', responseText);
                        throw new Error('サーバーからの無効なレスポンス');
                    }
                    
                    console.log('解析された結果:', result);
                    
                    if (result.success) {
                        savedTotal += result.data.saved_count;
                    } else {
                        throw new Error(result.data || '保存に失敗しました');
                    }
                    
                    // 少し待機
                    await new Promise(resolve => setTimeout(resolve, 100));
                }
                
                // 成功メッセージ
                progressText.textContent = `完了: ${savedTotal}件のフィールドを保存しました`;
                progressFill.style.backgroundColor = '#00a32a';
                
                // 3秒後にプログレスバーを非表示
                setTimeout(() => {
                    progressDiv.style.display = 'none';
                }, 3000);
                
            } catch (error) {
                console.error('保存エラー:', error);
                progressText.textContent = 'エラー: ' + error.message;
                progressFill.style.backgroundColor = '#d63638';
            } finally {
                // ボタンを再有効化
                saveBtn.disabled = false;
                saveBtn.textContent = '変更を保存';
            }
        }
        
        function collectFormData() {
            const formData = {};
            const inputs = document.querySelectorAll('.becf-table input, .becf-table textarea');
            console.log('見つかった入力フィールド数:', inputs.length);
            
            inputs.forEach((input, index) => {
                if (input.name) {
                    // input.name は "becf_field[post_id][field_key]" の形式
                    const matches = input.name.match(/becf_field\[(\d+)\]\[(.+?)\]/);
                    if (matches) {
                        const postId = matches[1];
                        const fieldKey = matches[2];
                        let value = input.value.trim(); // 前後の空白を除去
                        
                        if (!formData[postId]) {
                            formData[postId] = {};
                        }
                        
                        // JSON文字列かどうかチェックして配列に変換
                        if (value.startsWith('[') && value.endsWith(']')) {
                            try {
                                const parsedArray = JSON.parse(value);
                                if (Array.isArray(parsedArray)) {
                                    value = parsedArray;
                                    console.log(`JSON配列を解析: postId=${postId}, fieldKey=${fieldKey}, array=`, parsedArray);
                                }
                            } catch (e) {
                                console.log(`JSON解析失敗（文字列として処理）: postId=${postId}, fieldKey=${fieldKey}, value="${value}"`);
                            }
                        }
                        
                        // 空の値でも明示的に送信（サーバー側で削除処理される）
                        formData[postId][fieldKey] = value;
                        
                        if (Array.isArray(value)) {
                            console.log(`配列データ: postId=${postId}, fieldKey=${fieldKey}, array=`, value);
                        } else if (value !== '') {
                            console.log(`値あり: postId=${postId}, fieldKey=${fieldKey}, value="${value}"`);
                        } else {
                            console.log(`空の値（削除対象): postId=${postId}, fieldKey=${fieldKey}`);
                        }
                    } else {
                        console.log('マッチしなかったフィールド:', input.name);
                    }
                }
            });
            
            console.log('最終的なフォームデータ:', formData);
            return formData;
        }
        
        function chunkFormData(formData, chunkSize) {
            const chunks = [];
            let currentChunk = {};
            let currentCount = 0;
            
            for (const postId in formData) {
                currentChunk[postId] = formData[postId];
                currentCount++;
                
                if (currentCount >= chunkSize) {
                    chunks.push(currentChunk);
                    currentChunk = {};
                    currentCount = 0;
                }
            }
            
            if (currentCount > 0) {
                chunks.push(currentChunk);
            }
            
            return chunks;
        }
        
        function cfbeFilterFields() {
            const selectedField = document.getElementById('becf_filter_field').value;
            const headers = document.querySelectorAll('.becf-field-header');
            const cells = document.querySelectorAll('.becf-field-cell');
            
            headers.forEach(header => {
                header.style.display = (selectedField === '' || header.dataset.field === selectedField) ? '' : 'none';
            });
            
            cells.forEach(cell => {
                cell.style.display = (selectedField === '' || cell.dataset.field === selectedField) ? '' : 'none';
            });
        }

        function cfbeResetFilter() {
            document.getElementById('becf_filter_field').value = '';
            cfbeFilterFields();
        }
        
        function cfbeSearchTitle() {
            const searchTerm = document.getElementById('becf_search_title').value.toLowerCase();
            const rows = document.querySelectorAll('.becf-table tbody tr');
            let visibleCount = 0;
            
            rows.forEach(row => {
                const titleCell = row.querySelector('.becf-col-title a');
                if (titleCell) {
                    const title = titleCell.textContent.toLowerCase();
                    const isMatch = searchTerm === '' || title.includes(searchTerm);
                    
                    if (isMatch) {
                        row.classList.remove('becf-row-hidden');
                        titleCell.classList.add('becf-search-highlight');
                        visibleCount++;
                    } else {
                        row.classList.add('becf-row-hidden');
                        titleCell.classList.remove('becf-search-highlight');
                    }
                }
            });
            
            // 検索結果数を表示
            updateSearchResultsInfo(visibleCount, rows.length);
        }
        
        function cfbeResetSearch() {
            document.getElementById('becf_search_title').value = '';
            const rows = document.querySelectorAll('.becf-table tbody tr');
            
            rows.forEach(row => {
                row.classList.remove('becf-row-hidden');
                const titleCell = row.querySelector('.becf-col-title a');
                if (titleCell) {
                    titleCell.classList.remove('becf-search-highlight');
                }
            });
            
            updateSearchResultsInfo(rows.length, rows.length);
        }
        
        function updateSearchResultsInfo(visible, total) {
            let infoElement = document.querySelector('.becf-search-results');
            if (!infoElement) {
                infoElement = document.createElement('span');
                infoElement.className = 'becf-search-results';
                infoElement.style.marginLeft = '10px';
                infoElement.style.color = '#646970';
                infoElement.style.fontSize = '13px';
                document.getElementById('becf_search_title').parentNode.appendChild(infoElement);
            }
            
            if (visible === total) {
                infoElement.textContent = '';
            } else {
                infoElement.textContent = `(${visible}/${total}件表示 - 現在のページのみ)`;
            }
        }
        
        // エンターキーで検索実行
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('becf_search_title');
            if (searchInput) {
                searchInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        cfbeSearchTitle();
                    }
                });
                
                // リアルタイム検索（オプション）
                searchInput.addEventListener('input', function() {
                    if (this.value === '') {
                        cfbeResetSearch();
                    }
                });
            }
        });
        
        // 削除機能の初期化
        
        // 各フィールドの保存された値を管理するオブジェクト
        let savedFieldValues = {};
        let savedRowValues = {};
        
        // 全フィールド削除/復元ボタンのイベント用変数
        let allFieldsSaved = {};
        let allFieldsCleared = false;
        
        // 統一された状態管理システム
        
        // 統合状態チェック関数 - 全ての削除状態を統一的に判定
        function getUnifiedState() {
            const allInputs = document.querySelectorAll('.becf-table input[type="text"], .becf-table textarea');
            const state = {
                totalInputs: allInputs.length,
                emptyInputs: 0,
                hasIndividualDeletes: Object.keys(savedFieldValues).length > 0,
                hasRowDeletes: Object.keys(savedRowValues).length > 0,
                hasBulkDelete: allFieldsCleared,
                fieldStates: {},
                rowStates: {}
            };
            
            // 各入力フィールドの状態を記録
            allInputs.forEach(input => {
                if (input.value.trim() === '') {
                    state.emptyInputs++;
                }
                
                // フィールドごとの集計
                const fieldKey = input.closest('td').dataset.field;
                const postId = input.closest('tr').dataset.postId;
                
                if (fieldKey) {
                    if (!state.fieldStates[fieldKey]) {
                        state.fieldStates[fieldKey] = { total: 0, empty: 0, hasData: false };
                    }
                    state.fieldStates[fieldKey].total++;
                    if (input.value.trim() === '') {
                        state.fieldStates[fieldKey].empty++;
                    }
                    // 復元可能なデータがあるか
                    state.fieldStates[fieldKey].hasData = savedFieldValues[fieldKey] || allFieldsCleared;
                }
                
                if (postId) {
                    if (!state.rowStates[postId]) {
                        state.rowStates[postId] = { total: 0, empty: 0, hasData: false };
                    }
                    state.rowStates[postId].total++;
                    if (input.value.trim() === '') {
                        state.rowStates[postId].empty++;
                    }
                    // 復元可能なデータがあるか
                    state.rowStates[postId].hasData = savedRowValues[postId] || allFieldsCleared;
                }
            });
            
            return state;
        }
        
        // フィールドボタン状態更新（統合状態使用）
        function updateFieldButtonStates() {
            const state = getUnifiedState();
            
            document.querySelectorAll('.becf-clear-field-btn').forEach(btn => {
                const fieldKey = btn.dataset.field;
                const fieldState = state.fieldStates[fieldKey];
                
                if (!fieldState) return;
                
                const allEmpty = fieldState.empty === fieldState.total;
                const hasRestorationData = fieldState.hasData;
                
                if (allEmpty && hasRestorationData) {
                    btn.textContent = '復元';
                    btn.classList.add('becf-cleared');
                    btn.title = 'この項目の値を復元';
                } else {
                    btn.textContent = '削除';
                    btn.classList.remove('becf-cleared');
                    btn.title = 'この項目の全ての値を削除';
                }
            });
        }
        
        // 行ボタン機能はチェックボックス方式に変更されました
        
        // 一括ボタン状態更新（統合状態使用）
        function updateBulkButtonState() {
            const clearAllBtn = document.querySelector('.becf-clear-all-btn');
            if (!clearAllBtn) return;
            
            if (allFieldsCleared) {
                clearAllBtn.innerHTML = '全てのフィールドを復元';
                clearAllBtn.nextElementSibling.textContent = '※ 削除前の値に戻します';
            } else {
                clearAllBtn.innerHTML = '全てのフィールドを削除';
                clearAllBtn.nextElementSibling.textContent = '※ 表示中の全ての入力値が削除されます';
            }
        }
        
        // 統一された状態更新関数
        function updateAllButtonStates(context = '') {
            console.log(`統合状態更新: ${context}`);
            const state = getUnifiedState();
            console.log('現在の統合状態:', {
                総入力数: state.totalInputs,
                空の入力数: state.emptyInputs,
                個別削除済み: state.hasIndividualDeletes,
                行削除済み: state.hasRowDeletes,
                一括削除済み: state.hasBulkDelete,
                フィールド状態数: Object.keys(state.fieldStates).length,
                行状態数: Object.keys(state.rowStates).length
            });
            
            updateFieldButtonStates();
            updateBulkButtonState();
        }
        
        // 特定要素のみ更新（効率化用）
        function updateSpecificStates(affectedFields = [], affectedRows = []) {
            const state = getUnifiedState();
            
            // 特定フィールドのボタン更新
            affectedFields.forEach(fieldKey => {
                const btn = document.querySelector(`.becf-clear-field-btn[data-field="${fieldKey}"]`);
                if (btn && state.fieldStates[fieldKey]) {
                    const fieldState = state.fieldStates[fieldKey];
                    const allEmpty = fieldState.empty === fieldState.total;
                    const hasRestorationData = fieldState.hasData;
                    
                    if (allEmpty && hasRestorationData) {
                        btn.textContent = '復元';
                        btn.classList.add('becf-cleared');
                        btn.title = 'この項目の値を復元';
                    } else {
                        btn.textContent = '削除';
                        btn.classList.remove('becf-cleared');
                        btn.title = 'この項目の全ての値を削除';
                    }
                }
            });
            
            // 特定行のボタン更新
            affectedRows.forEach(postId => {
                const button = document.querySelector(`button[data-post-id="${postId}"]`);
                if (button && state.rowStates[postId]) {
                    const rowState = state.rowStates[postId];
                    const allEmpty = rowState.empty === rowState.total;
                    const hasRestorationData = rowState.hasData;
                    
                    if (allEmpty && hasRestorationData) {
                        button.innerHTML = '↩️ 行復元';
                        button.classList.add('becf-cleared');
                        button.title = 'この行のフィールドを復元';
                    } else {
                        button.innerHTML = '🗑️ 行削除';
                        button.classList.remove('becf-cleared');
                        button.title = 'この行の全フィールドを削除';
                    }
                }
            });
            
            // 一括ボタンは常に更新
            updateBulkButtonState();
        }
        
        // 進捗表示用の要素を作成
        function createProgressModal() {
            const modal = document.createElement('div');
            modal.id = 'becf-progress-modal';
            modal.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.7);
                z-index: 999999;
                display: flex;
                align-items: center;
                justify-content: center;
            `;
            
            const content = document.createElement('div');
            content.style.cssText = `
                background: white;
                padding: 30px;
                border-radius: 8px;
                text-align: center;
                min-width: 300px;
                box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            `;
            
            content.innerHTML = `
                <h3 style="margin-top: 0;">処理中...</h3>
                <div style="width: 100%; background: #f0f0f0; border-radius: 10px; overflow: hidden; margin: 20px 0;">
                    <div id="becf-progress-bar" style="width: 0%; height: 20px; background: #2271b1; transition: width 0.3s ease;"></div>
                </div>
                <div id="becf-progress-text">開始しています...</div>
                <p style="color: #666; font-size: 13px; margin-top: 15px;">大量のデータを処理しています。しばらくお待ちください。</p>
            `;
            
            modal.appendChild(content);
            document.body.appendChild(modal);
            return modal;
        }
        
        // 進捗更新関数
        function updateProgress(percent, text) {
            const progressBar = document.getElementById('becf-progress-bar');
            const progressText = document.getElementById('becf-progress-text');
            if (progressBar) progressBar.style.width = percent + '%';
            if (progressText) progressText.textContent = text;
        }
        
        // 進捗モーダルを閉じる
        function closeProgressModal() {
            const modal = document.getElementById('becf-progress-modal');
            if (modal) {
                modal.remove();
            }
        }
        
        // 配列を小さなチャンクに分割
        function chunkArray(array, chunkSize) {
            const chunks = [];
            for (let i = 0; i < array.length; i += chunkSize) {
                chunks.push(array.slice(i, i + chunkSize));
            }
            return chunks;
        }
        
        // 非同期処理でフィールドを処理
        async function processFieldsAsync(elements, processor, progressText) {
            const modal = createProgressModal();
            const chunks = chunkArray(Array.from(elements), 50); // 50個ずつ処理
            
            try {
                for (let i = 0; i < chunks.length; i++) {
                    const chunk = chunks[i];
                    const progress = Math.round(((i + 1) / chunks.length) * 100);
                    
                    updateProgress(progress, `${progressText} (${i + 1}/${chunks.length})`);
                    
                    // チャンクを処理
                    chunk.forEach((element, localIndex) => {
                        const globalIndex = i * 50 + localIndex; // グローバルインデックスを計算
                        processor(element, globalIndex);
                    });
                    
                    // UIをブロックしないように少し待機
                    await new Promise(resolve => setTimeout(resolve, 10));
                }
                
                updateProgress(100, '完了しました');
                await new Promise(resolve => setTimeout(resolve, 500));
                
            } finally {
                closeProgressModal();
            }
        }
        
        // 項目ごと削除/復元ボタンのイベント
        document.querySelectorAll('.becf-clear-field-btn').forEach(button => {
            button.addEventListener('click', async function() {
                const fieldKey = this.dataset.field;
                const fieldInputs = document.querySelectorAll(`td[data-field="${fieldKey}"] input[type="text"], td[data-field="${fieldKey}"] textarea`);
                
                console.log('フィールド削除/復元:', fieldKey, 'フィールド数:', fieldInputs.length);
                
                // 現在の状態を確認（削除済みかどうか）
                const isCleared = this.classList.contains('becf-cleared');
                
                if (isCleared) {
                    // 復元処理
                    let hasDataToRestore = false;
                    
                    // 一括削除状態の場合、まず allFieldsSaved から復元を試行
                    if (allFieldsCleared && allFieldsSaved) {
                        const allInputs = document.querySelectorAll('.becf-table input[type="text"], .becf-table textarea');
                        await processFieldsAsync(
                            fieldInputs,
                            (el) => {
                                const globalIndex = Array.from(allInputs).indexOf(el);
                                if (globalIndex !== -1 && allFieldsSaved[globalIndex] !== undefined) {
                                    el.value = allFieldsSaved[globalIndex];
                                    hasDataToRestore = true;
                                }
                            },
                            'フィールドを復元中（一括データより）'
                        );
                    }
                    
                    // savedFieldValues から復元を試行（個別削除されたフィールドの場合）
                    if (!hasDataToRestore && savedFieldValues[fieldKey]) {
                        await processFieldsAsync(
                            fieldInputs,
                            (el, index) => {
                                if (savedFieldValues[fieldKey][index] !== undefined) {
                                    el.value = savedFieldValues[fieldKey][index];
                                    hasDataToRestore = true;
                                }
                            },
                            'フィールドを復元中（個別データより）'
                        );
                    }
                    
                    // savedFieldValues にデータがない場合、行削除データから復元を試行
                    if (!hasDataToRestore) {
                        await processFieldsAsync(
                            fieldInputs,
                            (el) => {
                                // 行削除データから該当する値を探す
                                for (const [postId, rowData] of Object.entries(savedRowValues)) {
                                    const rowElement = document.querySelector(`button[data-post-id="${postId}"]`);
                                    if (rowElement) {
                                        const targetRow = rowElement.closest('tr');
                                        const rowInputs = Array.from(targetRow.querySelectorAll('.becf-field-cell input[type="text"], .becf-field-cell textarea'));
                                        const elementIndex = rowInputs.indexOf(el);
                                        
                                        if (elementIndex !== -1 && rowData[elementIndex] !== undefined) {
                                            el.value = rowData[elementIndex];
                                            hasDataToRestore = true;
                                        }
                                    }
                                }
                            },
                            'フィールドを復元中（行データより）'
                        );
                    }

                    
                    // まだデータがない場合、allFieldsSaved から復元を試行
                    if (!hasDataToRestore && Object.keys(allFieldsSaved).length > 0) {
                        const allInputs = document.querySelectorAll('.becf-table input[type="text"], .becf-table textarea');
                        await processFieldsAsync(
                            fieldInputs,
                            (el) => {
                                const globalIndex = Array.from(allInputs).indexOf(el);
                                if (globalIndex !== -1 && allFieldsSaved[globalIndex] !== undefined) {
                                    el.value = allFieldsSaved[globalIndex];
                                }
                            },
                            'フィールドを復元中'
                        );
                    }
                    
                    this.textContent = '削除';
                    this.classList.remove('becf-cleared');
                    this.title = 'この項目の全ての値を削除';
                    
                    // 個別復元時は、savedFieldValuesから削除
                    delete savedFieldValues[fieldKey];
                    
                    // 統合状態管理で影響を受ける要素を更新
                    const affectedPostIds = new Set();
                    fieldInputs.forEach(input => {
                        const postId = input.closest('tr').dataset.postId;
                        if (postId) affectedPostIds.add(postId);
                    });
                    updateSpecificStates([fieldKey], Array.from(affectedPostIds));
                } else {
                    // 削除処理（値を保存してから削除）
                    savedFieldValues[fieldKey] = [];
                    
                    await processFieldsAsync(
                        fieldInputs,
                        (el, index) => {
                            savedFieldValues[fieldKey][index] = el.value;
                            el.value = '';
                        },
                        'フィールドを削除中'
                    );
                    
                    this.textContent = '復元';
                    this.classList.add('becf-cleared');
                    this.title = 'この項目の値を復元';
                    
                    // 統合状態管理で影響を受ける要素を更新
                    const affectedPostIds = new Set();
                    fieldInputs.forEach(input => {
                        const postId = input.closest('tr').dataset.postId;
                        if (postId) affectedPostIds.add(postId);
                    });
                    updateSpecificStates([fieldKey], Array.from(affectedPostIds));
                }
            });
        });
        

        
        // チェックボックス機能の初期化
        function initRowCheckboxes() {
            const selectAllCheckbox = document.getElementById('becf-select-all-rows');
            const rowCheckboxes = document.querySelectorAll('.becf-row-checkbox');
            const bulkActions = document.getElementById('becf-bulk-row-actions');
            const selectedCount = document.getElementById('becf-selected-count');
            
            // 全選択チェックボックスの処理
            if (selectAllCheckbox) {
                selectAllCheckbox.addEventListener('change', function() {
                    rowCheckboxes.forEach(checkbox => {
                        checkbox.checked = this.checked;
                        toggleRowHighlight(checkbox);
                    });
                    updateBulkActionsVisibility();
                });
            }
            
            // 各行チェックボックスの処理
            rowCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    toggleRowHighlight(this);
                    updateSelectAllState();
                    updateBulkActionsVisibility();
                });
            });
            
            // 行のハイライト切り替え
            function toggleRowHighlight(checkbox) {
                const row = checkbox.closest('tr');
                if (checkbox.checked) {
                    row.classList.add('becf-row-selected');
                } else {
                    row.classList.remove('becf-row-selected');
                }
            }
            
            // 全選択チェックボックスの状態更新
            function updateSelectAllState() {
                if (selectAllCheckbox) {
                    const checkedBoxes = document.querySelectorAll('.becf-row-checkbox:checked');
                    selectAllCheckbox.checked = checkedBoxes.length === rowCheckboxes.length && rowCheckboxes.length > 0;
                    selectAllCheckbox.indeterminate = checkedBoxes.length > 0 && checkedBoxes.length < rowCheckboxes.length;
                }
            }
            
            // 一括操作ボタンの表示/非表示
            function updateBulkActionsVisibility() {
                const checkedBoxes = document.querySelectorAll('.becf-row-checkbox:checked');
                if (checkedBoxes.length > 0) {
                    bulkActions.classList.add('show');
                    selectedCount.textContent = `${checkedBoxes.length}行選択中`;
                } else {
                    bulkActions.classList.remove('show');
                }
            }
        }
        
        // 選択された行を削除する関数
        window.cfbeDeleteSelectedRows = async function() {
            const checkedBoxes = document.querySelectorAll('.becf-row-checkbox:checked');
            if (checkedBoxes.length === 0) {
                alert('削除する行を選択してください。');
                return;
            }
            
            if (!confirm(`選択した${checkedBoxes.length}行のフィールドを削除しますか？`)) {
                return;
            }
            
            for (const checkbox of checkedBoxes) {
                const postId = checkbox.dataset.postId;
                const row = checkbox.closest('tr');
                const rowFieldInputs = row.querySelectorAll('.becf-field-cell input[type="text"], .becf-field-cell textarea');
                
                console.log(`🗑️ 行削除処理開始: 投稿ID ${postId}, フィールド数: ${rowFieldInputs.length}`);
                
                // 行削除データを保存
                if (!savedRowValues[postId]) {
                    savedRowValues[postId] = [];
                    let deletedCount = 0;
                    rowFieldInputs.forEach((input, index) => {
                        savedRowValues[postId][index] = input.value;
                        if (input.value.trim() !== '') {
                            deletedCount++;
                        }
                    });
                    console.log(`📝 保存データ: 投稿ID ${postId}, ${deletedCount}個のフィールドに値あり`);
                }
                
                // フィールドを空にする
                let clearedCount = 0;
                rowFieldInputs.forEach(input => {
                    if (input.value.trim() !== '') {
                        clearedCount++;
                    }
                    input.value = '';
                });
                console.log(`🔄 削除完了: 投稿ID ${postId}, ${clearedCount}個のフィールドを空に設定`);
                
                // 行の見た目を削除状態に変更
                row.classList.add('becf-row-deleted');
            }
            
            // チェックボックスの選択を解除
            checkedBoxes.forEach(checkbox => {
                checkbox.checked = false;
                toggleRowHighlight(checkbox);
            });
            
            // 統合状態管理で全体更新
            updateAllButtonStates('選択行削除');
            
            // 一括操作ボタンを非表示
            document.getElementById('becf-bulk-row-actions').classList.remove('show');
        };
        
        // 選択された行を復元する関数
        window.cfbeRestoreSelectedRows = async function() {
            const checkedBoxes = document.querySelectorAll('.becf-row-checkbox:checked');
            if (checkedBoxes.length === 0) {
                alert('復元する行を選択してください。');
                return;
            }
            
            for (const checkbox of checkedBoxes) {
                const postId = checkbox.dataset.postId;
                const row = checkbox.closest('tr');
                const rowFieldInputs = row.querySelectorAll('.becf-field-cell input[type="text"], .becf-field-cell textarea');
                
                console.log(`行復元処理開始: 投稿ID ${postId}`);
                
                // 行削除データから復元
                if (savedRowValues[postId]) {
                    rowFieldInputs.forEach((input, index) => {
                        if (savedRowValues[postId][index] !== undefined) {
                            input.value = savedRowValues[postId][index];
                        }
                    });
                    delete savedRowValues[postId];
                }
                // 一括削除データから復元
                else if (allFieldsCleared && allFieldsSaved) {
                    const allInputs = document.querySelectorAll('.becf-table input[type="text"], .becf-table textarea');
                    rowFieldInputs.forEach(input => {
                        const globalIndex = Array.from(allInputs).indexOf(input);
                        if (globalIndex !== -1 && allFieldsSaved[globalIndex] !== undefined) {
                            input.value = allFieldsSaved[globalIndex];
                        }
                    });
                }
                
                // 行の見た目を通常状態に戻す
                row.classList.remove('becf-row-deleted');
            }
            
            // チェックボックスの選択を解除
            checkedBoxes.forEach(checkbox => {
                checkbox.checked = false;
                toggleRowHighlight(checkbox);
            });
            
            // 統合状態管理で全体更新
            updateAllButtonStates('選択行復元');
            
            // 一括操作ボタンを非表示
            document.getElementById('becf-bulk-row-actions').classList.remove('show');
        };
        
        // ヘルパー関数
        function toggleRowHighlight(checkbox) {
            const row = checkbox.closest('tr');
            if (checkbox.checked) {
                row.classList.add('becf-row-selected');
            } else {
                row.classList.remove('becf-row-selected');
            }
        }
        
        // チェックボックス機能を初期化
        initRowCheckboxes();
        
        // 全フィールド削除/復元ボタンのイベント
        window.cfbeClearAllFields = async function() {
            const allInputs = document.querySelectorAll('.becf-table input[type="text"], .becf-table textarea');
            const clearAllBtn = document.querySelector('.becf-clear-all-btn');
            
            console.log('🔄 cfbeClearAllFields呼び出し開始');
            console.log('現在の状態:', {
                allFieldsCleared: allFieldsCleared,
                allInputs数: allInputs.length,
                clearAllBtn存在: !!clearAllBtn
            });
            
            // ボタンを無効化して重複実行を防ぐ
            clearAllBtn.disabled = true;
            const originalText = clearAllBtn.innerHTML;
            clearAllBtn.innerHTML = '処理中...';
            
            try {
                if (allFieldsCleared) {
                    // 全復元処理
                    console.log('=== 一括復元開始 ===');
                    console.log('復元前の状態:', {
                        allFieldsCleared: allFieldsCleared,
                        allFieldsSaved存在: !!allFieldsSaved,
                        allFieldsSaved項目数: allFieldsSaved ? Object.keys(allFieldsSaved).length : 0,
                        allInputs数: allInputs.length,
                        savedFieldValues項目数: Object.keys(savedFieldValues).length,
                        savedRowValues項目数: Object.keys(savedRowValues).length
                    });
                    
                    // allFieldsSavedが存在しない場合のエラーハンドリング
                    if (!allFieldsSaved || Object.keys(allFieldsSaved).length === 0) {
                        console.error('⚠️ 復元データが見つかりません！');
                        alert('復元データが見つかりません。一括削除を実行してから復元してください。');
                        clearAllBtn.innerHTML = originalText;
                        clearAllBtn.disabled = false;
                        return;
                    }
                    
                    let restoredCount = 0;
                    let skippedCount = 0;
                    
                    await processFieldsAsync(
                        allInputs,
                        (el, index) => {
                            if (allFieldsSaved[index] !== undefined) {
                                const oldValue = el.value;
                                el.value = allFieldsSaved[index];
                                restoredCount++;
                                
                                if (index < 3 || index % 100 === 0) { // 詳細ログを減らす
                                    console.log(`✅ 復元 [${index}]: "${oldValue}" → "${allFieldsSaved[index]}"`);
                                }
                            } else {
                                skippedCount++;
                                if (index < 3) {
                                    console.log(`⏭️ スキップ [${index}]: 復元データなし`);
                                }
                            }
                        },
                        '全フィールドを復元中'
                    );
                    
                    console.log(`復元完了: ${restoredCount}個復元, ${skippedCount}個スキップ`);
                    
                    // 状態をリセット
                    allFieldsCleared = false;
                    
                    // 個別削除・行削除の履歴もクリア（一括復元で全て元に戻ったため）
                    savedFieldValues = {};
                    savedRowValues = {};
                    allFieldsSaved = {};
                    
                    console.log('=== 一括復元完了 - 全状態リセット ===');
                    
                    // 統合状態管理で全ボタン更新
                    updateAllButtonStates('一括復元完了');
                } else {
                    // 一括削除処理
                    console.log('=== 一括削除開始 ===');
                    console.log('削除前の状態:', {
                        allInputs数: allInputs.length,
                        savedFieldValues項目数: Object.keys(savedFieldValues).length,
                        savedRowValues項目数: Object.keys(savedRowValues).length,
                        allFieldsCleared: allFieldsCleared
                    });
                    
                    // 復元用データを初期化
                    allFieldsSaved = {};
                    
                    // 各フィールドの現在の状態を事前保存（個別削除済みフィールドの復元用）
                    const fieldButtons = document.querySelectorAll('.becf-clear-field-btn');
                    for (const btn of fieldButtons) {
                        const fieldKey = btn.dataset.field;
                        const fieldInputs = document.querySelectorAll(`td[data-field="${fieldKey}"] input[type="text"], td[data-field="${fieldKey}"] textarea`);
                        
                        // まだ個別削除されていないフィールドの場合、現在の値を保存
                        if (!savedFieldValues[fieldKey]) {
                            savedFieldValues[fieldKey] = [];
                            fieldInputs.forEach((el, index) => {
                                savedFieldValues[fieldKey][index] = el.value;
                            });
                            console.log(`📝 フィールド ${fieldKey}: 現在値を新規保存 (${fieldInputs.length}項目)`);
                        } else {
                            console.log(`📋 フィールド ${fieldKey}: 既存の削除済み値を使用`);
                        }
                    }
                    
                    let savedCount = 0;
                    let emptyCount = 0;
                    
                    await processFieldsAsync(
                        allInputs,
                        (el, index) => {
                            // 保存すべき値を決定（優先順位: 個別削除データ > 現在表示値）
                            let valueToSave = el.value;
                            
                            const fieldKey = el.closest('td').dataset.field;
                            if (fieldKey && savedFieldValues[fieldKey]) {
                                // このフィールドのインデックスを計算
                                const fieldInputs = document.querySelectorAll(`td[data-field="${fieldKey}"] input[type="text"], td[data-field="${fieldKey}"] textarea`);
                                const elementIndex = Array.from(fieldInputs).indexOf(el);
                                
                                if (elementIndex !== -1 && savedFieldValues[fieldKey][elementIndex] !== undefined) {
                                    valueToSave = savedFieldValues[fieldKey][elementIndex];
                                }
                            }
                            
                            // allFieldsSavedに保存
                            allFieldsSaved[index] = valueToSave;
                            
                            if (valueToSave !== '') {
                                savedCount++;
                            } else {
                                emptyCount++;
                            }
                            
                            // フィールドを空にする
                            el.value = '';
                            
                            if (index < 3 || index % 200 === 0) { // ログを適度に出力
                                console.log(`💾 保存 [${index}]: "${valueToSave}" (フィールド: ${fieldKey})`);
                            }
                        },
                        '全フィールドを削除中'
                    );
                    
                    allFieldsCleared = true;
                    console.log(`=== 一括削除完了 ===`);
                    console.log(`保存結果: 合計${Object.keys(allFieldsSaved).length}項目 (値あり${savedCount}, 空${emptyCount})`);
                    
                    // 統合状態管理で全ボタン更新
                    updateAllButtonStates('一括削除完了');
                }
            } catch (error) {
                console.error('❌ 一括処理でエラーが発生:', error);
                alert('処理中にエラーが発生しました。ページを再読み込みしてください。');
                clearAllBtn.innerHTML = originalText;
            } finally {
                // ボタンを再有効化
                clearAllBtn.disabled = false;
                console.log('🔄 cfbeClearAllFields処理完了 - ボタン再有効化');
            }
        }
        

    </script>
    <?php
}

// カスタムフィールドを保存
function becf_save_custom_fields() {
    if (!isset($_POST['becf_field']) || !is_array($_POST['becf_field'])) {
        return;
    }

    foreach ($_POST['becf_field'] as $post_id => $fields) {
        $post_id = intval($post_id);
        
        if (!current_user_can('edit_post', $post_id)) {
            continue;
        }

        foreach ($fields as $key => $value) {
            if (trim($value) === '') {
                delete_post_meta($post_id, $key);
            } else {
                update_post_meta($post_id, $key, $value);
            }
        }
    }
}

// 個別フィールド削除
function becf_delete_individual_field() {
    if (!isset($_POST['delete_post_id']) || !isset($_POST['delete_field_key_individual'])) {
        echo '<div class="notice notice-error"><p>削除に必要な情報が不足しています。</p></div>';
        return;
    }

    $post_id = intval($_POST['delete_post_id']);
    $field_key = sanitize_text_field($_POST['delete_field_key_individual']);

    if (!current_user_can('edit_post', $post_id)) {
        echo '<div class="notice notice-error"><p>この投稿を編集する権限がありません。</p></div>';
        return;
    }

    if (delete_post_meta($post_id, $field_key)) {
        echo '<div class="notice notice-success is-dismissible"><p>カスタムフィールド「' . esc_html($field_key) . '」を投稿ID ' . $post_id . ' から削除しました。</p></div>';
    } else {
        echo '<div class="notice notice-warning"><p>カスタムフィールドの削除に失敗したか、既に存在しませんでした。</p></div>';
    }
}

// 一括フィールド削除
function becf_bulk_delete_fields() {
    if (!isset($_POST['delete_field_key'])) {
        echo '<div class="notice notice-error"><p>削除するフィールドが指定されていません。</p></div>';
        return;
    }

    $field_key = sanitize_text_field($_POST['delete_field_key']);
    
    if (empty($field_key)) {
        echo '<div class="notice notice-error"><p>有効なフィールド名が指定されていません。</p></div>';
        return;
    }

    // 削除の実行
    global $wpdb;
    
    $result = $wpdb->delete(
        $wpdb->postmeta,
        array('meta_key' => $field_key),
        array('%s')
    );

    if ($result !== false) {
        echo '<div class="notice notice-success is-dismissible"><p>カスタムフィールド「' . esc_html($field_key) . '」を ' . intval($result) . ' 件の投稿から削除しました。</p></div>';
    } else {
        echo '<div class="notice notice-error"><p>カスタムフィールドの一括削除に失敗しました。</p></div>';
    }
}
