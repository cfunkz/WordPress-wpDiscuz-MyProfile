<?php
/**
 * Plugin Name: Profile Insights Pro
 * Description: User dashboard with Karma, AJAX pagination, wpDiscuz Subscriptions, and Full WP Profile Editing.
 * Version:     4.4
 * Author:      cFunkz
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DevProfileInsightsPro {

    private $per_page            = 6;
    private $pw_max_attempts     = 3;    // max password changes per window
    private $pw_window_seconds   = 3600; // 1 hour
    private $stamping_vote       = false; // re-entrancy guard for stamp_vote_time

    public function __construct() {
        add_shortcode( 'user_insights',               [ $this, 'render_shortcode' ] );
        add_action(    'wp_enqueue_scripts',          [ $this, 'enqueue_assets' ] );
        add_action(    'wp_ajax_dpi_load_more',       [ $this, 'ajax_load_more' ] );
        add_action(    'wp_ajax_dpi_update_profile',  [ $this, 'ajax_update_profile' ] );

        // Stamp exact vote time so it can be sorted correctly in the feed.
        add_action( 'updated_comment_meta', [ $this, 'stamp_vote_time' ], 10, 4 );
        add_action( 'added_comment_meta',   [ $this, 'stamp_vote_time' ], 10, 4 );
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
            </div>

            <div class="dpi-body">
                
                <div id="dpi-tab-activity" class="dpi-tab-content active">
                    <h4 class="dpi-sec-label">Recent wpDiscuz Activity</h4>
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
                    <h4 class="dpi-sec-label">My Product Ratings</h4>
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

            </div>
        </div>
        <?php return ob_get_clean();
    }

    // ---------------------------------------------------------------
    // Item Renderers
    // ---------------------------------------------------------------

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

    // ---------------------------------------------------------------
    // AJAX Endpoints
    // ---------------------------------------------------------------

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

        $user_data = [ 'ID' => $user_id ];
        
        // Handle generic profile fields
        $fields = [ 'first_name', 'last_name', 'display_name', 'user_email', 'user_url' ];
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
            }
        }

        if ( isset( $_POST['description'] ) ) {
            $user_data['description'] = sanitize_textarea_field( wp_unslash( $_POST['description'] ) );
        }

        // Handle security (password change)
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
        }

        $result = wp_update_user( $user_data );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( 'Profile updated successfully!' . ( ! empty( $new_pass ) ? ' Logging you out…' : '' ) );
    }

    // ---------------------------------------------------------------
    // CSS & JS
    // ---------------------------------------------------------------

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
        
        /* Tabs */
        .dpi-tabs { display: flex; background: rgba(128,128,128,.05); border-bottom: 1px solid rgba(128,128,128,.15); overflow-x: auto; }
        .dpi-tab-btn { flex: 1; padding: 14px; background: transparent; border: none; font-size: 0.85rem; font-weight: 600; color: inherit; cursor: pointer; opacity: 0.6; transition: all 0.2s; border-bottom: 2px solid transparent; white-space: nowrap; }
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
        .dpi-empty { opacity: .4; font-size: .83rem; padding: 8px 0; }
        .dpi-more { width: 100%; padding: 9px; background: transparent; border: 1px dashed rgba(128,128,128,.3); border-radius: 9px; cursor: pointer; color: inherit; font-size: .78rem; margin: 2px 0 18px; }
        .dpi-more:hover:not(:disabled) { border-color: #6366f1; color: #6366f1; }
        
        /* Forms */
        .dpi-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 18px; }
        .dpi-form-grid input, .dpi-form-grid textarea, .dpi-pw-row input { width: 100%; box-sizing: border-box; background: rgba(128,128,128,.09); border: 1px solid rgba(128,128,128,.2); padding: 10px 14px; border-radius: 7px; color: inherit; font-size: .88rem; font-family: inherit; }
        .dpi-form-grid input:focus, .dpi-form-grid textarea:focus, .dpi-pw-row input:focus { outline: none; border-color: #6366f1; }
        .dpi-pw-row { display: flex; gap: 8px; }
        .dpi-btn-primary { background: #6366f1; color: #fff; border: none; padding: 12px 20px; border-radius: 7px; cursor: pointer; font-weight: 700; font-size: .88rem; margin-top: 18px; width: 100%; transition: opacity 0.2s; }
        .dpi-btn-primary:hover { opacity: 0.9; }
        .dpi-btn-primary:disabled { opacity: .5; cursor: default; }
        .dpi-pw-limit { font-size: .72rem; opacity: .4; margin: 6px 0 0; }
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
            const bye   = <?php echo wp_json_encode( wp_logout_url( home_url() ) ); ?>;

            function post(data){
                const fd = new FormData();
                Object.entries(data).forEach(([k,v]) => fd.append(k,v));
                return fetch(ajax, {method:'POST', body:fd}).then(r => r.json());
            }

            // Tab Switcher Logic
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

            // Load More
            wrap.addEventListener('click', function(e){
                const btn = e.target.closest('.dpi-more');
                if(!btn || btn.disabled) return;
                const type = btn.dataset.type;
                const page = parseInt(btn.dataset.page, 10) + 1;
                btn.disabled = true;
                btn.textContent = 'Loading…';
                post({action:'dpi_load_more', nonce, type, page}).then(res => {
                    if(res.success && res.data.html){
                        const id = type === 'comments' ? 'dpi-comments-list' : (type === 'subs' ? 'dpi-subs-list' : 'dpi-rated-list');
                        document.getElementById(id).insertAdjacentHTML('beforeend', res.data.html);
                        btn.dataset.page = page;
                    }
                    if(!res.success || !res.data.has_more){
                        btn.remove();
                    } else {
                        btn.disabled = false;
                        btn.textContent = 'Load More';
                    }
                }).catch(() => { btn.disabled = false; btn.textContent = 'Retry'; });
            });

            // Save Profile Form
            const profileForm = document.getElementById('dpi-profile-form');
            if(profileForm) {
                profileForm.addEventListener('submit', function(e){
                    e.preventDefault();
                    const btn = document.getElementById('dpi-save-btn');
                    const fd = new FormData(profileForm);
                    
                    const passInput = fd.get('new_password');
                    if(passInput && passInput.length > 0 && passInput.length < 8){ 
                        alert('Password must be at least 8 characters.'); 
                        return; 
                    }

                    btn.disabled = true;
                    btn.textContent = 'Saving...';
                    
                    const data = { action: 'dpi_update_profile', nonce: nonce };
                    fd.forEach((value, key) => data[key] = value);

                    post(data).then(res => {
                        if(res.success){
                            Swal.fire('Saved!', res.data, 'success').then(() => {
                                if(passInput) location.href = bye;
                            });
                        } else {
                            Swal.fire('Error', res.data, 'error');
                        }
                    }).catch(() => { 
                        Swal.fire('Error', 'Connection failed. Please try again.', 'error'); 
                    }).finally(() => {
                        btn.disabled = false;
                        btn.textContent = 'Save Profile Settings';
                    });
                });
            }
        })();
        </script>
        <?php
    }
}

new DevProfileInsightsPro();
