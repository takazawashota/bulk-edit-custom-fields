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
add_action('admin_menu', 'cfbe_add_admin_menu');
function cfbe_add_admin_menu() {
    add_menu_page(
        'Bulk Edit Custom Fields',
        'Bulk Edit Custom Fields',
        'edit_posts',
        'custom-fields-bulk-edit',
        'cfbe_render_page',
        'dashicons-edit',
        100
    );
}

// AJAX保存ハンドラーを追加
add_action('wp_ajax_cfbe_save_fields', 'cfbe_ajax_save_fields');
function cfbe_ajax_save_fields() {
    // デバッグ情報をログに出力
    error_log('CFBE AJAX Save: 開始');
    error_log('POST data: ' . print_r($_POST, true));
    
    // Nonce確認
    if (!check_admin_referer('cfbe_bulk_edit', 'nonce')) {
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
                    $field_value = sanitize_textarea_field($field_value);
                    
                    $result = update_post_meta($post_id, $field_key, $field_value);
                    if ($result) {
                        $saved_count++;
                        error_log("CFBE AJAX Save: 保存成功 - Post ID: {$post_id}, Field: {$field_key}, Value: {$field_value}");
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
function cfbe_render_page() {
    // データ保存処理
    if (isset($_POST['cfbe_submit']) && check_admin_referer('cfbe_bulk_edit', 'cfbe_nonce')) {
        cfbe_save_custom_fields();
        echo '<div class="notice notice-success is-dismissible"><p>カスタムフィールドを更新しました。</p></div>';
    }

    // デバッグモード
    $debug_mode = isset($_GET['debug']) && $_GET['debug'] == '1';

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

    if ($debug_mode) {
        echo '<div class="notice notice-info"><h3>デバッグ情報</h3>';
        echo '<p>取得した投稿数: ' . count($posts) . ' / 全体: ' . $total_posts . '件</p>';
        echo '<p>現在ページ: ' . $current_page . ' / 全ページ: ' . $total_pages . '</p>';
        echo '<p>1ページあたり: ' . $posts_per_page . '件</p>';
        echo '<p>選択された投稿タイプ: ' . esc_html($selected_post_type) . '</p>';
        echo '<p>利用可能な投稿タイプ: ' . implode(', ', array_keys($post_types)) . '</p>';
        if (!empty($posts)) {
            $first_post = $posts[0];
            echo '<p>最初の投稿ID: ' . $first_post->ID . '</p>';
            echo '<p>最初の投稿タイトル: ' . esc_html($first_post->post_title) . '</p>';
            echo '<p>最初の投稿タイプ: ' . esc_html($first_post->post_type) . '</p>';
            
            $all_meta = get_post_meta($first_post->ID);
            echo '<p>最初の投稿の全メタデータ（get_post_meta）:</p>';
            echo '<pre style="max-height: 300px; overflow: auto; background: #f0f0f0; padding: 10px;">' . print_r($all_meta, true) . '</pre>';
            
            // 個別にメタデータを取得してみる
            echo '<h4>個別取得テスト:</h4>';
            if (is_array($all_meta)) {
                foreach ($all_meta as $key => $values) {
                    if (substr($key, 0, 1) !== '_') {
                        $single_value = get_post_meta($first_post->ID, $key, true);
                        echo '<p><strong>' . esc_html($key) . '</strong>: ';
                        echo '<code>' . esc_html(print_r($single_value, true)) . '</code></p>';
                    }
                }
            }
        }
        echo '</div>';
    }

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
                    
                    // 配列やオブジェクトの場合はJSON形式に変換
                    if (is_array($value) || is_object($value)) {
                        $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                    }
                    
                    $post_data[$post->ID]['fields'][$meta_key] = $value;
                }
            }
        }
    }
    
    ksort($custom_field_keys);

    ?>

    <style>
        .cfbe-wrap {
            margin: 20px 20px 20px 0;
        }
        
        .cfbe-info {
            background: #fff;
            border-left: 4px solid #72aee6;
            padding: 12px 16px;
            margin: 15px 0;
        }

        .cfbe-info p {
            margin: 0;
            font-size: 14px;
        }
        
        .cfbe-filter-section {
            margin: 20px 0 0;
            padding: 16px;
            background: #fff;
            border: 1px solid #c3c4c7;
            border-radius: 4px;
        }
        
        .cfbe-filter-section select {
            min-width: 250px;
            margin: 0 10px 0 0;
            height: 32px;
            vertical-align: middle;
        }
        
        .cfbe-filter-section .button {
            vertical-align: middle;
            margin-right: 5px;
        }
        
        .cfbe-table-wrapper {
            overflow-x: auto;
            overflow-y: auto;
            max-height: 100vh;
            background: #fff;
            border: 1px solid #c3c4c7;
            margin: 20px 0;
            position: relative;
        }
        
        .cfbe-table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
        }
        
        .cfbe-table th,
        .cfbe-table td {
            padding: 12px;
            border: 1px solid #dcdcde;
            text-align: left;
            vertical-align: top;
        }
        
        .cfbe-table thead th {
            background: #f6f7f7;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .cfbe-col-fixed {
            position: sticky;
            background: #fff;
            z-index: 5;
        }
        
        .cfbe-table thead .cfbe-col-fixed {
            background: #f6f7f7;
            z-index: 15;
        }
        
        .cfbe-col-title {
            left: 0;
            min-width: 300px;
            max-width: 400px;
        }
        
        .cfbe-col-checkbox {
            left: 300px;
            text-align: center;
            padding: 8px !important;
            border-right: 1px solid #c3c4c7;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
        }
        
        .cfbe-field-header,
        .cfbe-field-cell {
            min-width: 250px;
        }
        
        .cfbe-field-header small {
            color: #646970;
            font-weight: normal;
            font-size: 10px;
        }
        
        .cfbe-table tbody tr:hover td {
            background: #f6f7f7;
        }
        
        .cfbe-table tbody tr:hover .cfbe-col-fixed {
            background: #f0f0f1;
        }
        
        /* チェックボックス列の背景色設定 */
        .cfbe-table thead .cfbe-col-checkbox {
            background: #f6f7f7;
            z-index: 15;
        }
        
        .cfbe-table tbody .cfbe-col-checkbox {
            background: #fff;
            z-index: 5;
        }
        
        .cfbe-table tbody tr:hover .cfbe-col-checkbox {
            background: #f0f0f1;
        }
        
        .cfbe-input,
        .cfbe-textarea {
            width: 100%;
            padding: 6px 8px;
            font-size: 13px;
            line-height: 1.5;
            border: 1px solid #8c8f94;
            border-radius: 4px;
            box-sizing: border-box;
        }
        
        .cfbe-textarea {
            resize: vertical;
            min-height: 60px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        
        .cfbe-input:focus,
        .cfbe-textarea:focus {
            border-color: #2271b1;
            box-shadow: 0 0 0 1px #2271b1;
            outline: none;
        }
        
        .cfbe-status {
            display: inline-block;
            font-size: 12px;
            font-weight: 500;
        }
        
        .cfbe-status-publish {
            color: #00a32a;
        }
        
        .cfbe-status-draft {
            color: #dba617;
        }
        
        .cfbe-post-type {
            display: inline-block;
            font-size: 10px;
            font-weight: 500;
            color: #333;
            margin: 0 2px;
        }
        
        .cfbe-page-id {
            font-size: 11px;
            color: #646970;
            margin-top: 5px;
            font-weight: normal;
            line-height: 1.4;
        }
        
        .cfbe-page-id .cfbe-status {
            font-size: 12px;
            margin: 0 2px;
        }
        
        .cfbe-submit-section {
            background: #fff;
            padding: 16px;
            border: 1px solid #c3c4c7;
            border-radius: 4px;
        }
        
        .cfbe-help-text {
            color: #646970;
            font-size: 13px;
        }
        
        /* 削除/復元ボタンのスタイル */
        .cfbe-clear-field-btn {
            transition: all 0.3s ease;
        }
        
        .cfbe-clear-field-btn.cfbe-cleared {
            background-color: #00a32a !important;
            border-color: #00a32a !important;
            color: white !important;
        }
        
        .cfbe-clear-field-btn.cfbe-cleared:hover {
            background-color: #008a20 !important;
            border-color: #008a20 !important;
        }
        
        /* 行選択チェックボックスのスタイル */
        
        .cfbe-row-checkbox, #cfbe-select-all-rows {
            margin: 0;
            cursor: pointer;
            transform: scale(1.2);
        }
        
        .cfbe-row-selected {
            background-color: #e8f4fd !important;
        }
        
        .cfbe-row-selected .cfbe-col-fixed {
            background-color: #e8f4fd !important;
        }
        
        /* 行操作ボタン群のスタイル */
        .cfbe-bulk-row-actions {
            margin: 10px 0;
            padding: 15px;
            background-color: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 4px;
            display: none;
            align-items: center;
            gap: 15px;
        }
        
        .cfbe-bulk-row-actions.show {
            display: flex;
        }
        
        .cfbe-selected-count {
            font-weight: bold;
            color: #0073aa;
        }
        
        .cfbe-row-action-btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .cfbe-delete-selected-btn {
            background-color: #dc3232;
            color: white;
        }
        
        .cfbe-delete-selected-btn:hover {
            background-color: #a02622;
        }
        
        .cfbe-restore-selected-btn {
            background-color: #00a32a;
            color: white;
        }
        
        .cfbe-restore-selected-btn:hover {
            background-color: #008a20;
        }
        
        /* 削除された行のスタイル */
        .cfbe-row-deleted {
            opacity: 0.5;
            background-color: #ffe6e6 !important;
        }
        
        .cfbe-row-deleted input,
        .cfbe-row-deleted textarea {
            background-color: #ffcccc !important;
        }
        
        /* 保存プログレスバーのスタイル */
        .cfbe-progress-bar {
            width: 100%;
            height: 20px;
            background-color: #f1f1f1;
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 5px;
        }
        
        .cfbe-progress-fill {
            height: 100%;
            background-color: #00a32a;
            transition: width 0.3s ease;
        }
        
        .cfbe-progress-text {
            font-size: 14px;
            color: #666;
        }
        
        #cfbe-ajax-save-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .cfbe-table a {
            text-decoration: none;
            color: #2271b1;
        }
        
        .cfbe-table a:hover {
            color: #135e96;
            text-decoration: underline;
        }
        
        .cfbe-row-hidden {
            display: none !important;
        }
        
        .cfbe-search-highlight {
            background-color: #fff3cd;
        }
        
        .cfbe-pagination {
            margin: 20px 0;
            text-align: center;
            padding: 15px;
            background: #fff;
            border: 1px solid #c3c4c7;
            border-radius: 4px;
        }
        
        .cfbe-pagination-info {
            margin-right: 20px;
            font-weight: bold;
            color: #646970;
        }
        
        .cfbe-pagination-link {
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
        
        .cfbe-pagination-link:hover {
            background: #2271b1;
            color: #fff;
            border-color: #2271b1;
        }
        
        .cfbe-pagination-current {
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
        
        .cfbe-pagination-dots {
            padding: 8px 4px;
            margin: 0 3px;
            color: #646970;
        }
        
        /* 削除機能用スタイル */
        .cfbe-field-header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .cfbe-clear-actions {
            display: flex;
            align-items: center;
        }

        .cfbe-save-actions {
            display: flex;
            align-items: center;
            margin-top: 14px;
        }

        .cfbe-help-text {
            margin-left: 10px;
        }
    </style>

    <div class="wrap cfbe-wrap <?php echo $debug_mode ? 'cfbe-debug-mode' : ''; ?>">
        <h1>
            Bulk Edit Custom Fields
            <?php if ($debug_mode): ?>
                <span class="cfbe-debug-badge">🔍 デバッグモード</span>
            <?php endif; ?>
        </h1>
        
        <?php if ($debug_mode): ?>
            <div class="notice notice-info">
                <h3>収集されたカスタムフィールドキー</h3>
                <pre><?php print_r(array_keys($custom_field_keys)); ?></pre>
                
                <h3>最初の投稿のデータ構造（$post_data）</h3>
                <?php if (!empty($post_data)): ?>
                    <?php $first_post_id = array_key_first($post_data); ?>
                    <pre style="max-height: 300px; overflow: auto; background: #f0f0f0; padding: 10px;"><?php print_r($post_data[$first_post_id]); ?></pre>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <div class="cfbe-info">
            <p>
                <strong>投稿表示:</strong> <?php echo count($posts); ?> 件 (全体: <?php echo $total_posts; ?> 件) | 
                <strong>ページ:</strong> <?php echo $current_page; ?> / <?php echo $total_pages; ?> | 
                <strong>カスタムフィールド数:</strong> <?php echo count($custom_field_keys); ?> 種類
                <?php if (!$debug_mode): ?>
                    | <a href="?page=custom-fields-bulk-edit&post_type=<?php echo esc_attr($selected_post_type); ?>&paged=<?php echo $current_page; ?>&debug=1">デバッグモードで開く</a>
                <?php else: ?>
                    | <a href="?page=custom-fields-bulk-edit&post_type=<?php echo esc_attr($selected_post_type); ?>&paged=<?php echo $current_page; ?>" style="color: #d63384; font-weight: bold;">🔙 通常モードに戻る</a>
                <?php endif; ?>
            </p>
        </div>
        
        <!-- 投稿タイプフィルタ -->
        <div class="cfbe-filter-section">
            <form method="get" action="" style="display: inline-block;">
                <input type="hidden" name="page" value="custom-fields-bulk-edit">
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
                <label for="cfbe_search_title" style="margin-right: 10px;"><strong>記事名検索: </strong></label>
                <input type="text" id="cfbe_search_title" placeholder="記事タイトルで検索..." style="width: 250px; margin-right: 10px;">
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
            <?php wp_nonce_field('cfbe_bulk_edit', 'cfbe_nonce'); ?>
            
            <div class="cfbe-filter-section">
                <label for="cfbe_filter_field" style="margin-right: 10px;"><strong>表示するフィールド: </strong></label>
                <select id="cfbe_filter_field">
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
            function cfbe_render_pagination($current_page, $total_pages, $selected_post_type, $debug_mode = false) {
                if ($total_pages <= 1) return;
                
                $base_url = '?page=custom-fields-bulk-edit&post_type=' . urlencode($selected_post_type);
                if ($debug_mode) {
                    $base_url .= '&debug=1';
                }
                
                echo '<div class="cfbe-pagination">';
                echo '<span class="cfbe-pagination-info">ページ ' . $current_page . ' / ' . $total_pages . '</span>';
                
                // 前のページ
                if ($current_page > 1) {
                    echo '<a href="' . $base_url . '&paged=' . ($current_page - 1) . '" class="cfbe-pagination-link">‹ 前のページ</a>';
                }
                
                // ページ番号
                $start_page = max(1, $current_page - 2);
                $end_page = min($total_pages, $current_page + 2);
                
                if ($start_page > 1) {
                    echo '<a href="' . $base_url . '&paged=1" class="cfbe-pagination-link">1</a>';
                    if ($start_page > 2) {
                        echo '<span class="cfbe-pagination-dots">...</span>';
                    }
                }
                
                for ($i = $start_page; $i <= $end_page; $i++) {
                    if ($i == $current_page) {
                        echo '<span class="cfbe-pagination-current">' . $i . '</span>';
                    } else {
                        echo '<a href="' . $base_url . '&paged=' . $i . '" class="cfbe-pagination-link">' . $i . '</a>';
                    }
                }
                
                if ($end_page < $total_pages) {
                    if ($end_page < $total_pages - 1) {
                        echo '<span class="cfbe-pagination-dots">...</span>';
                    }
                    echo '<a href="' . $base_url . '&paged=' . $total_pages . '" class="cfbe-pagination-link">' . $total_pages . '</a>';
                }
                
                // 次のページ
                if ($current_page < $total_pages) {
                    echo '<a href="' . $base_url . '&paged=' . ($current_page + 1) . '" class="cfbe-pagination-link">次のページ ›</a>';
                }
                
                echo '</div>';
            }
            
            // 上部ページネーション
            cfbe_render_pagination($current_page, $total_pages, $selected_post_type, $debug_mode);
            ?>

            <div class="cfbe-table-wrapper">
                <table class="cfbe-table">
                    <thead>
                        <tr>
                            <th class="cfbe-col-fixed cfbe-col-title">投稿タイトル</th>
                            <th class="cfbe-col-fixed cfbe-col-checkbox">
                                <label>
                                    <input type="checkbox" id="cfbe-select-all-rows" title="全行選択/解除">
                                </label>
                            </th>
                            <?php foreach ($custom_field_keys as $key): ?>
                                <th class="cfbe-field-header" data-field="<?php echo esc_attr($key); ?>">
                                    <div class="cfbe-field-header-content">
                                        <div class="cfbe-field-title">
                                            <?php 
                                            $display_label = isset($field_labels[$key]) ? $field_labels[$key] : $key;
                                            echo esc_html($display_label);
                                            if ($display_label !== $key) {
                                                echo '<br><small>(' . esc_html($key) . ')</small>';
                                            }
                                            ?>
                                        </div>
                                        <div class="cfbe-field-actions">
                                            <button type="button" class="cfbe-clear-field-btn button button-small" 
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
                                <td class="cfbe-col-fixed cfbe-col-title">
                                    <strong>
                                        <a href="<?php echo get_edit_post_link($post->ID); ?>" target="_blank" title="編集">
                                            <?php echo esc_html($post_data[$post->ID]['title'] ?: '(タイトルなし)'); ?>
                                        </a>
                                    </strong>
                                    <div class="cfbe-page-id">
                                        ID: <?php echo $post->ID; ?> | 
                                        <?php 
                                        $post_type_obj = get_post_type_object($post_data[$post->ID]['type']);
                                        echo '<span class="cfbe-post-type">' . esc_html($post_type_obj ? $post_type_obj->label : $post_data[$post->ID]['type']) . '</span>';
                                        ?> | 
                                        <?php 
                                        $status_labels = array(
                                            'publish' => '公開',
                                            'draft' => '下書き',
                                            'pending' => '承認待ち',
                                            'private' => '非公開'
                                        );
                                        $status = $post_data[$post->ID]['status'];
                                        echo '<span class="cfbe-status cfbe-status-' . esc_attr($status) . '">' . esc_html($status_labels[$status] ?? $status) . '</span>';
                                        ?>
                                    </div>
                                </td>
                                <td class="cfbe-col-fixed cfbe-col-checkbox">
                                    <input type="checkbox" class="cfbe-row-checkbox" 
                                           data-post-id="<?php echo esc_attr($post->ID); ?>"
                                           title="この行を選択">
                                </td>
                                <?php foreach ($custom_field_keys as $key): ?>
                                    <td class="cfbe-field-cell" data-field="<?php echo esc_attr($key); ?>">
                                        <?php
                                        $value = isset($post_data[$post->ID]['fields'][$key]) ? $post_data[$post->ID]['fields'][$key] : '';
                                        $field_name = "cfbe_field[{$post->ID}][{$key}]";
                                        
                                        // 文字列に変換
                                        $value_str = strval($value);
                                        
                                        // 長いテキストや改行を含む場合はテキストエリア
                                        if (strlen($value_str) > 60 || strpos($value_str, "\n") !== false) {
                                            ?>
                                            <textarea 
                                                name="<?php echo esc_attr($field_name); ?>" 
                                                rows="3" 
                                                class="cfbe-textarea"
                                                placeholder="(空)"
                                            ><?php echo esc_textarea($value_str); ?></textarea>
                                            <?php
                                        } else {
                                            ?>
                                            <input 
                                                type="text" 
                                                name="<?php echo esc_attr($field_name); ?>" 
                                                value="<?php echo esc_attr($value_str); ?>" 
                                                class="cfbe-input"
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
            <div class="cfbe-bulk-row-actions" id="cfbe-bulk-row-actions">
                <span class="cfbe-selected-count" id="cfbe-selected-count">0行選択中</span>
                <button type="button" class="cfbe-row-action-btn cfbe-delete-selected-btn" onclick="cfbeDeleteSelectedRows()">
                    選択した行を削除
                </button>
                <button type="button" class="cfbe-row-action-btn cfbe-restore-selected-btn" onclick="cfbeRestoreSelectedRows()">
                    選択した行を復元
                </button>
            </div>

            <?php
            // 下部ページネーション
            cfbe_render_pagination($current_page, $total_pages, $selected_post_type, $debug_mode);
            ?>

            <div class="cfbe-submit-section">
                <div class="cfbe-clear-actions">
                    <button type="button" class="button cfbe-clear-all-btn" onclick="cfbeClearAllFields()">
                        全てのフィールドを削除
                    </button>
                    <span class="cfbe-help-text">※ 表示中の全ての入力値が削除されます</span>
                </div>
                <div class="cfbe-save-actions">
                    <button type="button" id="cfbe-ajax-save-btn" class="button button-primary button-large">
                        変更を保存
                    </button>
                    <span class="cfbe-help-text">※ 変更後、必ず保存ボタンをクリックしてください</span>
                </div>
                <div id="cfbe-save-progress" style="display: none; margin-top: 16px;">
                    <div class="cfbe-progress-bar">
                        <div class="cfbe-progress-fill" style="width: 0%;"></div>
                    </div>
                    <div class="cfbe-progress-text">保存中...</div>
                </div>
            </div>
        </form>
        
        <!-- 一括削除確認モーダル -->
        
        <!-- 個別削除用の隠しフォーム -->
        
        <?php endif; ?>
    </div>

    <script>
        // AJAX保存機能
        document.addEventListener('DOMContentLoaded', function() {
            const saveBtn = document.getElementById('cfbe-ajax-save-btn');
            if (saveBtn) {
                saveBtn.addEventListener('click', cfbeAjaxSave);
            }
        });
        
        async function cfbeAjaxSave() {
            const saveBtn = document.getElementById('cfbe-ajax-save-btn');
            const progressDiv = document.getElementById('cfbe-save-progress');
            const progressFill = document.querySelector('.cfbe-progress-fill');
            const progressText = document.querySelector('.cfbe-progress-text');
            
            // ボタンを無効化
            saveBtn.disabled = true;
            saveBtn.textContent = '保存中...';
            progressDiv.style.display = 'block';
            
            try {
                // フォームデータを収集
                const formData = collectFormData();
                console.log('収集されたフォームデータ:', formData);
                
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
                    
                    const response = await fetch(ajaxurl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: new URLSearchParams({
                            action: 'cfbe_save_fields',
                            nonce: '<?php echo wp_create_nonce('cfbe_bulk_edit'); ?>',
                            chunk_data: JSON.stringify(chunks[i])
                        })
                    });
                    
                    console.log('レスポンス:', response.status, response.statusText);
                    
                    const result = await response.json();
                    console.log('結果:', result);
                    
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
            const inputs = document.querySelectorAll('.cfbe-table input, .cfbe-table textarea');
            console.log('見つかった入力フィールド数:', inputs.length);
            
            inputs.forEach((input, index) => {
                if (input.name && input.value !== '') {
                    console.log(`フィールド ${index}: name="${input.name}", value="${input.value}"`);
                    // input.name は "cfbe_field[post_id][field_key]" の形式
                    const matches = input.name.match(/cfbe_field\[(\d+)\]\[(.+?)\]/);
                    if (matches) {
                        const postId = matches[1];
                        const fieldKey = matches[2];
                        
                        if (!formData[postId]) {
                            formData[postId] = {};
                        }
                        formData[postId][fieldKey] = input.value;
                        console.log(`追加: postId=${postId}, fieldKey=${fieldKey}, value=${input.value}`);
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
            const selectedField = document.getElementById('cfbe_filter_field').value;
            const headers = document.querySelectorAll('.cfbe-field-header');
            const cells = document.querySelectorAll('.cfbe-field-cell');
            
            headers.forEach(header => {
                header.style.display = (selectedField === '' || header.dataset.field === selectedField) ? '' : 'none';
            });
            
            cells.forEach(cell => {
                cell.style.display = (selectedField === '' || cell.dataset.field === selectedField) ? '' : 'none';
            });
        }

        function cfbeResetFilter() {
            document.getElementById('cfbe_filter_field').value = '';
            cfbeFilterFields();
        }
        
        function cfbeSearchTitle() {
            const searchTerm = document.getElementById('cfbe_search_title').value.toLowerCase();
            const rows = document.querySelectorAll('.cfbe-table tbody tr');
            let visibleCount = 0;
            
            rows.forEach(row => {
                const titleCell = row.querySelector('.cfbe-col-title a');
                if (titleCell) {
                    const title = titleCell.textContent.toLowerCase();
                    const isMatch = searchTerm === '' || title.includes(searchTerm);
                    
                    if (isMatch) {
                        row.classList.remove('cfbe-row-hidden');
                        titleCell.classList.add('cfbe-search-highlight');
                        visibleCount++;
                    } else {
                        row.classList.add('cfbe-row-hidden');
                        titleCell.classList.remove('cfbe-search-highlight');
                    }
                }
            });
            
            // 検索結果数を表示
            updateSearchResultsInfo(visibleCount, rows.length);
        }
        
        function cfbeResetSearch() {
            document.getElementById('cfbe_search_title').value = '';
            const rows = document.querySelectorAll('.cfbe-table tbody tr');
            
            rows.forEach(row => {
                row.classList.remove('cfbe-row-hidden');
                const titleCell = row.querySelector('.cfbe-col-title a');
                if (titleCell) {
                    titleCell.classList.remove('cfbe-search-highlight');
                }
            });
            
            updateSearchResultsInfo(rows.length, rows.length);
        }
        
        function updateSearchResultsInfo(visible, total) {
            let infoElement = document.querySelector('.cfbe-search-results');
            if (!infoElement) {
                infoElement = document.createElement('span');
                infoElement.className = 'cfbe-search-results';
                infoElement.style.marginLeft = '10px';
                infoElement.style.color = '#646970';
                infoElement.style.fontSize = '13px';
                document.getElementById('cfbe_search_title').parentNode.appendChild(infoElement);
            }
            
            if (visible === total) {
                infoElement.textContent = '';
            } else {
                infoElement.textContent = `(${visible}/${total}件表示 - 現在のページのみ)`;
            }
        }
        
        // エンターキーで検索実行
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('cfbe_search_title');
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
            const allInputs = document.querySelectorAll('.cfbe-table input[type="text"], .cfbe-table textarea');
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
            
            document.querySelectorAll('.cfbe-clear-field-btn').forEach(btn => {
                const fieldKey = btn.dataset.field;
                const fieldState = state.fieldStates[fieldKey];
                
                if (!fieldState) return;
                
                const allEmpty = fieldState.empty === fieldState.total;
                const hasRestorationData = fieldState.hasData;
                
                if (allEmpty && hasRestorationData) {
                    btn.textContent = '復元';
                    btn.classList.add('cfbe-cleared');
                    btn.title = 'この項目の値を復元';
                } else {
                    btn.textContent = '削除';
                    btn.classList.remove('cfbe-cleared');
                    btn.title = 'この項目の全ての値を削除';
                }
            });
        }
        
        // 行ボタン機能はチェックボックス方式に変更されました
        
        // 一括ボタン状態更新（統合状態使用）
        function updateBulkButtonState() {
            const clearAllBtn = document.querySelector('.cfbe-clear-all-btn');
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
                const btn = document.querySelector(`.cfbe-clear-field-btn[data-field="${fieldKey}"]`);
                if (btn && state.fieldStates[fieldKey]) {
                    const fieldState = state.fieldStates[fieldKey];
                    const allEmpty = fieldState.empty === fieldState.total;
                    const hasRestorationData = fieldState.hasData;
                    
                    if (allEmpty && hasRestorationData) {
                        btn.textContent = '復元';
                        btn.classList.add('cfbe-cleared');
                        btn.title = 'この項目の値を復元';
                    } else {
                        btn.textContent = '削除';
                        btn.classList.remove('cfbe-cleared');
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
                        button.classList.add('cfbe-cleared');
                        button.title = 'この行のフィールドを復元';
                    } else {
                        button.innerHTML = '🗑️ 行削除';
                        button.classList.remove('cfbe-cleared');
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
            modal.id = 'cfbe-progress-modal';
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
                    <div id="cfbe-progress-bar" style="width: 0%; height: 20px; background: #2271b1; transition: width 0.3s ease;"></div>
                </div>
                <div id="cfbe-progress-text">開始しています...</div>
                <p style="color: #666; font-size: 13px; margin-top: 15px;">大量のデータを処理しています。しばらくお待ちください。</p>
            `;
            
            modal.appendChild(content);
            document.body.appendChild(modal);
            return modal;
        }
        
        // 進捗更新関数
        function updateProgress(percent, text) {
            const progressBar = document.getElementById('cfbe-progress-bar');
            const progressText = document.getElementById('cfbe-progress-text');
            if (progressBar) progressBar.style.width = percent + '%';
            if (progressText) progressText.textContent = text;
        }
        
        // 進捗モーダルを閉じる
        function closeProgressModal() {
            const modal = document.getElementById('cfbe-progress-modal');
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
        document.querySelectorAll('.cfbe-clear-field-btn').forEach(button => {
            button.addEventListener('click', async function() {
                const fieldKey = this.dataset.field;
                const fieldInputs = document.querySelectorAll(`td[data-field="${fieldKey}"] input[type="text"], td[data-field="${fieldKey}"] textarea`);
                
                console.log('フィールド削除/復元:', fieldKey, 'フィールド数:', fieldInputs.length);
                
                // 現在の状態を確認（削除済みかどうか）
                const isCleared = this.classList.contains('cfbe-cleared');
                
                if (isCleared) {
                    // 復元処理
                    let hasDataToRestore = false;
                    
                    // 一括削除状態の場合、まず allFieldsSaved から復元を試行
                    if (allFieldsCleared && allFieldsSaved) {
                        const allInputs = document.querySelectorAll('.cfbe-table input[type="text"], .cfbe-table textarea');
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
                                        const rowInputs = Array.from(targetRow.querySelectorAll('.cfbe-field-cell input[type="text"], .cfbe-field-cell textarea'));
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
                        const allInputs = document.querySelectorAll('.cfbe-table input[type="text"], .cfbe-table textarea');
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
                    this.classList.remove('cfbe-cleared');
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
                    this.classList.add('cfbe-cleared');
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
            const selectAllCheckbox = document.getElementById('cfbe-select-all-rows');
            const rowCheckboxes = document.querySelectorAll('.cfbe-row-checkbox');
            const bulkActions = document.getElementById('cfbe-bulk-row-actions');
            const selectedCount = document.getElementById('cfbe-selected-count');
            
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
                    row.classList.add('cfbe-row-selected');
                } else {
                    row.classList.remove('cfbe-row-selected');
                }
            }
            
            // 全選択チェックボックスの状態更新
            function updateSelectAllState() {
                if (selectAllCheckbox) {
                    const checkedBoxes = document.querySelectorAll('.cfbe-row-checkbox:checked');
                    selectAllCheckbox.checked = checkedBoxes.length === rowCheckboxes.length && rowCheckboxes.length > 0;
                    selectAllCheckbox.indeterminate = checkedBoxes.length > 0 && checkedBoxes.length < rowCheckboxes.length;
                }
            }
            
            // 一括操作ボタンの表示/非表示
            function updateBulkActionsVisibility() {
                const checkedBoxes = document.querySelectorAll('.cfbe-row-checkbox:checked');
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
            const checkedBoxes = document.querySelectorAll('.cfbe-row-checkbox:checked');
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
                const rowFieldInputs = row.querySelectorAll('.cfbe-field-cell input[type="text"], .cfbe-field-cell textarea');
                
                console.log(`行削除処理開始: 投稿ID ${postId}`);
                
                // 行削除データを保存
                if (!savedRowValues[postId]) {
                    savedRowValues[postId] = [];
                    rowFieldInputs.forEach((input, index) => {
                        savedRowValues[postId][index] = input.value;
                    });
                }
                
                // フィールドを空にする
                rowFieldInputs.forEach(input => {
                    input.value = '';
                });
                
                // 行の見た目を削除状態に変更
                row.classList.add('cfbe-row-deleted');
            }
            
            // チェックボックスの選択を解除
            checkedBoxes.forEach(checkbox => {
                checkbox.checked = false;
                toggleRowHighlight(checkbox);
            });
            
            // 統合状態管理で全体更新
            updateAllButtonStates('選択行削除');
            
            // 一括操作ボタンを非表示
            document.getElementById('cfbe-bulk-row-actions').classList.remove('show');
        };
        
        // 選択された行を復元する関数
        window.cfbeRestoreSelectedRows = async function() {
            const checkedBoxes = document.querySelectorAll('.cfbe-row-checkbox:checked');
            if (checkedBoxes.length === 0) {
                alert('復元する行を選択してください。');
                return;
            }
            
            for (const checkbox of checkedBoxes) {
                const postId = checkbox.dataset.postId;
                const row = checkbox.closest('tr');
                const rowFieldInputs = row.querySelectorAll('.cfbe-field-cell input[type="text"], .cfbe-field-cell textarea');
                
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
                    const allInputs = document.querySelectorAll('.cfbe-table input[type="text"], .cfbe-table textarea');
                    rowFieldInputs.forEach(input => {
                        const globalIndex = Array.from(allInputs).indexOf(input);
                        if (globalIndex !== -1 && allFieldsSaved[globalIndex] !== undefined) {
                            input.value = allFieldsSaved[globalIndex];
                        }
                    });
                }
                
                // 行の見た目を通常状態に戻す
                row.classList.remove('cfbe-row-deleted');
            }
            
            // チェックボックスの選択を解除
            checkedBoxes.forEach(checkbox => {
                checkbox.checked = false;
                toggleRowHighlight(checkbox);
            });
            
            // 統合状態管理で全体更新
            updateAllButtonStates('選択行復元');
            
            // 一括操作ボタンを非表示
            document.getElementById('cfbe-bulk-row-actions').classList.remove('show');
        };
        
        // ヘルパー関数
        function toggleRowHighlight(checkbox) {
            const row = checkbox.closest('tr');
            if (checkbox.checked) {
                row.classList.add('cfbe-row-selected');
            } else {
                row.classList.remove('cfbe-row-selected');
            }
        }
        
        // チェックボックス機能を初期化
        initRowCheckboxes();
        
        // 全フィールド削除/復元ボタンのイベント
        window.cfbeClearAllFields = async function() {
            const allInputs = document.querySelectorAll('.cfbe-table input[type="text"], .cfbe-table textarea');
            const clearAllBtn = document.querySelector('.cfbe-clear-all-btn');
            
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
                    const fieldButtons = document.querySelectorAll('.cfbe-clear-field-btn');
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
function cfbe_save_custom_fields() {
    if (!isset($_POST['cfbe_field']) || !is_array($_POST['cfbe_field'])) {
        return;
    }

    foreach ($_POST['cfbe_field'] as $post_id => $fields) {
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
function cfbe_delete_individual_field() {
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
function cfbe_bulk_delete_fields() {
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
