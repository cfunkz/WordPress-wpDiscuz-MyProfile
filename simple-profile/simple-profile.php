<?php
/**
 * Plugin Name: Profile Insights Pro
 * Description: User dashboard with Karma, AJAX pagination, wpDiscuz Subscriptions, Author Stats, and Profile Editing.
 * Version:     4.5
 * Author:      cFunkz
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DevProfileInsightsPro {

    private $per_page           = 6;
    private $pw_max_attempts     = 3;    // max password changes per window
    private $pw_window_seconds   = 3600; // 1 hour
    private $stamping_vote       = false; // re-entrancy guard for stamp_vote_time

    public function __construct() {
        add_shortcode( 'user_insights',             [ $this, 'render_shortcode' ] );
        add_action(    'wp_enqueue_scripts',          [ $this, 'enqueue_assets' ] );
        add_action(    'wp_ajax_dpi_load_more',       [ $this, 'ajax_load_more' ] );
        add_action(    'wp_ajax_dpi_update_profile',  [ $this, 'ajax_update_profile' ] );

        // Stamp exact vote time so it can be sorted correctly in the feed.
        add_action( 'updated_comment_meta', [ $this, 'stamp_vote_time' ], 10, 4 );
        add_action( 'added_comment_meta',   [ $this, 'stamp_vote_time' ], 10, 4 );

        // Enforce Author Notification Settings (WordPress Core Hook)
        add_filter( 'notify_post_author', [ $this, 'enforce_comment_notifications' ], 10, 2 );
    }

    // ---------------------------------------------------------------
    // Author Setting Hooks
    // ---------------------------------------------------------------

    public function enforce_comment_notifications( $maybe_notify, $comment_id ) {
        $comment = get_comment( $comment_id );
        if ( ! $comment ) return $maybe_notify;
        
        $post = get_post( $comment->comment_post_ID );
        if ( ! $post ) return $maybe_notify;

        $notify_setting = get_user_meta( $post->post_author, 'dpi_notify_new_comments', true );
        
        if ( $notify_setting === '0' ) {
            return false;
        }
        return $maybe_notify;
    }

    public function stamp_vote_time( $meta_id, $comment_id, $meta_key, $meta_value ) {
        if ( $meta_key !== 'wpdiscuz_votes' ) return;
        if ( $this->stamping_vote ) return;

        $this->stamping_vote = true;
        update_comment_meta( (int) $comment_id, 'dpi_vote_time', current_time( 'mysql', true ) );
        $this->stamping_vote = false;
    }

    private function get_pw_attempts( $user_id ) {
        return (int) get_transient( 'dpi_pw_attempts_' . $user_id );
    }

    private function increment_pw_attempts( $user_id ) {
        $key      = 'dpi_pw_attempts_' . $user_id;
        $attempts = $this->get_pw_attempts( $user_id ) + 1;
        set_transient( $key, $attempts, $this->pw_window_seconds );
        return $attempts;
    }

    // ---------------------------------------------------------------
    // Data Fetching
    // ---------------------------------------------------------------

    private function get_total_karma( $user_id ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE( SUM( CAST( m.meta_value AS SIGNED ) ), 0 )
             FROM   {$wpdb->commentmeta} m
             INNER JOIN {$wpdb->comments} c ON m.comment_id = c.comment_ID
             WHERE  c.user_id  = %d
               AND  m.meta_key = 'wpdiscuz_votes'",
            $user_id
        ) );
    }

    private function get_author_word_count( $user_id ) {
        global $wpdb;
        $posts = $wpdb->get_col( $wpdb->prepare( 
            "SELECT post_content FROM {$wpdb->posts} WHERE post_author = %d AND post_status = 'publish' AND post_type = 'post'", 
            $user_id 
        ) );
        $count = 0;
        foreach ( $posts as $content ) {
            $count += str_word_count( strip_tags( $content ) );
        }
        return $count;
    }

    private function get_activity( $user_id, $page = 0 ) {
        global $wpdb;
        $items = [];

        $comments = $wpdb->get_results( $wpdb->prepare(
            "SELECT c.comment_ID, c.comment_post_ID, c.comment_content, c.comment_date_gmt,
                    CAST( COALESCE( mv.meta_value, '0' ) AS SIGNED ) AS vote_count,
                    vt.meta_value AS vote_time
             FROM   {$wpdb->comments} c
             LEFT JOIN {$wpdb->commentmeta} mv ON mv.comment_id = c.comment_ID AND mv.meta_key = 'wpdiscuz_votes'
             LEFT JOIN {$wpdb->commentmeta} vt ON vt.comment_id = c.comment_ID AND vt.meta_key = 'dpi_vote_time'
             WHERE  c.user_id          = %d
               AND  c.comment_approved = '1'
             ORDER  BY c.comment_date_gmt DESC LIMIT 100",
            $user_id
        ) );

        foreach ( $comments as $c ) {
            $items[] = [ 'type' => 'comment', 'sort_time' => $c->comment_date_gmt, 'data' => $c ];
            if ( (int) $c->vote_count !== 0 && ! empty( $c->vote_time ) ) {
                $items[] = [ 'type' => 'vote', 'sort_time' => $c->vote_time, 'data' => $c ];
            }
        }

        $own_ids = $wpdb->get_col( $wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE user_id = %d LIMIT 500", $user_id ) );
        if ( $own_ids ) {
            $ph = implode( ',', array_map( 'intval', $own_ids ) );
            $replies = $wpdb->get_results( $wpdb->prepare(
                "SELECT comment_ID, comment_post_ID, comment_content, comment_date_gmt
                 FROM   {$wpdb->comments}
                 WHERE  comment_parent IN ( $ph ) AND user_id != %d AND comment_approved = '1'
                 ORDER  BY comment_date_gmt DESC LIMIT 50",
                $user_id
            ) );

            foreach ( $replies as $r ) {
                $items[] = [ 'type' => 'reply', 'sort_time' => $r->comment_date_gmt, 'data' => $r ];
            }
        }

        $author_comments = $wpdb->get_results( $wpdb->prepare(
            "SELECT c.comment_ID, c.comment_post_ID, c.comment_content, c.comment_date_gmt
             FROM   {$wpdb->comments} c
             INNER JOIN {$wpdb->posts} p ON c.comment_post_ID = p.ID
             WHERE  p.post_author = %d
               AND  c.user_id != %d
               AND  c.comment_approved = '1'
             ORDER  BY c.comment_date_gmt DESC LIMIT 50",
            $user_id, $user_id
        ) );

        foreach ( $author_comments as $ac ) {
            $items[] = [ 'type' => 'author_comment', 'sort_time' => $ac->comment_date_gmt, 'data' => $ac ];
        }

        usort( $items, fn( $a, $b ) => strcmp( $b['sort_time'], $a['sort_time'] ) );
        
        $offset = max( 0, (int) $page ) * $this->per_page;
        return array_slice( $items, $offset, $this->per_page );
    }

    private function get_wpdiscuz_subscriptions( $user_email, $page = 0 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_comments_subscription';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) return [];
        $offset = max( 0, (int) $page ) * $this->per_page;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE email = %s ORDER BY id DESC LIMIT %d OFFSET %d",
            $user_email, $this->per_page, $offset
        ) ) ?: [];
    }

    private function get_rated_posts( $user_id, $page = 0 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_users_rated';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) return [];
        $offset = max( 0, (int) $page ) * $this->per_page;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT post_id FROM `{$table}` WHERE user_id = %d ORDER BY id DESC LIMIT %d OFFSET %d",
            $user_id, $this->per_page, $offset
        ) ) ?: [];
    }

    // ---------------------------------------------------------------
    // Assets & Shortcode Rendering
    // ---------------------------------------------------------------

    public function enqueue_assets() {
        if ( ! is_user_logged_in() ) return;
        wp_register_style( 'dpi-styles', false );
        wp_enqueue_style( 'dpi-styles' );
        wp_add_inline_style( 'dpi-styles', $this->get_css() );
        wp_enqueue_script( 'dpi-swal', 'https://cdn.jsdelivr.net/npm/sweetalert2@11', [], '11', true );
        add_action( 'wp_footer', [ $this, 'render_js' ] );
    }

    public function render_shortcode() {
        if ( ! is_user_logged_in() )
            return '<p class="dpi-notice">🔒 Please log in to view your profile.</p>';

        $user          = wp_get_current_user();
        $comment_count = (int) get_comments( [ 'user_id' => $user->ID, 'count' => true, 'status' => 'approve' ] );
        $karma         = $this->get_total_karma( $user->ID );
        $activities    = $this->get_activity( $user->ID, 0 );
        $subscriptions = $this->get_wpdiscuz_subscriptions( $user->user_email, 0 );
        $rated         = $this->get_rated_posts( $user->ID, 0 );
        
        $is_author     = current_user_can( 'edit_posts' );

        ob_start(); ?>
        <div class="dpi-wrap" id="dpi-profile" data-nonce="<?php echo esc_attr( wp_create_nonce( 'dpi_nonce' ) ); ?>">

            <div class="dpi-header">
                <?php echo get_avatar( $user->ID, 72 ); ?>
                <div>
                    <h2><?php echo esc_html( $user->display_name ); ?></h2>
                    <p><?php echo esc_html( $user->user_email ); ?></p>
                </div>
            </div>

            <div class="dpi-stats">
                <div class="dpi-stat"><strong><?php echo esc_html( $comment_count ); ?></strong><span>Comments</span></div>
                <div class="dpi-stat"><strong><?php echo esc_html( $karma ); ?></strong><span>Karma</span></div>
                <div class="dpi-stat"><strong><?php echo count($subscriptions); ?></strong><span>Subscriptions</span></div>
            </div>

            <div class="dpi-tabs">
                <button class="dpi-tab-btn active" data-target="dpi-tab-activity">Activity Feed</button>
                <button class="dpi-tab-btn" data-target="dpi-tab-subs">Subscriptions</button>
                <button class="dpi-tab-btn" data-target="dpi-tab-ratings">Ratings</button>
                <button class="dpi-tab-btn" data-target="dpi-tab-settings">Settings</button>
                <?php if ( $is_author ) : ?>
                    <button class="dpi-tab-btn" data-target="dpi-tab-author-settings">Statistics</button>
                <?php endif; ?>
            </div>

            <div class="dpi-body">
                
                <div id="dpi-tab-activity" class="dpi-tab-content active">
                    <h4 class="dpi-sec-label">Recent Activity</h4>
                    <div id="dpi-comments-list">
                        <?php if ( $activities ) {
                            foreach ( $activities as $a ) $this->render_item( $a );
                        } else echo '<p class="dpi-empty">No activity yet.</p>'; ?>
                    </div>
                    <?php if ( count( $activities ) >= $this->per_page ) : ?>
                        <button class="dpi-more" data-type="comments" data-page="0">Show More</button>
                    <?php endif; ?>
                </div>

                <div id="dpi-tab-subs" class="dpi-tab-content">
                    <h4 class="dpi-sec-label">Active Subscriptions</h4>
                    <div id="dpi-subs-list">
                        <?php if ( $subscriptions ) {
                            foreach ( $subscriptions as $s ) $this->render_subscription( $s );
                        } else echo '<p class="dpi-empty">No subscriptions active.</p>'; ?>
                    </div>
                    <?php if ( count( $subscriptions ) >= $this->per_page ) : ?>
                        <button class="dpi-more" data-type="subs" data-page="0">Load More</button>
                    <?php endif; ?>
                </div>

                <div id="dpi-tab-ratings" class="dpi-tab-content">
                    <h4 class="dpi-sec-label">My Ratings</h4>
                    <div id="dpi-rated-list">
                        <?php if ( $rated ) {
                            foreach ( $rated as $r ) $this->render_rated( $r );
                        } else echo '<p class="dpi-empty">No ratings yet.</p>'; ?>
                    </div>
                    <?php if ( count( $rated ) >= $this->per_page ) : ?>
                        <button class="dpi-more" data-type="rated" data-page="0">Load More</button>
                    <?php endif; ?>
                </div>

                <div id="dpi-tab-settings" class="dpi-tab-content">
                    <form id="dpi-profile-form">
                        <h4 class="dpi-sec-label">General Profile Info</h4>
                        <div class="dpi-form-grid">
                            <input type="text" name="first_name" placeholder="First Name" value="<?php echo esc_attr($user->first_name); ?>">
                            <input type="text" name="last_name" placeholder="Last Name" value="<?php echo esc_attr($user->last_name); ?>">
                            <input type="text" name="display_name" placeholder="Display Name (Required)" value="<?php echo esc_attr($user->display_name); ?>" required>
                            <input type="email" name="user_email" placeholder="Email Address (Required)" value="<?php echo esc_attr($user->user_email); ?>" required>
                            <input type="url" name="user_url" placeholder="Website URL" value="<?php echo esc_attr($user->user_url); ?>" style="grid-column: 1 / -1;">
                            <textarea name="description" placeholder="Biographical Info..." rows="3" style="grid-column: 1 / -1;"><?php echo esc_textarea($user->description); ?></textarea>
                        </div>
                        
                        <h4 class="dpi-sec-label" style="margin-top: 24px;">Security Settings</h4>
                        <div class="dpi-pw-row">
                            <input type="password" name="new_password" placeholder="New password (min 8 chars, optional)" autocomplete="new-password">
                        </div>
                        <p class="dpi-pw-limit">Max <?php echo (int) $this->pw_max_attempts; ?> password changes per hour.</p>

                        <button type="submit" id="dpi-save-btn" class="dpi-btn-primary">Save Profile Settings</button>
                    </form>
                </div>

                <?php if ( $is_author ) : 
                    $total_posts = count_user_posts( $user->ID, 'post', true );
                    $word_count  = $this->get_author_word_count( $user->ID );
                    $read_time   = ceil( $word_count / 200 ); 
                    
                    $notify_comments = get_user_meta( $user->ID, 'dpi_notify_new_comments', true ) !== '0';
                ?>
                <div id="dpi-tab-author-settings" class="dpi-tab-content">
                    
                    <div class="dpi-author-stats-box" style="margin-bottom: 24px; padding: 18px; background: rgba(99,102,241,0.08); border-radius: 10px; border: 1px solid rgba(99,102,241,0.2);">
                        <h4 class="dpi-sec-label" style="margin-bottom: 12px;">Your Writing Statistics</h4>
                        <div class="dpi-stats" style="border-bottom: none;">
                            <div class="dpi-stat"><strong><?php echo esc_html( $total_posts ); ?></strong><span>Total Posts</span></div>
                            <div class="dpi-stat"><strong><?php echo esc_html( number_format($word_count) ); ?></strong><span>Words Written</span></div>
                            <div class="dpi-stat"><strong><?php echo esc_html( $read_time ); ?>m</strong><span>Read Time</span></div>
                        </div>
                        <div style="margin-top: 15px; text-align: center;">
                            <a href="<?php echo esc_url( admin_url('post-new.php') ); ?>" style="display: inline-block; width: auto; text-decoration: none; padding: 10px 24px;">➕ Add New Post</a>
                        </div>
                    </div>

                    <form id="dpi-author-settings-form">
                        <input type="hidden" name="dpi_author_settings_submitted" value="1">
                        
                        <h4 class="dpi-sec-label">Author Preferences</h4>
                        <div class="dpi-checkbox-row">
                            <label>
                                <input type="checkbox" name="dpi_notify_new_comments" value="1" <?php checked($notify_comments); ?>>
                                Email me when someone comments on my posts
                            </label>
                        </div>

                        <button type="submit" id="dpi-save-author-btn" class="dpi-btn-primary">Save Author Settings</button>
                    </form>
                </div>
                <?php endif; ?>

            </div>
        </div>
        <?php return ob_get_clean();
    }

    private function render_item( $act ) {
        $type    = $act['type'];
        $data    = $act['data'];
        $title   = esc_html( get_the_title( (int) $data->comment_post_ID ) );
        $link    = esc_url( get_comment_link( (int) $data->comment_ID ) );
        $snippet = esc_html( wp_trim_words( $data->comment_content, 10, '…' ) );

        if ( $type === 'vote' ) {
            $score = (int) $data->vote_count;
            $up    = $score > 0;
            printf(
                '<a href="%s" class="dpi-item %s"><span class="dpi-badge">%s</span><strong>%s</strong><small>Score: %d</small></a>',
                $link, $up ? 'dpi-like' : 'dpi-dislike', $up ? '👍 Upvoted' : '👎 Downvoted', $title, $score
            );
        } elseif ( $type === 'reply' ) {
            printf( '<a href="%s" class="dpi-item dpi-reply"><span class="dpi-badge">💬 Reply received</span><strong>%s</strong><small>"%s"</small></a>', $link, $title, $snippet );
        } elseif ( $type === 'author_comment' ) {
            printf( '<a href="%s" class="dpi-item dpi-author-comment"><span class="dpi-badge" style="color:#a855f7;">📢 Comment on your post</span><strong>%s</strong><small>"%s"</small></a>', $link, $title, $snippet );
        } else {
            printf( '<a href="%s" class="dpi-item"><span class="dpi-badge">📝 Comment</span><strong>%s</strong><small>"%s"</small></a>', $link, $title, $snippet );
        }
    }

    private function render_subscription( $s ) {
        $title = esc_html( get_the_title( (int) $s->post_id ) );
        $link  = esc_url( get_permalink( (int) $s->post_id ) );
        $type  = esc_html( ucwords( str_replace( '_', ' ', $s->type ?? 'post' ) ) );
        printf( '<a href="%s" class="dpi-item dpi-reply"><span class="dpi-badge">🔔 %s Subscription</span><strong>%s</strong></a>', $link, $type, $title );
    }

    private function render_rated( $r ) {
        printf( '<a href="%s" class="dpi-item">⭐ %s</a>', esc_url( get_permalink( (int) $r->post_id ) ), esc_html( get_the_title( (int) $r->post_id ) ) );
    }

    public function ajax_load_more() {
        check_ajax_referer( 'dpi_nonce', 'nonce' );
        $user_id = get_current_user_id();
        $user    = wp_get_current_user();
        if ( ! $user_id ) wp_send_json_error( 'Not logged in.' );

        $type = sanitize_key( wp_unslash( $_POST['type'] ?? '' ) );
        $page = max( 1, (int) ( $_POST['page'] ?? 1 ) );

        if ( ! in_array( $type, [ 'comments', 'rated', 'subs' ], true ) ) {
            wp_send_json_error( 'Invalid type.' );
        }

        ob_start();
        if ( $type === 'comments' ) {
            $items = $this->get_activity( $user_id, $page );
            foreach ( $items as $a ) $this->render_item( $a );
        } elseif ( $type === 'subs' ) {
            $items = $this->get_wpdiscuz_subscriptions( $user->user_email, $page );
            foreach ( $items as $s ) $this->render_subscription( $s );
        } else {
            $items = $this->get_rated_posts( $user_id, $page );
            foreach ( $items as $r ) $this->render_rated( $r );
        }

        wp_send_json_success( [
            'html'     => ob_get_clean(),
            'has_more' => count( $items ) >= $this->per_page,
        ] );
    }

    public function ajax_update_profile() {
        check_ajax_referer( 'dpi_nonce', 'nonce' );
        $user_id = get_current_user_id();
        if ( ! $user_id ) wp_send_json_error( 'Not logged in.' );

        if ( isset( $_POST['dpi_author_settings_submitted'] ) && current_user_can( 'edit_posts' ) ) {
            $notify_comments = isset( $_POST['dpi_notify_new_comments'] ) ? '1' : '0';
            update_user_meta( $user_id, 'dpi_notify_new_comments', $notify_comments );
            wp_send_json_success( 'Author settings updated successfully!' );
        }

        $user_data = [ 'ID' => $user_id ];
        $fields = [ 'first_name', 'last_name', 'display_name', 'user_email', 'user_url' ];
        $needs_update = false;

        foreach ( $fields as $field ) {
            if ( isset( $_POST[$field] ) ) {
                $val = sanitize_text_field( wp_unslash( $_POST[$field] ) );
                if ( $field === 'user_email' ) {
                    $val = sanitize_email( $val );
                    if ( ! is_email( $val ) ) wp_send_json_error( 'Invalid email address.' );
                    if ( email_exists( $val ) && email_exists( $val ) !== $user_id ) {
                        wp_send_json_error( 'Email address is already in use.' );
                    }
                }
                $user_data[$field] = $val;
                $needs_update = true;
            }
        }

        if ( isset( $_POST['description'] ) ) {
            $user_data['description'] = sanitize_textarea_field( wp_unslash( $_POST['description'] ) );
            $needs_update = true;
        }

        $new_pass = trim( wp_unslash( $_POST['new_password'] ?? '' ) );
        if ( ! empty( $new_pass ) ) {
            if ( $this->get_pw_attempts( $user_id ) >= $this->pw_max_attempts ) {
                wp_send_json_error( 'Too many password attempts. Please try again in an hour.' );
            }
            if ( mb_strlen( $new_pass ) < 8 ) {
                wp_send_json_error( 'Password must be at least 8 characters.' );
            }
            $this->increment_pw_attempts( $user_id );
            $user_data['user_pass'] = $new_pass;
            $needs_update = true;
        }

        if ( $needs_update ) {
            $result = wp_update_user( $user_data );
            if ( is_wp_error( $result ) ) {
                wp_send_json_error( $result->get_error_message() );
            }
        }

        wp_send_json_success( 'Profile updated successfully!' . ( ! empty( $new_pass ) ? ' Logging you out…' : '' ) );
    }

    private function get_css() { return '
        .dpi-wrap { max-width: auto; margin: 20px auto; border: 1px solid rgba(128,128,128,.2); border-radius: 18px; overflow: hidden; font-family: system-ui,sans-serif; color: inherit; background: rgba(128,128,128,.04); }
        .dpi-header { display: flex; align-items: center; gap: 16px; padding: 22px; border-bottom: 1px solid rgba(128,128,128,.15); }
        .dpi-header img { border-radius: 50%; border: 2px solid #6366f1; }
        .dpi-header h2 { margin: 0 0 3px; font-size: 1.05rem; }
        .dpi-header p  { margin: 0; opacity: .55; font-size: .82rem; }
        .dpi-stats { display: grid; grid-template-columns: repeat(3,1fr); border-bottom: 1px solid rgba(128,128,128,.15); }
        .dpi-stat  { padding: 14px; text-align: center; border-right: 1px solid rgba(128,128,128,.15); }
        .dpi-stat:last-child { border-right: none; }
        .dpi-stat strong { display: block; font-size: 1.25rem; color: #6366f1; }
        .dpi-stat span   { font-size: .58rem; text-transform: uppercase; opacity: .55; font-weight: 700; }
        
        .dpi-tabs { display: flex; background: rgba(128,128,128,.05); border-bottom: 1px solid rgba(128,128,128,.15); overflow-x: auto; }
        .dpi-tab-btn { flex: 1; padding: 14px; background: transparent; border: none; font-size: 0.85rem; font-weight: 600; color: inherit; cursor: pointer; opacity: 0.6; transition: all 0.1s; border-bottom: 2px solid transparent; white-space: nowrap; }
        .dpi-tab-btn:hover { opacity: 1; background: rgba(128,128,128,.05); }
        .dpi-tab-btn.active { opacity: 1; border-bottom-color: #6366f1; color: #6366f1; }
        .dpi-tab-content { display: none; }
        .dpi-tab-content.active { display: block; animation: dpiFadeIn 0.3s ease-in-out; }
        @keyframes dpiFadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }

        .dpi-body { padding: 24px; }
        .dpi-sec-label { font-size: .62rem; text-transform: uppercase; letter-spacing: .05em; opacity: .45; font-weight: 700; margin: 0 0 12px; }
        .dpi-item { display: block; padding: 12px 14px; background: rgba(128,128,128,.08); border-left: 4px solid transparent; border-radius: 10px; margin-bottom: 7px; text-decoration: none !important; color: inherit !important; transition: background .15s; }
        .dpi-item:hover { background: rgba(128,128,128,.14); }
        .dpi-item strong { display: block; font-size: .9rem; }
        .dpi-item small  { display: block; opacity: .55; font-size: .78rem; margin-top: 2px; }
        .dpi-badge { font-size: .58rem; font-weight: 800; text-transform: uppercase; opacity: .6; display: block; margin-bottom: 3px; }
        .dpi-reply   { border-left-color: #3b82f6; }
        .dpi-like    { border-left-color: #10b981; }
        .dpi-dislike { border-left-color: #ef4444; }
        .dpi-author-comment { border-left-color: #a855f7; }
        .dpi-empty { opacity: .4; font-size: .83rem; padding: 8px 0; }
        .dpi-more { width: 100%; padding: 9px; background: transparent; border: 1px dashed rgba(128,128,128,.3); border-radius: 9px; cursor: pointer; color: inherit; font-size: .78rem; margin: 2px 0 18px; }
        .dpi-more:hover:not(:disabled) { border-color: #6366f1; color: #6366f1; }
        
        .dpi-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 18px; }
        .dpi-form-grid input, .dpi-form-grid textarea, .dpi-pw-row input { width: 100%; box-sizing: border-box; background: rgba(128,128,128,.09); border: 1px solid rgba(128,128,128,.2); padding: 10px 14px; border-radius: 7px; color: inherit; font-size: .88rem; font-family: inherit; }
        .dpi-form-grid input:focus, .dpi-form-grid textarea:focus, .dpi-pw-row input:focus { outline: none; border-color: #6366f1; }
        .dpi-pw-row { display: flex; gap: 8px; }
        .dpi-btn-primary { background: #6366f1; color: #fff; border: none; padding: 12px 20px; border-radius: 7px; cursor: pointer; font-weight: 700; font-size: .88rem; margin-top: 18px; width: 100%; transition: opacity 0.2s; }
        .dpi-btn-primary:hover { opacity: 0.9; }
        .dpi-btn-primary:disabled { opacity: .5; cursor: default; }
        .dpi-pw-limit { font-size: .72rem; opacity: .4; margin: 6px 0 0; }
        .dpi-checkbox-row { margin-bottom: 15px; font-size: 0.9rem; }
        .dpi-checkbox-row label { display: flex; align-items: center; gap: 8px; cursor: pointer; }
        .dpi-checkbox-row input[type="checkbox"] { width: auto; transform: scale(1.1); cursor: pointer; }
        @media (max-width: 600px) { .dpi-form-grid { grid-template-columns: 1fr; } }
    '; }

    public function render_js() {
        if ( ! is_user_logged_in() ) return; ?>
        <script>
        (function(){
            const wrap = document.getElementById('dpi-profile');
            if(!wrap) return;
            const nonce = wrap.dataset.nonce;
            const ajax  = <?php echo wp_json_encode( admin_url('admin-ajax.php') ); ?>;

            function post(data){
                const fd = new FormData();
                Object.entries(data).forEach(([k,v]) => fd.append(k,v));
                return fetch(ajax, {method:'POST', body:fd}).then(r => r.json());
            }

            const tabBtns = wrap.querySelectorAll('.dpi-tab-btn');
            const tabContents = wrap.querySelectorAll('.dpi-tab-content');

            tabBtns.forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    const target = btn.dataset.target;
                    tabBtns.forEach(b => b.classList.remove('active'));
                    tabContents.forEach(c => c.classList.remove('active'));
                    btn.classList.add('active');
                    document.getElementById(target).classList.add('active');
                });
            });

            wrap.addEventListener('click', function(e){
                const btn = e.target.closest('.dpi-more');
                if(!btn || btn.disabled) return;
                const type = btn.dataset.type;
                const page = parseInt(btn.dataset.page, 10) + 1;
                btn.disabled = true;
                btn.textContent = 'Loading…';
                
                post({ action:'dpi_load_more', nonce, type, page }).then(res => {
                    if(res.success){
                        document.getElementById('dpi-' + (type==='comments'?'comments':(type==='subs'?'subs':'rated')) + '-list').insertAdjacentHTML('beforeend', res.data.html);
                        btn.dataset.page = page;
                        if(!res.data.has_more) btn.remove();
                        else { btn.disabled = false; btn.textContent = 'Load More'; }
                    }
                });
            });

            const forms = ['dpi-profile-form', 'dpi-author-settings-form'];
            forms.forEach(id => {
                const f = document.getElementById(id);
                if(!f) return;
                f.addEventListener('submit', e => {
                    e.preventDefault();
                    const btn = f.querySelector('button[type="submit"]');
                    btn.disabled = true;
                    const fd = new FormData(f);
                    fd.append('action', 'dpi_update_profile');
                    fd.append('nonce', nonce);

                    fetch(ajax, {method:'POST', body:fd}).then(r => r.json()).then(res => {
                        Swal.fire({ 
                            icon: res.success ? 'success' : 'error', 
                            text: res.data,
                            toast: true, position: 'top-end', showConfirmButton: false, timer: 3000
                        });
                        if(res.success && res.data.includes('logout')) setTimeout(() => location.reload(), 2000);
                        btn.disabled = false;
                    });
                });
            });
        })();
        </script>
        <?php
    }
}
new DevProfileInsightsPro();
