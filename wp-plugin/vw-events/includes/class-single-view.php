<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Renders event metadata around the post content on the single-event page.
 * Theme-agnostic — uses the `the_content` filter so the active theme's
 * single template still provides header, sidebar and padding.
 */
final class VW_Events_Single_View {

    public static function init(): void {
        add_filter( 'the_content', [ __CLASS__, 'inject' ], 12 );
        add_filter( 'archive_template', [ __CLASS__, 'archive_template' ] );
        add_filter( 'taxonomy_template', [ __CLASS__, 'archive_template' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
        add_action( 'pre_get_posts', [ __CLASS__, 'order_archive_by_start' ] );
        add_action( 'template_redirect', [ __CLASS__, 'maybe_redirect_archive' ] );
    }

    public static function maybe_redirect_archive(): void {
        if ( ! is_post_type_archive( 'vw_event' ) ) { return; }
        $url = self::archive_url();
        if ( $url === '' ) { return; }

        // Loop-Schutz: gleicher Pfad → nicht weiterleiten, Archive-Template rendert.
        $target_path  = wp_parse_url( $url, PHP_URL_PATH ) ?: '';
        $current_path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) : '';
        if ( trailingslashit( $target_path ) === trailingslashit( $current_path ) ) {
            return;
        }

        wp_safe_redirect( $url, 301 );
        exit;
    }

    public static function archive_url(): string {
        $settings = VW_Events_Admin_UI::get_settings();
        $raw      = trim( (string) ( $settings['archive_url'] ?? '' ) );
        if ( $raw === '' ) { return ''; }
        if ( preg_match( '#^https?://#i', $raw ) ) { return $raw; }
        return home_url( '/' . ltrim( $raw, '/' ) );
    }

    public static function order_archive_by_start( WP_Query $q ): void {
        if ( is_admin() || ! $q->is_main_query() ) { return; }
        if ( $q->is_post_type_archive( 'vw_event' ) || $q->is_tax( [ 'vw_standort', 'vw_event_category' ] ) ) {
            $q->set( 'meta_key', '_vw_event_start' );
            $q->set( 'orderby', 'meta_value' );
            $q->set( 'order', 'ASC' );
            $q->set( 'posts_per_page', 20 );
        }
    }

    public static function archive_template( string $template ): string {
        if ( is_post_type_archive( 'vw_event' ) || is_tax( [ 'vw_standort', 'vw_event_category' ] ) ) {
            $custom = VW_EVENTS_DIR . 'templates/frontend/archive-event.php';
            if ( file_exists( $custom ) ) {
                return $custom;
            }
        }
        return $template;
    }

    public static function enqueue(): void {
        if ( is_singular( 'vw_event' ) || is_post_type_archive( 'vw_event' ) || is_tax( [ 'vw_standort', 'vw_event_category' ] ) ) {
            wp_enqueue_style( 'vw-events-single', VW_EVENTS_URL . 'assets/css/single-event.css', [], VW_EVENTS_VERSION );
        }
    }


    public static function inject( string $content ): string {
        if ( ! is_singular( 'vw_event' ) || ! in_the_loop() || ! is_main_query() ) {
            return $content;
        }
        $post_id = get_the_ID();
        if ( ! $post_id ) { return $content; }

        $start   = (string) get_post_meta( $post_id, '_vw_event_start', true );
        $end     = (string) get_post_meta( $post_id, '_vw_event_end', true );
        $all_day = (bool)   get_post_meta( $post_id, '_vw_event_all_day', true );
        $when    = vw_events_format_date_range( $start, $end, $all_day, ' · ' );

        $loc_name = (string) get_post_meta( $post_id, '_vw_event_location_name', true );
        $loc_addr = (string) get_post_meta( $post_id, '_vw_event_location_addr', true );
        $org_name = (string) get_post_meta( $post_id, '_vw_event_organizer_name', true );
        $url      = (string) get_post_meta( $post_id, '_vw_event_url', true );
        $standorte = wp_get_post_terms( $post_id, 'vw_standort', [ 'fields' => 'names' ] );
        $cats      = wp_get_post_terms( $post_id, 'vw_event_category', [ 'fields' => 'names' ] );

        $has_image = has_post_thumbnail( $post_id );
        ob_start();
        ?>
        <div class="vw-event-header<?php echo $has_image ? ' has-image' : ''; ?>">
            <?php if ( $has_image ) : ?>
                <figure class="vw-event-image">
                    <?php echo get_the_post_thumbnail( $post_id, 'large' ); ?>
                </figure>
            <?php endif; ?>
            <div class="vw-event-meta">
            <?php if ( $when !== '—' ) : ?>
                <div class="vw-event-meta-row">
                    <span class="vw-event-meta-label"><?php esc_html_e( 'Wann', 'vw-events' ); ?></span>
                    <span class="vw-event-meta-value"><?php echo esc_html( $when ); ?></span>
                </div>
            <?php endif; ?>

            <?php if ( $loc_name !== '' || $loc_addr !== '' ) : ?>
                <div class="vw-event-meta-row">
                    <span class="vw-event-meta-label"><?php esc_html_e( 'Wo', 'vw-events' ); ?></span>
                    <span class="vw-event-meta-value">
                        <?php if ( $loc_name !== '' ) : ?>
                            <strong><?php echo esc_html( $loc_name ); ?></strong>
                        <?php endif; ?>
                        <?php if ( $loc_addr !== '' ) : ?>
                            <?php if ( $loc_name !== '' ) echo '<br>'; ?>
                            <?php echo nl2br( esc_html( $loc_addr ) ); ?>
                        <?php endif; ?>
                    </span>
                </div>
            <?php endif; ?>

            <?php if ( $org_name !== '' ) : ?>
                <div class="vw-event-meta-row">
                    <span class="vw-event-meta-label"><?php esc_html_e( 'Veranstalter', 'vw-events' ); ?></span>
                    <span class="vw-event-meta-value"><?php echo esc_html( $org_name ); ?></span>
                </div>
            <?php endif; ?>

            <?php if ( ! empty( $standorte ) && is_array( $standorte ) ) : ?>
                <div class="vw-event-meta-row">
                    <span class="vw-event-meta-label"><?php esc_html_e( 'Ortsteil', 'vw-events' ); ?></span>
                    <span class="vw-event-meta-value"><?php echo esc_html( implode( ', ', $standorte ) ); ?></span>
                </div>
            <?php endif; ?>

            <?php if ( ! empty( $cats ) && is_array( $cats ) ) : ?>
                <div class="vw-event-meta-row">
                    <span class="vw-event-meta-label"><?php esc_html_e( 'Kategorie', 'vw-events' ); ?></span>
                    <span class="vw-event-meta-value"><?php echo esc_html( implode( ', ', $cats ) ); ?></span>
                </div>
            <?php endif; ?>

            <?php if ( $url !== '' ) : ?>
                <div class="vw-event-meta-row">
                    <span class="vw-event-meta-label"><?php esc_html_e( 'Link', 'vw-events' ); ?></span>
                    <span class="vw-event-meta-value">
                        <a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $url ); ?></a>
                    </span>
                </div>
            <?php endif; ?>
            </div>
        </div>
        <?php
        $meta_html = (string) ob_get_clean();

        $back_url  = self::archive_url() ?: (string) get_post_type_archive_link( 'vw_event' );
        $back_html = $back_url ? '<p class="vw-event-back"><a href="' . esc_url( $back_url ) . '">← ' . esc_html__( 'Alle Veranstaltungen', 'vw-events' ) . '</a></p>' : '';

        return $meta_html . $content . $back_html;
    }
}
