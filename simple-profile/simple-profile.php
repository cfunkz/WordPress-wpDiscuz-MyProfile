<?php
/**
 * Plugin Name: Profile Insights Pro
 * Description: User dashboard with Karma, AJAX pagination, wpDiscuz Subscriptions, Author Stats and Profile Editing.
 * Version:     4.6
 * Author:      cFunkz
 * License:     GPL-2.0-or-later
 * Requires PHP: 7.2
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'DPI_OPTION', 'dpi_settings' );

class DevProfileInsightsPro {

    private $per_page          = 6;
    private $pw_max_attempts   = 3;
    private $pw_window_seconds = 3600;
    private $stamping_vote     = false;
    private $opts              = array();

    // -----------------------------------------------------------------------
    // Boot
    // -----------------------------------------------------------------------

    public function __construct() {
        $this->opts     = wp_parse_args( (array) get_option( DPI_OPTION, array() ), $this->defaults() );
        $this->per_page = max( 1, (int) $this->opts['per_page'] );

        add_shortcode( 'user_insights',              array( $this, 'render_shortcode' ) );
        add_action( 'wp_enqueue_scripts',            array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_dpi_load_more',         array( $this, 'ajax_load_more' ) );
        add_action( 'wp_ajax_dpi_update_profile',    array( $this, 'ajax_update_profile' ) );
        add_action( 'updated_comment_meta',          array( $this, 'stamp_vote_time' ), 10, 4 );
        add_action( 'added_comment_meta',            array( $this, 'stamp_vote_time' ), 10, 4 );
        add_filter( 'notify_post_author',            array( $this, 'enforce_comment_notifications' ), 10, 2 );
        add_filter( 'user_contactmethods',           array( $this, 'add_custom_contact_methods' ) );
        add_action( 'admin_menu',                    array( $this, 'admin_menu' ) );
        add_action( 'admin_init',                    array( $this, 'admin_init' ) );
    }

    // -----------------------------------------------------------------------
    // Config
    // -----------------------------------------------------------------------

    private function defaults() {
        return array(
            'primary_color'  => '#6366f1',
            'btn_text_color' => '#ffffff',
            'stats_box_bg'   => '#6366f1',
            'border_radius'  => 18,
            'per_page'       => 6,
            'tabs_enabled'   => array( 'activity', 'subs', 'ratings', 'settings', 'author' ),
            'fields_enabled' => array( 'first_name', 'last_name', 'display_name', 'user_email', 'user_url', 'description', 'contact_methods', 'password' ),
        );
    }

    private function builtin_tabs() {
        return array(
            'activity' => 'Activity Feed',
            'subs'     => 'Subscriptions',
            'ratings'  => 'Ratings',
            'settings' => 'Settings',
            'author'   => 'Statistics',
        );
    }

    private function profile_fields() {
        return array(
            'first_name'      => 'First Name',
            'last_name'       => 'Last Name',
            'display_name'    => 'Display Name',
            'user_email'      => 'Email Address',
            'user_url'        => 'Website URL',
            'description'     => 'Bio / Description',
            'contact_methods' => 'Social & Contact Links',
            'password'        => 'Password Change',
        );
    }

    private function tab_enabled( $id ) {
        return in_array( $id, (array) $this->opts['tabs_enabled'], true );
    }

    private function field_enabled( $id ) {
        return in_array( $id, (array) $this->opts['fields_enabled'], true );
    }

    // -----------------------------------------------------------------------
    // WordPress hooks
    // -----------------------------------------------------------------------

    public function add_custom_contact_methods( $methods ) {
        $methods['linkedin'] = 'LinkedIn URL';
        $methods['github']   = 'GitHub URL';
        return $methods;
    }

    public function enforce_comment_notifications( $maybe_notify, $comment_id ) {
        $comment = get_comment( $comment_id );
        if ( ! $comment ) return $maybe_notify;
        $post = get_post( $comment->comment_post_ID );
        if ( ! $post )    return $maybe_notify;
        if ( get_user_meta( $post->post_author, 'dpi_notify_new_comments', true ) === '0' ) {
            return false;
        }
        return $maybe_notify;
    }

    public function stamp_vote_time( $meta_id, $comment_id, $meta_key, $meta_value ) {
        if ( $meta_key !== 'wpdiscuz_votes' || $this->stamping_vote ) return;
        $this->stamping_vote = true;
        update_comment_meta( (int) $comment_id, 'dpi_vote_time', current_time( 'mysql', true ) );
        $this->stamping_vote = false;
    }

    // -----------------------------------------------------------------------
    // Password rate-limiting
    // -----------------------------------------------------------------------

    private function get_pw_attempts( $user_id ) {
        return (int) get_transient( 'dpi_pw_' . $user_id );
    }

    private function increment_pw_attempts( $user_id ) {
        $n = $this->get_pw_attempts( $user_id ) + 1;
        set_transient( 'dpi_pw_' . $user_id, $n, $this->pw_window_seconds );
        return $n;
    }

    // -----------------------------------------------------------------------
    // Data queries
    // -----------------------------------------------------------------------

    private function get_total_karma( $user_id ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(CAST(m.meta_value AS SIGNED)),0)
             FROM {$wpdb->commentmeta} m
             INNER JOIN {$wpdb->comments} c ON m.comment_id = c.comment_ID
             WHERE c.user_id = %d AND m.meta_key = 'wpdiscuz_votes'",
            $user_id
        ) );
    }

    private function get_author_word_count( $user_id ) {
        global $wpdb;
        $posts = $wpdb->get_col( $wpdb->prepare(
            "SELECT post_content FROM {$wpdb->posts}
             WHERE post_author = %d AND post_status = 'publish' AND post_type = 'post'",
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
        $items = array();

        $comments = $wpdb->get_results( $wpdb->prepare(
            "SELECT c.comment_ID, c.comment_post_ID, c.comment_content, c.comment_date_gmt,
                    CAST(COALESCE(mv.meta_value,'0') AS SIGNED) AS vote_count,
                    vt.meta_value AS vote_time
             FROM {$wpdb->comments} c
             LEFT JOIN {$wpdb->commentmeta} mv ON mv.comment_id = c.comment_ID AND mv.meta_key = 'wpdiscuz_votes'
             LEFT JOIN {$wpdb->commentmeta} vt ON vt.comment_id = c.comment_ID AND vt.meta_key = 'dpi_vote_time'
             WHERE c.user_id = %d AND c.comment_approved = '1'
             ORDER BY c.comment_date_gmt DESC LIMIT 100",
            $user_id
        ) );

        foreach ( $comments as $c ) {
            $items[] = array( 'type' => 'comment', 'sort_time' => $c->comment_date_gmt, 'data' => $c );
            if ( (int) $c->vote_count !== 0 && ! empty( $c->vote_time ) ) {
                $items[] = array( 'type' => 'vote', 'sort_time' => $c->vote_time, 'data' => $c );
            }
        }

        $own_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT comment_ID FROM {$wpdb->comments} WHERE user_id = %d LIMIT 500", $user_id
        ) );

        if ( $own_ids ) {
            $ph      = implode( ',', array_map( 'intval', $own_ids ) );
            $replies = $wpdb->get_results( $wpdb->prepare(
                "SELECT comment_ID, comment_post_ID, comment_content, comment_date_gmt
                 FROM {$wpdb->comments}
                 WHERE comment_parent IN ($ph) AND user_id != %d AND comment_approved = '1'
                 ORDER BY comment_date_gmt DESC LIMIT 50",
                $user_id
            ) );
            foreach ( $replies as $r ) {
                $items[] = array( 'type' => 'reply', 'sort_time' => $r->comment_date_gmt, 'data' => $r );
            }
        }

        $on_my_posts = $wpdb->get_results( $wpdb->prepare(
            "SELECT c.comment_ID, c.comment_post_ID, c.comment_content, c.comment_date_gmt
             FROM {$wpdb->comments} c
             INNER JOIN {$wpdb->posts} p ON c.comment_post_ID = p.ID
             WHERE p.post_author = %d AND c.user_id != %d AND c.comment_approved = '1'
             ORDER BY c.comment_date_gmt DESC LIMIT 50",
            $user_id, $user_id
        ) );
        foreach ( $on_my_posts as $ac ) {
            $items[] = array( 'type' => 'author_comment', 'sort_time' => $ac->comment_date_gmt, 'data' => $ac );
        }

        usort( $items, function( $a, $b ) {
            return strcmp( $b['sort_time'], $a['sort_time'] );
        } );

        return array_slice( $items, max( 0, (int) $page ) * $this->per_page, $this->per_page );
    }

    private function get_wpdiscuz_subscriptions( $user_email, $page = 0 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_comments_subscription';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) return array();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE email = %s ORDER BY id DESC LIMIT %d OFFSET %d",
            $user_email, $this->per_page, max( 0, (int) $page ) * $this->per_page
        ) ) ?: array();
    }

    private function get_rated_posts( $user_id, $page = 0 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_users_rated';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) return array();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT post_id FROM `{$table}` WHERE user_id = %d ORDER BY id DESC LIMIT %d OFFSET %d",
            $user_id, $this->per_page, max( 0, (int) $page ) * $this->per_page
        ) ) ?: array();
    }

    // -----------------------------------------------------------------------
    // Assets
    // -----------------------------------------------------------------------

    public function enqueue_assets() {
        if ( ! is_user_logged_in() ) return;
        wp_register_style( 'dpi-styles', false, array(), '5.1' );
        wp_enqueue_style( 'dpi-styles' );
        wp_add_inline_style( 'dpi-styles', $this->get_css() );
        wp_enqueue_script( 'dpi-swal', 'https://cdn.jsdelivr.net/npm/sweetalert2@11', array(), '11', true );
        add_action( 'wp_footer', array( $this, 'render_js' ) );
    }

    // -----------------------------------------------------------------------
    // Shortcode
    // -----------------------------------------------------------------------

    public function render_shortcode() {
        if ( ! is_user_logged_in() )
            return '<p class="dpi-notice">&#128274; Please log in to view your profile.</p>';

        $user            = wp_get_current_user();
        $is_author       = current_user_can( 'edit_posts' );
        $comment_count   = (int) get_comments( array( 'user_id' => $user->ID, 'count' => true, 'status' => 'approve' ) );
        $karma           = $this->get_total_karma( $user->ID );
        $subscriptions   = $this->get_wpdiscuz_subscriptions( $user->user_email );
        $rated           = $this->get_rated_posts( $user->ID );
        $activities      = $this->get_activity( $user->ID );
        $contact_methods = wp_get_user_contact_methods( $user );

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
                <div class="dpi-stat"><strong><?php echo esc_html( count( $subscriptions ) ); ?></strong><span>Subscriptions</span></div>
            </div>

            <?php
            // --- Tab bar ---
            $first = true;
            echo '<div class="dpi-tabs">';
            foreach ( $this->builtin_tabs() as $tab_id => $tab_label ) {
                if ( ! $this->tab_enabled( $tab_id ) ) continue;
                if ( $tab_id === 'author' && ! $is_author ) continue;
                $ac = $first ? ' active' : '';
                echo '<button class="dpi-tab-btn' . $ac . '" data-target="dpi-tab-' . esc_attr( $tab_id ) . '">' . esc_html( $tab_label ) . '</button>';
                $first = false;
            }
            echo '</div>';

            // --- Tab panes ---
            $first = true;
            echo '<div class="dpi-body">';

            if ( $this->tab_enabled( 'activity' ) ) {
                $ac = $first ? ' active' : ''; $first = false; ?>
                <div id="dpi-tab-activity" class="dpi-tab-content<?php echo $ac; ?>">
                    <h4 class="dpi-sec-label">Recent Activity</h4>
                    <div id="dpi-comments-list">
                        <?php if ( $activities ) { foreach ( $activities as $a ) $this->render_item( $a ); }
                        else { echo '<p class="dpi-empty">No activity yet.</p>'; } ?>
                    </div>
                    <?php if ( count( $activities ) >= $this->per_page ) : ?>
                        <button class="dpi-more" data-type="comments" data-page="0">Show More</button>
                    <?php endif; ?>
                </div>
            <?php }

            if ( $this->tab_enabled( 'subs' ) ) {
                $ac = $first ? ' active' : ''; $first = false; ?>
                <div id="dpi-tab-subs" class="dpi-tab-content<?php echo $ac; ?>">
                    <h4 class="dpi-sec-label">Active Subscriptions</h4>
                    <div id="dpi-subs-list">
                        <?php if ( $subscriptions ) { foreach ( $subscriptions as $s ) $this->render_subscription( $s ); }
                        else { echo '<p class="dpi-empty">No subscriptions active.</p>'; } ?>
                    </div>
                    <?php if ( count( $subscriptions ) >= $this->per_page ) : ?>
                        <button class="dpi-more" data-type="subs" data-page="0">Load More</button>
                    <?php endif; ?>
                </div>
            <?php }

            if ( $this->tab_enabled( 'ratings' ) ) {
                $ac = $first ? ' active' : ''; $first = false; ?>
                <div id="dpi-tab-ratings" class="dpi-tab-content<?php echo $ac; ?>">
                    <h4 class="dpi-sec-label">My Ratings</h4>
                    <div id="dpi-rated-list">
                        <?php if ( $rated ) { foreach ( $rated as $r ) $this->render_rated( $r ); }
                        else { echo '<p class="dpi-empty">No ratings yet.</p>'; } ?>
                    </div>
                    <?php if ( count( $rated ) >= $this->per_page ) : ?>
                        <button class="dpi-more" data-type="rated" data-page="0">Load More</button>
                    <?php endif; ?>
                </div>
            <?php }

            if ( $this->tab_enabled( 'settings' ) ) {
                $ac = $first ? ' active' : ''; $first = false; ?>
                <div id="dpi-tab-settings" class="dpi-tab-content<?php echo $ac; ?>">
                    <form id="dpi-profile-form">
                        <?php
                        $show_general = $this->field_enabled('first_name') || $this->field_enabled('last_name')
                            || $this->field_enabled('display_name') || $this->field_enabled('user_email')
                            || $this->field_enabled('user_url')     || $this->field_enabled('description');
                        if ( $show_general ) : ?>
                            <h4 class="dpi-sec-label">General Info</h4>
                            <div class="dpi-form-grid">
                                <?php if ( $this->field_enabled('first_name') ) : ?>
                                    <input type="text" name="first_name" placeholder="First Name" value="<?php echo esc_attr( $user->first_name ); ?>">
                                <?php endif; ?>
                                <?php if ( $this->field_enabled('last_name') ) : ?>
                                    <input type="text" name="last_name" placeholder="Last Name" value="<?php echo esc_attr( $user->last_name ); ?>">
                                <?php endif; ?>
                                <?php if ( $this->field_enabled('display_name') ) : ?>
                                    <input type="text" name="display_name" placeholder="Display Name" value="<?php echo esc_attr( $user->display_name ); ?>" required>
                                <?php endif; ?>
                                <?php if ( $this->field_enabled('user_email') ) : ?>
                                    <input type="email" name="user_email" placeholder="Email Address" value="<?php echo esc_attr( $user->user_email ); ?>" required>
                                <?php endif; ?>
                                <?php if ( $this->field_enabled('user_url') ) : ?>
                                    <input type="url" name="user_url" placeholder="Website URL" value="<?php echo esc_attr( $user->user_url ); ?>" style="grid-column:1/-1;">
                                <?php endif; ?>
                                <?php if ( $this->field_enabled('description') ) : ?>
                                    <textarea name="description" placeholder="Bio..." rows="3" style="grid-column:1/-1;"><?php echo esc_textarea( $user->description ); ?></textarea>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ( $this->field_enabled('contact_methods') && ! empty( $contact_methods ) ) : ?>
                            <h4 class="dpi-sec-label" style="margin-top:24px;">Social &amp; Contact Links</h4>
                            <div class="dpi-form-grid">
                                <?php foreach ( $contact_methods as $key => $label ) : ?>
                                    <input type="text" name="<?php echo esc_attr( $key ); ?>"
                                           placeholder="<?php echo esc_attr( $label ); ?>"
                                           value="<?php echo esc_attr( get_user_meta( $user->ID, $key, true ) ); ?>">
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ( $this->field_enabled('password') ) : ?>
                            <h4 class="dpi-sec-label" style="margin-top:24px;">Security</h4>
                            <div class="dpi-pw-row">
                                <input type="password" name="new_password" placeholder="New password (min 8 chars)" autocomplete="new-password">
                            </div>
                            <p class="dpi-pw-limit">Max <?php echo (int) $this->pw_max_attempts; ?> changes per hour.</p>
                        <?php endif; ?>

                        <button type="submit" class="dpi-btn-primary">Save Profile</button>
                    </form>
                </div>
            <?php }

            if ( $this->tab_enabled( 'author' ) && $is_author ) {
                $ac          = $first ? ' active' : ''; $first = false;
                $total_posts = (int) count_user_posts( $user->ID, 'post', true );
                $word_count  = $this->get_author_word_count( $user->ID );
                $read_time   = max( 1, (int) ceil( $word_count / 200 ) );
                $notify_on   = get_user_meta( $user->ID, 'dpi_notify_new_comments', true ) !== '0'; ?>
                <div id="dpi-tab-author" class="dpi-tab-content<?php echo $ac; ?>">
                    <div class="dpi-author-stats-box">
                        <h4 class="dpi-sec-label" style="color:var(--dpi-primary);">Writing Statistics</h4>
                        <div class="dpi-stats" style="border-bottom:none;">
                            <div class="dpi-stat"><strong><?php echo esc_html( $total_posts ); ?></strong><span>Posts</span></div>
                            <div class="dpi-stat"><strong><?php echo esc_html( number_format( $word_count ) ); ?></strong><span>Words</span></div>
                            <div class="dpi-stat"><strong><?php echo esc_html( $read_time ); ?>m</strong><span>Read Time</span></div>
                        </div>
                        <div style="margin-top:15px;text-align:center;">
                            <a href="<?php echo esc_url( admin_url('post-new.php') ); ?>" class="dpi-btn-primary" style="display:inline-block;width:auto;text-decoration:none;padding:10px 24px;">&#x2795; Add New Post</a>
                        </div>
                    </div>
                    <form id="dpi-author-settings-form">
                        <input type="hidden" name="dpi_author_settings_submitted" value="1">
                        <h4 class="dpi-sec-label">Preferences</h4>
                        <div class="dpi-checkbox-row">
                            <label>
                                <input type="checkbox" name="dpi_notify_new_comments" value="1" <?php checked( $notify_on ); ?>>
                                Email me when someone comments on my posts
                            </label>
                        </div>
                        <button type="submit" class="dpi-btn-primary">Save Preferences</button>
                    </form>
                </div>
            <?php }

            echo '</div>'; // .dpi-body ?>

        </div><!-- .dpi-wrap -->
        <?php return ob_get_clean();
    }

    // -----------------------------------------------------------------------
    // Item renderers
    // -----------------------------------------------------------------------

    private function render_item( $act ) {
        $type    = $act['type'];
        $data    = $act['data'];
        $title   = esc_html( get_the_title( (int) $data->comment_post_ID ) );
        $link    = esc_url( get_comment_link( (int) $data->comment_ID ) );
        $snippet = esc_html( wp_trim_words( $data->comment_content, 10, '...' ) );

        if ( $type === 'vote' ) {
            $score = (int) $data->vote_count;
            $up    = $score > 0;
            printf( '<a href="%s" class="dpi-item %s"><span class="dpi-badge">%s</span><strong>%s</strong><small>Score: %d</small></a>',
                $link, $up ? 'dpi-like' : 'dpi-dislike', $up ? 'Upvoted' : 'Downvoted', $title, $score );
        } elseif ( $type === 'reply' ) {
            printf( '<a href="%s" class="dpi-item dpi-reply"><span class="dpi-badge">Reply received</span><strong>%s</strong><small>"%s"</small></a>', $link, $title, $snippet );
        } elseif ( $type === 'author_comment' ) {
            printf( '<a href="%s" class="dpi-item dpi-author-comment"><span class="dpi-badge">Comment on your post</span><strong>%s</strong><small>"%s"</small></a>', $link, $title, $snippet );
        } else {
            printf( '<a href="%s" class="dpi-item"><span class="dpi-badge">Comment</span><strong>%s</strong><small>"%s"</small></a>', $link, $title, $snippet );
        }
    }

    private function render_subscription( $s ) {
        printf( '<a href="%s" class="dpi-item dpi-reply"><span class="dpi-badge">%s Subscription</span><strong>%s</strong></a>',
            esc_url( get_permalink( (int) $s->post_id ) ),
            esc_html( ucwords( str_replace( '_', ' ', ! empty( $s->type ) ? $s->type : 'post' ) ) ),
            esc_html( get_the_title( (int) $s->post_id ) )
        );
    }

    private function render_rated( $r ) {
        printf( '<a href="%s" class="dpi-item">%s</a>',
            esc_url( get_permalink( (int) $r->post_id ) ),
            esc_html( get_the_title( (int) $r->post_id ) )
        );
    }

    // -----------------------------------------------------------------------
    // AJAX handlers
    // -----------------------------------------------------------------------

    public function ajax_load_more() {
        check_ajax_referer( 'dpi_nonce', 'nonce' );
        $user_id = get_current_user_id();
        if ( ! $user_id ) wp_send_json_error( 'Not logged in.' );

        $type = sanitize_key( wp_unslash( isset( $_POST['type'] ) ? $_POST['type'] : '' ) );
        $page = max( 1, (int) ( isset( $_POST['page'] ) ? $_POST['page'] : 1 ) );

        if ( ! in_array( $type, array( 'comments', 'rated', 'subs' ), true ) ) {
            wp_send_json_error( 'Invalid type.' );
        }

        $user = wp_get_current_user();
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

        wp_send_json_success( array(
            'html'     => ob_get_clean(),
            'has_more' => count( $items ) >= $this->per_page,
        ) );
    }

    public function ajax_update_profile() {
        check_ajax_referer( 'dpi_nonce', 'nonce' );
        $user_id = get_current_user_id();
        if ( ! $user_id ) wp_send_json_error( 'Not logged in.' );

        // Author preferences branch
        if ( ! empty( $_POST['dpi_author_settings_submitted'] ) && current_user_can( 'edit_posts' ) ) {
            update_user_meta( $user_id, 'dpi_notify_new_comments', isset( $_POST['dpi_notify_new_comments'] ) ? '1' : '0' );
            wp_send_json_success( 'Preferences saved!' );
        }

        $user_data    = array( 'ID' => $user_id );
        $needs_update = false;

        foreach ( array( 'first_name', 'last_name', 'display_name', 'user_email', 'user_url' ) as $field ) {
            if ( ! $this->field_enabled( $field ) || ! isset( $_POST[ $field ] ) ) continue;

            if ( $field === 'user_url' ) {
                $val = esc_url_raw( wp_unslash( $_POST[ $field ] ) );
            } elseif ( $field === 'user_email' ) {
                $val = sanitize_email( wp_unslash( $_POST[ $field ] ) );
                if ( ! is_email( $val ) ) wp_send_json_error( 'Invalid email address.' );
                $existing = email_exists( $val );
                if ( $existing && (int) $existing !== $user_id ) wp_send_json_error( 'Email already in use.' );
            } else {
                $val = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
                if ( $field === 'display_name' && $val === '' ) wp_send_json_error( 'Display name cannot be empty.' );
            }

            $user_data[ $field ] = $val;
            $needs_update        = true;
        }

        if ( $this->field_enabled('description') && isset( $_POST['description'] ) ) {
            $user_data['description'] = sanitize_textarea_field( wp_unslash( $_POST['description'] ) );
            $needs_update = true;
        }

        if ( $this->field_enabled('contact_methods') ) {
            foreach ( wp_get_user_contact_methods( get_userdata( $user_id ) ) as $key => $label ) {
                if ( isset( $_POST[ $key ] ) ) {
                    update_user_meta( $user_id, $key, sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) );
                }
            }
        }

        $logout_after = false;
        if ( $this->field_enabled('password') ) {
            // Don't sanitize_text_field passwords — it strips valid special characters
            $new_pass = trim( wp_unslash( isset( $_POST['new_password'] ) ? $_POST['new_password'] : '' ) );
            if ( $new_pass !== '' ) {
                if ( $this->get_pw_attempts( $user_id ) >= $this->pw_max_attempts ) {
                    wp_send_json_error( 'Too many password changes. Try again in an hour.' );
                }
                if ( mb_strlen( $new_pass ) < 8 ) {
                    wp_send_json_error( 'Password must be at least 8 characters.' );
                }
                $this->increment_pw_attempts( $user_id );
                $user_data['user_pass'] = $new_pass;
                $needs_update           = true;
                $logout_after           = true;
            }
        }

        if ( $needs_update ) {
            $result = wp_update_user( $user_data );
            if ( is_wp_error( $result ) ) wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( 'Profile updated!' . ( $logout_after ? ' Logging you out...' : '' ) );
    }

    // -----------------------------------------------------------------------
    // Admin
    // -----------------------------------------------------------------------

    public function admin_menu() {
        add_menu_page(
            'Profile Insights Pro',
            'Profile Insights',
            'manage_options',
            'profile-insights-pro',
            array( $this, 'admin_page' ),
            'dashicons-id-alt',
            80
        );
    }

    public function admin_init() {
        register_setting( 'dpi_group', DPI_OPTION, array( $this, 'sanitize_options' ) );
    }

    public function sanitize_options( $input ) {
        if ( ! is_array( $input ) ) return $this->defaults();
        $out = array();

        foreach ( array( 'primary_color' => '#6366f1', 'btn_text_color' => '#ffffff', 'stats_box_bg' => '#6366f1' ) as $key => $fallback ) {
            $val        = isset( $input[ $key ] ) ? sanitize_hex_color( $input[ $key ] ) : '';
            $out[ $key ] = $val ? $val : $fallback;
        }

        $out['border_radius'] = max( 0, min( 40, (int) ( isset( $input['border_radius'] ) ? $input['border_radius'] : 18 ) ) );
        $out['per_page']      = max( 1, min( 50,  (int) ( isset( $input['per_page'] )      ? $input['per_page']      : 6  ) ) );

        $out['tabs_enabled']   = array_values( array_intersect(
            isset( $input['tabs_enabled'] ) ? (array) $input['tabs_enabled'] : array(),
            array_keys( $this->builtin_tabs() )
        ) );
        $out['fields_enabled'] = array_values( array_intersect(
            isset( $input['fields_enabled'] ) ? (array) $input['fields_enabled'] : array(),
            array_keys( $this->profile_fields() )
        ) );

        return $out;
    }

    public function admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'No permission.' );
        $opts    = $this->opts;
        $opt_key = DPI_OPTION;
        ?>
        <div class="wrap">
            <h1>Profile Insights Pro &mdash; Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'dpi_group' ); ?>

                <h2 class="title">Design</h2>
                <table class="form-table">
                    <tr>
                        <th><label for="dpi-color">Primary Colour</label></th>
                        <td>
                            <input type="color" id="dpi-color" name="<?php echo esc_attr( $opt_key ); ?>[primary_color]" value="<?php echo esc_attr( $opts['primary_color'] ); ?>">
                            <p class="description">Accent colour for borders, active tabs, and buttons.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="dpi-btn-text">Button Text Colour</label></th>
                        <td>
                            <input type="color" id="dpi-btn-text" name="<?php echo esc_attr( $opt_key ); ?>[btn_text_color]" value="<?php echo esc_attr( $opts['btn_text_color'] ); ?>">
                            <p class="description">Text colour on primary buttons (Save, Add New Post).</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="dpi-stats-bg">Statistics Box Colour</label></th>
                        <td>
                            <input type="color" id="dpi-stats-bg" name="<?php echo esc_attr( $opt_key ); ?>[stats_box_bg]" value="<?php echo esc_attr( $opts['stats_box_bg'] ); ?>">
                            <p class="description">Pick any colour &mdash; it will be applied at 13% opacity so it always looks tinted, not solid.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="dpi-radius">Border Radius (px)</label></th>
                        <td><input type="number" id="dpi-radius" min="0" max="40" name="<?php echo esc_attr( $opt_key ); ?>[border_radius]" value="<?php echo esc_attr( $opts['border_radius'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="dpi-perpage">Items per Page</label></th>
                        <td>
                            <input type="number" id="dpi-perpage" min="1" max="50" name="<?php echo esc_attr( $opt_key ); ?>[per_page]" value="<?php echo esc_attr( $opts['per_page'] ); ?>">
                            <p class="description">Items loaded per AJAX page (1&ndash;50).</p>
                        </td>
                    </tr>
                </table>

                <h2 class="title">Visible Tabs</h2>
                <table class="form-table">
                    <tr>
                        <th>Enable Tabs</th>
                        <td>
                            <?php foreach ( $this->builtin_tabs() as $tid => $tlabel ) : ?>
                                <label style="display:block;margin-bottom:6px;">
                                    <input type="checkbox" name="<?php echo esc_attr( $opt_key ); ?>[tabs_enabled][]" value="<?php echo esc_attr( $tid ); ?>" <?php checked( in_array( $tid, (array) $opts['tabs_enabled'], true ) ); ?>>
                                    <?php echo esc_html( $tlabel ); ?>
                                    <em style="color:#888;font-size:12px;">(<?php echo esc_html( $tid ); ?>)</em>
                                </label>
                            <?php endforeach; ?>
                            <p class="description">Statistics tab only appears for users with the <code>edit_posts</code> capability.</p>
                        </td>
                    </tr>
                </table>

                <h2 class="title">Visible Profile Fields</h2>
                <table class="form-table">
                    <tr>
                        <th>Enable Fields</th>
                        <td>
                            <?php foreach ( $this->profile_fields() as $fid => $flabel ) : ?>
                                <label style="display:block;margin-bottom:6px;">
                                    <input type="checkbox" name="<?php echo esc_attr( $opt_key ); ?>[fields_enabled][]" value="<?php echo esc_attr( $fid ); ?>" <?php checked( in_array( $fid, (array) $opts['fields_enabled'], true ) ); ?>>
                                    <?php echo esc_html( $flabel ); ?>
                                </label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                </table>

                <hr style="margin:30px 0;">
                <?php submit_button( 'Save Settings' ); ?>
            </form>
        </div>
        <?php
    }

    // -----------------------------------------------------------------------
    // CSS
    // -----------------------------------------------------------------------

    private function hex_to_rgba( $hex, $opacity ) {
        $hex = ltrim( sanitize_hex_color( $hex ) ?: '#6366f1', '#' );
        if ( strlen( $hex ) === 3 ) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        return 'rgba(' . hexdec( substr( $hex, 0, 2 ) ) . ',' . hexdec( substr( $hex, 2, 2 ) ) . ',' . hexdec( substr( $hex, 4, 2 ) ) . ',' . $opacity . ')';
    }

    private function get_css() {
        $primary  = sanitize_hex_color( $this->opts['primary_color'] ) ?: '#6366f1';
        $btn_text = sanitize_hex_color( $this->opts['btn_text_color'] ) ?: '#ffffff';
        $stats_bg = $this->hex_to_rgba( $this->opts['stats_box_bg'], '0.13' );
        $radius   = max( 0, (int) $this->opts['border_radius'] ) . 'px';

        return "
        :root{--dpi-primary:{$primary};--dpi-btn-text:{$btn_text};--dpi-stats-bg:{$stats_bg};--dpi-radius:{$radius};}
        .dpi-wrap{max-width:100%;margin:20px auto;border:1px solid rgba(128,128,128,.2);border-radius:var(--dpi-radius);overflow:hidden;font-family:system-ui,sans-serif;color:inherit;background:rgba(128,128,128,.04);}
        .dpi-header{display:flex;align-items:center;gap:16px;padding:22px;border-bottom:1px solid rgba(128,128,128,.15);}
        .dpi-header img{border-radius:50%;border:2px solid var(--dpi-primary);}
        .dpi-header h2{margin:0 0 3px;font-size:1.05rem;}.dpi-header p{margin:0;opacity:.55;font-size:.82rem;}
        .dpi-stats{display:grid;grid-template-columns:repeat(3,1fr);border-bottom:1px solid rgba(128,128,128,.15);}
        .dpi-stat{padding:14px;text-align:center;border-right:1px solid rgba(128,128,128,.15);}.dpi-stat:last-child{border-right:none;}
        .dpi-stat strong{display:block;font-size:1.25rem;color:var(--dpi-primary);}.dpi-stat span{font-size:.58rem;text-transform:uppercase;opacity:.55;font-weight:700;}
        .dpi-tabs{display:flex;background:rgba(128,128,128,.05);border-bottom:1px solid rgba(128,128,128,.15);overflow-x:auto;}
        .dpi-tab-btn{flex:1;padding:14px;background:transparent;border:none;font-size:.85rem;font-weight:600;color:inherit;cursor:pointer;opacity:.6;transition:all .1s;border-bottom:2px solid transparent;white-space:nowrap;}
        .dpi-tab-btn:hover{opacity:1;background:rgba(128,128,128,.05);}.dpi-tab-btn.active{opacity:1;border-bottom-color:var(--dpi-primary);color:var(--dpi-primary);}
        .dpi-tab-content{display:none;}.dpi-tab-content.active{display:block;animation:dpiFade .25s ease;}
        @keyframes dpiFade{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:translateY(0)}}
        .dpi-body{padding:24px;}
        .dpi-sec-label{font-size:.62rem;text-transform:uppercase;letter-spacing:.05em;opacity:.45;font-weight:700;margin:0 0 12px;}
        .dpi-item{display:block;padding:12px 14px;background:rgba(128,128,128,.08);border-left:4px solid transparent;border-radius:10px;margin-bottom:7px;text-decoration:none!important;color:inherit!important;transition:background .15s;}
        .dpi-item:hover{background:rgba(128,128,128,.14);}.dpi-item strong{display:block;font-size:.9rem;}.dpi-item small{display:block;opacity:.55;font-size:.78rem;margin-top:2px;}
        .dpi-badge{font-size:.58rem;font-weight:800;text-transform:uppercase;opacity:.6;display:block;margin-bottom:3px;}
        .dpi-reply{border-left-color:#3b82f6;}.dpi-like{border-left-color:#10b981;}.dpi-dislike{border-left-color:#ef4444;}.dpi-author-comment{border-left-color:var(--dpi-primary);}
        .dpi-empty{opacity:.4;font-size:.83rem;padding:8px 0;}
        .dpi-more{width:100%;padding:9px;background:transparent;border:1px dashed rgba(128,128,128,.3);border-radius:9px;cursor:pointer;color:inherit;font-size:.78rem;margin:2px 0 18px;}
        .dpi-more:hover:not(:disabled){border-color:var(--dpi-primary);color:var(--dpi-primary);}
        .dpi-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:18px;}
        .dpi-form-grid input,.dpi-form-grid textarea,.dpi-pw-row input{width:100%;box-sizing:border-box;background:rgba(128,128,128,.09);border:1px solid rgba(128,128,128,.2);padding:10px 14px;border-radius:7px;color:inherit;font-size:.88rem;font-family:inherit;}
        .dpi-form-grid input:focus,.dpi-form-grid textarea:focus,.dpi-pw-row input:focus{outline:none;border-color:var(--dpi-primary);}
        .dpi-pw-row{display:flex;gap:8px;}.dpi-pw-limit{font-size:.72rem;opacity:.4;margin:6px 0 0;}
        .dpi-btn-primary{background:var(--dpi-primary);color:var(--dpi-btn-text)!important;border:none;padding:12px 20px;border-radius:7px;cursor:pointer;font-weight:700;font-size:.88rem;margin-top:18px;width:100%;transition:opacity .2s;}
        .dpi-btn-primary:hover{opacity:.9;}.dpi-btn-primary:disabled{opacity:.5;cursor:default;}
        .dpi-checkbox-row{margin-bottom:15px;font-size:.9rem;}.dpi-checkbox-row label{display:flex;align-items:center;gap:8px;cursor:pointer;}
        .dpi-checkbox-row input[type='checkbox']{width:auto;}
        .dpi-author-stats-box{padding:18px;background:var(--dpi-stats-bg);border-radius:10px;border:1px solid rgba(128,128,128,.15);margin-bottom:24px;}
        .dpi-notice{padding:12px 16px;background:rgba(128,128,128,.08);border-radius:8px;}
        @media(max-width:600px){.dpi-form-grid{grid-template-columns:1fr;}}
        ";
    }

    // -----------------------------------------------------------------------
    // JavaScript
    // -----------------------------------------------------------------------

    public function render_js() {
        if ( ! is_user_logged_in() ) return;
        ?>
        <script>
        (function(){
            var wrap = document.getElementById('dpi-profile');
            if (!wrap) return;
            var nonce = wrap.dataset.nonce;
            var ajax  = <?php echo wp_json_encode( admin_url('admin-ajax.php') ); ?>;

            // Tab switching
            wrap.querySelectorAll('.dpi-tab-btn').forEach(function(btn){
                btn.addEventListener('click', function(){
                    wrap.querySelectorAll('.dpi-tab-btn').forEach(function(b){ b.classList.remove('active'); });
                    wrap.querySelectorAll('.dpi-tab-content').forEach(function(c){ c.classList.remove('active'); });
                    btn.classList.add('active');
                    var t = document.getElementById(btn.dataset.target);
                    if (t) t.classList.add('active');
                });
            });

            // Load More
            wrap.addEventListener('click', function(e){
                var btn = e.target.closest('.dpi-more');
                if (!btn || btn.disabled) return;
                var type = btn.dataset.type;
                var page = parseInt(btn.dataset.page, 10) + 1;
                btn.disabled = true; btn.textContent = 'Loading...';

                var fd = new FormData();
                fd.append('action','dpi_load_more'); fd.append('nonce',nonce);
                fd.append('type',type); fd.append('page',page);

                fetch(ajax, {method:'POST',body:fd})
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        if (!res.success) return;
                        var map = {comments:'dpi-comments-list', subs:'dpi-subs-list', rated:'dpi-rated-list'};
                        var list = document.getElementById(map[type]);
                        if (list) list.insertAdjacentHTML('beforeend', res.data.html);
                        btn.dataset.page = page;
                        if (res.data.has_more){ btn.disabled=false; btn.textContent='Load More'; }
                        else { btn.remove(); }
                    })
                    .catch(function(){ btn.disabled=false; btn.textContent='Retry'; });
            });

            // Form submission
            ['dpi-profile-form','dpi-author-settings-form'].forEach(function(id){
                var f = document.getElementById(id);
                if (!f) return;
                f.addEventListener('submit', function(e){
                    e.preventDefault();
                    var s = f.querySelector('button[type="submit"]');
                    s.disabled = true;
                    var fd = new FormData(f);
                    fd.append('action','dpi_update_profile'); fd.append('nonce',nonce);

                    fetch(ajax, {method:'POST',body:fd})
                        .then(function(r){ return r.json(); })
                        .then(function(res){
                            if (typeof Swal !== 'undefined') {
                                Swal.fire({ icon:res.success?'success':'error', text:res.data,
                                    toast:true, position:'top-end', showConfirmButton:false, timer:3000 });
                            } else { alert(res.data); }
                            if (res.success && typeof res.data === 'string' && res.data.indexOf('Logging') !== -1) {
                                setTimeout(function(){ location.reload(); }, 2200);
                            }
                            s.disabled = false;
                        })
                        .catch(function(){ s.disabled=false; });
                });
            });
        })();
        </script>
        <?php
    }
}

add_action( 'plugins_loaded', function() {
    new DevProfileInsightsPro();
} );
