<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<h2>Neue Veranstaltung eingereicht</h2>
<p><strong><?php echo esc_html( $title ); ?></strong></p>
<table cellpadding="6" cellspacing="0" style="border-collapse:collapse;">
    <tr><td><strong>Termin:</strong></td><td><?php echo esc_html( $when ); ?></td></tr>
    <?php if ( ! empty( $location_name ) || ! empty( $location_addr ) ) : ?>
        <tr><td><strong>Ort:</strong></td><td><?php echo esc_html( trim( $location_name . "\n" . $location_addr ) ); ?></td></tr>
    <?php endif; ?>
    <tr><td><strong>Veranstalter:</strong></td><td><?php echo esc_html( $organizer ); ?></td></tr>
</table>
<p><a href="<?php echo esc_url( $edit_link ); ?>">Im Backend prüfen →</a></p>
<p style="color:#666;font-size:12px;"><?php echo esc_html( $site_name ); ?> · <?php echo esc_url( $site_url ); ?></p>
