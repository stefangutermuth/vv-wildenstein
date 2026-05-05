<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Shared utilities for the vw-events plugin.
 */
final class VW_Events_Helpers {

    public const META_KEYS_PUBLIC = [
        '_vw_event_start',
        '_vw_event_end',
        '_vw_event_all_day',
        '_vw_event_repeat',
        '_vw_event_repeat_until',
        '_vw_event_location_name',
        '_vw_event_location_addr',
        '_vw_event_organizer_name',
        '_vw_event_url',
    ];

    public const META_KEYS_PRIVATE = [
        '_vw_event_organizer_email',
        '_vw_event_submitter_name',
        '_vw_event_submitter_email',
        '_vw_event_submission_ip',
        '_vw_event_source',
    ];

    public const STANDORT_DEFAULTS = [
        'gruenhainichen' => 'Grünhainichen',
        'borstendorf'    => 'Borstendorf',
        'waldkirchen'    => 'Waldkirchen',
        'boernichen'     => 'Börnichen',
        'verband-weit'   => 'Verband-weit',
    ];

    public const CATEGORY_DEFAULTS = [
        'kultur'   => 'Kultur',
        'sport'    => 'Sport',
        'kirche'   => 'Kirche',
        'verein'   => 'Verein',
        'markt'    => 'Markt',
        'bildung'  => 'Bildung',
        'sonstige' => 'Sonstige',
    ];

    public static function to_iso8601( ?string $local, bool $all_day = false ): ?string {
        if ( ! $local ) { return null; }
        try {
            $tz = wp_timezone();
            $dt = new DateTimeImmutable( $local, $tz );
            if ( $all_day ) {
                return $dt->format( 'Y-m-d' );
            }
            return $dt->format( 'c' );
        } catch ( Exception $e ) {
            return null;
        }
    }

    public static function format_event( WP_Post $post ): array {
        $all_day = (bool) get_post_meta( $post->ID, '_vw_event_all_day', true );
        $start   = (string) get_post_meta( $post->ID, '_vw_event_start', true );
        $end     = (string) get_post_meta( $post->ID, '_vw_event_end', true );

        $thumb_id = get_post_thumbnail_id( $post );
        $image    = null;
        if ( $thumb_id ) {
            $src = wp_get_attachment_image_src( $thumb_id, 'large' );
            if ( $src ) {
                $image = [
                    'url' => $src[0],
                    'alt' => (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ),
                ];
            }
        }

        $standort_terms = wp_get_post_terms( $post->ID, 'vw_standort', [ 'fields' => 'slugs' ] );
        $cat_terms      = wp_get_post_terms( $post->ID, 'vw_event_category', [ 'fields' => 'slugs' ] );

        return [
            'id'              => $post->ID,
            'slug'            => $post->post_name,
            'title'           => get_the_title( $post ),
            'description_html'=> wp_kses_post( apply_filters( 'the_content', $post->post_content ) ),
            'start'           => self::to_iso8601( $start, $all_day ),
            'end'             => $end ? self::to_iso8601( $end, $all_day ) : null,
            'all_day'         => $all_day,
            'repeat'          => (string) ( get_post_meta( $post->ID, '_vw_event_repeat', true ) ?: 'none' ),
            'repeat_until'    => ( get_post_meta( $post->ID, '_vw_event_repeat_until', true ) ?: null ),
            'location'        => [
                'name'    => (string) get_post_meta( $post->ID, '_vw_event_location_name', true ),
                'address' => (string) get_post_meta( $post->ID, '_vw_event_location_addr', true ),
            ],
            'organizer'       => [
                'name' => (string) get_post_meta( $post->ID, '_vw_event_organizer_name', true ),
            ],
            'url'             => (string) get_post_meta( $post->ID, '_vw_event_url', true ),
            'image'           => $image,
            'standort'        => is_array( $standort_terms ) ? $standort_terms : [],
            'category'        => is_array( $cat_terms ) ? $cat_terms : [],
            'permalink'       => get_permalink( $post ),
        ];
    }

    public static function client_ip(): string {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
            $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
        } elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $ip = trim( explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] )[0] );
        }
        return (string) $ip;
    }

    public static function hash_ip( string $ip ): string {
        $salt = wp_salt( 'auth' );
        return hash( 'sha256', $salt . '|' . $ip );
    }
}

/**
 * Render the JS filter bar (month dropdown, standort buttons, category pills,
 * quick-tabs). The actual filtering is done by assets/js/filter.js, which
 * looks for `.vw-events-filterbar` and `.vw-events-list` siblings.
 */
