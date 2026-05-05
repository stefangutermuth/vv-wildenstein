<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class VW_Events_Frontend_Form {

    public static function init(): void {
        add_shortcode( 'vw_event_submit', [ __CLASS__, 'shortcode' ] );
        add_shortcode( 'vw_events_list', [ __CLASS__, 'shortcode_list' ] );
        add_shortcode( 'vw_events_upcoming', [ __CLASS__, 'shortcode_upcoming' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'register_assets' ] );
    }

    public static function shortcode_upcoming( $atts = [] ): string {
        $atts = shortcode_atts( [
            'count'    => 3,
            'standort' => '',
            'category' => '',
        ], $atts, 'vw_events_upcoming' );

        wp_enqueue_style( 'vw-events-single', VW_EVENTS_URL . 'assets/css/single-event.css', [], VW_EVENTS_VERSION );

        $args = [
            'post_type'      => 'vw_event',
            'post_status'    => 'publish',
            'posts_per_page' => max( 1, (int) $atts['count'] ),
            'meta_key'       => '_vw_event_start',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
            'tax_query'      => [],
            'meta_query'     => [
                [
                    'key'     => '_vw_event_start',
                    'value'   => current_time( 'Y-m-d\TH:i:s' ),
                    'compare' => '>=',
                    'type'    => 'DATETIME',
                ],
            ],
        ];
        if ( $atts['standort'] !== '' ) {
            $slugs   = array_map( 'sanitize_key', array_filter( array_map( 'trim', explode( ',', $atts['standort'] ) ) ) );
            $slugs[] = 'verband-weit';
            $args['tax_query'][] = [
                'taxonomy' => 'vw_standort',
                'field'    => 'slug',
                'terms'    => array_values( array_unique( $slugs ) ),
            ];
        }
        if ( $atts['category'] !== '' ) {
            $args['tax_query'][] = [
                'taxonomy' => 'vw_event_category',
                'field'    => 'slug',
                'terms'    => array_map( 'sanitize_key', array_filter( array_map( 'trim', explode( ',', $atts['category'] ) ) ) ),
            ];
        }

        return VW_Events_Multisite::with_master( static function () use ( $args ) {
            $q = new WP_Query( $args );
            ob_start();
            if ( ! $q->have_posts() ) {
                echo '<p class="vw-events-empty">' . esc_html__( 'Keine kommenden Veranstaltungen.', 'vw-events' ) . '</p>';
            } else {
                echo '<ul class="vw-events-upcoming">';
                while ( $q->have_posts() ) : $q->the_post();
                    $post_id  = get_the_ID();
                    $start    = (string) get_post_meta( $post_id, '_vw_event_start', true );
                    $end      = (string) get_post_meta( $post_id, '_vw_event_end', true );
                    $all_day  = (bool)   get_post_meta( $post_id, '_vw_event_all_day', true );
                    $when     = vw_events_format_date_range( $start, $end, $all_day, ' · ' );
                    $loc_name = (string) get_post_meta( $post_id, '_vw_event_location_name', true );
                    ?>
                    <li class="vw-event-up">
                        <a class="vw-event-up-link" href="<?php the_permalink(); ?>">
                            <?php if ( has_post_thumbnail() ) : ?>
                                <div class="vw-event-up-image"><?php the_post_thumbnail( 'medium' ); ?></div>
                            <?php else : ?>
                                <div class="vw-event-up-image vw-event-up-image-empty">📅</div>
                            <?php endif; ?>
                            <div class="vw-event-up-body">
                                <h3 class="vw-event-up-title"><?php the_title(); ?></h3>
                                <?php if ( $when !== '—' ) : ?><p class="vw-event-up-when"><?php echo esc_html( $when ); ?></p><?php endif; ?>
                                <?php if ( $loc_name !== '' ) : ?><p class="vw-event-up-where"><?php echo esc_html( $loc_name ); ?></p><?php endif; ?>
                            </div>
                        </a>
                    </li>
                    <?php
                endwhile;
                echo '</ul>';
            }
            wp_reset_postdata();
            return (string) ob_get_clean();
        } );
    }

    public static function shortcode_list( $atts = [] ): string {
        $atts = shortcode_atts( [
            'standort' => '',
            'category' => '',
            'limit'    => 20,
            'past'     => 'false',
            'filter'   => 'true',
        ], $atts, 'vw_events_list' );

        wp_enqueue_style( 'vw-events-single', VW_EVENTS_URL . 'assets/css/single-event.css', [], VW_EVENTS_VERSION );
        $with_filter = strtolower( (string) $atts['filter'] ) !== 'false';
        if ( $with_filter ) {
            wp_enqueue_style( 'vw-events-filter' );
            wp_enqueue_script( 'vw-events-filter' );
        }

        $args = [
            'post_type'      => 'vw_event',
            'post_status'    => 'publish',
            'posts_per_page' => max( 1, (int) $atts['limit'] ),
            'meta_key'       => '_vw_event_start',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
            'tax_query'      => [],
            'meta_query'     => [],
        ];

        if ( $atts['standort'] !== '' ) {
            $slugs = array_map( 'sanitize_key', array_filter( array_map( 'trim', explode( ',', $atts['standort'] ) ) ) );
            $slugs[] = 'verband-weit';
            $args['tax_query'][] = [
                'taxonomy' => 'vw_standort',
                'field'    => 'slug',
                'terms'    => array_values( array_unique( $slugs ) ),
            ];
        }
        if ( $atts['category'] !== '' ) {
            $args['tax_query'][] = [
                'taxonomy' => 'vw_event_category',
                'field'    => 'slug',
                'terms'    => array_map( 'sanitize_key', array_filter( array_map( 'trim', explode( ',', $atts['category'] ) ) ) ),
            ];
        }
        if ( strtolower( (string) $atts['past'] ) !== 'true' ) {
            $args['meta_query'][] = [
                'key'     => '_vw_event_start',
                'value'   => current_time( 'Y-m-d\TH:i:s' ),
                'compare' => '>=',
                'type'    => 'DATETIME',
            ];
        }

        return VW_Events_Multisite::with_master( static function () use ( $args, $with_filter ) {
            $q = new WP_Query( $args );
            ob_start();
            if ( ! $q->have_posts() ) {
                echo '<p class="vw-events-empty">' . esc_html__( 'Aktuell sind keine Veranstaltungen eingetragen.', 'vw-events' ) . '</p>';
            } else {
                if ( $with_filter ) {
                    echo vw_events_render_filter_bar( $q );
                }
                echo '<ul class="vw-events-list">';
                while ( $q->have_posts() ) : $q->the_post();
                    $post_id   = get_the_ID();
                    $start     = (string) get_post_meta( $post_id, '_vw_event_start', true );
                    $end       = (string) get_post_meta( $post_id, '_vw_event_end', true );
                    $all_day   = (bool)   get_post_meta( $post_id, '_vw_event_all_day', true );
                    $when      = vw_events_format_date_range( $start, $end, $all_day, ' · ' );
                    $loc_name  = (string) get_post_meta( $post_id, '_vw_event_location_name', true );
                    $standorte = wp_get_post_terms( $post_id, 'vw_standort', [ 'fields' => 'names' ] );
                    ?>
                    <li class="vw-event-card"<?php echo vw_events_card_data_attrs( $post_id ); ?>>
                        <a class="vw-event-card-link" href="<?php the_permalink(); ?>">
                            <?php if ( has_post_thumbnail() ) : ?>
                                <div class="vw-event-card-image"><?php the_post_thumbnail( 'medium' ); ?></div>
                            <?php else : ?>
                                <div class="vw-event-card-image vw-event-card-image-empty">📅</div>
                            <?php endif; ?>
                            <div class="vw-event-card-body">
                                <h2 class="vw-event-card-title"><?php the_title(); ?></h2>
                                <?php if ( $when !== '—' ) : ?><p class="vw-event-card-when"><?php echo esc_html( $when ); ?></p><?php endif; ?>
                                <?php if ( $loc_name !== '' ) : ?><p class="vw-event-card-where"><?php echo esc_html( $loc_name ); ?></p><?php endif; ?>
                                <?php if ( ! empty( $standorte ) && is_array( $standorte ) ) : ?><p class="vw-event-card-tags"><?php echo esc_html( implode( ' · ', $standorte ) ); ?></p><?php endif; ?>
                            </div>
                        </a>
                    </li>
                    <?php
                endwhile;
                echo '</ul>';
            }
            wp_reset_postdata();
            return (string) ob_get_clean();
        } );
    }

    public static function register_assets(): void {
        wp_register_style( 'vw-events-form', VW_EVENTS_URL . 'assets/css/frontend-form.css', [], VW_EVENTS_VERSION );
        wp_register_script( 'vw-events-form', VW_EVENTS_URL . 'assets/js/frontend-form.js', [], VW_EVENTS_VERSION, true );
        wp_register_style( 'vw-events-filter', VW_EVENTS_URL . 'assets/css/filter.css', [], VW_EVENTS_VERSION );
        wp_register_script( 'vw-events-filter', VW_EVENTS_URL . 'assets/js/filter.js', [], VW_EVENTS_VERSION, true );

        $settings = VW_Events_Admin_UI::get_settings();
        wp_localize_script( 'vw-events-form', 'VW_EVENTS', [
            'rest_url'         => esc_url_raw( rest_url( VW_Events_REST_Events::NS . '/submissions' ) ),
            'turnstile_site'   => (string) $settings['turnstile_site'],
            'max_file_bytes'   => VW_Events_REST_Submissions::MAX_FILE_BYTES,
            'i18n'             => [
                'success_title' => __( 'Vielen Dank!', 'vw-events' ),
                'success'       => __( 'Dein Event wurde eingereicht und wird vor Veröffentlichung geprüft.', 'vw-events' ),
                'error'         => __( 'Es ist ein Fehler aufgetreten.', 'vw-events' ),
                'too_big'       => __( 'Bild ist zu groß (max. 10 MB).', 'vw-events' ),
                'another'       => __( 'Noch ein Event einreichen', 'vw-events' ),
            ],
        ] );
    }

    public static function shortcode( $atts = [] ): string {
        $settings = VW_Events_Admin_UI::get_settings();

        // Auf Subsites kein Form rendern — Upload läuft ausschließlich auf dem Master.
        if ( VW_Events_Multisite::is_subsite() ) {
            wp_enqueue_style( 'vw-events-form' );
            $url = (string) ( $settings['submit_url'] ?? '' );
            ob_start();
            ?>
            <div class="vw-event-submit-elsewhere">
                <p><?php esc_html_e( 'Veranstaltungen werden zentral über den Verein Wildenstein eingereicht.', 'vw-events' ); ?></p>
                <?php if ( $url !== '' ) : ?>
                    <p><a class="vw-submit" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Event einreichen →', 'vw-events' ); ?></a></p>
                <?php endif; ?>
            </div>
            <?php
            return (string) ob_get_clean();
        }

        wp_enqueue_style( 'vw-events-form' );
        wp_enqueue_script( 'vw-events-form' );
        if ( ! empty( $settings['turnstile_site'] ) ) {
            wp_enqueue_script( 'cf-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', [], null, true );
        }

        $standorte  = get_terms( [ 'taxonomy' => 'vw_standort', 'hide_empty' => false ] );
        $categories = get_terms( [ 'taxonomy' => 'vw_event_category', 'hide_empty' => false ] );

        ob_start();
        $turnstile_site = (string) $settings['turnstile_site'];
        include VW_EVENTS_DIR . 'templates/frontend/form.php';
        return (string) ob_get_clean();
    }
}
