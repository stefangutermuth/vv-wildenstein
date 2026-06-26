<?php
/**
 * Läuft auf der ALTEN Installation melder.vv-wildenstein.com:
 *   wp eval-file export-meldungen.php > meldungen.json
 *
 * Gibt alle `meldungen` als JSON-Array aus, das der Importer
 * (`wp vw-melder import`) auf vv-wildenstein.com einliest.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    fwrite( STDERR, "Nur via wp eval-file ausführen.\n" );
    return;
}

$posts = get_posts( [
    'post_type'   => 'meldungen',
    'post_status' => 'any',
    'numberposts' => -1,
    'orderby'     => 'date',
    'order'       => 'ASC',
] );

$out = [];

foreach ( $posts as $post ) {
    $loc_raw = get_post_meta( $post->ID, 'location', true );
    $loc     = is_string( $loc_raw ) ? maybe_unserialize( $loc_raw ) : $loc_raw;
    if ( ! is_array( $loc ) ) { $loc = []; }

    // Adresse: bevorzugt formatierte OSM-Adresse, sonst separates Textfeld.
    $address = (string) ( $loc['address'] ?? '' );
    if ( $address === '' ) {
        $address = (string) get_post_meta( $post->ID, 'address', true );
    }

    // Anliegen: echte Taxonomie-Zuordnung, Fallback auf ACF-Feld "kategorie auswahl".
    $anliegen_slug = '';
    $a_terms = get_the_terms( $post->ID, 'angelegenheit' );
    if ( is_array( $a_terms ) && $a_terms ) {
        $anliegen_slug = $a_terms[0]->slug;
    } else {
        $cat_id = (int) get_post_meta( $post->ID, 'kategorie auswahl', true );
        if ( $cat_id ) {
            $t = get_term( $cat_id, 'angelegenheit' );
            if ( $t && ! is_wp_error( $t ) ) { $anliegen_slug = $t->slug; }
        }
    }

    // Status-Taxonomie.
    $status_slug = '';
    $s_terms = get_the_terms( $post->ID, 'status_meldung' );
    if ( is_array( $s_terms ) && $s_terms ) {
        $status_slug = $s_terms[0]->slug;
    }

    // Foto (Beitragsbild).
    $image_url = '';
    $thumb_id  = get_post_thumbnail_id( $post->ID );
    if ( $thumb_id ) {
        $image_url = (string) wp_get_attachment_image_url( $thumb_id, 'full' );
    }

    $out[] = [
        'source_id'      => $post->ID,
        'title'          => get_the_title( $post ),
        'content'        => $post->post_content,
        'date'           => $post->post_date,
        'status_slug'    => $status_slug,
        'anliegen_slug'  => $anliegen_slug,
        'lat'            => isset( $loc['lat'] ) ? (string) $loc['lat'] : '',
        'lng'            => isset( $loc['lng'] ) ? (string) $loc['lng'] : '',
        'address'        => $address,
        'city'           => (string) ( $loc['city'] ?? '' ),
        'postcode'       => (string) ( $loc['post_code'] ?? '' ),
        'reporter_name'  => trim( (string) get_post_meta( $post->ID, 'name_des_nutzers', true ) ),
        'reporter_email' => (string) get_post_meta( $post->ID, 'e-mail_des_einsenders', true ),
        'notify'         => (bool) get_post_meta( $post->ID, 'allow_comments', true ),
        'internal_note'  => (string) get_post_meta( $post->ID, 'hinweis_intern', true ),
        'image_url'      => $image_url,
    ];
}

echo wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
echo "\n";