function vw_events_render_filter_bar( WP_Query $q ): string {
    if ( ! $q->have_posts() ) { return ''; }

    // Distinct months represented in the result set.
    $months = [];
    foreach ( $q->posts as $post ) {
        $start = (string) get_post_meta( $post->ID, '_vw_event_start', true );
        if ( $start === '' ) { continue; }
        $ts = strtotime( $start );
        if ( ! $ts ) { continue; }
        $key = date( 'Y-m', $ts );
        if ( ! isset( $months[ $key ] ) ) {
            $months[ $key ] = date_i18n( 'F Y', $ts );
        }
    }
    ksort( $months );

    $standorte  = get_terms( [ 'taxonomy' => 'vw_standort', 'hide_empty' => false ] );
    $categories = get_terms( [ 'taxonomy' => 'vw_event_category', 'hide_empty' => false ] );

    ob_start();
    ?>
    <div class="vw-events-filterbar" data-vw-filter>
        <div class="vw-events-filterbar-row">
            <div class="vw-events-quicktabs" role="group" aria-label="<?php esc_attr_e( 'Schnellfilter', 'vw-events' ); ?>">
                <button type="button" data-quick="all" class="is-active"><?php esc_html_e( 'Alle', 'vw-events' ); ?></button>
                <button type="button" data-quick="today"><?php esc_html_e( 'Heute', 'vw-events' ); ?></button>
                <button type="button" data-quick="week"><?php esc_html_e( 'Diese Woche', 'vw-events' ); ?></button>
                <button type="button" data-quick="month"><?php esc_html_e( 'Diesen Monat', 'vw-events' ); ?></button>
            </div>
            <label class="vw-events-month">
                <span class="vw-events-month-label"><?php esc_html_e( 'Monat', 'vw-events' ); ?></span>
                <select data-filter="month">
                    <option value=""><?php esc_html_e( 'Alle', 'vw-events' ); ?></option>
                    <?php foreach ( $months as $key => $label ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <?php if ( is_array( $standorte ) && count( $standorte ) > 1 ) : ?>
            <div class="vw-events-pills" role="group" aria-label="<?php esc_attr_e( 'Standorte', 'vw-events' ); ?>">
                <button type="button" data-filter="standort" data-value="" class="is-active"><?php esc_html_e( 'Alle Standorte', 'vw-events' ); ?></button>
                <?php foreach ( $standorte as $term ) : ?>
                    <button type="button" data-filter="standort" data-value="<?php echo esc_attr( $term->slug ); ?>"><?php echo esc_html( $term->name ); ?></button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ( is_array( $categories ) && count( $categories ) > 1 ) : ?>
            <div class="vw-events-pills vw-events-pills--cat" role="group" aria-label="<?php esc_attr_e( 'Kategorien', 'vw-events' ); ?>">
                <button type="button" data-filter="category" data-value="" class="is-active"><?php esc_html_e( 'Alle Kategorien', 'vw-events' ); ?></button>
                <?php foreach ( $categories as $term ) : ?>
                    <button type="button" data-filter="category" data-value="<?php echo esc_attr( $term->slug ); ?>"><?php echo esc_html( $term->name ); ?></button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="vw-events-filter-status" hidden></div>
    </div>
    <?php
    return (string) ob_get_clean();
}

/**
 * Build data-attributes string for an event card (used by the filter JS).
 */
function vw_events_card_data_attrs( int $post_id ): string {
    $start = (string) get_post_meta( $post_id, '_vw_event_start', true );
    $end   = (string) get_post_meta( $post_id, '_vw_event_end', true );
    $month = '';
    $start_iso = '';
    $end_iso   = '';
    if ( $start !== '' && ( $ts = strtotime( $start ) ) ) {
        $month     = date( 'Y-m', $ts );
        $start_iso = date( 'Y-m-d', $ts );
    }
    if ( $end !== '' && ( $te = strtotime( $end ) ) ) {
        $end_iso = date( 'Y-m-d', $te );
    } else {
        $end_iso = $start_iso;
    }
    $standorte  = wp_get_post_terms( $post_id, 'vw_standort', [ 'fields' => 'slugs' ] );
    $categories = wp_get_post_terms( $post_id, 'vw_event_category', [ 'fields' => 'slugs' ] );

    return sprintf(
        ' data-month="%s" data-start="%s" data-end="%s" data-standort="%s" data-category="%s"',
        esc_attr( $month ),
        esc_attr( $start_iso ),
        esc_attr( $end_iso ),
        esc_attr( implode( ' ', is_array( $standorte ) ? $standorte : [] ) ),
        esc_attr( implode( ' ', is_array( $categories ) ? $categories : [] ) )
    );
}

/**
 * Format an event date range for display.
 * Output: "30. April 2026<sep>18:00 – 20:00" (single day)
 *         "30. April 2026" (all-day, single day)
 *         "30. April 2026 18:00<sep>– 1. Mai 2026 02:00" (multi-day)
 */
function vw_events_format_date_range( string $start, string $end = '', bool $all_day = false, string $sep = "\n" ): string {
    if ( $start === '' ) { return '—'; }
    $ts_start = strtotime( $start );
    $ts_end   = $end !== '' ? strtotime( $end ) : 0;
    if ( ! $ts_start ) { return esc_html( $start ); }

    $date_fmt = 'j. F Y';
    $time_fmt = 'H:i';

    $start_date = date_i18n( $date_fmt, $ts_start );
    $start_time = date_i18n( $time_fmt, $ts_start );

    if ( $all_day ) {
        if ( $ts_end && date( 'Y-m-d', $ts_end ) !== date( 'Y-m-d', $ts_start ) ) {
            return $start_date . ' – ' . date_i18n( $date_fmt, $ts_end );
        }
        return $start_date;
    }

    if ( ! $ts_end ) {
        return $start_date . $sep . $start_time;
    }

    if ( date( 'Y-m-d', $ts_end ) === date( 'Y-m-d', $ts_start ) ) {
        return $start_date . $sep . $start_time . ' – ' . date_i18n( $time_fmt, $ts_end );
    }

    return $start_date . ' ' . $start_time . $sep . '– ' . date_i18n( $date_fmt, $ts_end ) . ' ' . date_i18n( $time_fmt, $ts_end );
}
