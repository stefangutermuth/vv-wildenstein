<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class VW_Events_REST_Submissions {

    public const MAX_FILE_BYTES = 10 * 1024 * 1024;
    public const ALLOWED_MIME   = [ 'image/jpeg', 'image/png', 'image/webp' ];
    public const RATE_LIMIT     = 5;
    public const RATE_WINDOW    = HOUR_IN_SECONDS;

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( VW_Events_REST_Events::NS, '/submissions', [
            'methods'             => 'POST',
            'permission_callback' => '__return_true',
            'callback'            => [ __CLASS__, 'handle' ],
        ] );
    }

    public static function handle( WP_REST_Request $req ) {
        // Honeypot: silently accept-look-like-success
        if ( ! empty( $req->get_param( 'website_url' ) ) || ! empty( $req->get_param( 'honeypot' ) ) ) {
            return new WP_REST_Response( [ 'ok' => true, 'message' => __( 'Vielen Dank, dein Event wird geprüft.', 'vw-events' ) ], 200 );
        }

        $ip      = VW_Events_Helpers::client_ip();
        $rate_k  = 'vw_events_rl_' . md5( $ip );
        $count   = (int) get_transient( $rate_k );
        if ( $count >= self::RATE_LIMIT ) {
            return new WP_REST_Response( [ 'ok' => false, 'errors' => [ '_global' => __( 'Zu viele Einreichungen. Bitte später erneut versuchen.', 'vw-events' ) ] ], 429 );
        }

        $errors = [];
        $get    = static fn( string $k, string $default = '' ) => trim( (string) ( $req->get_param( $k ) ?? $default ) );

        $title          = $get( 'title' );
        $description    = $get( 'description' );
        $start          = $get( 'start' );
        $end            = $get( 'end' );
        $all_day        = (bool) $req->get_param( 'all_day' );
        $location_name  = $get( 'location_name' );
        $location_addr  = $get( 'location_addr' );
        $organizer_name = $get( 'organizer_name' );
        $organizer_mail = $get( 'organizer_email' );
        $url            = $get( 'url' );
        $standorte      = (array) ( $req->get_param( 'standort' ) ?? [] );
        $categories     = (array) ( $req->get_param( 'category' ) ?? [] );
        $submitter_name = $get( 'submitter_name' );
        $submitter_mail = $get( 'submitter_email' );
        $turnstile      = $get( 'turnstile_token' );
        if ( $turnstile === '' ) {
            $turnstile = $get( 'cf-turnstile-response' );
        }

        if ( $title === '' || mb_strlen( $title ) > 200 )                $errors['title'] = __( 'Titel ist Pflicht (max. 200 Zeichen).', 'vw-events' );
        if ( $description === '' || mb_strlen( $description ) > 8000 )   $errors['description'] = __( 'Beschreibung ist Pflicht (max. 8000 Zeichen).', 'vw-events' );
        if ( $start === '' || ! strtotime( $start ) )                    $errors['start'] = __( 'Start-Datum ist Pflicht.', 'vw-events' );
        if ( $end !== '' && ! strtotime( $end ) )                        $errors['end'] = __( 'Ungültiges Ende-Datum.', 'vw-events' );
        if ( $organizer_name === '' )                                    $errors['organizer_name'] = __( 'Veranstalter-Name ist Pflicht.', 'vw-events' );
        if ( ! is_email( $organizer_mail ) )                             $errors['organizer_email'] = __( 'Gültige E-Mail erforderlich.', 'vw-events' );
        if ( $submitter_name === '' )                                    $errors['submitter_name'] = __( 'Bitte Namen angeben.', 'vw-events' );
        if ( ! is_email( $submitter_mail ) )                             $errors['submitter_email'] = __( 'Gültige E-Mail erforderlich.', 'vw-events' );
        if ( $url !== '' && ! filter_var( $url, FILTER_VALIDATE_URL ) )  $errors['url'] = __( 'Ungültige URL.', 'vw-events' );

        if ( is_string( $standorte ) ) {
            $standorte = array_filter( array_map( 'trim', explode( ',', $standorte ) ) );
        }
        $standorte = array_values( array_unique( array_map( 'sanitize_key', (array) $standorte ) ) );
        if ( empty( $standorte ) ) {
            $errors['standort'] = __( 'Mindestens einen Standort wählen.', 'vw-events' );
        }
        if ( is_string( $categories ) ) {
            $categories = array_filter( array_map( 'trim', explode( ',', $categories ) ) );
        }
        $categories = array_values( array_unique( array_map( 'sanitize_key', (array) $categories ) ) );

        if ( ! VW_Events_Turnstile::verify( $turnstile, $ip ) ) {
            $errors['turnstile_token'] = __( 'Bot-Schutz fehlgeschlagen. Bitte erneut versuchen.', 'vw-events' );
        }

        // File handling
        $file_id = null;
        $files   = $req->get_file_params();
        if ( ! empty( $files['image'] ) && ! empty( $files['image']['tmp_name'] ) ) {
            $file = $files['image'];
            if ( (int) $file['size'] > self::MAX_FILE_BYTES ) {
                $errors['image'] = __( 'Bild ist zu groß (max. 10 MB).', 'vw-events' );
            } else {
                $check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
                if ( empty( $check['type'] ) || ! in_array( $check['type'], self::ALLOWED_MIME, true ) ) {
                    $errors['image'] = __( 'Nur JPEG, PNG oder WEBP erlaubt.', 'vw-events' );
                } else {
                    $size = @getimagesize( $file['tmp_name'] );
                    if ( ! $size || $size[0] > 8000 || $size[1] > 8000 ) {
                        $errors['image'] = __( 'Bild-Dimensionen zu groß (max. 8000×8000).', 'vw-events' );
                    }
                }
            }
        }

        if ( ! empty( $errors ) ) {
            return new WP_REST_Response( [ 'ok' => false, 'errors' => $errors ], 400 );
        }

        // Rate-limit increment after validation passes
        set_transient( $rate_k, $count + 1, self::RATE_WINDOW );

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // Custom upload subdir for submissions
        $sub_filter = static function ( $dirs ) {
            $sub = '/vw-events-submissions/' . date( 'Y/m' );
            $dirs['subdir'] = $sub;
            $dirs['path']   = $dirs['basedir'] . $sub;
            $dirs['url']    = $dirs['baseurl'] . $sub;
            return $dirs;
        };

        $post_id = wp_insert_post( [
            'post_type'    => 'vw_event',
            'post_status'  => 'pending',
            'post_title'   => sanitize_text_field( $title ),
            'post_content' => wp_kses_post( $description ),
        ], true );

        if ( is_wp_error( $post_id ) ) {
            return new WP_REST_Response( [ 'ok' => false, 'errors' => [ '_global' => $post_id->get_error_message() ] ], 500 );
        }

        // Meta
        update_post_meta( $post_id, '_vw_event_start', sanitize_text_field( $start ) );
        if ( $end !== '' )            update_post_meta( $post_id, '_vw_event_end', sanitize_text_field( $end ) );
        update_post_meta( $post_id, '_vw_event_all_day', $all_day );
        update_post_meta( $post_id, '_vw_event_repeat', 'none' );
        if ( $location_name !== '' )  update_post_meta( $post_id, '_vw_event_location_name', sanitize_text_field( $location_name ) );
        if ( $location_addr !== '' )  update_post_meta( $post_id, '_vw_event_location_addr', sanitize_textarea_field( $location_addr ) );
        update_post_meta( $post_id, '_vw_event_organizer_name', sanitize_text_field( $organizer_name ) );
        update_post_meta( $post_id, '_vw_event_organizer_email', sanitize_email( $organizer_mail ) );
        if ( $url !== '' )            update_post_meta( $post_id, '_vw_event_url', esc_url_raw( $url ) );
        update_post_meta( $post_id, '_vw_event_submitter_name', sanitize_text_field( $submitter_name ) );
        update_post_meta( $post_id, '_vw_event_submitter_email', sanitize_email( $submitter_mail ) );
        update_post_meta( $post_id, '_vw_event_submission_ip', VW_Events_Helpers::hash_ip( $ip ) );
        update_post_meta( $post_id, '_vw_event_source', 'frontend_form' );

        // Taxonomies
        wp_set_object_terms( $post_id, $standorte, 'vw_standort', false );
        if ( ! empty( $categories ) ) {
            wp_set_object_terms( $post_id, $categories, 'vw_event_category', false );
        }

        // Image
        if ( ! empty( $files['image']['tmp_name'] ) ) {
            add_filter( 'upload_dir', $sub_filter );
            if ( ! isset( $_FILES['image'] ) ) {
                $_FILES['image'] = $files['image'];
            }
            $attach_id = media_handle_upload( 'image', $post_id );
            remove_filter( 'upload_dir', $sub_filter );

            if ( ! is_wp_error( $attach_id ) ) {
                set_post_thumbnail( $post_id, $attach_id );
                self::strip_exif( get_attached_file( $attach_id ) );
            }
        }

        // Notify
        VW_Events_Mailer::notify_admin_new_submission( $post_id );
        VW_Events_Mailer::notify_submitter_thanks( $post_id );

        return new WP_REST_Response( [
            'ok'      => true,
            'message' => __( 'Vielen Dank, dein Event wird geprüft.', 'vw-events' ),
        ], 200 );
    }

    private static function strip_exif( ?string $path ): void {
        if ( ! $path || ! file_exists( $path ) ) { return; }
        $mime = mime_content_type( $path );
        if ( $mime === 'image/jpeg' && function_exists( 'imagecreatefromjpeg' ) ) {
            $img = @imagecreatefromjpeg( $path );
            if ( $img ) { imagejpeg( $img, $path, 90 ); imagedestroy( $img ); }
        } elseif ( $mime === 'image/png' && function_exists( 'imagecreatefrompng' ) ) {
            $img = @imagecreatefrompng( $path );
            if ( $img ) { imagepng( $img, $path ); imagedestroy( $img ); }
        }
    }
}
