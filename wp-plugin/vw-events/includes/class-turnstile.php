<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class VW_Events_Turnstile {

    public const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public static function verify( string $token, string $remote_ip = '' ): bool {
        $settings = VW_Events_Admin_UI::get_settings();
        $secret   = (string) ( $settings['turnstile_secret'] ?? '' );
        if ( $secret === '' ) {
            return false;
        }
        if ( $token === '' ) {
            return false;
        }

        $body = [
            'secret'   => $secret,
            'response' => $token,
        ];
        if ( $remote_ip !== '' ) {
            $body['remoteip'] = $remote_ip;
        }

        $resp = wp_remote_post( self::VERIFY_URL, [
            'timeout' => 10,
            'body'    => $body,
        ] );
        if ( is_wp_error( $resp ) ) {
            return false;
        }
        $data = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
        return is_array( $data ) && ! empty( $data['success'] );
    }
}
