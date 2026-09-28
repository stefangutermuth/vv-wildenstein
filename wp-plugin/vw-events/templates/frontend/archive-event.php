<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
wp_enqueue_style( 'vw-events-filter' );
wp_enqueue_script( 'vw-events-filter' );
get_header();

$is_tax_standort = is_tax( 'vw_standort' );
$is_tax_cat      = is_tax( 'vw_event_category' );
$tax_term        = ( $is_tax_standort || $is_tax_cat ) ? get_queried_object() : null;

// Always run the query in master-blog context (no-op on master).
$query_args = [
    'post_type'      => 'vw_event',
    'post_status'    => 'publish',
    'posts_per_page' => 20,
    'paged'          => max( 1, (int) get_query_var( 'paged' ) ),
    'meta_key'       => '_vw_event_start',
    'orderby'        => 'meta_value',
    'order'          => 'ASC',
];
if ( $tax_term && ! empty( $tax_term->slug ) ) {
    $query_args['tax_query'] = [ [
        'taxonomy' => $tax_term->taxonomy,
        'field'    => 'slug',
        'terms'    => $tax_term->slug,
    ] ];
}

// Remote-Modus: Master-URL gesetzt → Events live per REST vom Master holen.
if ( VW_Events_Multisite::is_remote() ) {
    wp_enqueue_style( 'vw-events-single', VW_EVENTS_URL . 'assets/css/single-event.css', [], VW_EVENTS_VERSION );
    $params = [
        'per_page' => 100,
        'page'     => 1,
        'from'     => current_time( 'Y-m-d\TH:i:s' ),
    ];
    if ( $is_tax_standort && $tax_term ) { $params['standort'] = $tax_term->slug; }
    if ( $is_tax_cat && $tax_term )      { $params['category'] = $tax_term->slug; }
    $all = [];
    while ( true ) {
        $batch = VW_Events_Multisite::fetch_remote_events( $params );
        if ( empty( $batch ) ) { break; }
        foreach ( $batch as $ev ) { $all[] = $ev; }
        if ( count( $batch ) < $params['per_page'] ) { break; }
        $params['page']++;
        if ( $params['page'] > 50 ) { break; }
    }
    ?>
    <main class="vw-events-archive-main">
        <div class="vw-events-archive">
            <header class="vw-events-archive-header">
                <h1><?php
                    if ( $is_tax_standort && $tax_term ) {
                        printf( esc_html__( 'Veranstaltungen in %s', 'vw-events' ), esc_html( $tax_term->name ) );
                    } elseif ( $is_tax_cat && $tax_term ) {
                        printf( esc_html__( 'Kategorie: %s', 'vw-events' ), esc_html( $tax_term->name ) );
                    } else {
                        esc_html_e( 'Veranstaltungen', 'vw-events' );
                    }
                ?></h1>
            </header>
            <?php if ( ! empty( $all ) ) : ?>
                <?php echo vw_events_render_filter_bar_from_array( $all ); ?>
                <div class="vw-events-list">
                    <?php foreach ( $all as $ev ) echo vw_events_render_card_from_array( $ev ); ?>
                </div>
            <?php else : ?>
                <p class="vw-events-empty"><?php esc_html_e( 'Aktuell sind keine Veranstaltungen eingetragen.', 'vw-events' ); ?></p>
            <?php endif; ?>
        </div>
    </main>
    <?php
    get_footer();
    return;
}

VW_Events_Multisite::with_master( static function () use ( $query_args, $is_tax_standort, $is_tax_cat, $tax_term ) {
    $q = new WP_Query( $query_args );
    ?>
    <main class="vw-events-archive-main">
        <div class="vw-events-archive">
            <header class="vw-events-archive-header">
                <h1><?php
                    if ( $is_tax_standort && $tax_term ) {
                        printf( esc_html__( 'Veranstaltungen in %s', 'vw-events' ), esc_html( $tax_term->name ) );
                    } elseif ( $is_tax_cat && $tax_term ) {
                        printf( esc_html__( 'Kategorie: %s', 'vw-events' ), esc_html( $tax_term->name ) );
                    } else {
                        esc_html_e( 'Veranstaltungen', 'vw-events' );
                    }
                ?></h1>
            </header>

            <?php if ( $q->have_posts() ) : ?>
                <?php echo vw_events_render_filter_bar( $q ); ?>
                <div class="vw-events-list">
                    <?php while ( $q->have_posts() ) : $q->the_post();
                        $post_id   = get_the_ID();
                        $start     = (string) get_post_meta( $post_id, '_vw_event_start', true );
                        $end       = (string) get_post_meta( $post_id, '_vw_event_end', true );
                        $all_day   = (bool)   get_post_meta( $post_id, '_vw_event_all_day', true );
                        $when      = vw_events_format_date_range( $start, $end, $all_day, ' · ' );
                        $loc_name  = (string) get_post_meta( $post_id, '_vw_event_location_name', true );
                        $standorte = wp_get_post_terms( $post_id, 'vw_standort', [ 'fields' => 'names' ] );
                        $thumb_url = has_post_thumbnail() ? get_the_post_thumbnail_url( $post_id, 'large' ) : '';
                    ?>
                        <article class="vw-event-card<?php echo $thumb_url ? '' : ' has-no-image'; ?>"<?php echo vw_events_card_data_attrs( $post_id ); ?>>
                            <a class="vw-event-card-link" href="<?php the_permalink(); ?>">
                                <?php if ( $thumb_url ) : ?>
                                    <div class="vw-event-card-image">
                                        <img class="vw-event-card-image-fg" src="<?php echo esc_url( $thumb_url ); ?>" alt="" loading="lazy">
                                    </div>
                                <?php endif; ?>
                                <div class="vw-event-card-body">
                                    <h2 class="vw-event-card-title"><?php the_title(); ?></h2>
                                    <?php if ( $when !== '—' ) : ?><p class="vw-event-card-when"><?php echo esc_html( $when ); ?></p><?php endif; ?>
                                    <?php if ( $loc_name !== '' ) : ?><p class="vw-event-card-where"><?php echo esc_html( $loc_name ); ?></p><?php endif; ?>
                                    <?php if ( ! empty( $standorte ) && is_array( $standorte ) ) : ?><p class="vw-event-card-tags"><?php echo esc_html( implode( ' · ', $standorte ) ); ?></p><?php endif; ?>
                                    <?php $excerpt = wp_strip_all_tags( get_the_excerpt() ); if ( $excerpt !== '' ) : ?>
                                        <p class="vw-event-card-excerpt"><?php echo esc_html( wp_trim_words( $excerpt, 30, '…' ) ); ?></p>
                                    <?php endif; ?>
                                </div>
                            </a>
                        </article>
                    <?php endwhile; ?>
                </div>
                <?php wp_reset_postdata(); ?>
            <?php else : ?>
                <p class="vw-events-empty"><?php esc_html_e( 'Aktuell sind keine Veranstaltungen eingetragen.', 'vw-events' ); ?></p>
            <?php endif; ?>
        </div>
    </main>
    <?php
} );

get_footer();
