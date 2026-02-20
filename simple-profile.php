<?php
/**
 * Plugin Name: Profile Insights Pro
 * Description: User dashboard with Karma tracking and AJAX pagination.
 * Version:     4.3
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
        add_action(    'wp_ajax_dpi_change_password', [ $this, 'ajax_change_password' ] );

        // Stamp exact vote time so it can be sorted correctly in the feed.
        add_action( 'updated_comment_meta', [ $this, 'stamp_vote_time' ], 10, 4 );
        add_action( 'added_comment_meta',   [ $this, 'stamp_vote_time' ], 10, 4 );
    }

    // ---------------------------------------------------------------
    // Vote timestamp — guard prevents the update_comment_meta call
    // below from re-triggering this same hook infinitely.
    // ---------------------------------------------------------------

    public function stamp_vote_time( $meta_id, $comment_id, $meta_key, $meta_value ) {
        if ( $meta_key !== 'wpdiscuz_votes' ) return;
        if ( $this->stamping_vote ) return; // re-entrancy guard

        $this->stamping_vote = true;
        update_comment_meta( (int) $comment_id, 'dpi_vote_time', current_time( 'mysql', true ) );
        $this->stamping_vote = false;
    }

    // ---------------------------------------------------------------
    // Rate limiting — stored as a transient per user
    // ---------------------------------------------------------------

    private function get_pw_attempts( $user_id ) {
        return (int) get_transient( 'dpi_pw_attempts_' . $user_id );
    }

    private function increment_pw_attempts( $user_id ) {
        $key      = 'dpi_pw_attempts_' . $user_id;
        $attempts = $this->get_pw_attempts( $user_id ) + 1;
        // set_transient only sets expiry on first call; we overwrite each time
        // so we re-set with the same TTL to keep the window consistent.
        set_transient( $key, $attempts, $this->pw_window_seconds );
        return $attempts;
    }

    // ---------------------------------------------------------------
    // Data
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

        // Own approved comments with vote meta joined.
        $comments = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                c.comment_ID,
                c.comment_post_ID,
                c.comment_content,
                c.comment_date_gmt,
                CAST( COALESCE( mv.meta_value, '0' ) AS SIGNED ) AS vote_count,
                vt.meta_value AS vote_time
             FROM   {$wpdb->comments} c
             LEFT JOIN {$wpdb->commentmeta} mv
                    ON mv.comment_id = c.comment_ID AND mv.meta_key = 'wpdiscuz_votes'
             LEFT JOIN {$wpdb->commentmeta} vt
                    ON vt.comment_id = c.comment_ID AND vt.meta_key = 'dpi_vote_time'
             WHERE  c.user_id          = %d
               AND  c.comment_approved = '1'
             ORDER  BY c.comment_date_gmt DESC
             LIMIT  100",
            $user_id
        ) );

        foreach ( $comments as $c ) {
            $items[] = [
                'type'      => 'comment',
                'sort_time' => $c->comment_date_gmt,
                'data'      => $c,
            ];

            // Vote card uses the real vote timestamp, not the comment date.
            if ( (int) $c->vote_count !== 0 && ! empty( $c->vote_time ) ) {
                $items[] = [
                    'type'      => 'vote',
                    'sort_time' => $c->vote_time,
                    'data'      => $c,
                ];
            }
        }

        // Replies by other users on our comments.
        $own_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT comment_ID FROM {$wpdb->comments} WHERE user_id = %d LIMIT 500",
            $user_id
        ) );

        if ( $own_ids ) {
            $ph = implode( ',', array_map( 'intval', $own_ids ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $ph is all intval-cast
            $replies = $wpdb->get_results( $wpdb->prepare(
                "SELECT comment_ID, comment_post_ID, comment_content, comment_date_gmt
                 FROM   {$wpdb->comments}
                 WHERE  comment_parent IN ( $ph )
                   AND  user_id       != %d
                   AND  comment_approved = '1'
                 ORDER  BY comment_date_gmt DESC
                 LIMIT  50",
                $user_id
            ) );

            foreach ( $replies as $r ) {
                $items[] = [
                    'type'      => 'reply',
                    'sort_time' => $r->comment_date_gmt,
                    'data'      => $r,
                ];
            }
        }

        usort( $items, fn( $a, $b ) => strcmp( $b['sort_time'], $a['sort_time'] ) );

        $offset = max( 0, (int) $page ) * $this->per_page;
        return array_slice( $items, $offset, $this->per_page );
    }

    private function get_rated_posts( $user_id, $page = 0 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_users_rated';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) return [];
        $offset = max( 0, (int) $page ) * $this->per_page;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT post_id FROM `{$table}` WHERE user_id = %d ORDER BY id DESC LIMIT %d OFFSET %d",
            $user_id, $this->per_page, $offset
        ) ) ?: [];
    }

    // ---------------------------------------------------------------
    // Assets
    // ---------------------------------------------------------------

    public function enqueue_assets() {
        if ( ! is_user_logged_in() ) return;
        wp_register_style( 'dpi-styles', false );
        wp_enqueue_style( 'dpi-styles' );
        wp_add_inline_style( 'dpi-styles', $this->get_css() );
        wp_enqueue_script( 'dpi-swal', 'https://cdn.jsdelivr.net/npm/sweetalert2@11', [], '11', true );
        add_action( 'wp_footer', [ $this, 'render_js' ] );
    }

    // ---------------------------------------------------------------
    // Shortcode
    // ---------------------------------------------------------------

    public function render_shortcode() {
        if ( ! is_user_logged_in() )
            return '<p class="dpi-notice">🔒 Please log in to view your profile.</p>';

        $user          = wp_get_current_user();
        $comment_count = (int) get_comments( [ 'user_id' => $user->ID, 'count' => true, 'status' => 'approve' ] );
        $karma         = $this->get_total_karma( $user->ID );
        $activities    = $this->get_activity( $user->ID, 0 );
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
                <div class="dpi-stat"><strong>🛡️</strong><span>Verified</span></div>
            </div>

            <div class="dpi-body">

                <h4 class="dpi-sec-label">Recent Activity</h4>
                <div id="dpi-comments-list">
                    <?php if ( $activities ) {
                        foreach ( $activities as $a ) $this->render_item( $a );
                    } else {
                        echo '<p class="dpi-empty">No activity yet.</p>';
                    } ?>
                </div>
                <?php if ( count( $activities ) >= $this->per_page ) : ?>
                    <button class="dpi-more" data-type="comments" data-page="0">Show More</button>
                <?php endif; ?>

                <h4 class="dpi-sec-label">Ratings</h4>
                <div id="dpi-rated-list">
                    <?php if ( $rated ) {
                        foreach ( $rated as $r ) $this->render_rated( $r );
                    } else {
                        echo '<p class="dpi-empty">No ratings yet.</p>';
                    } ?>
                </div>
                <?php if ( count( $rated ) >= $this->per_page ) : ?>
                    <button class="dpi-more" data-type="rated" data-page="0">Load More</button>
                <?php endif; ?>

                <h4 class="dpi-sec-label">Security</h4>
                <div class="dpi-pw-row">
                    <input type="password" id="dpi-pw" placeholder="New password (min 8 chars)" autocomplete="new-password">
                    <button id="dpi-pw-save">Update</button>
                </div>
                <p class="dpi-pw-limit">Max <?php echo (int) $this->pw_max_attempts; ?> changes per hour.</p>

            </div>
        </div>
        <?php return ob_get_clean();
    }

    // ---------------------------------------------------------------
    // Render helpers — each value is escaped at the point of output
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
            $class = $up ? 'dpi-like' : 'dpi-dislike';
            $label = $up ? '👍 Upvoted' : '👎 Downvoted';
            printf(
                '<a href="%s" class="dpi-item %s"><span class="dpi-badge">%s</span><strong>%s</strong><small>Score: %d</small></a>',
                $link, esc_attr( $class ), esc_html( $label ), $title, $score
            );

        } elseif ( $type === 'reply' ) {
            printf(
                '<a href="%s" class="dpi-item dpi-reply"><span class="dpi-badge">💬 Reply received</span><strong>%s</strong><small>"%s"</small></a>',
                $link, $title, $snippet
            );

        } else {
            printf(
                '<a href="%s" class="dpi-item"><span class="dpi-badge">📝 Comment</span><strong>%s</strong><small>"%s"</small></a>',
                $link, $title, $snippet
            );
        }
    }

    private function render_rated( $r ) {
        printf(
            '<a href="%s" class="dpi-item">⭐ %s</a>',
            esc_url( get_permalink( (int) $r->post_id ) ),
            esc_html( get_the_title( (int) $r->post_id ) )
        );
    }

    // ---------------------------------------------------------------
    // AJAX — load more
    // ---------------------------------------------------------------

    public function ajax_load_more() {
        check_ajax_referer( 'dpi_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) wp_send_json_error( 'Not logged in.' );

        $type = sanitize_key( wp_unslash( $_POST['type'] ?? '' ) );
        $page = max( 1, (int) ( $_POST['page'] ?? 1 ) );

        if ( ! in_array( $type, [ 'comments', 'rated' ], true ) ) {
            wp_send_json_error( 'Invalid type.' );
        }

        ob_start();
        if ( $type === 'comments' ) {
            $items = $this->get_activity( $user_id, $page );
            foreach ( $items as $a ) $this->render_item( $a );
        } else {
            $items = $this->get_rated_posts( $user_id, $page );
            foreach ( $items as $r ) $this->render_rated( $r );
        }

        wp_send_json_success( [
            'html'     => ob_get_clean(),
            'has_more' => count( $items ) >= $this->per_page,
        ] );
    }

    // ---------------------------------------------------------------
    // AJAX — change password (rate limited: 3 per hour per user)
    // ---------------------------------------------------------------

    public function ajax_change_password() {
        check_ajax_referer( 'dpi_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) wp_send_json_error( 'Not logged in.' );

        // Rate limit check.
        if ( $this->get_pw_attempts( $user_id ) >= $this->pw_max_attempts ) {
            wp_send_json_error( 'Too many attempts. Please try again in an hour.' );
        }

        $new_pass = trim( wp_unslash( $_POST['new_password'] ?? '' ) );

        if ( mb_strlen( $new_pass ) < 8 ) {
            wp_send_json_error( 'Password must be at least 8 characters.' );
        }

        // Count this attempt before changing — so a failed wp_set_password
        // still burns an attempt and can't be brute-forced.
        $this->increment_pw_attempts( $user_id );

        wp_set_password( $new_pass, $user_id );
        wp_send_json_success( 'Password updated! Logging you out…' );
    }

    // ---------------------------------------------------------------
    // CSS
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
        .dpi-body { padding: 18px; }
        .dpi-sec-label { font-size: .62rem; text-transform: uppercase; letter-spacing: .05em; opacity: .45; font-weight: 700; margin: 20px 0 8px; }
        .dpi-sec-label:first-child { margin-top: 0; }
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
        .dpi-more:disabled { opacity: .45; cursor: default; }
        .dpi-pw-row { display: flex; gap: 8px; }
        .dpi-pw-row input { flex: 1; background: rgba(128,128,128,.09); border: 1px solid rgba(128,128,128,.2); padding: 9px 12px; border-radius: 7px; color: inherit; font-size: .88rem; }
        .dpi-pw-row input:focus { outline: none; border-color: #6366f1; }
        .dpi-pw-row button { background: #6366f1; color: #fff; border: none; padding: 0 16px; border-radius: 7px; cursor: pointer; font-weight: 700; font-size: .84rem; }
        .dpi-pw-row button:disabled { opacity: .5; cursor: default; }
        .dpi-pw-limit { font-size: .72rem; opacity: .4; margin: 6px 0 0; }
    '; }

    // ---------------------------------------------------------------
    // JS
    // ---------------------------------------------------------------

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
                        const id = type === 'comments' ? 'dpi-comments-list' : 'dpi-rated-list';
                        document.getElementById(id).insertAdjacentHTML('beforeend', res.data.html);
                        btn.dataset.page = page;
                    }
                    if(!res.success || !res.data.has_more){
                        btn.remove();
                    } else {
                        btn.disabled = false;
                        btn.textContent = type === 'comments' ? 'Show More' : 'Load More';
                    }
                }).catch(() => { btn.disabled = false; btn.textContent = 'Retry'; });
            });

            // Change password
            document.getElementById('dpi-pw-save').addEventListener('click', function(){
                const pass = document.getElementById('dpi-pw').value;
                if(pass.length < 8){ alert('Password must be at least 8 characters.'); return; }
                const btn = this;
                btn.disabled = true;
                btn.textContent = '…';
                post({action:'dpi_change_password', nonce, new_password:pass}).then(res => {
                    if(res.success){
                        Swal.fire('Done', res.data, 'success').then(() => location.href = bye);
                    } else {
                        Swal.fire('Error', res.data, 'error');
                        btn.disabled = false;
                        btn.textContent = 'Update';
                    }
                }).catch(() => { btn.disabled = false; btn.textContent = 'Update'; });
            });
        })();
        </script>
        <?php
    }
}

new DevProfileInsightsPro();
