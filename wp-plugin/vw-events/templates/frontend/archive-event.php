<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
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
                <ul class="vw-events-list">
                    <?php while ( $q->have_posts() ) : $q->the_post();
                        $post_id   = get_the_ID();
                        $start     = (string) get_post_meta( $post_id, '_vw_event_start', true );
                        $end       = (string) get_post_meta( $post_id, '_vw_event_end', true );
                        $all_day   = (bool)   get_post_meta( $post_id, '_vw_event_all_day', true );
                        $when      = vw_events_format_date_range( $start, $end, $all_day, ' · ' );
                        $loc_name  = (string) get_post_meta( $post_id, '_vw_event_location_name', true );
                        $standorte = wp_get_post_terms( $post_id, 'vw_standort', [ 'fields' => 'names' ] );
                    ?>
                        <li class="vw-event-card">
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
                    <?php endwhile; ?>
                </ul>
                <?php wp_reset_postdata(); ?>
            <?php else : ?>
                <p class="vw-events-empty"><?php esc_html_e( 'Aktuell sind keine Veranstaltungen eingetragen.', 'vw-events' ); ?></p>
            <?php endif; ?>
        </div>
    </main>
    <?php
} );

get_footer();
