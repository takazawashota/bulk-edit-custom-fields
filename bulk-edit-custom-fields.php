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
            overflow: auto;
            max-height: 100vh;
            background: #fff;
            border: 1px solid #c3c4c7;
            margin: 20px 0;
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
        
        .cfbe-col-actions {
            left: 300px;
            width: 120px;
            min-width: 120px;
            text-align: center;
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
            padding: 5px 12px;
            background: #f0f0f1;
            border-radius: 3px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .cfbe-status-publish {
            background: #00a32a;
            color: #fff;
        }
        
        .cfbe-status-draft {
            background: #dba617;
            color: #fff;
        }
        
        .cfbe-post-type {
            display: inline-block;
            padding: 2px 6px;
            background: #e0e0e0;
            border-radius: 3px;
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
            padding: 2px 6px;
            font-size: 10px;
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
        
        /* 行削除ボタンのスタイル */
        .cfbe-clear-row-btn {
            padding: 4px 8px !important;
            transition: all 0.3s ease;
        }
        
        .cfbe-clear-row-btn:hover {
        }
        
        .cfbe-clear-row-btn.cfbe-cleared {
            color: #fff !important;
            background-color: #00a32a !important;
            border-color: #00a32a !important;
        }
        
        .cfbe-clear-row-btn.cfbe-cleared:hover {
            background-color: #008a20 !important;
            border-color: #008a20 !important;
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
                            <th class="cfbe-col-fixed cfbe-col-actions">行操作</th>
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
                                <td class="cfbe-col-fixed cfbe-col-actions">
                                    <button type="button" class="cfbe-clear-row-btn button button-small" 
                                            data-post-id="<?php echo esc_attr($post->ID); ?>"
                                            title="この行の全フィールドを削除">
                                        行削除
                                    </button>
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
                    <?php submit_button('変更を保存', 'primary large', 'cfbe_submit', false); ?>
                    <span class="cfbe-help-text">※ 変更後、必ず保存ボタンをクリックしてください</span>
                </div>
            </div>
        </form>
        
        <!-- 一括削除確認モーダル -->
        
        <!-- 個別削除用の隠しフォーム -->
        
        <?php endif; ?>
    </div>

    <script>
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
        const savedFieldValues = {};
        
        // 全フィールド削除/復元ボタンのイベント用変数
        let allFieldsSaved = {};
        let allFieldsCleared = false;
        
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
                    chunk.forEach((element, index) => processor(element, index));
                    
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
                    
                    // まず savedFieldValues から復元を試行
                    if (savedFieldValues[fieldKey]) {
                        await processFieldsAsync(
                            fieldInputs,
                            (el, index) => {
                                if (savedFieldValues[fieldKey][index] !== undefined) {
                                    el.value = savedFieldValues[fieldKey][index];
                                    hasDataToRestore = true;
                                }
                            },
                            'フィールドを復元中'
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
                }
            });
        });
        
        // 行ごと削除/復元の変数
        const savedRowValues = {};
        
        // 行ごと削除/復元ボタンのイベント
        document.querySelectorAll('.cfbe-clear-row-btn').forEach(button => {
            button.addEventListener('click', async function() {
                const postId = this.getAttribute('data-post-id');
                
                // 現在の行を特定
                const currentRow = this.closest('tr');
                const rowFieldInputs = currentRow.querySelectorAll('.cfbe-field-cell input[type="text"], .cfbe-field-cell textarea');
                
                console.log('行削除/復元:', postId, '行内フィールド数:', rowFieldInputs.length);
                
                // 現在の状態を確認（削除済みかどうか）
                const isCleared = this.classList.contains('cfbe-cleared');
                
                if (isCleared) {
                    // 行復元処理
                    const rowFieldKeys = new Set();
                    
                    if (savedRowValues[postId]) {
                        await processFieldsAsync(
                            rowFieldInputs,
                            (el, index) => {
                                if (savedRowValues[postId][index] !== undefined) {
                                    el.value = savedRowValues[postId][index];
                                    
                                    // フィールドキーを収集
                                    const fieldKey = el.closest('td').dataset.field;
                                    if (fieldKey) {
                                        rowFieldKeys.add(fieldKey);
                                    }
                                }
                            },
                            `投稿ID ${postId} の行を復元中`
                        );
                    }
                    
                    // 関連する個別フィールドボタンの状態を確認・更新
                    rowFieldKeys.forEach(fieldKey => {
                        const allFieldInputs = document.querySelectorAll(`td[data-field="${fieldKey}"] input[type="text"], td[data-field="${fieldKey}"] textarea`);
                        
                        // このフィールドの削除済み状態をチェック
                        // savedFieldValues に保存されたデータと現在の表示値を比較
                        let allFieldsRestored = true;
                        
                        if (savedFieldValues[fieldKey]) {
                            allFieldInputs.forEach((input, index) => {
                                const savedValue = savedFieldValues[fieldKey][index];
                                const currentValue = input.value;
                                
                                // 保存された値と現在の値が異なる場合、まだ復元されていない
                                if (savedValue !== undefined && savedValue !== currentValue) {
                                    allFieldsRestored = false;
                                }
                            });
                        }
                        
                        const fieldBtn = document.querySelector(`.cfbe-clear-field-btn[data-field="${fieldKey}"]`);
                        if (fieldBtn && allFieldsRestored) {
                            // 全てのフィールドが復元されている場合、ボタンを初期状態に戻す
                            fieldBtn.textContent = '削除';
                            fieldBtn.classList.remove('cfbe-cleared');
                            fieldBtn.title = 'この項目の全ての値を削除';
                            
                            // savedFieldValuesからも削除
                            delete savedFieldValues[fieldKey];
                            
                            console.log(`フィールド ${fieldKey} のボタンを「削除」状態に戻しました`);
                        } else if (fieldBtn) {
                            console.log(`フィールド ${fieldKey} はまだ完全に復元されていません`);
                        }
                    });
                    
                    this.innerHTML = '行削除';
                    this.classList.remove('cfbe-cleared');
                    this.title = 'この行の全フィールドを削除';
                    
                    // 追加の状態チェック: 全てのフィールドボタンの状態を再評価
                    setTimeout(() => {
                        document.querySelectorAll('.cfbe-clear-field-btn').forEach(btn => {
                            const fieldKey = btn.dataset.field;
                            if (fieldKey && savedFieldValues[fieldKey]) {
                                const allFieldInputs = document.querySelectorAll(`td[data-field="${fieldKey}"] input[type="text"], td[data-field="${fieldKey}"] textarea`);
                                let hasAnyEmptyField = false;
                                
                                // 現在の値が空のフィールドがあるかチェック
                                allFieldInputs.forEach(input => {
                                    if (input.value === '') {
                                        hasAnyEmptyField = true;
                                    }
                                });
                                
                                // 空のフィールドがない場合、完全に復元されている
                                if (!hasAnyEmptyField) {
                                    btn.textContent = '削除';
                                    btn.classList.remove('cfbe-cleared');
                                    btn.title = 'この項目の全ての値を削除';
                                    delete savedFieldValues[fieldKey];
                                    console.log(`フィールド ${fieldKey} を完全復元状態に更新しました`);
                                }
                            }
                        });
                    }, 100);
                    
                    // 行復元時は、savedRowValuesから削除
                    delete savedRowValues[postId];
                } else {
                    // 行削除処理（値を保存してから削除）
                    savedRowValues[postId] = [];
                    
                    // 行内のフィールドキーも同時に収集
                    const rowFieldKeys = {};
                    rowFieldInputs.forEach(el => {
                        const fieldKey = el.closest('td').dataset.field;
                        if (fieldKey) {
                            if (!rowFieldKeys[fieldKey]) {
                                rowFieldKeys[fieldKey] = [];
                            }
                            rowFieldKeys[fieldKey].push(el);
                        }
                    });
                    
                    await processFieldsAsync(
                        rowFieldInputs,
                        (el, index) => {
                            const fieldKey = el.closest('td').dataset.field;
                            savedRowValues[postId][index] = el.value;
                            
                            // 個別フィールド用のデータも保存（後で個別復元に使用）
                            if (fieldKey) {
                                if (!savedFieldValues[fieldKey]) {
                                    savedFieldValues[fieldKey] = [];
                                }
                                const allFieldInputs = document.querySelectorAll(`td[data-field="${fieldKey}"] input[type="text"], td[data-field="${fieldKey}"] textarea`);
                                const fieldIndex = Array.from(allFieldInputs).indexOf(el);
                                if (fieldIndex !== -1) {
                                    savedFieldValues[fieldKey][fieldIndex] = el.value;
                                }
                            }
                            
                            el.value = '';
                        },
                        `投稿ID ${postId} の行を削除中`
                    );
                    
                    // 関連する個別フィールドボタンの状態を更新
                    Object.keys(rowFieldKeys).forEach(fieldKey => {
                        const fieldBtn = document.querySelector(`.cfbe-clear-field-btn[data-field="${fieldKey}"]`);
                        if (fieldBtn) {
                            fieldBtn.textContent = '復元';
                            fieldBtn.classList.add('cfbe-cleared');
                            fieldBtn.title = 'この項目の値を復元';
                        }
                    });
                    
                    this.innerHTML = '行復元';
                    this.classList.add('cfbe-cleared');
                    this.title = 'この行のフィールドを復元';
                }
            });
        });
        
        // 全フィールド削除/復元ボタンのイベント
        window.cfbeClearAllFields = async function() {
            const allInputs = document.querySelectorAll('.cfbe-table input[type="text"], .cfbe-table textarea');
            const clearAllBtn = document.querySelector('.cfbe-clear-all-btn');
            
            // ボタンを無効化して重複実行を防ぐ
            clearAllBtn.disabled = true;
            const originalText = clearAllBtn.innerHTML;
            clearAllBtn.innerHTML = '処理中...';
            
            try {
                if (allFieldsCleared) {
                    // 全復元
                    await processFieldsAsync(
                        allInputs,
                        (el, index) => {
                            if (allFieldsSaved[index] !== undefined) {
                                el.value = allFieldsSaved[index];
                            }
                        },
                        '全フィールドを復元中'
                    );
                    
                    clearAllBtn.innerHTML = '全てのフィールドを削除';
                    clearAllBtn.nextElementSibling.textContent = '※ 表示中の全ての入力値が削除されます';
                    allFieldsCleared = false;
                    
                    // 全ての個別ボタンを初期状態に戻す
                    document.querySelectorAll('.cfbe-clear-field-btn').forEach(btn => {
                        btn.textContent = '削除';
                        btn.classList.remove('cfbe-cleared');
                        btn.title = 'この項目の全ての値を削除';
                    });
                    
                    // 個別削除の履歴もクリア
                    Object.keys(savedFieldValues).forEach(key => {
                        delete savedFieldValues[key];
                    });
                    
                    // allFieldsSavedをクリア
                    allFieldsSaved = {};
                } else {
                    // 全削除（現在の表示値 + 個別削除済みの値を保存してから削除）
                    allFieldsSaved = {};
                    
                    // 各フィールドごとのデータも保存（個別復元用）
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
                        }
                    }
                    
                    await processFieldsAsync(
                        allInputs,
                        (el, index) => {
                            // 現在表示されている値を保存
                            let valueToSave = el.value;
                            
                            // もしこの要素が個別削除されたフィールドに属している場合、
                            // savedFieldValuesから元の値を取得
                            const fieldKey = el.closest('td').dataset.field;
                            if (fieldKey && savedFieldValues[fieldKey]) {
                                // この要素のインデックスを取得
                                const fieldInputs = document.querySelectorAll(`td[data-field="${fieldKey}"] input[type="text"], td[data-field="${fieldKey}"] textarea`);
                                const elementIndex = Array.from(fieldInputs).indexOf(el);
                                if (elementIndex !== -1 && savedFieldValues[fieldKey][elementIndex] !== undefined) {
                                    valueToSave = savedFieldValues[fieldKey][elementIndex];
                                }
                            }
                            
                            allFieldsSaved[index] = valueToSave;
                            el.value = '';
                        },
                        '全フィールドを削除中'
                    );
                    
                    clearAllBtn.innerHTML = '全てのフィールドを復元';
                    clearAllBtn.nextElementSibling.textContent = '※ 削除前の値に戻します';
                    allFieldsCleared = true;
                    
                    // 個別ボタンも削除状態に変更
                    document.querySelectorAll('.cfbe-clear-field-btn').forEach(btn => {
                        btn.textContent = '復元';
                        btn.classList.add('cfbe-cleared');
                        btn.title = 'この項目の値を復元';
                    });
                }
            } catch (error) {
                console.error('処理中にエラーが発生しました:', error);
                alert('処理中にエラーが発生しました。ページを再読み込みしてください。');
                clearAllBtn.innerHTML = originalText;
            } finally {
                // ボタンを再有効化
                clearAllBtn.disabled = false;
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
