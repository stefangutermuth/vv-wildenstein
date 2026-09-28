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
 * Liefert ein Array [day, month_abbr] für ein Event-Start-Date.
 * Beispiele: ['15', 'Mai'], ['09', 'Dez']. Bei Recurring/leerem Start: [null, null].
 */
function vw_events_calendar_leaf( int $post_id ): array {
    $repeat = (string) get_post_meta( $post_id, '_vw_event_repeat', true );
    if ( $repeat && $repeat !== 'none' ) {
        return [ '∞', strtoupper( substr( $repeat, 0, 3 ) ) ];
    }
    $start = (string) get_post_meta( $post_id, '_vw_event_start', true );
    if ( ! $start ) { return [ null, null ]; }
    $ts = strtotime( $start );
    if ( ! $ts ) { return [ null, null ]; }
    return [
        date( 'j', $ts ),
        ucfirst( strtolower( substr( date_i18n( 'M', $ts ), 0, 3 ) ) ),
    ];
}

/**
 * meta_query-Klausel für „relevante" Events:
 *   1) Start liegt in der Zukunft, ODER
 *   2) Ende liegt in der Zukunft (aktuell laufende mehrtägige Events), ODER
 *   3) Event ist eine Dauerveranstaltung (_vw_event_repeat != 'none')
 */
function vw_events_meta_query_relevant(): array {
    $now = current_time( 'Y-m-d\TH:i:s' );
    return [
        'relation' => 'OR',
        [
            'key'     => '_vw_event_start',
            'value'   => $now,
            'compare' => '>=',
            'type'    => 'DATETIME',
        ],
        [
            'key'     => '_vw_event_end',
            'value'   => $now,
            'compare' => '>=',
            'type'    => 'DATETIME',
        ],
        [
            'key'     => '_vw_event_repeat',
            'value'   => 'none',
            'compare' => '!=',
        ],
    ];
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
            <label class="vw-events-duration">
                <span class="vw-events-duration-label"><?php esc_html_e( 'Dauer', 'vw-events' ); ?></span>
                <select data-filter="duration">
                    <option value=""><?php esc_html_e( 'Alle Dauern', 'vw-events' ); ?></option>
                    <option value="single"><?php esc_html_e( 'Eintägig', 'vw-events' ); ?></option>
                    <option value="multi"><?php esc_html_e( 'Mehrtägig', 'vw-events' ); ?></option>
                </select>
            </label>
            <div class="vw-events-viewtoggle" role="group" aria-label="<?php esc_attr_e( 'Ansicht', 'vw-events' ); ?>">
                <button type="button" data-view="grid" class="is-active" aria-label="<?php esc_attr_e( 'Kachelansicht', 'vw-events' ); ?>" title="<?php esc_attr_e( 'Kacheln', 'vw-events' ); ?>">▦</button>
                <button type="button" data-view="list" aria-label="<?php esc_attr_e( 'Listenansicht', 'vw-events' ); ?>" title="<?php esc_attr_e( 'Liste', 'vw-events' ); ?>">☰</button>
            </div>
        </div>

        <label class="vw-events-search">
            <span class="vw-events-search-icon" aria-hidden="true">🔎</span>
            <input
                type="search"
                data-filter="search"
                placeholder="<?php esc_attr_e( 'Veranstaltung, Ort oder Veranstalter suchen …', 'vw-events' ); ?>"
                aria-label="<?php esc_attr_e( 'Veranstaltungen durchsuchen', 'vw-events' ); ?>"
                autocomplete="off"
            />
            <button type="button" class="vw-events-search-clear" data-search-clear hidden aria-label="<?php esc_attr_e( 'Suche zurücksetzen', 'vw-events' ); ?>">×</button>
        </label>

        <div class="vw-events-filter-status" hidden></div>
    </div>
    <?php
    return (string) ob_get_clean();
}

/**
 * Build data-attributes string for an event card (used by the filter JS).
 */
/**
 * Render the filter bar from an array of remote events (REST-JSON-Format).
 * Pendant zu vw_events_render_filter_bar(), das ein WP_Query verlangt.
 */
function vw_events_render_filter_bar_from_array( array $events ): string {
    if ( empty( $events ) ) { return ''; }
    $months = [];
    foreach ( $events as $e ) {
        $start = (string) ( $e['start'] ?? '' );
        if ( $start === '' ) { continue; }
        $ts = strtotime( $start );
        if ( ! $ts ) { continue; }
        $key = date( 'Y-m', $ts );
        if ( ! isset( $months[ $key ] ) ) {
            $months[ $key ] = date_i18n( 'F Y', $ts );
        }
    }
    ksort( $months );
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
            <label class="vw-events-duration">
                <span class="vw-events-duration-label"><?php esc_html_e( 'Dauer', 'vw-events' ); ?></span>
                <select data-filter="duration">
                    <option value=""><?php esc_html_e( 'Alle Dauern', 'vw-events' ); ?></option>
                    <option value="single"><?php esc_html_e( 'Eintägig', 'vw-events' ); ?></option>
                    <option value="multi"><?php esc_html_e( 'Mehrtägig', 'vw-events' ); ?></option>
                </select>
            </label>
            <div class="vw-events-viewtoggle" role="group" aria-label="<?php esc_attr_e( 'Ansicht', 'vw-events' ); ?>">
                <button type="button" data-view="grid" class="is-active" title="<?php esc_attr_e( 'Kacheln', 'vw-events' ); ?>">▦</button>
                <button type="button" data-view="list" title="<?php esc_attr_e( 'Liste', 'vw-events' ); ?>">☰</button>
            </div>
        </div>
        <label class="vw-events-search">
            <span class="vw-events-search-icon" aria-hidden="true">🔎</span>
            <input type="search" data-filter="search"
                placeholder="<?php esc_attr_e( 'Veranstaltung, Ort oder Veranstalter suchen …', 'vw-events' ); ?>"
                aria-label="<?php esc_attr_e( 'Veranstaltungen durchsuchen', 'vw-events' ); ?>" autocomplete="off" />
            <button type="button" class="vw-events-search-clear" data-search-clear hidden aria-label="<?php esc_attr_e( 'Suche zurücksetzen', 'vw-events' ); ?>">×</button>
        </label>
        <div class="vw-events-filter-status" hidden></div>
    </div>
    <?php
    return (string) ob_get_clean();
}

/**
 * Rendert eine Event-Card aus dem REST-JSON-Format der Master-WP.
 */
function vw_events_render_card_from_array( array $e ): string {
    $start = (string) ( $e['start'] ?? '' );
    $end   = (string) ( $e['end'] ?? '' );
    $all_day = ! empty( $e['all_day'] );
    $when    = vw_events_format_date_range( $start, $end, $all_day, ' · ' );
    $title   = (string) ( $e['title'] ?? '' );
    $loc     = isset( $e['location']['name'] ) ? (string) $e['location']['name'] : '';
    $standorte = is_array( $e['standort'] ?? null ) ? $e['standort'] : [];
    $categories = is_array( $e['category'] ?? null ) ? $e['category'] : [];
    $thumb   = isset( $e['image']['url'] ) ? (string) $e['image']['url'] : '';
    $link    = (string) ( $e['permalink'] ?? '' );
    $descr   = (string) ( $e['description_html'] ?? '' );
    $excerpt = wp_trim_words( wp_strip_all_tags( $descr ), 30, '…' );

    $month_attr = '';
    $start_d    = '';
    $end_d      = '';
    if ( $start !== '' && ( $ts = strtotime( $start ) ) ) {
        $month_attr = date( 'Y-m', $ts );
        $start_d    = date( 'Y-m-d', $ts );
    }
    if ( $end !== '' && ( $te = strtotime( $end ) ) ) {
        $end_d = date( 'Y-m-d', $te );
    } else {
        $end_d = $start_d;
    }

    ob_start();
    ?>
    <article class="vw-event-card<?php echo $thumb ? '' : ' has-no-image'; ?>"
        data-month="<?php echo esc_attr( $month_attr ); ?>"
        data-start="<?php echo esc_attr( $start_d ); ?>"
        data-end="<?php echo esc_attr( $end_d ); ?>"
        data-standort="<?php echo esc_attr( implode( ' ', $standorte ) ); ?>"
        data-category="<?php echo esc_attr( implode( ' ', $categories ) ); ?>">
        <a class="vw-event-card-link" href="<?php echo esc_url( $link ); ?>" target="_blank" rel="noopener">
            <?php if ( $thumb ) : ?>
                <div class="vw-event-card-image">
                    <img class="vw-event-card-image-fg" src="<?php echo esc_url( $thumb ); ?>" alt="" loading="lazy">
                </div>
            <?php endif; ?>
            <div class="vw-event-card-body">
                <h2 class="vw-event-card-title"><?php echo esc_html( $title ); ?></h2>
                <?php if ( $when !== '—' ) : ?><p class="vw-event-card-when"><?php echo esc_html( $when ); ?></p><?php endif; ?>
                <?php if ( $loc !== '' ) : ?><p class="vw-event-card-where"><?php echo esc_html( $loc ); ?></p><?php endif; ?>
                <?php if ( ! empty( $standorte ) ) : ?><p class="vw-event-card-tags"><?php echo esc_html( implode( ' · ', $standorte ) ); ?></p><?php endif; ?>
                <?php if ( $excerpt !== '' ) : ?><p class="vw-event-card-excerpt"><?php echo esc_html( $excerpt ); ?></p><?php endif; ?>
            </div>
        </a>
    </article>
    <?php
    return (string) ob_get_clean();
}

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
